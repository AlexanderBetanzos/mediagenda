-- =====================================================================
--  MediAgenda  -  DATOS DE PRUEBA para lo nuevo
--  Horarios · Óptica (micas + graduación) · Laboratorio · Documentos ·
--  Agenda en línea.
--
--  CÓMO USARLO
--    1. Cambia el slug de la línea de abajo por el de TU consultorio.
--       Si no lo sabes:  SELECT id, nombre, slug FROM consultorios;
--    2. Corre el archivo completo. Es idempotente: se puede correr dos veces
--       sin duplicar nada.
--
--  NO borra nada de lo que ya tienes. Solo agrega catálogos y un paciente de
--  prueba llamado "Prueba Óptica" (fácil de encontrar y borrar después).
-- =====================================================================

SET @slug := 'principal';          -- <<<<<<  CAMBIA ESTO

SET @tid := (SELECT id FROM consultorios WHERE slug = @slug);
-- Si esto revienta con "Column 'consultorio_id' cannot be null", el slug está mal.


-- =====================================================================
--  1. HORARIOS DE ATENCIÓN  (sin esto, la agenda en línea no ofrece nada)
--     Lunes a viernes 9:00–14:00 y 16:00–19:00 · Sábado 9:00–13:00.
--     El hueco de 14:00 a 16:00 es la comida: al no estar en el horario,
--     simplemente no se ofrece.
--     Se aplica a TODOS los médicos/admin activos del consultorio.
-- =====================================================================
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
WHERE u.consultorio_id = @tid AND u.activo = 1 AND u.rol IN ('medico','admin')
  AND NOT EXISTS (
      SELECT 1 FROM medico_horarios h
      WHERE h.consultorio_id = @tid AND h.medico_id = u.id
        AND h.dia_semana = d.dia AND h.hora_inicio = d.ini
  );


-- =====================================================================
--  2. AGENDA EN LÍNEA: encendida, 30 días de anticipación, citas de 30 min
-- =====================================================================
INSERT INTO configuracion (consultorio_id, clave, valor) VALUES
 (@tid, 'agenda_online',          '1'),
 (@tid, 'agenda_online_dias',     '30'),
 (@tid, 'agenda_online_duracion', '30'),
 (@tid, 'agenda_online_aviso',    'Llega 10 minutos antes de tu cita.')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);


-- =====================================================================
--  3. ÓPTICA — catálogo de micas (con precios reales, no en 0)
--     El rango es lo que hace útil el catálogo: al armar un trabajo solo se
--     ofrecen las micas que cubren la graduación del paciente.
-- =====================================================================
INSERT INTO optica_micas
  (consultorio_id, nombre, tipo_lente, material, tratamientos, esfera_min, esfera_max, cilindro_max, precio, dias_entrega)
SELECT * FROM (
  SELECT @tid, 'Monofocal CR-39',                     'monofocal',   'CR-39',            NULL,                            -4.00,  4.00, 2.00,  450.00, 2 UNION ALL
  SELECT @tid, 'Monofocal CR-39 antirreflejante',     'monofocal',   'CR-39',            'Antirreflejante',               -4.00,  4.00, 2.00,  790.00, 3 UNION ALL
  SELECT @tid, 'Monofocal policarbonato AR',          'monofocal',   'Policarbonato',    'Antirreflejante',               -8.00,  6.00, 4.00, 1190.00, 3 UNION ALL
  SELECT @tid, 'Monofocal alto índice 1.67 AR',       'monofocal',   'Alto índice 1.67', 'Antirreflejante',              -12.00,  8.00, 6.00, 2290.00, 5 UNION ALL
  SELECT @tid, 'Monofocal fotocromático AR',          'monofocal',   'Policarbonato',    'Fotocromático, antirreflejante', -8.00,  6.00, 4.00, 1890.00, 5 UNION ALL
  SELECT @tid, 'Monofocal filtro azul AR',            'monofocal',   'CR-39',            'Filtro azul, antirreflejante',   -6.00,  6.00, 4.00, 1290.00, 4 UNION ALL
  SELECT @tid, 'Bifocal flat-top CR-39',              'bifocal',     'CR-39',            NULL,                            -6.00,  6.00, 4.00, 1090.00, 4 UNION ALL
  SELECT @tid, 'Progresivo estándar CR-39 AR',        'progresivo',  'CR-39',            'Antirreflejante',               -6.00,  6.00, 4.00, 2490.00, 5 UNION ALL
  SELECT @tid, 'Progresivo digital policarbonato AR', 'progresivo',  'Policarbonato',    'Antirreflejante',               -8.00,  6.00, 4.00, 3990.00, 7 UNION ALL
  SELECT @tid, 'Progresivo premium alto índice AR',   'progresivo',  'Alto índice 1.67', 'Antirreflejante, filtro azul', -12.00,  8.00, 6.00, 6490.00, 10 UNION ALL
  SELECT @tid, 'Ocupacional (oficina) AR',            'ocupacional', 'CR-39',            'Antirreflejante',               -6.00,  6.00, 4.00, 1990.00, 6
) x
WHERE NOT EXISTS (SELECT 1 FROM optica_micas m WHERE m.consultorio_id = @tid);


