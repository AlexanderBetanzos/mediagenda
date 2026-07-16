-- =====================================================================
--  MediOS  -  Súper-admin (gestión de consultorios)
--  Ejecutar DESPUÉS de multitenant.sql. Idempotente en MariaDB.
-- =====================================================================

-- Marca de súper-administrador (dueño del producto). Ve y gestiona TODOS
-- los consultorios, no solo el suyo.
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS es_superadmin TINYINT(1) NOT NULL DEFAULT 0;

-- Teléfono de contacto del consultorio (capturado en el registro).
ALTER TABLE consultorios ADD COLUMN IF NOT EXISTS telefono VARCHAR(40) DEFAULT NULL;

-- Otorga súper-admin a los administradores del consultorio principal (#1).
-- Ajusta el WHERE a tu correo si quieres limitarlo a una sola cuenta:
--   UPDATE usuarios SET es_superadmin = 1 WHERE email = 'tucorreo@dominio.com';
UPDATE usuarios SET es_superadmin = 1 WHERE consultorio_id = 1 AND rol = 'admin';
