-- =====================================================================
--  MediOS  -  Control prenatal (Ginecología y Obstetricia)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Un embarazo por paciente (activo) agrupa sus visitas de control.
-- =====================================================================
CREATE TABLE IF NOT EXISTS embarazos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  fum            DATE DEFAULT NULL,               -- fecha de última menstruación
  fpp            DATE DEFAULT NULL,               -- fecha probable de parto (FUM + 280 d)
  grupo_sanguineo VARCHAR(6) DEFAULT NULL,
  gestas         TINYINT DEFAULT NULL,
  partos         TINYINT DEFAULT NULL,
  cesareas       TINYINT DEFAULT NULL,
  abortos        TINYINT DEFAULT NULL,
  riesgo         ENUM('bajo','alto') NOT NULL DEFAULT 'bajo',
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  desenlace      VARCHAR(120) DEFAULT NULL,        -- al cerrar: parto/cesárea/aborto…
  cerrado_en     DATE DEFAULT NULL,
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_emb_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_emb (consultorio_id, paciente_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prenatal_visitas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  embarazo_id    INT NOT NULL,
  fecha          DATE NOT NULL,
  sdg            DECIMAL(4,1) DEFAULT NULL,        -- semanas de gestación
  peso           DECIMAL(5,2) DEFAULT NULL,        -- kg
  presion        VARCHAR(20)  DEFAULT NULL,        -- 120/80
  fcf            SMALLINT DEFAULT NULL,            -- frecuencia cardiaca fetal (lpm)
  altura_uterina DECIMAL(4,1) DEFAULT NULL,        -- cm
  movimientos    TINYINT(1) DEFAULT NULL,          -- percibe movimientos fetales
  edema          VARCHAR(40) DEFAULT NULL,
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pv_emb FOREIGN KEY (embarazo_id) REFERENCES embarazos(id) ON DELETE CASCADE,
  INDEX idx_pv (embarazo_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
