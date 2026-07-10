-- =====================================================================
--  MediAgenda  -  Odontograma real: por caras, con historial y ligado al
--  plan de tratamiento.
--  Ejecutar DESPUÉS de especialidades.sql y presupuestos.sql. Idempotente.
--
--  Modelo: una MARCA por (paciente, diente, cara, condición).
--    · existente  = hallazgo actual (caries, obturado, ausente…)
--    · requerido  = tratamiento planeado sobre esa cara (obturación, corona…)
--    · realizado  = tratamiento ya ejecutado
--  Una misma cara puede tener a la vez un hallazgo y un tratamiento requerido.
--  `cara` = 'C' (diente completo) | O,M,D,V,L.
--
--  La tabla vieja `odontogramas` (un JSON por paciente) se conserva: el código
--  la importa a `odontograma_marcas` la primera vez que se abre la ficha.
-- =====================================================================

CREATE TABLE IF NOT EXISTS odontograma_marcas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  diente         VARCHAR(2) NOT NULL,
  cara           CHAR(1) NOT NULL DEFAULT 'C',
  condicion      ENUM('existente','requerido','realizado') NOT NULL DEFAULT 'existente',
  estado         VARCHAR(24) NOT NULL,           -- clave de hallazgo o de tratamiento
  presupuesto_id INT DEFAULT NULL,               -- presupuesto que cotizó el tratamiento
  actualizado_por INT DEFAULT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_marca (paciente_id, diente, cara, condicion),
  CONSTRAINT fk_odomarca_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_odomarca_usuario  FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_odomarca_tenant (consultorio_id),
  INDEX idx_odomarca_paciente (paciente_id, diente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nota libre por diente (una por paciente/diente).
CREATE TABLE IF NOT EXISTS odontograma_notas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  diente         VARCHAR(2) NOT NULL,
  nota           VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_odonota (paciente_id, diente),
  CONSTRAINT fk_odonota_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_odonota_tenant (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial: una foto completa del odontograma en cada guardado.
CREATE TABLE IF NOT EXISTS odontograma_historial (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  snapshot       LONGTEXT NOT NULL,              -- JSON: {marcas:[…], notas:{…}}
  motivo         VARCHAR(120) DEFAULT NULL,
  usuario_id     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_odohist_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_odohist_usuario  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_odohist_paciente (paciente_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Marca de importación del odontograma viejo (para no reimportar en cada visita).
ALTER TABLE odontogramas ADD COLUMN IF NOT EXISTS migrado TINYINT(1) NOT NULL DEFAULT 0;

-- El item del presupuesto recuerda qué tratamiento del odontograma representa,
-- para poder actualizar el hallazgo de la cara cuando se marca como realizado.
ALTER TABLE presupuesto_items ADD COLUMN IF NOT EXISTS tratamiento VARCHAR(24) DEFAULT NULL;
