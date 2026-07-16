-- =====================================================================
--  MediOS  -  Catálogo de médicos: médicos sin login
--  Ejecutar DESPUÉS de schema.sql. Idempotente.
--
--  No todo médico necesita entrar al sistema. El dueño usa el dashboard; un
--  médico que solo pasa consulta es un TRABAJADOR que debe existir en la agenda
--  (para asignarle citas y consultas) pero sin obligarlo a tener usuario y
--  contraseña. Para eso, email y password dejan de ser obligatorios: un médico
--  sin correo simplemente no puede iniciar sesión, pero sí recibe citas.
--
--  Se agrega también `cedula` (cédula profesional), útil en recetas e informes.
-- =====================================================================

-- email: de NOT NULL a NULL. El índice UNIQUE admite varios NULL en MySQL, así
-- que muchos médicos sin correo conviven sin chocar.
ALTER TABLE usuarios MODIFY COLUMN email VARCHAR(150) NULL;

-- password: NULL = este usuario no inicia sesión.
ALTER TABLE usuarios MODIFY COLUMN password_hash VARCHAR(255) NULL;

-- cédula profesional (opcional).
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'cedula') = 0,
  'ALTER TABLE usuarios ADD COLUMN cedula VARCHAR(40) DEFAULT NULL AFTER especialidad',
  'SELECT "usuarios.cedula ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
