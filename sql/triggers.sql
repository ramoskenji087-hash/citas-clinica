SET client_encoding = 'UTF8';
-- =============================================================================
-- sql/triggers.sql
-- Sistema Web de Gestión de Citas Médicas - Clínica Vida Salud
-- Se ejecuta DESPUÉS de sql/schema.sql.
--
-- Importante: schema.sql borra las tablas con DROP TABLE ... CASCADE, y los
-- triggers se borran junto con ellas. Por eso cada vez que vuelvas a ejecutar
-- schema.sql, debes ejecutar también este archivo.
--
-- Qué hace este archivo: las reglas que un CHECK o una clave foránea no pueden
-- expresar, porque dependen de la hora actual, de otras tablas o de cómo
-- cambia el registro.
--
-- Códigos de error (SQLSTATE). PHP lee el código y muestra un mensaje claro:
--   VS001  Médico inactivo no recibe citas nuevas ni reprogramadas (RN04)
--   VS002  Fecha u hora pasada (RN06)
--   VS003  Fuera del horario del médico o cruza la medianoche (RN05)
--   VS004  Duración o hora de inicio que no respetan la configuración (RN08, RF05)
--   VS005  Cambio de estado no permitido (RF10)
--   VS006  Atendida o Inasistencia antes de que empiece la cita (RF10)
--   VS007  Reprogramación no permitida o cambio de datos que identifican la cita (RF08)
--   VS008  Borrado físico prohibido (RN11, RF09)
--   VS009  Paciente inactivo no recibe citas nuevas (RN11)
--   VS010  Especialidad inactiva no se usa en citas nuevas (RF04)
--   VS011  Cita nueva que no nace como Programada (RF06, RF10)
--   VS012  Bloques de horario del mismo médico y día que se cruzan (RF05)
--   23P01  Citas superpuestas del mismo médico (RF07, RN07). Lo lanza la
--          restricción EXCLUDE de la tabla cita, no un trigger.
--
-- Nota: las transiciones de estado están en fn_transicion_permitida. Deben ser
-- exactamente las mismas que la constante de transiciones de PHP.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 0. Limpieza previa: permite volver a ejecutar este archivo sin errores.
-- -----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_cita_validar           ON cita;
DROP TRIGGER IF EXISTS trg_cita_no_borrar         ON cita;
DROP TRIGGER IF EXISTS trg_horario_sin_solapes    ON horario_medico;
DROP TRIGGER IF EXISTS trg_paciente_no_borrar     ON paciente;
DROP TRIGGER IF EXISTS trg_medico_no_borrar       ON medico;
DROP TRIGGER IF EXISTS trg_especialidad_no_borrar ON especialidad;

-- -----------------------------------------------------------------------------
-- 1. Máquina de estados (RF10).
--    Programada  -> Confirmada, Atendida, Cancelada, Inasistencia
--    Confirmada  -> Atendida, Cancelada, Inasistencia
--    Atendida, Cancelada, Inasistencia -> ninguno (estados finales)
--    La reprogramación no es un cambio de estado; se valida en fn_cita_validar.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_transicion_permitida(p_origen TEXT, p_destino TEXT)
RETURNS BOOLEAN
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE p_origen
        WHEN 'Programada' THEN p_destino IN ('Confirmada', 'Atendida', 'Cancelada', 'Inasistencia')
        WHEN 'Confirmada' THEN p_destino IN ('Atendida', 'Cancelada', 'Inasistencia')
        ELSE FALSE
    END;
$$;

-- -----------------------------------------------------------------------------
-- 2. Prohibir el borrado físico (RN11, RF09).
--    Una sola función sirve para las cuatro tablas.
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_bloquear_borrado()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'No se permite borrar registros. Desactive el registro en su lugar.'
        USING ERRCODE = 'VS008';
END;
$$;

