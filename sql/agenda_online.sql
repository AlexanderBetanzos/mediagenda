-- =====================================================================
--  MediAgenda  -  Agenda en línea + confirmación de cita
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql, agenda.sql y planes.sql.
--  Idempotente.
--
--  Por qué esto: el consultorio ya mide su tasa de inasistencia, pero no podía
--  hacer nada con ella. El recordatorio salía y el paciente no tenía forma de
--  responderlo. Ahora el correo lleva un enlace con un token: el paciente
--  CONFIRMA o CANCELA solo, y una cancelación a tiempo libera el hueco en vez
--  de descubrirse cuando el paciente no llega.
--
--  El token va en la propia cita (no en tabla aparte): es un dato de la cita,
--  muere con ella y así el enlace se invalida solo al borrarla.
-- =====================================================================

-- 1) Confirmación y origen de la cita ---------------------------------------
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citas' AND COLUMN_NAME = 'token') = 0,
  "ALTER TABLE citas
     ADD COLUMN token         VARCHAR(32) DEFAULT NULL,
     ADD COLUMN confirmada_en DATETIME    DEFAULT NULL,
     ADD COLUMN cancelada_en  DATETIME    DEFAULT NULL,
     ADD COLUMN cancelada_por ENUM('paciente','consultorio') DEFAULT NULL,
     ADD COLUMN origen        ENUM('mostrador','online') NOT NULL DEFAULT 'mostrador',
     ADD UNIQUE INDEX uq_cita_token (token)",
  'SELECT "citas ya tiene token/confirmacion"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Ajustes por consultorio (viven en `configuracion`, vía cfg()) ----------
--    agenda_online          '1'/'0'  — ¿la página pública de reservas está abierta?
--    agenda_online_dias     entero   — con cuánta anticipación se puede reservar
--    agenda_online_duracion minutos  — duración del hueco que se ofrece
--    agenda_online_aviso    texto    — nota que ve el paciente antes de reservar
--    Se crean con valores por omisión SOLO si no existen (no pisa lo que ya haya).
INSERT INTO configuracion (consultorio_id, clave, valor)
SELECT c.id, 'agenda_online', '0' FROM consultorios c
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (consultorio_id, clave, valor)
SELECT c.id, 'agenda_online_dias', '30' FROM consultorios c
ON DUPLICATE KEY UPDATE valor = valor;

INSERT INTO configuracion (consultorio_id, clave, valor)
SELECT c.id, 'agenda_online_duracion', '30' FROM consultorios c
ON DUPLICATE KEY UPDATE valor = valor;

-- 3) La agenda en línea es del plan Profesional (va con el portal) ----------
--    La CONFIRMACIÓN por enlace, en cambio, no se gatea: mejora el recordatorio
--    por correo que ya se vende en Básico, y es justo lo que baja las faltas.
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('agenda_online', 'Agenda en línea', 2, 21)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('profesional', 'agenda_online'),
 ('clinica',     'agenda_online')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
