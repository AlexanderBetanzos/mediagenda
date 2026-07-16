-- =====================================================================
--  MediOS  -  Cargar horario a los MÉDICOS actuales del consultorio
--
--  Le pone a cada médico (rol 'medico', activo) el mismo horario base:
--    Lun a Vie 9:00–14:00 y 16:00–19:00 · Sábado 9:00–13:00.
--  (La comida 14–16 no se ofrece porque no está en el horario.)
--
--  Solo toca médicos que NO tengan horario aún, así que se puede correr sin
--  duplicar. Luego cada médico puede ajustar el suyo en /citas/horarios.
--
--  CÓMO USARLO: revisa el slug y córrelo completo.
-- =====================================================================

SET @slug := 'mi-clinica';          -- <<<<<< tu consultorio
SET @tid  := (SELECT id FROM consultorios WHERE slug = @slug);

INSERT INTO medico_horarios (consultorio_id, medico_id, dia_semana, hora_inicio, hora_fin)
SELECT @tid, u.id, d.dia, d.ini, d.fin
FROM usuarios u
CROSS JOIN (
    SELECT 1 AS dia, '09:00:00' AS ini, '14:00:00' AS fin UNION ALL
    SELECT 1, '16:00:00', '19:00:00' UNION ALL
    SELECT 2, '09:00:00', '14:00:00' UNION ALL
    SELECT 2, '16:00:00', '19:00:00' UNION ALL
    SELECT 3, '09:00:00', '14:00:00' UNION ALL
    SELECT 3, '16:00:00', '19:00:00' UNION ALL
    SELECT 4, '09:00:00', '14:00:00' UNION ALL
    SELECT 4, '16:00:00', '19:00:00' UNION ALL
    SELECT 5, '09:00:00', '14:00:00' UNION ALL
    SELECT 5, '16:00:00', '19:00:00' UNION ALL
    SELECT 6, '09:00:00', '13:00:00'
) d
WHERE u.consultorio_id = @tid
  AND u.activo = 1
  AND u.rol = 'medico'
  AND NOT EXISTS (
      SELECT 1 FROM medico_horarios h
      WHERE h.consultorio_id = @tid AND h.medico_id = u.id
  );

-- Comprobación: cuántos médicos quedaron con horario.
SELECT u.id, u.nombre,
       COUNT(h.id) AS franjas
FROM usuarios u
LEFT JOIN medico_horarios h ON h.medico_id = u.id AND h.consultorio_id = @tid
WHERE u.consultorio_id = @tid AND u.activo = 1 AND u.rol = 'medico'
GROUP BY u.id, u.nombre
ORDER BY u.nombre;