-- -----------------------------------------------------------------------------
-- 3. Validación de citas: alta, reprogramación y cambio de estado.
--    Las comprobaciones de disponibilidad (fecha, activos, horario y duración)
--    solo se hacen al crear o reprogramar. Así, marcar como Confirmada una cita
--    de ayer no falla por "fecha pasada".
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_cita_validar()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_duracion       INTEGER;
    v_hora_inicio    TIME;
    v_disponibilidad BOOLEAN := FALSE;  -- TRUE cuando hay que revisar disponibilidad
BEGIN
    IF TG_OP = 'INSERT' THEN
        -- RF06 y RF10: una cita nueva siempre nace como Programada.
        IF NEW.estado <> 'Programada' THEN
            RAISE EXCEPTION 'Una cita nueva debe registrarse como Programada.'
                USING ERRCODE = 'VS011';
        END IF;
        v_disponibilidad := TRUE;

    ELSE
        -- RF08: reprogramar cambia solo la fecha y la hora. Los datos que
        -- identifican la cita no pueden cambiar.
        IF NEW.id_paciente          IS DISTINCT FROM OLD.id_paciente
           OR NEW.id_medico         IS DISTINCT FROM OLD.id_medico
           OR NEW.id_especialidad   IS DISTINCT FROM OLD.id_especialidad
           OR NEW.id_usuario_registro IS DISTINCT FROM OLD.id_usuario_registro
           OR NEW.fecha_creacion    IS DISTINCT FROM OLD.fecha_creacion THEN
            RAISE EXCEPTION 'Paciente, médico, especialidad y registro de una cita no se pueden cambiar.'
                USING ERRCODE = 'VS007';
        END IF;

        IF NEW.inicio IS DISTINCT FROM OLD.inicio OR NEW.fin IS DISTINCT FROM OLD.fin THEN
            -- Reprogramación: solo desde Programada o Confirmada, y queda en Programada.
            IF OLD.estado NOT IN ('Programada', 'Confirmada') THEN
                RAISE EXCEPTION 'Solo se pueden reprogramar citas Programadas o Confirmadas.'
                    USING ERRCODE = 'VS007';
            END IF;
            IF NEW.estado <> 'Programada' THEN
                RAISE EXCEPTION 'Al reprogramar, la cita queda en estado Programada.'
                    USING ERRCODE = 'VS007';
            END IF;
            v_disponibilidad := TRUE;

        ELSIF NEW.estado IS DISTINCT FROM OLD.estado THEN
            -- RF10: solo se permiten las transiciones de la máquina de estados.
            IF NOT fn_transicion_permitida(OLD.estado, NEW.estado) THEN
                RAISE EXCEPTION 'Cambio de estado no permitido: de % a %.', OLD.estado, NEW.estado
                    USING ERRCODE = 'VS005';
            END IF;
            -- Atendida e Inasistencia solo cuando la cita ya comenzó.
            IF NEW.estado IN ('Atendida', 'Inasistencia') AND NEW.inicio > LOCALTIMESTAMP THEN
                RAISE EXCEPTION 'Atendida e Inasistencia solo se registran cuando la cita ya comenzó.'
                    USING ERRCODE = 'VS006';
            END IF;
        END IF;
    END IF;

    IF v_disponibilidad THEN
        -- RN06: no se programan citas en fechas u horas pasadas.
        IF NEW.inicio < LOCALTIMESTAMP THEN
            RAISE EXCEPTION 'No se pueden programar citas en fechas u horas pasadas.'
                USING ERRCODE = 'VS002';
        END IF;

        -- RN04, RN11 y RF04: médico, paciente y especialidad deben estar activos.
        IF NOT EXISTS (SELECT 1 FROM medico WHERE id_medico = NEW.id_medico AND activo) THEN
            RAISE EXCEPTION 'El médico está inactivo y no puede recibir citas.'
                USING ERRCODE = 'VS001';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM paciente WHERE id_paciente = NEW.id_paciente AND activo) THEN
            RAISE EXCEPTION 'El paciente está inactivo.'
                USING ERRCODE = 'VS009';
        END IF;
        IF NOT EXISTS (SELECT 1 FROM especialidad WHERE id_especialidad = NEW.id_especialidad AND activo) THEN
            RAISE EXCEPTION 'La especialidad está inactiva.'
                USING ERRCODE = 'VS010';
        END IF;

        -- RN05: la cita debe caer dentro de un bloque de horario del médico para ese día.
        -- dia_semana usa numeración ISO: 1 = lunes ... 7 = domingo.
        SELECT h.duracion_cita, h.hora_inicio
          INTO v_duracion, v_hora_inicio
          FROM horario_medico h
         WHERE h.id_medico = NEW.id_medico
           AND h.dia_semana = CAST(EXTRACT(ISODOW FROM NEW.inicio) AS INTEGER)
           AND NEW.inicio::date = NEW.fin::date      -- no puede cruzar la medianoche
           AND NEW.inicio::time >= h.hora_inicio
           AND NEW.fin::time <= h.hora_fin;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'La cita está fuera del horario de atención del médico.'
                USING ERRCODE = 'VS003';
        END IF;

        -- RN08 y RF05: la duración y la hora de inicio respetan el intervalo configurado.
        IF (NEW.fin - NEW.inicio) <> make_interval(mins => v_duracion) THEN
            RAISE EXCEPTION 'La duración de la cita no coincide con la duración configurada.'
                USING ERRCODE = 'VS004';
        END IF;
        IF (CAST(EXTRACT(EPOCH FROM (NEW.inicio::time - v_hora_inicio)) AS INTEGER) / 60) % v_duracion <> 0 THEN
            RAISE EXCEPTION 'La hora de inicio no coincide con los intervalos configurados.'
                USING ERRCODE = 'VS004';
        END IF;
    END IF;

    -- El solape entre citas no se comprueba aquí. Lo impide la restricción
    -- EXCLUDE ex_cita_medico_sin_solapes, que funciona aunque haya usuarios simultáneos.
    RETURN NEW;
