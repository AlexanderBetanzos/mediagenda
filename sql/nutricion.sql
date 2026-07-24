-- =====================================================================
--  MediOS  -  Nutrición (valoraciones antropométricas con evolución)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS nutricion_valoraciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  fecha          DATE NOT NULL,
  peso           DECIMAL(5,2) DEFAULT NULL,   -- kg
  estatura       DECIMAL(5,2) DEFAULT NULL,   -- cm
  grasa_pct      DECIMAL(4,1) DEFAULT NULL,   -- % grasa corporal
  musculo_pct    DECIMAL(4,1) DEFAULT NULL,   -- % masa muscular
  cintura        DECIMAL(5,1) DEFAULT NULL,   -- cm
  cadera         DECIMAL(5,1) DEFAULT NULL,   -- cm
  meta_peso      DECIMAL(5,2) DEFAULT NULL,   -- kg objetivo
  kcal_plan      SMALLINT DEFAULT NULL,       -- kcal del plan
  plan           TEXT DEFAULT NULL,           -- indicaciones del plan alimenticio
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nut_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_nut (consultorio_id, paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
