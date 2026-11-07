-- Duración máxima de cita: 15 minutos (5, 10 o 15).
-- Ejecutar UNA vez sobre citas_clinica (en su computadora) y sobre neondb (en la nube).
-- Las citas ya reservadas conservan la duración con la que se crearon.

UPDATE horario_medico SET duracion_cita = 15 WHERE duracion_cita > 15;

ALTER TABLE horario_medico DROP CONSTRAINT IF EXISTS horario_medico_duracion_cita_check;
ALTER TABLE horario_medico ADD CONSTRAINT horario_medico_duracion_cita_check CHECK (duracion_cita IN (5, 10, 15));