END;
$$;

-- -----------------------------------------------------------------------------
-- 4. Bloques de horario sin solapes por médico y día (RF05).
--    Antes de comparar se bloquea la fila del médico, para que dos cambios
--    simultáneos se procesen uno después del otro (igual que en PHP, RN13).
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_horario_sin_solapes()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM 1 FROM medico WHERE id_medico = NEW.id_medico FOR UPDATE;

    IF EXISTS (
        SELECT 1
          FROM horario_medico h
         WHERE h.id_medico  = NEW.id_medico
           AND h.dia_semana = NEW.dia_semana
           AND h.id_horario <> NEW.id_horario
           AND NEW.hora_inicio < h.hora_fin
           AND h.hora_inicio < NEW.hora_fin
    ) THEN
        RAISE EXCEPTION 'El bloque de horario se cruza con otro bloque del mismo médico y día.'
            USING ERRCODE = 'VS012';
    END IF;

    RETURN NEW;
END;
$$;

-- -----------------------------------------------------------------------------
-- 5. Activación de los triggers.
-- -----------------------------------------------------------------------------
CREATE TRIGGER trg_cita_validar
    BEFORE INSERT OR UPDATE ON cita
    FOR EACH ROW EXECUTE FUNCTION fn_cita_validar();

CREATE TRIGGER trg_cita_no_borrar
    BEFORE DELETE ON cita
    FOR EACH ROW EXECUTE FUNCTION fn_bloquear_borrado();

CREATE TRIGGER trg_horario_sin_solapes
    BEFORE INSERT OR UPDATE ON horario_medico
    FOR EACH ROW EXECUTE FUNCTION fn_horario_sin_solapes();

CREATE TRIGGER trg_paciente_no_borrar
    BEFORE DELETE ON paciente
    FOR EACH ROW EXECUTE FUNCTION fn_bloquear_borrado();

CREATE TRIGGER trg_medico_no_borrar
    BEFORE DELETE ON medico
    FOR EACH ROW EXECUTE FUNCTION fn_bloquear_borrado();

CREATE TRIGGER trg_especialidad_no_borrar
    BEFORE DELETE ON especialidad
    FOR EACH ROW EXECUTE FUNCTION fn_bloquear_borrado();

-- Fin de sql/triggers.sql
