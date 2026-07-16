-- =====================================================================
--  MediOS Agenda  -  Plantillas de consulta
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Formatos reutilizables que pre-llenan la nueva consulta del expediente.
-- =====================================================================

CREATE TABLE IF NOT EXISTS plantillas_consulta (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(120) NOT NULL,
  tipo           ENUM('general','medico','dental') NOT NULL DEFAULT 'general',
  motivo         VARCHAR(255) DEFAULT NULL,
  exploracion    TEXT DEFAULT NULL,
  diagnostico    TEXT DEFAULT NULL,
  tratamiento    TEXT DEFAULT NULL,
  receta         TEXT DEFAULT NULL,
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_plant_tenant (consultorio_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
