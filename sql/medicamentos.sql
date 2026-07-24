-- =====================================================================
--  MediOS  -  Medicamentos actuales del paciente (lista estructurada)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS paciente_medicamentos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  nombre         VARCHAR(160) NOT NULL,
  dosis          VARCHAR(80)  DEFAULT NULL,
  frecuencia     VARCHAR(80)  DEFAULT NULL,
  via            VARCHAR(40)  DEFAULT NULL,
  inicio         DATE DEFAULT NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  suspendido_en  DATE DEFAULT NULL,
  notas          VARCHAR(255) DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pacmed_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_pacmed (paciente_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
