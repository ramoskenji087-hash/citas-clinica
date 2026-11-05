-- Horarios de prueba para probar la reserva. Ejecutar UNA vez sobre citas_clinica y sobre neondb.
-- Se suman a los horarios que ya existen (los de Juan, Ana y Lucía). No se cruzan entre sí.
INSERT INTO horario_medico (id_horario, id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita) VALUES
    (6,  1, 2, '14:00', '18:00', 30),   -- Juan:  martes 14:00-18:00, citas de 30 min
    (7,  1, 5, '08:00', '12:00', 30),   -- Juan:  viernes 08:00-12:00, citas de 30 min
    (8,  2, 1, '14:00', '18:00', 20),   -- Ana:   lunes 14:00-18:00, citas de 20 min
    (9,  2, 4, '09:00', '12:00', 20),   -- Ana:   jueves 09:00-12:00, citas de 20 min
    (10, 3, 1, '14:00', '17:00', 45),   -- Lucía: lunes 14:00-17:00, citas de 45 min
    (11, 3, 3, '08:00', '12:00', 30);   -- Lucía: miércoles 08:00-12:00, citas de 30 min