-- =====================================================================
--  4. ÓPTICA — armazones (son productos del inventario, categoría "Armazón")
-- =====================================================================
INSERT INTO productos (consultorio_id, nombre, sku, categoria, unidad, precio, stock_minimo)
SELECT * FROM (
  SELECT @tid, 'Ray-Ban RB5154 Clubmaster · Negro', 'ARM-001', 'Armazón', 'pieza', 2890.00, 1 UNION ALL
  SELECT @tid, 'Oakley OX8046 Airdrop · Gris',      'ARM-002', 'Armazón', 'pieza', 2490.00, 1 UNION ALL
  SELECT @tid, 'Vogue VO5028 · Carey',              'ARM-003', 'Armazón', 'pieza', 1590.00, 1 UNION ALL
  SELECT @tid, 'Armazón económico metal · Dorado',  'ARM-004', 'Armazón', 'pieza',  590.00, 2
) x
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.consultorio_id = @tid AND p.sku = 'ARM-001');

-- Existencias (5 piezas de cada armazón), para que el POS y la orden de trabajo
-- tengan de dónde descontar.
INSERT INTO inventario_movimientos (consultorio_id, producto_id, tipo, cantidad, motivo)
SELECT @tid, p.id, 'entrada', 5, 'Carga inicial de prueba'
FROM productos p
WHERE p.consultorio_id = @tid AND p.categoria = 'Armazón'
  AND NOT EXISTS (
      SELECT 1 FROM inventario_movimientos m
      WHERE m.producto_id = p.id AND m.motivo = 'Carga inicial de prueba'
  );


-- =====================================================================
--  5. ÓPTICA — un paciente con graduación real, listo para armarle un trabajo
--     Miopía con astigmatismo y presbicia incipiente: el caso típico que
--     obliga a un progresivo.
-- =====================================================================
INSERT INTO pacientes (consultorio_id, nombre, apellidos, fecha_nacimiento, sexo, telefono, email, tipo, alergias, antecedentes)
SELECT @tid, 'Prueba', 'Óptica', '1978-04-12', 'F', '5551234567', 'prueba.optica@example.com',
       'optica', 'Penicilina', 'Hipertensión controlada'
WHERE NOT EXISTS (
    SELECT 1 FROM pacientes p WHERE p.consultorio_id = @tid AND p.nombre = 'Prueba' AND p.apellidos = 'Óptica'
);

SET @pac_optica := (SELECT id FROM pacientes
                    WHERE consultorio_id = @tid AND nombre = 'Prueba' AND apellidos = 'Óptica' LIMIT 1);
SET @medico := (SELECT id FROM usuarios
                WHERE consultorio_id = @tid AND activo = 1 AND rol IN ('medico','admin') ORDER BY id LIMIT 1);

-- Graduación del año pasado (para ver el historial) y la de hoy (empeoró).
INSERT INTO optica_graduaciones
  (consultorio_id, paciente_id, optometrista_id, fecha, vigencia,
   od_esfera, od_cilindro, od_eje, od_adicion, od_av, od_dip, od_altura,
   oi_esfera, oi_cilindro, oi_eje, oi_adicion, oi_av, oi_dip, oi_altura,
   dip, tipo_lente, diagnostico, notas)
