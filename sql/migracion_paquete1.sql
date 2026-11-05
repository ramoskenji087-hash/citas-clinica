-- Paquete 1: ejecutar UNA vez sobre la base citas_clinica (en su computadora)
-- y otra vez sobre la base de Neon (en la nube). No hace falta en la base de pruebas.
-- Agrega: permiso para ver el motivo de las citas, ficha médica, contacto de emergencia
-- y preferencias del paciente.

ALTER TABLE usuario
    ADD COLUMN IF NOT EXISTS puede_ver_motivo BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE paciente
    ADD COLUMN IF NOT EXISTS tipo_sangre VARCHAR(3) CHECK (tipo_sangre IN ('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-')),
    ADD COLUMN IF NOT EXISTS alergias TEXT,
    ADD COLUMN IF NOT EXISTS enfermedades_cronicas TEXT,
    ADD COLUMN IF NOT EXISTS medicamentos TEXT,
    ADD COLUMN IF NOT EXISTS antecedentes TEXT,
    ADD COLUMN IF NOT EXISTS contacto_emergencia_nombre VARCHAR(120),
    ADD COLUMN IF NOT EXISTS contacto_emergencia_parentesco VARCHAR(60),
    ADD COLUMN IF NOT EXISTS contacto_emergencia_telefono VARCHAR(20),
    ADD COLUMN IF NOT EXISTS recibir_notificaciones BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS compartir_historial_medico BOOLEAN NOT NULL DEFAULT FALSE;
