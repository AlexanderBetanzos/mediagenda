-- =====================================================================
--  MediOS  -  DATOS DE PRUEBA
--  Horarios · Agenda en línea · Óptica · Laboratorio · Documentos
--
--  CÓMO USARLO
--    1. Revisa el slug de la línea de abajo (debe ser el de TU consultorio).
--    2. Corre el archivo completo.
--
--  Se puede correr las veces que quieras: primero BORRA sus propios datos de
--  prueba (los pacientes llamados "Prueba" y los catálogos que él mismo creó) y
--  luego los vuelve a insertar. No toca nada que no haya creado él.
--
--  Escrito sin trucos de SQL a propósito (nada de NOT EXISTS ni de tablas
--  derivadas): dos versiones anteriores murieron por incompatibilidades de MySQL
--  y el import se detenía a la mitad, dejando la base a medio llenar.
-- =====================================================================

SET @slug := 'mi-clinica';          -- <<<<<<  CAMBIA ESTO SI USAS OTRO CONSULTORIO

SET @tid    := (SELECT id FROM consultorios WHERE slug = @slug);
SET @medico := (SELECT id FROM usuarios
                WHERE consultorio_id = @tid AND activo = 1
                  AND rol IN ('medico','admin') ORDER BY id LIMIT 1);


-- =====================================================================
--  0. El paciente puede ser de óptica (por si sql/optica.sql no llegó a correr)
-- =====================================================================
SET @col := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacientes' AND COLUMN_NAME = 'tipo');
SET @sql := IF(@col NOT LIKE '%optica%',
  "ALTER TABLE pacientes MODIFY COLUMN tipo ENUM('medico','dental','optica') NOT NULL DEFAULT 'medico'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =====================================================================
--  1. LIMPIEZA de corridas anteriores de ESTE script
--     Al borrar los pacientes de prueba caen con ellos, en cascada, sus
--     graduaciones, órdenes de trabajo, órdenes de laboratorio, consultas y citas.
-- =====================================================================
DELETE FROM pacientes
WHERE consultorio_id = @tid AND nombre = 'Prueba' AND apellidos IN ('Óptica', 'Laboratorio');

DELETE FROM optica_micas         WHERE consultorio_id = @tid;
DELETE FROM lab_estudios         WHERE consultorio_id = @tid;
DELETE FROM documento_plantillas WHERE consultorio_id = @tid;
DELETE FROM productos            WHERE consultorio_id = @tid AND categoria = 'Armazón';


-- =====================================================================
--  2. HORARIOS  (sin esto la agenda en línea no ofrece ningún hueco)
--     L–V 9:00–14:00 y 16:00–19:00 · Sábado 9:00–13:00.
--     La comida (14–16) no necesita bloqueo: al no estar en el horario,
--     simplemente no se ofrece.
-- =====================================================================
DELETE FROM medico_horarios WHERE consultorio_id = @tid;

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
WHERE u.consultorio_id = @tid AND u.activo = 1 AND u.rol IN ('medico','admin');


-- =====================================================================
--  3. AGENDA EN LÍNEA encendida
-- =====================================================================
INSERT INTO configuracion (consultorio_id, clave, valor) VALUES
 (@tid, 'agenda_online',          '1'),
 (@tid, 'agenda_online_dias',     '30'),
 (@tid, 'agenda_online_duracion', '30'),
 (@tid, 'agenda_online_aviso',    'Llega 10 minutos antes de tu cita.')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);


-- =====================================================================
--  4. ÓPTICA — catálogo de micas
--     El RANGO es lo que hace útil el catálogo: al armar un trabajo solo se
--     ofrecen las micas que pueden fabricarse con la graduación del paciente.
-- =====================================================================
INSERT INTO optica_micas
 (consultorio_id, nombre, tipo_lente, material, tratamientos, esfera_min, esfera_max, cilindro_max, precio, dias_entrega)
