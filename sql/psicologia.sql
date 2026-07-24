-- =====================================================================
--  MediOS  -  Psicología (sesiones + escalas PHQ-9 / GAD-7)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS psico_sesiones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  fecha          DATE NOT NULL,
  enfoque        VARCHAR(160) DEFAULT NULL,   -- tema/enfoque de la sesión
  notas          TEXT DEFAULT NULL,           -- evolución/observaciones
  tareas         TEXT DEFAULT NULL,           -- tareas para el paciente
  phq9           TINYINT DEFAULT NULL,        -- depresión 0-27
  gad7           TINYINT DEFAULT NULL,        -- ansiedad 0-21
  riesgo         ENUM('ninguno','bajo','moderado','alto') DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_psico_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_psico (consultorio_id, paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
