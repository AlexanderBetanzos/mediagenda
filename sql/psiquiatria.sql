-- =====================================================================
--  MediOS  -  Psiquiatría (notas con examen mental, riesgo suicida,
--  escalas y diagnóstico CIE-10; psicofármacos con historial de ajustes).
--  Las tablas también se crean solas al abrir el módulo (ensure_psiq_tables).
--  Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS psiq_notas (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
  fecha DATE NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'seguimiento',   -- inicial|seguimiento|urgencia|interconsulta
  motivo TEXT, padecimiento TEXT, antecedentes TEXT,
  examen TEXT, examen_obs TEXT,                                           -- examen mental (JSON por dominio)
  cssrs TEXT, riesgo_suicida VARCHAR(10) DEFAULT NULL, riesgo_hetero VARCHAR(10) DEFAULT NULL, factores TEXT,
  phq9 TINYINT DEFAULT NULL, gad7 TINYINT DEFAULT NULL, hamd TINYINT DEFAULT NULL, ymrs TINYINT DEFAULT NULL, audit TINYINT DEFAULT NULL,
  dx_codigo VARCHAR(20) DEFAULT NULL, dx_texto VARCHAR(255) DEFAULT NULL, dx_secundarios TEXT,
  plan TEXT, proxima VARCHAR(80) DEFAULT NULL,
  creado_por INT DEFAULT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_psiq_notas (consultorio_id, paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS psiq_meds (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
  farmaco VARCHAR(120) NOT NULL, clase VARCHAR(60) DEFAULT NULL,
  dosis VARCHAR(60) DEFAULT NULL, frecuencia VARCHAR(80) DEFAULT NULL, indicacion VARCHAR(160) DEFAULT NULL,
  inicio DATE NOT NULL, fin DATE DEFAULT NULL, motivo_fin VARCHAR(160) DEFAULT NULL,  -- fin NULL = activo
  efectos VARCHAR(255) DEFAULT NULL, anterior_id INT DEFAULT NULL,                  -- anterior_id: esquema que ajustó
  creado_por INT DEFAULT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_psiq_meds (consultorio_id, paciente_id, fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
