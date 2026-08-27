-- =====================================================================
--  MediOS  -  Perfil público del médico (foto y semblanza)
--  Ejecutar DESPUÉS de schema.sql. Idempotente en MariaDB.
--
--  El equipo del micrositio se veía como una lista de nombres con horario.
--  Un paciente que elige médico quiere ver una cara y saber que existe: la
--  foto y la cédula son lo que convierte un nombre en una persona.
--  La cédula ya estaba en la tabla; solo faltaba enseñarla.
-- =====================================================================

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS foto       VARCHAR(255) DEFAULT NULL;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS semblanza  VARCHAR(600) DEFAULT NULL;