VALUES
 (@tid, 'Monofocal CR-39',                     'monofocal',   'CR-39',            NULL,                             -4.00, 4.00, 2.00,  450.00,  2),
 (@tid, 'Monofocal CR-39 antirreflejante',     'monofocal',   'CR-39',            'Antirreflejante',                -4.00, 4.00, 2.00,  790.00,  3),
 (@tid, 'Monofocal policarbonato AR',          'monofocal',   'Policarbonato',    'Antirreflejante',                -8.00, 6.00, 4.00, 1190.00,  3),
 (@tid, 'Monofocal alto índice 1.67 AR',       'monofocal',   'Alto índice 1.67', 'Antirreflejante',               -12.00, 8.00, 6.00, 2290.00,  5),
 (@tid, 'Monofocal fotocromático AR',          'monofocal',   'Policarbonato',    'Fotocromático, antirreflejante',  -8.00, 6.00, 4.00, 1890.00,  5),
 (@tid, 'Monofocal filtro azul AR',            'monofocal',   'CR-39',            'Filtro azul, antirreflejante',    -6.00, 6.00, 4.00, 1290.00,  4),
 (@tid, 'Bifocal flat-top CR-39',              'bifocal',     'CR-39',            NULL,                             -6.00, 6.00, 4.00, 1090.00,  4),
 (@tid, 'Progresivo estándar CR-39 AR',        'progresivo',  'CR-39',            'Antirreflejante',                -6.00, 6.00, 4.00, 2490.00,  5),
 (@tid, 'Progresivo digital policarbonato AR', 'progresivo',  'Policarbonato',    'Antirreflejante',                -8.00, 6.00, 4.00, 3990.00,  7),
 (@tid, 'Progresivo premium alto índice AR',   'progresivo',  'Alto índice 1.67', 'Antirreflejante, filtro azul',  -12.00, 8.00, 6.00, 6490.00, 10),
 (@tid, 'Ocupacional (oficina) AR',            'ocupacional', 'CR-39',            'Antirreflejante',                -6.00, 6.00, 4.00, 1990.00,  6);


-- =====================================================================
--  5. ÓPTICA — armazones (son productos del inventario)
-- =====================================================================
INSERT INTO productos (consultorio_id, nombre, sku, categoria, unidad, precio, stock_minimo) VALUES
 (@tid, 'Ray-Ban RB5154 Clubmaster · Negro', 'ARM-001', 'Armazón', 'pieza', 2890.00, 1),
 (@tid, 'Oakley OX8046 Airdrop · Gris',      'ARM-002', 'Armazón', 'pieza', 2490.00, 1),
 (@tid, 'Vogue VO5028 · Carey',              'ARM-003', 'Armazón', 'pieza', 1590.00, 1),
 (@tid, 'Armazón económico metal · Dorado',  'ARM-004', 'Armazón', 'pieza',  590.00, 2);

-- Existencias: 5 piezas de cada uno, para que la orden de trabajo tenga de dónde
-- descontar cuando el armazón se manda a tallar.
INSERT INTO inventario_movimientos (consultorio_id, producto_id, tipo, cantidad, motivo)
SELECT @tid, p.id, 'entrada', 5, 'Carga inicial de prueba'
FROM productos p
WHERE p.consultorio_id = @tid AND p.categoria = 'Armazón';

SET @armazon := (SELECT id FROM productos WHERE consultorio_id = @tid AND sku = 'ARM-001' LIMIT 1);


-- =====================================================================
--  6. ÓPTICA — paciente con DOS graduaciones (la de hace un año y la de hoy)
--     Miopía con astigmatismo y presbicia: el caso que obliga a un progresivo.
--     Con dos se ve el historial: avanzó media dioptría en un año.
-- =====================================================================
INSERT INTO pacientes
 (consultorio_id, nombre, apellidos, fecha_nacimiento, sexo, telefono, email, tipo, alergias, antecedentes)
VALUES
 (@tid, 'Prueba', 'Óptica', '1978-04-12', 'F', '5551234567', 'prueba.optica@example.com',
  'optica', 'Penicilina', 'Hipertensión controlada');

SET @pac_optica := LAST_INSERT_ID();

INSERT INTO optica_graduaciones
 (consultorio_id, paciente_id, optometrista_id, fecha, vigencia,
  od_esfera, od_cilindro, od_eje, od_adicion, od_av, od_dip, od_altura,
  oi_esfera, oi_cilindro, oi_eje, oi_adicion, oi_av, oi_dip, oi_altura,
  dip, tipo_lente, diagnostico, notas)
