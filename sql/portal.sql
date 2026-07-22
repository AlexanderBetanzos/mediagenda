-- =====================================================================
--  MediOS  -  Portal del paciente (acceso de pacientes)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
--  El paciente inicia sesión con su correo y una contraseña que le asigna
--  el consultorio. Sesión separada de la del personal.
-- =====================================================================

ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_password_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_activo TINYINT(1) NOT NULL DEFAULT 0;
-- Token de un solo uso para que el paciente cree su acceso al portal desde el
-- correo de confirmación de cita (auto-registro). Se limpia al activar.
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_token VARCHAR(64) DEFAULT NULL;
