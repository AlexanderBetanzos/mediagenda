-- =====================================================================
--  Módulos adicionales: Recetas y Facturación
--  Ejecutar DESPUÉS de schema.sql, dentro de la BD ya seleccionada.
-- =====================================================================

DROP TABLE IF EXISTS receta_items;
DROP TABLE IF EXISTS recetas;
DROP TABLE IF EXISTS factura_items;
DROP TABLE IF EXISTS facturas;

-- ---------------------------------------------------------------------
--  Recetas (cabecera) + medicamentos (detalle)
-- ---------------------------------------------------------------------
CREATE TABLE recetas (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id  INT NOT NULL,
  medico_id    INT NOT NULL,
  consulta_id  INT DEFAULT NULL,
  fecha        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diagnostico  VARCHAR(255) DEFAULT NULL,
  indicaciones TEXT DEFAULT NULL,
  notas        TEXT DEFAULT NULL,
  creado_en    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rec_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_medico   FOREIGN KEY (medico_id)   REFERENCES usuarios(id)  ON DELETE RESTRICT,
  INDEX idx_rec_paciente (paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receta_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  receta_id   INT NOT NULL,
  medicamento VARCHAR(200) NOT NULL,
  dosis       VARCHAR(120) DEFAULT NULL,
  frecuencia  VARCHAR(120) DEFAULT NULL,
  duracion    VARCHAR(120) DEFAULT NULL,
  CONSTRAINT fk_recitem_receta FOREIGN KEY (receta_id) REFERENCES recetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Facturación (cabecera) + conceptos (detalle)
-- ---------------------------------------------------------------------
CREATE TABLE facturas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  folio       VARCHAR(30) NOT NULL UNIQUE,
  paciente_id INT NOT NULL,
  medico_id   INT DEFAULT NULL,
  fecha       DATE NOT NULL,
  subtotal    DECIMAL(10,2) NOT NULL DEFAULT 0,
  descuento   DECIMAL(10,2) NOT NULL DEFAULT 0,
  total       DECIMAL(10,2) NOT NULL DEFAULT 0,
  estado      ENUM('pendiente','pagada','cancelada') NOT NULL DEFAULT 'pendiente',
  metodo_pago VARCHAR(40) DEFAULT NULL,
  notas       TEXT DEFAULT NULL,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fac_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_fac_medico   FOREIGN KEY (medico_id)   REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_fac_fecha (fecha),
  INDEX idx_fac_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE factura_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  factura_id  INT NOT NULL,
  descripcion VARCHAR(200) NOT NULL,
  cantidad    INT NOT NULL DEFAULT 1,
  precio      DECIMAL(10,2) NOT NULL DEFAULT 0,
  importe     DECIMAL(10,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_facitem_factura FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