SELECT * FROM (
  SELECT @tid, @pac_optica, @medico, DATE_SUB(CURDATE(), INTERVAL 1 YEAR), DATE_SUB(CURDATE(), INTERVAL 1 DAY),
         -1.75, -0.50,  95, 1.50, '20/25', 31.0, 18.0,
         -1.50, -0.75,  85, 1.50, '20/25', 31.0, 18.0,
         62.0, 'progresivo', 'Miopía con astigmatismo, presbicia incipiente', 'Primera graduación progresiva.'
  UNION ALL
  SELECT @tid, @pac_optica, @medico, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
         -2.25, -0.75,  95, 2.00, '20/20', 31.0, 18.0,
         -2.00, -0.75,  85, 2.00, '20/20', 31.0, 18.0,
         62.0, 'progresivo', 'Miopía con astigmatismo, presbicia', 'Avanzó media dioptría en un año.'
) x
WHERE @pac_optica IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM optica_graduaciones g WHERE g.paciente_id = @pac_optica);


-- =====================================================================
--  6. LABORATORIO — catálogo de estudios con precios
-- =====================================================================
INSERT INTO lab_estudios (consultorio_id, nombre, categoria, muestra, preparacion, unidad, referencia, precio)
SELECT * FROM (
  SELECT @tid, 'Biometría hemática completa',      'Sangre', 'Sangre venosa', 'Ayuno de 8 h',   NULL,    NULL,        180.00 UNION ALL
  SELECT @tid, 'Química sanguínea (6 elementos)',  'Sangre', 'Sangre venosa', 'Ayuno de 8 h',   NULL,    NULL,        250.00 UNION ALL
  SELECT @tid, 'Glucosa en ayuno',                 'Sangre', 'Sangre venosa', 'Ayuno de 8 h',   'mg/dL', '70 - 100',   90.00 UNION ALL
  SELECT @tid, 'Hemoglobina glucosilada (HbA1c)',  'Sangre', 'Sangre venosa', NULL,             '%',     '< 5.7',     320.00 UNION ALL
  SELECT @tid, 'Perfil de lípidos',                'Sangre', 'Sangre venosa', 'Ayuno de 12 h',  NULL,    NULL,        290.00 UNION ALL
  SELECT @tid, 'Colesterol total',                 'Sangre', 'Sangre venosa', 'Ayuno de 12 h',  'mg/dL', '< 200',     110.00 UNION ALL
  SELECT @tid, 'Triglicéridos',                    'Sangre', 'Sangre venosa', 'Ayuno de 12 h',  'mg/dL', '< 150',     110.00 UNION ALL
  SELECT @tid, 'Ácido úrico',                      'Sangre', 'Sangre venosa', 'Ayuno de 8 h',   'mg/dL', '3.4 - 7.0',  95.00 UNION ALL
  SELECT @tid, 'Creatinina',                       'Sangre', 'Sangre venosa', NULL,             'mg/dL', '0.6 - 1.2',  95.00 UNION ALL
  SELECT @tid, 'Perfil tiroideo (TSH, T3, T4)',    'Sangre', 'Sangre venosa', NULL,             NULL,    NULL,        480.00 UNION ALL
  SELECT @tid, 'Examen general de orina',          'Orina',  'Orina',         'Primera micción del día', NULL, NULL,  120.00 UNION ALL
  SELECT @tid, 'Radiografía de tórax',             'Imagen', '—',             NULL,             NULL,    NULL,        350.00 UNION ALL
  SELECT @tid, 'Ultrasonido abdominal',            'Imagen', '—',             'Ayuno de 6 h',   NULL,    NULL,        650.00 UNION ALL
  SELECT @tid, 'Electrocardiograma',               'Imagen', '—',             NULL,             NULL,    NULL,        300.00
) x
WHERE NOT EXISTS (SELECT 1 FROM lab_estudios e WHERE e.consultorio_id = @tid);


