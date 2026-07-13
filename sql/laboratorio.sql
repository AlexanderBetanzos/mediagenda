-- =====================================================================
--  MediAgenda  -  Laboratorio (órdenes de estudio y resultados)
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql, archivos.sql y planes.sql.
--  Idempotente en MariaDB.
--
--  Flujo: el consultorio arma su catálogo de `lab_estudios` (con precio, tipo
--  de muestra y preparación del paciente). Una `lab_orden` agrupa varios
--  `lab_orden_items`. La orden avanza por estados (solicitada -> en proceso ->
--  lista -> entregada) y cada estudio puede capturar su resultado con unidad,
--  valor de referencia y bandera de fuera de rango.
--
--  Los PDFs/imágenes del resultado NO tienen tabla propia: se guardan en
--  `archivos` (expediente del paciente) con `lab_orden_id`, de modo que el
--  resultado aparece solo en el expediente y en el portal del paciente.
-- =====================================================================

-- 1) Catálogo de estudios ---------------------------------------------------
CREATE TABLE IF NOT EXISTS lab_estudios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(160) NOT NULL,
  codigo         VARCHAR(40)  DEFAULT NULL,          -- clave interna del consultorio
  categoria      VARCHAR(60)  DEFAULT NULL,          -- Sangre, Orina, Imagen, Patología…
  muestra        VARCHAR(60)  DEFAULT NULL,          -- Sangre venosa, Orina, Hisopado…
  preparacion    VARCHAR(255) DEFAULT NULL,          -- "Ayuno de 8 h", etc.
  unidad         VARCHAR(30)  DEFAULT NULL,          -- mg/dL, g/L…  (pre-llena el item)
  referencia     VARCHAR(60)  DEFAULT NULL,          -- "70 - 100"    (pre-llena el item)
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lest_tenant (consultorio_id, activo),
  INDEX idx_lest_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Orden de laboratorio (cabecera) ----------------------------------------
CREATE TABLE IF NOT EXISTS lab_ordenes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  folio          VARCHAR(30) NOT NULL,
  paciente_id    INT NOT NULL,
  medico_id      INT DEFAULT NULL,                   -- quién la solicita
  consulta_id    INT DEFAULT NULL,                   -- opcional: liga a la consulta
  fecha          DATE NOT NULL,
  estado         ENUM('solicitada','en_proceso','lista','entregada','cancelada')
                 NOT NULL DEFAULT 'solicitada',
  prioridad      ENUM('normal','urgente') NOT NULL DEFAULT 'normal',
  proveedor      VARCHAR(120) DEFAULT NULL,          -- laboratorio externo, si se envía fuera
  diagnostico    VARCHAR(255) DEFAULT NULL,          -- presuntivo, para orientar al laboratorio
  notas          TEXT DEFAULT NULL,
  total          DECIMAL(10,2) NOT NULL DEFAULT 0,
  entregada_en   DATETIME DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lab_folio (consultorio_id, folio),
  CONSTRAINT fk_lab_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_lab_medico   FOREIGN KEY (medico_id)   REFERENCES usuarios(id)  ON DELETE SET NULL,
  CONSTRAINT fk_lab_creador  FOREIGN KEY (creado_por)  REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_lab_tenant   (consultorio_id, estado),
  INDEX idx_lab_paciente (paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Estudios pedidos en la orden + su resultado -----------------------------
CREATE TABLE IF NOT EXISTS lab_orden_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  orden_id    INT NOT NULL,
  estudio_id  INT DEFAULT NULL,                      -- NULL = estudio escrito a mano
  nombre      VARCHAR(160) NOT NULL,                 -- copia: el catálogo puede cambiar
  precio      DECIMAL(10,2) NOT NULL DEFAULT 0,
  resultado   VARCHAR(160) DEFAULT NULL,             -- valor capturado ("95", "Negativo")
  unidad      VARCHAR(30)  DEFAULT NULL,
  referencia  VARCHAR(60)  DEFAULT NULL,
  fuera_rango TINYINT(1)   NOT NULL DEFAULT 0,       -- lo marca el médico: se resalta
  CONSTRAINT fk_labit_orden   FOREIGN KEY (orden_id)   REFERENCES lab_ordenes(id)  ON DELETE CASCADE,
  CONSTRAINT fk_labit_estudio FOREIGN KEY (estudio_id) REFERENCES lab_estudios(id) ON DELETE SET NULL,
  INDEX idx_labit_orden (orden_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Los archivos de resultado viven en el expediente ------------------------
--    Se añade la liga a la orden. MariaDB no soporta ADD COLUMN IF NOT EXISTS
--    en todas las versiones, así que se hace condicional vía information_schema.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archivos'
                  AND COLUMN_NAME = 'lab_orden_id');
SET @sql := IF(@existe = 0,
  'ALTER TABLE archivos
     ADD COLUMN lab_orden_id INT DEFAULT NULL AFTER consulta_id,
     ADD CONSTRAINT fk_arch_lab FOREIGN KEY (lab_orden_id)
         REFERENCES lab_ordenes(id) ON DELETE SET NULL',
  'SELECT "archivos.lab_orden_id ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) El módulo ya está en el catálogo (planes.sql) y solo lo incluye Clínica.
--    Se reafirma aquí para que este archivo se pueda correr por separado.
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('laboratorio', 'Laboratorio', 4, 12)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('clinica', 'laboratorio')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
