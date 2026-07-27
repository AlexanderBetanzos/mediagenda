-- =====================================================================
--  MediOS  -  Mapa corporal interactivo (hallazgos por zona del cuerpo)
--  El médico marca hallazgos sobre un cuerpo SVG. Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS mapa_corporal_hallazgos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  region         VARCHAR(40) NOT NULL,
  titulo         VARCHAR(160) NOT NULL,
  nota           TEXT DEFAULT NULL,
  severidad      ENUM('leve','moderado','grave') NOT NULL DEFAULT 'moderado',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mapc_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_mapc (consultorio_id, paciente_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
