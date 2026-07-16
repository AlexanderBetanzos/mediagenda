-- =====================================================================
--  MediOS Agenda  -  CRM (seguimiento de pacientes)
--  Ejecutar DESPUÉS de planes.sql y schema/multitenant. Idempotente.
-- =====================================================================

-- Seguimientos / tareas de relación con el paciente.
CREATE TABLE IF NOT EXISTS seguimientos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  tipo           ENUM('llamada','mensaje','revision','otro') NOT NULL DEFAULT 'otro',
  titulo         VARCHAR(160) NOT NULL,
  fecha_objetivo DATE DEFAULT NULL,
  estado         ENUM('pendiente','hecho') NOT NULL DEFAULT 'pendiente',
  nota           TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completado_en  DATETIME DEFAULT NULL,
  CONSTRAINT fk_seg_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_seg_pendiente (consultorio_id, estado, fecha_objetivo),
  INDEX idx_seg_paciente (paciente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrar el módulo 'crm' en el catálogo de entitlements (Profesional+).
INSERT INTO modulos (clave, nombre, fase, orden) VALUES ('crm', 'CRM / seguimiento', 2, 17)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), fase = VALUES(fase);
INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES ('profesional', 'crm'), ('clinica', 'crm')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
