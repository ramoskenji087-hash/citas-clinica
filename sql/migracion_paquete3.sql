-- Paquete 3: recordatorios automáticos. Ejecutar UNA vez sobre citas_clinica y sobre neondb.

ALTER TABLE cita
    ADD COLUMN IF NOT EXISTS recordatorio_enviado BOOLEAN NOT NULL DEFAULT FALSE;
