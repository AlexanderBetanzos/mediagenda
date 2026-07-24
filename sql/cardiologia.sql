-- =====================================================================
--  MediOS  -  Cardiología (valoraciones: riesgo CV, perfil, ECG)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS cardio_valoraciones (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  fecha          DATE NOT NULL,
  presion        VARCHAR(20)  DEFAULT NULL,   -- 120/80
  fc             SMALLINT DEFAULT NULL,       -- lpm
  colesterol_total DECIMAL(5,1) DEFAULT NULL, -- mg/dL
  hdl            DECIMAL(5,1) DEFAULT NULL,
  ldl            DECIMAL(5,1) DEFAULT NULL,
  trigliceridos  DECIMAL(6,1) DEFAULT NULL,
  glucosa        DECIMAL(5,1) DEFAULT NULL,
  tabaquismo     TINYINT(1) NOT NULL DEFAULT 0,
  diabetes       TINYINT(1) NOT NULL DEFAULT 0,
  nyha           ENUM('I','II','III','IV') DEFAULT NULL,   -- clase funcional
  riesgo         ENUM('bajo','moderado','alto','muy_alto') DEFAULT NULL,
  ecg_hallazgos  TEXT DEFAULT NULL,
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cardio_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_cardio (consultorio_id, paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
