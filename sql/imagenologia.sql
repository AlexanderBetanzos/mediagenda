-- =====================================================================
--  MediOS  -  Ultrasonido / Imagenología (estudios e informes)
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql, archivos.sql y planes.sql.
--  Idempotente en MariaDB.
--
--  Flujo: el consultorio arma su catálogo de `img_plantillas` (el protocolo de
--  cada región: qué se mide y qué se describe). Al crear un `img_estudio` se
--  copian los renglones de la plantilla a `img_hallazgos`, donde el médico
--  captura las mediciones. El estudio avanza por estados (programado ->
--  realizado -> informado -> entregado) y se cierra con impresión diagnóstica.
--
--  Las capturas del ecógrafo NO tienen tabla propia: se guardan en `archivos`
--  (expediente del paciente) con `img_estudio_id`, igual que laboratorio, de
--  modo que aparecen en el expediente y en el portal del paciente sin código
--  extra.
-- =====================================================================

-- 1) Catálogo de protocolos por región --------------------------------------
--    `campos` es el protocolo en JSON: la lista de renglones que se copian al
--    estudio. Se guarda como TEXT (convención del proyecto: MariaDB trata JSON
--    como alias de LONGTEXT y no todas las versiones validan igual).
CREATE TABLE IF NOT EXISTS img_plantillas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(160) NOT NULL,               -- "Ultrasonido abdominal completo"
  region         VARCHAR(60)  DEFAULT NULL,           -- abdominal, obstetrico, tiroides…
  campos         TEXT         DEFAULT NULL,           -- JSON: [{clave,etiqueta,tipo,unidad,referencia,opciones}]
  tecnica        TEXT         DEFAULT NULL,           -- técnica por defecto del informe
  preparacion    VARCHAR(255) DEFAULT NULL,           -- "Ayuno de 6 h", "Vejiga llena"
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_iplant_tenant (consultorio_id, activo),
  INDEX idx_iplant_region (region)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Estudio de imagen (cabecera + informe) ---------------------------------
CREATE TABLE IF NOT EXISTS img_estudios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  folio          VARCHAR(30) NOT NULL,
  paciente_id    INT NOT NULL,
  plantilla_id   INT DEFAULT NULL,                    -- protocolo usado (informativo)
  medico_id      INT DEFAULT NULL,                    -- quién realiza e interpreta
  consulta_id    INT DEFAULT NULL,                    -- opcional: liga a la consulta
  nombre         VARCHAR(160) NOT NULL,               -- copia: la plantilla puede cambiar
  region         VARCHAR(60)  DEFAULT NULL,
  fecha          DATE NOT NULL,
  estado         ENUM('programado','realizado','informado','entregado','cancelado')
                 NOT NULL DEFAULT 'programado',
  referente      VARCHAR(120) DEFAULT NULL,           -- médico que lo solicita (puede ser externo)
  indicacion     VARCHAR(255) DEFAULT NULL,           -- motivo del estudio / dx presuntivo
  equipo         VARCHAR(120) DEFAULT NULL,           -- marca y modelo del ecógrafo
  transductor    VARCHAR(60)  DEFAULT NULL,           -- convexo 3.5 MHz, lineal 7.5 MHz…
  tecnica        TEXT DEFAULT NULL,
  hallazgos      TEXT DEFAULT NULL,                   -- narrativa libre, además de los medidos
  impresion      TEXT DEFAULT NULL,                   -- impresión diagnóstica (cierra el informe)
  recomendaciones TEXT DEFAULT NULL,
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0,
  informado_en   DATETIME DEFAULT NULL,
  entregado_en   DATETIME DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_img_folio (consultorio_id, folio),
  CONSTRAINT fk_img_paciente  FOREIGN KEY (paciente_id)  REFERENCES pacientes(id)      ON DELETE CASCADE,
  CONSTRAINT fk_img_plantilla FOREIGN KEY (plantilla_id) REFERENCES img_plantillas(id) ON DELETE SET NULL,
  CONSTRAINT fk_img_medico    FOREIGN KEY (medico_id)    REFERENCES usuarios(id)       ON DELETE SET NULL,
  CONSTRAINT fk_img_creador   FOREIGN KEY (creado_por)   REFERENCES usuarios(id)       ON DELETE SET NULL,
  INDEX idx_img_tenant   (consultorio_id, estado),
  INDEX idx_img_paciente (paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Renglones medidos / descritos del estudio -------------------------------
--    Copiados del protocolo al crear el estudio. `valor` es texto para que un
--    mismo renglón sirva a una medida ("11.4"), a una descripción ("homogéneo")
--    o a una opción ("Normal"): el informe se lee igual y no obliga a normalizar.
CREATE TABLE IF NOT EXISTS img_hallazgos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  estudio_id  INT NOT NULL,
  clave       VARCHAR(60)  DEFAULT NULL,              -- identificador del renglón en la plantilla
  etiqueta    VARCHAR(120) NOT NULL,                  -- "Hígado", "DBP", "Endometrio"
  tipo        VARCHAR(20)  NOT NULL DEFAULT 'texto',  -- texto | area | numero | opcion
  valor       VARCHAR(255) DEFAULT NULL,
  unidad      VARCHAR(30)  DEFAULT NULL,              -- mm, cm, ml, lpm…
  referencia  VARCHAR(60)  DEFAULT NULL,              -- "< 6", "70 - 100"
  opciones    VARCHAR(255) DEFAULT NULL,              -- lista separada por | para tipo=opcion
  anormal     TINYINT(1)   NOT NULL DEFAULT 0,        -- lo marca el médico: se resalta
  orden       INT          NOT NULL DEFAULT 0,
  CONSTRAINT fk_ihal_estudio FOREIGN KEY (estudio_id) REFERENCES img_estudios(id) ON DELETE CASCADE,
  INDEX idx_ihal_estudio (estudio_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Las capturas del ecógrafo viven en el expediente ------------------------
--    MariaDB no soporta ADD COLUMN IF NOT EXISTS en todas las versiones, así
--    que se hace condicional vía information_schema (igual que laboratorio).
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'archivos'
                  AND COLUMN_NAME = 'img_estudio_id');
SET @sql := IF(@existe = 0,
  'ALTER TABLE archivos
     ADD COLUMN img_estudio_id INT DEFAULT NULL AFTER consulta_id,
     ADD CONSTRAINT fk_arch_img FOREIGN KEY (img_estudio_id)
         REFERENCES img_estudios(id) ON DELETE SET NULL',
  'SELECT "archivos.img_estudio_id ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Registro del módulo -----------------------------------------------------
--    Incluido en el plan Clínica; en Básico y Profesional se vende como add-on
--    activándolo por consultorio desde /platform (consultorio_modulos).
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('imagenologia', 'Ultrasonido / Imagenología', 4, 19)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), fase = VALUES(fase), orden = VALUES(orden);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('clinica', 'imagenologia')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
