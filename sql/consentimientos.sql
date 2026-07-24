-- =====================================================================
--  MediOS  -  Consentimiento informado (con firma del paciente y médico)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Las firmas se guardan como imagen (data URI PNG) capturada en canvas.
-- =====================================================================
CREATE TABLE IF NOT EXISTS consentimientos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  medico_id      INT DEFAULT NULL,
  titulo         VARCHAR(180) NOT NULL,
  contenido      MEDIUMTEXT DEFAULT NULL,
  firma_paciente MEDIUMTEXT DEFAULT NULL,   -- data:image/png;base64,...
  firma_medico   MEDIUMTEXT DEFAULT NULL,
  firmante       VARCHAR(160) DEFAULT NULL, -- quién firma (paciente/tutor)
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_consent_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_consent (consultorio_id, paciente_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