VALUES
 (@tid, @pac_optica, @medico, DATE_SUB(CURDATE(), INTERVAL 1 YEAR), DATE_SUB(CURDATE(), INTERVAL 1 DAY),
  -1.75, -0.50, 95, 1.50, '20/25', 31.0, 18.0,
  -1.50, -0.75, 85, 1.50, '20/25', 31.0, 18.0,
  62.0, 'progresivo', 'Miopía con astigmatismo, presbicia incipiente', 'Primera graduación progresiva.'),
 (@tid, @pac_optica, @medico, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
  -2.25, -0.75, 95, 2.00, '20/20', 31.0, 18.0,
  -2.00, -0.75, 85, 2.00, '20/20', 31.0, 18.0,
  62.0, 'progresivo', 'Miopía con astigmatismo, presbicia', 'Avanzó media dioptría en un año.');

SET @grad := (SELECT id FROM optica_graduaciones
              WHERE consultorio_id = @tid AND paciente_id = @pac_optica
              ORDER BY fecha DESC, id DESC LIMIT 1);
SET @mica := (SELECT id FROM optica_micas
              WHERE consultorio_id = @tid AND tipo_lente = 'progresivo'
              ORDER BY precio LIMIT 1);


-- =====================================================================
--  7. ÓPTICA — dos órdenes de trabajo
--     Una ATRASADA (prometida para ayer y aún en el laboratorio): sale en rojo y
--     hasta arriba, que es justo para lo que existe ese tablero.
--     Otra RECIBIDA, esperando a que el paciente pase a recogerla.
-- =====================================================================
INSERT INTO optica_trabajos
 (consultorio_id, folio, paciente_id, graduacion_id, vendedor_id, fecha, fecha_promesa, estado,
  armazon_producto_id, armazon_desc, armazon_precio,
  mica_id, mica_desc, mica_precio, tratamientos, laboratorio,
  descuento, total, anticipo, notas)
