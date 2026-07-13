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
--  Nota técnica: los SELECT de cada bloque llevan alias (c1, c2…) porque MySQL
--  nombra las columnas de una tabla derivada con el LITERAL que contienen, y dos
--  literales iguales (la adición 1.50 en ambos ojos) chocan con "Duplicate column".
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
  SELECT @tid AS c1, 'Monofocal CR-39' AS c2, 'monofocal' AS c3, 'CR-39' AS c4, NULL AS c5,
         -4.00 AS c6, 4.00 AS c7, 2.00 AS c8, 450.00 AS c9, 2 AS c10 UNION ALL
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
  SELECT @tid AS c1, 'Ray-Ban RB5154 Clubmaster · Negro' AS c2, 'ARM-001' AS c3, 'Armazón' AS c4,
         'pieza' AS c5, 2890.00 AS c6, 1 AS c7 UNION ALL
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
  SELECT @tid AS c1, @pac_optica AS c2, @medico AS c3,
         DATE_SUB(CURDATE(), INTERVAL 1 YEAR) AS c4, DATE_SUB(CURDATE(), INTERVAL 1 DAY) AS c5,
         -1.75 AS c6, -0.50 AS c7, 95 AS c8, 1.50 AS c9, '20/25' AS c10, 31.0 AS c11, 18.0 AS c12,
         -1.50 AS c13, -0.75 AS c14, 85 AS c15, 1.50 AS c16, '20/25' AS c17, 31.0 AS c18, 18.0 AS c19,
         62.0 AS c20, 'progresivo' AS c21,
         'Miopía con astigmatismo, presbicia incipiente' AS c22, 'Primera graduación progresiva.' AS c23
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
  SELECT @tid AS c1, 'Biometría hemática completa' AS c2, 'Sangre' AS c3, 'Sangre venosa' AS c4,
         'Ayuno de 8 h' AS c5, NULL AS c6, NULL AS c7, 180.00 AS c8 UNION ALL
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
  SELECT @tid AS c1, 'Constancia de buena salud' AS c2,
    CONCAT('A QUIEN CORRESPONDA:\n\n',
           'Por medio de la presente hago constar que {paciente}, de {edad}, fue valorado(a) ',
           'clínicamente en este consultorio el día de hoy, encontrándose en buen estado de salud ',
           'general, sin datos de enfermedad infectocontagiosa activa ni impedimento aparente para ',
           'realizar sus actividades habituales.\n\n',
           'Se extiende la presente a petición del interesado(a) para los fines legales que a este ',
           'convengan, en {consultorio}, a {fecha}.\n') AS c3, 1 AS c4
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
  SELECT @tid AS c1, @pac_optica AS c2, @medico AS c3, DATE_ADD(CURDATE(), INTERVAL 1 DAY) AS c4,
         '10:00:00' AS c5, 30 AS c6, 'medica' AS c7, 'Revisión de graduación' AS c8,
         'programada' AS c9, 'mostrador' AS c10, REPLACE(UUID(), '-', '') AS c11
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
--  9. ÓPTICA — dos órdenes de trabajo, para que el tablero no nazca vacío
--     Una ATRASADA (se prometió para ayer y sigue en el laboratorio: sale en
--     rojo y hasta arriba, que es el punto del tablero) y otra ya recibida,
--     esperando a que el paciente pase a recogerla.
-- =====================================================================
SET @grad := (SELECT id FROM optica_graduaciones
              WHERE consultorio_id = @tid AND paciente_id = @pac_optica
              ORDER BY fecha DESC LIMIT 1);
SET @mica := (SELECT id FROM optica_micas
              WHERE consultorio_id = @tid AND tipo_lente = 'progresivo' ORDER BY precio LIMIT 1);
SET @armazon := (SELECT id FROM productos
                 WHERE consultorio_id = @tid AND sku = 'ARM-001' LIMIT 1);

INSERT INTO optica_trabajos
  (consultorio_id, folio, paciente_id, graduacion_id, vendedor_id, fecha, fecha_promesa, estado,
   armazon_producto_id, armazon_desc, armazon_precio,
   mica_id, mica_desc, mica_precio, tratamientos, laboratorio,
   descuento, total, anticipo, notas)
SELECT * FROM (
  SELECT @tid AS c1, CONCAT('OPT-', YEAR(CURDATE()), '-9001') AS c2, @pac_optica AS c3, @grad AS c4,
         @medico AS c5, DATE_SUB(CURDATE(), INTERVAL 6 DAY) AS c6, DATE_SUB(CURDATE(), INTERVAL 1 DAY) AS c7,
         'en_laboratorio' AS c8,
         @armazon AS c9, 'Ray-Ban RB5154 Clubmaster · Negro' AS c10, 2890.00 AS c11,
         @mica AS c12, 'Progresivo estándar CR-39 AR' AS c13, 2490.00 AS c14,
         'Antirreflejante' AS c15, 'Laboratorio Óptico del Centro' AS c16,
         380.00 AS c17, 5000.00 AS c18, 2000.00 AS c19,
         'El laboratorio prometió para ayer y no ha llegado: hay que llamarles.' AS c20
  UNION ALL
  SELECT @tid, CONCAT('OPT-', YEAR(CURDATE()), '-9002'), @pac_optica, @grad,
         @medico, DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY),
         'recibido',
         NULL, 'Armazón del cliente (Vogue, aro completo)', 0.00,
         @mica, 'Progresivo estándar CR-39 AR', 2490.00,
         'Antirreflejante', 'Laboratorio Óptico del Centro',
         0.00, 2490.00, 1000.00,
         'Ya llegaron: avisar al paciente que pase a recogerlos.'
) x
WHERE @pac_optica IS NOT NULL AND @grad IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM optica_trabajos t WHERE t.consultorio_id = @tid);


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
