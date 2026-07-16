-- =====================================================================
--  MediOS Agenda  -  Portal del paciente (acceso de pacientes)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
--  El paciente inicia sesión con su correo y una contraseña que le asigna
--  el consultorio. Sesión separada de la del personal.
-- =====================================================================

ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_password_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_activo TINYINT(1) NOT NULL DEFAULT 0;
