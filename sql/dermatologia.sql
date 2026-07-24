-- =====================================================================
--  MediOS  -  Dermatología (lesiones con seguimiento foto-comparativo)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql/archivos.sql. Idempotente.
--  Las fotos se guardan en `archivos` (expediente) y se ligan a la lesión.
-- =====================================================================
CREATE TABLE IF NOT EXISTS derma_lesiones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  region         VARCHAR(120) DEFAULT NULL,   -- zona anatómica
  tipo           VARCHAR(120) DEFAULT NULL,   -- mácula, nevo, placa…
  descripcion    TEXT DEFAULT NULL,
  diagnostico    VARCHAR(255) DEFAULT NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_derma_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_derma (consultorio_id, paciente_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS derma_fotos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  lesion_id  INT NOT NULL,
  archivo_id INT NOT NULL,                    -- foto en la tabla archivos
  fecha      DATE NOT NULL,
  notas      VARCHAR(255) DEFAULT NULL,
  creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dfoto_lesion FOREIGN KEY (lesion_id) REFERENCES derma_lesiones(id) ON DELETE CASCADE,
  INDEX idx_dfoto (lesion_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