VALUES
 (@tid, CONCAT('OPT-', YEAR(CURDATE()), '-9001'), @pac_optica, @grad, @medico,
  DATE_SUB(CURDATE(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'en_laboratorio',
  @armazon, 'Ray-Ban RB5154 Clubmaster · Negro', 2890.00,
  @mica, 'Progresivo estándar CR-39 AR', 2490.00, 'Antirreflejante', 'Laboratorio Óptico del Centro',
  380.00, 5000.00, 2000.00, 'El laboratorio prometió para ayer y no ha llegado: hay que llamarles.'),

 (@tid, CONCAT('OPT-', YEAR(CURDATE()), '-9002'), @pac_optica, @grad, @medico,
  DATE_SUB(CURDATE(), INTERVAL 9 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'recibido',
  NULL, 'Armazón del cliente (Vogue, aro completo)', 0.00,
  @mica, 'Progresivo estándar CR-39 AR', 2490.00, 'Antirreflejante', 'Laboratorio Óptico del Centro',
  0.00, 2490.00, 1000.00, 'Ya llegaron: avisar al paciente que pase a recogerlos.');


-- =====================================================================
--  8. LABORATORIO — catálogo de estudios
-- =====================================================================
INSERT INTO lab_estudios
 (consultorio_id, nombre, categoria, muestra, preparacion, unidad, referencia, precio)
VALUES
 (@tid, 'Biometría hemática completa',     'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  NULL,    NULL,        180.00),
 (@tid, 'Química sanguínea (6 elementos)', 'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  NULL,    NULL,        250.00),
 (@tid, 'Glucosa en ayuno',                'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  'mg/dL', '70 - 100',   90.00),
 (@tid, 'Hemoglobina glucosilada (HbA1c)', 'Sangre', 'Sangre venosa', NULL,            '%',     '< 5.7',     320.00),
 (@tid, 'Perfil de lípidos',               'Sangre', 'Sangre venosa', 'Ayuno de 12 h', NULL,    NULL,        290.00),
 (@tid, 'Colesterol total',                'Sangre', 'Sangre venosa', 'Ayuno de 12 h', 'mg/dL', '< 200',     110.00),
 (@tid, 'Triglicéridos',                   'Sangre', 'Sangre venosa', 'Ayuno de 12 h', 'mg/dL', '< 150',     110.00),
 (@tid, 'Ácido úrico',                     'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  'mg/dL', '3.4 - 7.0',  95.00),
 (@tid, 'Creatinina',                      'Sangre', 'Sangre venosa', NULL,            'mg/dL', '0.6 - 1.2',  95.00),
 (@tid, 'Perfil tiroideo (TSH, T3, T4)',   'Sangre', 'Sangre venosa', NULL,            NULL,    NULL,        480.00),
 (@tid, 'Examen general de orina',         'Orina',  'Orina',         'Primera micción del día', NULL, NULL, 120.00),
 (@tid, 'Radiografía de tórax',            'Imagen', '—',             NULL,            NULL,    NULL,        350.00),
 (@tid, 'Ultrasonido abdominal',           'Imagen', '—',             'Ayuno de 6 h',  NULL,    NULL,        650.00),
 (@tid, 'Electrocardiograma',              'Imagen', '—',             NULL,            NULL,    NULL,        300.00);


-- =====================================================================
--  9. LABORATORIO — paciente clínico y tres órdenes
--     Diabético y alérgico a sulfas: sirve además para ver la banda roja de
--     alergias y el resumen clínico en su ficha.
-- =====================================================================
INSERT INTO pacientes
 (consultorio_id, nombre, apellidos, fecha_nacimiento, sexo, telefono, email, tipo, alergias, antecedentes)
VALUES
 (@tid, 'Prueba', 'Laboratorio', '1969-11-03', 'M', '5559876543', 'prueba.lab@example.com',
  'medico', 'Sulfas', 'Diabetes tipo 2, hipertensión');

SET @pac_lab := LAST_INSERT_ID();

-- Consulta previa: así el generador de documentos ya tiene un {diagnostico} que poner.
INSERT INTO consultas
 (consultorio_id, paciente_id, medico_id, fecha, motivo, exploracion, diagnostico, tratamiento, peso, estatura, presion)
VALUES
 (@tid, @pac_lab, @medico, DATE_SUB(NOW(), INTERVAL 3 DAY),
  'Control de diabetes', 'Paciente estable, sin datos de descompensación aguda.',
  'Diabetes mellitus tipo 2 en control',
  'Continuar metformina 850 mg cada 12 h. Solicito laboratorios.',
  88.5, 1.72, '135/85');

-- Orden 1: URGENTE, recién solicitada.
INSERT INTO lab_ordenes
 (consultorio_id, folio, paciente_id, medico_id, fecha, estado, prioridad, proveedor, diagnostico, notas, total)
VALUES
 (@tid, CONCAT('LAB-', YEAR(CURDATE()), '-9001'), @pac_lab, @medico, CURDATE(),
  'solicitada', 'urgente', 'Laboratorio Clínico Central', 'Diabetes mellitus tipo 2',
  'Paciente en ayuno desde anoche.', 0);
SET @lab1 := LAST_INSERT_ID();

INSERT INTO lab_orden_items (orden_id, estudio_id, nombre, precio, unidad, referencia)
SELECT @lab1, e.id, e.nombre, e.precio, e.unidad, e.referencia
FROM lab_estudios e
WHERE e.consultorio_id = @tid
  AND e.nombre IN ('Glucosa en ayuno', 'Hemoglobina glucosilada (HbA1c)', 'Perfil de lípidos');

UPDATE lab_ordenes SET total = (SELECT COALESCE(SUM(precio), 0) FROM lab_orden_items WHERE orden_id = @lab1)
WHERE id = @lab1;

-- Orden 2: en proceso.
INSERT INTO lab_ordenes
 (consultorio_id, folio, paciente_id, medico_id, fecha, estado, prioridad, proveedor, diagnostico, total)
VALUES
 (@tid, CONCAT('LAB-', YEAR(CURDATE()), '-9002'), @pac_lab, @medico, DATE_SUB(CURDATE(), INTERVAL 2 DAY),
  'en_proceso', 'normal', 'Laboratorio Clínico Central', 'Control anual', 0);
SET @lab2 := LAST_INSERT_ID();

INSERT INTO lab_orden_items (orden_id, estudio_id, nombre, precio, unidad, referencia)
SELECT @lab2, e.id, e.nombre, e.precio, e.unidad, e.referencia
FROM lab_estudios e
WHERE e.consultorio_id = @tid
  AND e.nombre IN ('Biometría hemática completa', 'Examen general de orina');

UPDATE lab_ordenes SET total = (SELECT COALESCE(SUM(precio), 0) FROM lab_orden_items WHERE orden_id = @lab2)
WHERE id = @lab2;

-- Orden 3: LISTA, con resultados capturados. Glucosa y HbA1c FUERA DE RANGO:
-- salen en rojo en pantalla y en el informe impreso. El colesterol, normal, para
-- que se vea el contraste.
INSERT INTO lab_ordenes
 (consultorio_id, folio, paciente_id, medico_id, fecha, estado, prioridad, proveedor, diagnostico, total)
VALUES
 (@tid, CONCAT('LAB-', YEAR(CURDATE()), '-9003'), @pac_lab, @medico, DATE_SUB(CURDATE(), INTERVAL 8 DAY),
  'lista', 'normal', 'Laboratorio Clínico Central', 'Diabetes mellitus tipo 2', 0);
SET @lab3 := LAST_INSERT_ID();

INSERT INTO lab_orden_items
 (orden_id, estudio_id, nombre, precio, unidad, referencia, resultado, fuera_rango)
VALUES
 (@lab3, (SELECT id FROM lab_estudios WHERE consultorio_id = @tid AND nombre = 'Glucosa en ayuno' LIMIT 1),
  'Glucosa en ayuno', 90.00, 'mg/dL', '70 - 100', '148', 1),
 (@lab3, (SELECT id FROM lab_estudios WHERE consultorio_id = @tid AND nombre = 'Hemoglobina glucosilada (HbA1c)' LIMIT 1),
  'Hemoglobina glucosilada (HbA1c)', 320.00, '%', '< 5.7', '7.8', 1),
 (@lab3, (SELECT id FROM lab_estudios WHERE consultorio_id = @tid AND nombre = 'Colesterol total' LIMIT 1),
  'Colesterol total', 110.00, 'mg/dL', '< 200', '176', 0);

UPDATE lab_ordenes SET total = (SELECT COALESCE(SUM(precio), 0) FROM lab_orden_items WHERE orden_id = @lab3)
WHERE id = @lab3;


-- =====================================================================
--  10. DOCUMENTOS — las cuatro plantillas más usadas
-- =====================================================================
INSERT INTO documento_plantillas (consultorio_id, nombre, cuerpo, orden) VALUES
 (@tid, 'Constancia de buena salud',
  'A QUIEN CORRESPONDA:\n\nPor medio de la presente hago constar que {paciente}, de {edad}, fue valorado(a) clínicamente en este consultorio el día de hoy, encontrándose en buen estado de salud general, sin datos de enfermedad infectocontagiosa activa ni impedimento aparente para realizar sus actividades habituales.\n\nSe extiende la presente a petición del interesado(a) para los fines legales que a este convengan, en {consultorio}, a {fecha}.\n', 1),

 (@tid, 'Justificante / incapacidad',
  'A QUIEN CORRESPONDA:\n\nHago constar que {paciente}, de {edad}, acudió a consulta médica el día de hoy con diagnóstico de {diagnostico}, por lo cual se indica reposo domiciliario por {dias} días a partir de esta fecha.\n\nSe extiende la presente para los fines que al interesado(a) convengan, en {consultorio}, a {fecha}.\n', 2),

 (@tid, 'Referencia a especialista',
  'ESTIMADO(A) COLEGA:\n\nLe envío a {paciente}, de {edad}, con diagnóstico de {diagnostico}, para su valoración y manejo especializado.\n\nResumen del caso:\n\n\nAgradezco de antemano su atención y quedo a sus órdenes.\n\nAtentamente,\n{medico} · {especialidad}\n{consultorio}, a {fecha}.\n', 3),

 (@tid, 'Resumen clínico',
  'RESUMEN CLÍNICO\n\nPaciente: {paciente}\nEdad: {edad}\nFecha: {fecha}\n\nAntecedentes de importancia:\n\n\nPadecimiento actual:\n\n\nExploración física:\n\n\nDiagnóstico: {diagnostico}\n\nPlan de tratamiento:\n\n\nAtentamente,\n{medico} · {especialidad}\n', 4);


-- =====================================================================
--  11. CITAS próximas, con token, para probar la CONFIRMACIÓN por enlace
-- =====================================================================
INSERT INTO citas
 (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, tipo, motivo, estado, origen, token)
VALUES
 (@tid, @pac_optica, @medico, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', 30, 'medica',
  'Entrega de lentes', 'programada', 'mostrador', REPLACE(UUID(), '-', '')),
 (@tid, @pac_lab, @medico, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '11:30:00', 30, 'medica',
  'Revisión de resultados', 'programada', 'mostrador', REPLACE(UUID(), '-', ''));



-- =====================================================================
--  12. SERVICIOS — catálogo de precios del consultorio
--      Alimenta a Presupuestos y al Punto de venta. `duracion_min` pre-llena la
--      cita; `aplica_diente` marca lo que se cotiza por pieza dental.
-- =====================================================================
DELETE FROM servicios WHERE consultorio_id = @tid;

INSERT INTO servicios (consultorio_id, nombre, codigo, categoria, precio, duracion_min, aplica_diente) VALUES
 (@tid, 'Consulta general',                 'CON-01', 'Consulta',    600.00,  30, 0),
 (@tid, 'Consulta de primera vez',          'CON-02', 'Consulta',    900.00,  45, 0),
 (@tid, 'Consulta de seguimiento',          'CON-03', 'Consulta',    450.00,  20, 0),
 (@tid, 'Certificado médico',               'CON-04', 'Consulta',    350.00,  15, 0),
 (@tid, 'Aplicación de inyección',          'PRO-01', 'Procedimiento', 200.00, 15, 0),
 (@tid, 'Curación de herida',               'PRO-02', 'Procedimiento', 450.00, 30, 0),
 (@tid, 'Retiro de puntos',                 'PRO-03', 'Procedimiento', 300.00, 20, 0),
 (@tid, 'Electrocardiograma',               'EST-01', 'Estudio',       500.00, 30, 0),
 (@tid, 'Limpieza dental (profilaxis)',     'DEN-01', 'Preventivo',    800.00, 40, 0),
 (@tid, 'Aplicación de flúor',              'DEN-02', 'Preventivo',    350.00, 20, 0),
 (@tid, 'Resina (una superficie)',          'DEN-03', 'Restauración',  900.00, 45, 1),
 (@tid, 'Resina (dos o más superficies)',   'DEN-04', 'Restauración', 1300.00, 60, 1),
 (@tid, 'Incrustación',                     'DEN-05', 'Restauración', 3200.00, 60, 1),
 (@tid, 'Endodoncia unirradicular',         'DEN-06', 'Endodoncia',   3500.00, 90, 1),
 (@tid, 'Endodoncia multirradicular',       'DEN-07', 'Endodoncia',   4800.00, 120, 1),
 (@tid, 'Corona de zirconia',               'DEN-08', 'Prótesis',     7500.00, 90, 1),
 (@tid, 'Extracción simple',                'DEN-09', 'Cirugía',      1200.00, 45, 1),
 (@tid, 'Extracción de tercer molar',       'DEN-10', 'Cirugía',      3800.00, 90, 1),
 (@tid, 'Blanqueamiento dental',            'DEN-11', 'Estética',     4500.00, 60, 0);


-- =====================================================================
--  13. PRESUPUESTO de ejemplo (plan de tratamiento)
--      Aceptado y a medio hacer: un procedimiento ya realizado y dos pendientes,
--      con dos abonos. Así se ve el avance y el SALDO, que es de lo que vive
--      esta pantalla.
-- =====================================================================
DELETE FROM presupuestos WHERE consultorio_id = @tid AND folio LIKE CONCAT('PRE-', YEAR(CURDATE()), '-90%');

INSERT INTO presupuestos
 (consultorio_id, folio, paciente_id, medico_id, fecha, vigencia, estado,
  subtotal, descuento, total, notas, aceptado_en, creado_por)
VALUES
 (@tid, CONCAT('PRE-', YEAR(CURDATE()), '-9001'), @pac_lab, @medico,
  DATE_SUB(CURDATE(), INTERVAL 12 DAY), DATE_ADD(CURDATE(), INTERVAL 18 DAY), 'aceptado',
  9600.00, 600.00, 9000.00,
  'El paciente aceptó el plan completo. Pagará en tres abonos.',
  DATE_SUB(NOW(), INTERVAL 10 DAY), @medico);

SET @pre := LAST_INSERT_ID();

INSERT INTO presupuesto_items
 (presupuesto_id, servicio_id, descripcion, diente, cantidad, precio, importe, estado, realizado_en, realizado_por, orden)
VALUES
 (@pre, (SELECT id FROM servicios WHERE consultorio_id = @tid AND codigo = 'DEN-01' LIMIT 1),
  'Limpieza dental (profilaxis)', NULL, 1,  800.00,  800.00, 'realizado', DATE_SUB(NOW(), INTERVAL 9 DAY), @medico, 1),
 (@pre, (SELECT id FROM servicios WHERE consultorio_id = @tid AND codigo = 'DEN-06' LIMIT 1),
  'Endodoncia unirradicular', '21', 1, 3500.00, 3500.00, 'pendiente', NULL, NULL, 2),
 (@pre, (SELECT id FROM servicios WHERE consultorio_id = @tid AND codigo = 'DEN-08' LIMIT 1),
  'Corona de zirconia', '21', 1, 5300.00, 5300.00, 'pendiente', NULL, NULL, 3);

-- Dos abonos: quedan $4,000 de saldo. Eso es lo que el mostrador tiene que cobrar.
INSERT INTO presupuesto_pagos
 (consultorio_id, presupuesto_id, fecha, monto, metodo, notas, usuario_id)
VALUES
 (@tid, @pre, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 3000.00, 'efectivo',   'Anticipo al aceptar el plan.', @medico),
 (@tid, @pre, DATE_SUB(CURDATE(), INTERVAL 3 DAY),  2000.00, 'transferencia', 'Segundo abono.',            @medico);


-- =====================================================================
--  LISTO. Comprobación: TODOS los números deben salir mayores que cero.
-- =====================================================================
SELECT
  @tid                                                                    AS consultorio,
  (SELECT COUNT(*) FROM medico_horarios      WHERE consultorio_id = @tid) AS horarios,
  (SELECT COUNT(*) FROM optica_micas         WHERE consultorio_id = @tid) AS micas,
  (SELECT COUNT(*) FROM optica_graduaciones  WHERE consultorio_id = @tid) AS graduaciones,
  (SELECT COUNT(*) FROM optica_trabajos      WHERE consultorio_id = @tid) AS trabajos_optica,
  (SELECT COUNT(*) FROM lab_estudios         WHERE consultorio_id = @tid) AS estudios_lab,
  (SELECT COUNT(*) FROM lab_ordenes          WHERE consultorio_id = @tid) AS ordenes_lab,
  (SELECT COUNT(*) FROM documento_plantillas WHERE consultorio_id = @tid) AS plantillas,
  (SELECT COUNT(*) FROM servicios            WHERE consultorio_id = @tid) AS servicios,
  (SELECT COUNT(*) FROM presupuestos         WHERE consultorio_id = @tid) AS presupuestos;

-- Enlaces para probar el flujo del paciente:
SELECT CONCAT('/agenda/confirmar?t=', c.token) AS confirmar_cita, c.fecha, c.hora
FROM citas c WHERE c.consultorio_id = @tid AND c.token IS NOT NULL AND c.fecha >= CURDATE();

SELECT CONCAT('/agenda/reservar?c=', slug) AS agenda_en_linea FROM consultorios WHERE id = @tid;
