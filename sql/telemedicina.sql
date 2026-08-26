-- =====================================================================
--  MediOS  -  Telemedicina (videoconsulta por cita)
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql, agenda_online.sql y
--  planes.sql. Idempotente en MariaDB.
--
--  La videollamada NO tiene tabla propia: cuelga de la cita. Cada cita marcada
--  como `en_linea` recibe una `sala` aleatoria que se genera la primera vez que
--  alguien entra, igual que `citas.token`.
--
--  Por qué `sala` es distinta de `token`: el token es la credencial del
--  paciente y viaja en la URL del recordatorio. El nombre de la sala viaja al
--  servidor de video, donde cualquiera que lo conozca puede entrar. Si fueran
--  el mismo valor, el servidor de video conocería la credencial de la cita.
--  Separados, filtrar uno no compromete al otro.
-- =====================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas'
                  AND COLUMN_NAME = 'modalidad');
SET @sql := IF(@existe = 0,
  "ALTER TABLE citas
     ADD COLUMN modalidad ENUM('presencial','en_linea') NOT NULL DEFAULT 'presencial',
     ADD COLUMN sala VARCHAR(40) DEFAULT NULL,
     ADD COLUMN sala_abierta_en DATETIME DEFAULT NULL",
  'SELECT "citas ya tiene modalidad/sala"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- El módulo ya está en el catálogo (planes.sql) para Profesional y Clínica.
-- Se reafirma aquí para que este archivo se pueda correr por separado.
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('telemedicina', 'Telemedicina', 2, 9)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('profesional', 'telemedicina'), ('clinica', 'telemedicina')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
