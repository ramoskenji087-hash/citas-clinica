-- Paquete 2: modalidad de la atención (presencial o virtual).
-- Ejecutar UNA vez sobre citas_clinica (en su computadora) y sobre neondb (en la nube).

ALTER TABLE cita
    ADD COLUMN IF NOT EXISTS modalidad VARCHAR(12) NOT NULL DEFAULT 'Presencial'
        CHECK (modalidad IN ('Presencial', 'Virtual'));
