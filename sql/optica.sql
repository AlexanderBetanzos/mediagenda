-- =====================================================================
--  MediAgenda  -  Óptica (graduaciones, micas y órdenes de trabajo)
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql, planes.sql e inventario.sql.
--  Idempotente en MariaDB.
--
--  Cómo encaja con lo que ya existe, para NO duplicar:
--    · Los ARMAZONES son productos del inventario (categoría "Armazón"): así
--      tienen stock, se venden en el POS y salen en los reportes como todo.
--    · Las MICAS sí necesitan catálogo propio: su precio no es fijo, depende del
--      RANGO DE GRADUACIÓN (una esfera de -6.00 cuesta más que una de -1.00).
--    · La ORDEN DE TRABAJO es lo que en otros sistemas llaman "Trabajos": sigue
--      el par de lentes desde que se pide hasta que el cliente lo recoge.
-- =====================================================================

-- 1) El paciente puede ser de óptica ----------------------------------------
--    `tipo` era ENUM('medico','dental'). Igual que el odontograma solo aplica a
--    los dentales, la graduación solo aplica a los de óptica.
SET @col := (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacientes' AND COLUMN_NAME = 'tipo');
SET @sql := IF(@col NOT LIKE '%optica%',
  "ALTER TABLE pacientes MODIFY COLUMN tipo ENUM('medico','dental','optica') NOT NULL DEFAULT 'medico'",
  'SELECT "pacientes.tipo ya acepta optica"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Graduación / receta oftálmica ------------------------------------------
--    Un renglón por ojo sería más "normalizado", pero en la práctica la
--    graduación SIEMPRE se captura y se lee como una unidad (OD y OI juntos),
--    así que van en la misma fila: es como la ve el optometrista en el papel.
--    Dioptrías con 2 decimales y signo: -0.75, +1.25 (pasos de 0.25).
CREATE TABLE IF NOT EXISTS optica_graduaciones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id  INT NOT NULL DEFAULT 1,
  paciente_id     INT NOT NULL,
  optometrista_id INT DEFAULT NULL,
  consulta_id     INT DEFAULT NULL,               -- opcional: liga a la consulta
  fecha           DATE NOT NULL,
  vigencia        DATE DEFAULT NULL,              -- normalmente 1 año

  -- Ojo derecho (OD)
  od_esfera       DECIMAL(5,2) DEFAULT NULL,
  od_cilindro     DECIMAL(5,2) DEFAULT NULL,
  od_eje          SMALLINT     DEFAULT NULL,      -- 0 a 180 grados
  od_adicion      DECIMAL(5,2) DEFAULT NULL,      -- para vista de cerca
  od_prisma       VARCHAR(20)  DEFAULT NULL,
  od_av           VARCHAR(20)  DEFAULT NULL,      -- agudeza visual: 20/20
  od_dip          DECIMAL(4,1) DEFAULT NULL,      -- distancia pupilar monocular
  od_altura       DECIMAL(4,1) DEFAULT NULL,      -- altura de montaje

  -- Ojo izquierdo (OI)
  oi_esfera       DECIMAL(5,2) DEFAULT NULL,
  oi_cilindro     DECIMAL(5,2) DEFAULT NULL,
  oi_eje          SMALLINT     DEFAULT NULL,
  oi_adicion      DECIMAL(5,2) DEFAULT NULL,
  oi_prisma       VARCHAR(20)  DEFAULT NULL,
  oi_av           VARCHAR(20)  DEFAULT NULL,
  oi_dip          DECIMAL(4,1) DEFAULT NULL,
  oi_altura       DECIMAL(4,1) DEFAULT NULL,

  dip             DECIMAL(4,1) DEFAULT NULL,      -- distancia interpupilar total
  tipo_lente      ENUM('monofocal','bifocal','progresivo','ocupacional') DEFAULT NULL,
  diagnostico     VARCHAR(255) DEFAULT NULL,      -- miopía, astigmatismo, presbicia…
  notas           TEXT DEFAULT NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_grad_paciente FOREIGN KEY (paciente_id)     REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_grad_optom    FOREIGN KEY (optometrista_id) REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_grad_paciente (paciente_id, fecha),
  INDEX idx_grad_tenant   (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Catálogo de micas (lentes) ---------------------------------------------
--    El precio se resuelve por RANGO: se elige la mica cuyo rango cubre la
--    graduación del paciente. Rango NULL = sin límite por ese lado.
CREATE TABLE IF NOT EXISTS optica_micas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(160) NOT NULL,           -- "Progresivo policarbonato AR"
  tipo_lente     ENUM('monofocal','bifocal','progresivo','ocupacional') NOT NULL DEFAULT 'monofocal',
  material       VARCHAR(60)  DEFAULT NULL,       -- CR-39, policarbonato, alto índice 1.67…
  tratamientos   VARCHAR(255) DEFAULT NULL,       -- antirreflejante, fotocromático, filtro azul…
  esfera_min     DECIMAL(5,2) DEFAULT NULL,       -- rango de graduación que cubre
  esfera_max     DECIMAL(5,2) DEFAULT NULL,
  cilindro_max   DECIMAL(5,2) DEFAULT NULL,       -- cilindro máximo (en valor absoluto)
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0,-- precio del PAR
  dias_entrega   INT NOT NULL DEFAULT 3,          -- para calcular la fecha promesa
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mica_tenant (consultorio_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Orden de trabajo -------------------------------------------------------
--    El armazón puede venir del inventario (producto_id) o escribirse a mano
--    (el cliente trajo el suyo). Los precios se COPIAN al momento de la venta:
--    si mañana sube el catálogo, la orden ya emitida no cambia.
CREATE TABLE IF NOT EXISTS optica_trabajos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id  INT NOT NULL DEFAULT 1,
  folio           VARCHAR(30) NOT NULL,
  paciente_id     INT NOT NULL,
  graduacion_id   INT DEFAULT NULL,
  vendedor_id     INT DEFAULT NULL,
  fecha           DATE NOT NULL,
  fecha_promesa   DATE DEFAULT NULL,              -- lo que se le prometió al cliente
  estado          ENUM('pedido','en_laboratorio','recibido','entregado','cancelado')
                  NOT NULL DEFAULT 'pedido',

  armazon_producto_id INT DEFAULT NULL,           -- si salió del inventario
  armazon_desc    VARCHAR(160) DEFAULT NULL,      -- marca/modelo/color, o "del cliente"
  armazon_precio  DECIMAL(10,2) NOT NULL DEFAULT 0,

  mica_id         INT DEFAULT NULL,
  mica_desc       VARCHAR(160) DEFAULT NULL,
  mica_precio     DECIMAL(10,2) NOT NULL DEFAULT 0,
  tratamientos    VARCHAR(255) DEFAULT NULL,

  laboratorio     VARCHAR(120) DEFAULT NULL,      -- a dónde se mandó a tallar
  descuento       DECIMAL(10,2) NOT NULL DEFAULT 0,
  total           DECIMAL(10,2) NOT NULL DEFAULT 0,
  anticipo        DECIMAL(10,2) NOT NULL DEFAULT 0,
  notas           TEXT DEFAULT NULL,
  entregado_en    DATETIME DEFAULT NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_trab_folio (consultorio_id, folio),
  CONSTRAINT fk_trab_paciente  FOREIGN KEY (paciente_id)   REFERENCES pacientes(id)           ON DELETE CASCADE,
  CONSTRAINT fk_trab_grad      FOREIGN KEY (graduacion_id) REFERENCES optica_graduaciones(id) ON DELETE SET NULL,
  CONSTRAINT fk_trab_mica      FOREIGN KEY (mica_id)       REFERENCES optica_micas(id)        ON DELETE SET NULL,
  CONSTRAINT fk_trab_vendedor  FOREIGN KEY (vendedor_id)   REFERENCES usuarios(id)            ON DELETE SET NULL,
  INDEX idx_trab_tenant   (consultorio_id, estado),
  INDEX idx_trab_paciente (paciente_id, fecha),
  INDEX idx_trab_promesa  (fecha_promesa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) El módulo y su plan ----------------------------------------------------
--    Óptica es una vertical: se vende desde Profesional (no en Básico).
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('optica', 'Óptica', 2, 19)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('profesional', 'optica'),
 ('clinica',     'optica')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
