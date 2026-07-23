-- =====================================================================
--  MediOS  -  Socios de plataforma (roles + asignación de clientes)
--  El dueño (rol 'super') ve y gestiona todo. Los socios (rol 'socio')
--  solo ven los consultorios que el dueño les asigna. Idempotente.
--  Ejecutar DESPUÉS de superadmin.sql (tabla plataforma_admins).
-- =====================================================================

-- Rol del admin de plataforma. Los admins existentes quedan como 'super'.
ALTER TABLE plataforma_admins ADD COLUMN IF NOT EXISTS rol ENUM('super','socio') NOT NULL DEFAULT 'super';
-- Teléfono de contacto del socio (se captura en el registro).
ALTER TABLE plataforma_admins ADD COLUMN IF NOT EXISTS telefono VARCHAR(30) DEFAULT NULL;

-- Qué consultorios ve/gestiona cada socio (el súper los ve todos, sin filas aquí).
CREATE TABLE IF NOT EXISTS plataforma_admin_consultorios (
  admin_id       INT NOT NULL,
  consultorio_id INT NOT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (admin_id, consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
