-- =====================================================================
--  MediOS  -  Oftalmología clínica (exámenes: AV, PIO, segmento, fondo)
--  Complementa el módulo de Óptica (graduaciones). Idempotente.
-- =====================================================================
CREATE TABLE IF NOT EXISTS oftalmo_examenes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  fecha          DATE NOT NULL,
  av_od          VARCHAR(20) DEFAULT NULL,     -- agudeza visual OD (20/20)
  av_oi          VARCHAR(20) DEFAULT NULL,     -- agudeza visual OI
  pio_od         DECIMAL(4,1) DEFAULT NULL,    -- presión intraocular OD (mmHg)
  pio_oi         DECIMAL(4,1) DEFAULT NULL,    -- presión intraocular OI
  segmento_ant   TEXT DEFAULT NULL,
  fondo_ojo      TEXT DEFAULT NULL,
  diagnostico    VARCHAR(255) DEFAULT NULL,
  plan           TEXT DEFAULT NULL,
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_oft_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_oft (consultorio_id, paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