-- =====================================================================
--  7. DOCUMENTOS — las cuatro plantillas más usadas
-- =====================================================================
INSERT INTO documento_plantillas (consultorio_id, nombre, cuerpo, orden)
SELECT * FROM (
  SELECT @tid, 'Constancia de buena salud',
    CONCAT('A QUIEN CORRESPONDA:\n\n',
           'Por medio de la presente hago constar que {paciente}, de {edad}, fue valorado(a) ',
           'clínicamente en este consultorio el día de hoy, encontrándose en buen estado de salud ',
           'general, sin datos de enfermedad infectocontagiosa activa ni impedimento aparente para ',
           'realizar sus actividades habituales.\n\n',
           'Se extiende la presente a petición del interesado(a) para los fines legales que a este ',
           'convengan, en {consultorio}, a {fecha}.\n'), 1
  UNION ALL
  SELECT @tid, 'Justificante / incapacidad',
    CONCAT('A QUIEN CORRESPONDA:\n\n',
           'Hago constar que {paciente}, de {edad}, acudió a consulta médica el día de hoy con ',
           'diagnóstico de {diagnostico}, por lo cual se indica reposo domiciliario por {dias} días ',
           'a partir de esta fecha.\n\n',
           'Se extiende la presente para los fines que al interesado(a) convengan, en {consultorio}, ',
           'a {fecha}.\n'), 2
  UNION ALL
  SELECT @tid, 'Referencia a especialista',
    CONCAT('ESTIMADO(A) COLEGA:\n\n',
           'Le envío a {paciente}, de {edad}, con diagnóstico de {diagnostico}, para su valoración ',
           'y manejo especializado.\n\nResumen del caso:\n\n\n',
           'Agradezco de antemano su atención y quedo a sus órdenes.\n\n',
           'Atentamente,\n{medico} · {especialidad}\n{consultorio}, a {fecha}.\n'), 3
  UNION ALL
  SELECT @tid, 'Resumen clínico',
    CONCAT('RESUMEN CLÍNICO\n\n',
           'Paciente: {paciente}\nEdad: {edad}\nFecha: {fecha}\n\n',
           'Antecedentes de importancia:\n\n\nPadecimiento actual:\n\n\nExploración física:\n\n\n',
           'Diagnóstico: {diagnostico}\n\nPlan de tratamiento:\n\n\n',
           'Atentamente,\n{medico} · {especialidad}\n'), 4
) x
WHERE NOT EXISTS (SELECT 1 FROM documento_plantillas d WHERE d.consultorio_id = @tid);


-- =====================================================================
--  8. CITAS de prueba para la CONFIRMACIÓN por enlace
--     Una mañana y otra pasado mañana, con su token ya generado.
--     El enlace del paciente es:
--        https://TU-DOMINIO/agenda/confirmar?t=<token>
--     Sácalos con la consulta del final.
-- =====================================================================
INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, tipo, motivo, estado, origen, token)
SELECT * FROM (
  SELECT @tid, @pac_optica, @medico, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', 30, 'medica',
         'Revisión de graduación', 'programada', 'mostrador', REPLACE(UUID(), '-', '')
  UNION ALL
  SELECT @tid, @pac_optica, @medico, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '11:30:00', 30, 'medica',
         'Entrega de lentes', 'programada', 'mostrador', REPLACE(UUID(), '-', '')
) x
WHERE @pac_optica IS NOT NULL AND @medico IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM citas c
      WHERE c.consultorio_id = @tid AND c.paciente_id = @pac_optica AND c.fecha >= CURDATE()
  );


-- =====================================================================
--  LISTO. Copia los enlaces de confirmación de aquí:
-- =====================================================================
SELECT c.fecha, c.hora,
       CONCAT(p.nombre, ' ', p.apellidos) AS paciente,
       CONCAT('/agenda/confirmar?t=', c.token) AS enlace_confirmacion
FROM citas c
JOIN pacientes p ON p.id = c.paciente_id
WHERE c.consultorio_id = @tid AND c.fecha >= CURDATE() AND c.token IS NOT NULL
ORDER BY c.fecha, c.hora;

-- Y el enlace público de tu agenda en línea:
SELECT CONCAT('/agenda/reservar?c=', slug) AS agenda_en_linea FROM consultorios WHERE id = @tid;
