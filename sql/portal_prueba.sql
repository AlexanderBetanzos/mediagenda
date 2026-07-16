-- =====================================================================
--  MediAgenda  -  Activar el portal para un PACIENTE DE PRUEBA
--
--  El portal NO es un registro abierto: cada paciente necesita que el consultorio
--  le active el acceso (desde su ficha). Esto lo hace para un paciente de prueba
--  para que puedas probar el login de una vez.
--
--  Credenciales que quedan:
--     Correo:      prueba.optica@example.com
--     Contraseña:  paciente123
--
--  (El hash es de 'paciente123'. Cámbialo cuando quieras desde la ficha.)
-- =====================================================================

SET @slug := 'mi-clinica';
SET @tid  := (SELECT id FROM consultorios WHERE slug = @slug);

UPDATE pacientes
SET portal_activo = 1,
    portal_password_hash = '$2y$10$wqyC7INlNpS25Zi2Olyfhug1luQfcZGivzPPAeudY4/jOF8heiFiu'
WHERE consultorio_id = @tid
  AND email = 'prueba.optica@example.com';

-- Comprobación (debe salir 1 fila con portal_activo = 1).
SELECT id, nombre, apellidos, email, portal_activo
FROM pacientes
WHERE consultorio_id = @tid AND email = 'prueba.optica@example.com';
