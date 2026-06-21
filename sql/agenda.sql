-- =====================================================================
--  MediAgenda  -  Agenda pro: horarios por médico y bloqueos
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
-- =====================================================================

-- Horario laboral semanal por médico (puede haber varios tramos por día).
-- dia_semana: 0=domingo … 6=sábado (igual que PHP date('w') y JS getDay()).
CREATE TABLE IF NOT EXISTS medico_horarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  medico_id      INT NOT NULL,
  dia_semana     TINYINT NOT NULL,
  hora_inicio    TIME NOT NULL,
  hora_fin       TIME NOT NULL,
  CONSTRAINT fk_mh_medico FOREIGN KEY (medico_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_mh_medico (medico_id, dia_semana),
  INDEX idx_mh_tenant (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueos de agenda (vacaciones, comidas, días festivos…).
-- medico_id NULL = bloqueo para todo el consultorio.
CREATE TABLE IF NOT EXISTS bloqueos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  medico_id      INT DEFAULT NULL,
  inicio         DATETIME NOT NULL,
  fin            DATETIME NOT NULL,
  motivo         VARCHAR(120) DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bloq_medico FOREIGN KEY (medico_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_bloq_tenant (consultorio_id, inicio),
  INDEX idx_bloq_medico (medico_id, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
