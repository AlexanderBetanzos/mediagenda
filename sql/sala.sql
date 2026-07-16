-- =====================================================================
--  MediOS  -  Sala de espera / flujo del día
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Agrega los estados operativos del día y marcas de tiempo para medir espera.
-- =====================================================================

ALTER TABLE citas MODIFY COLUMN estado
  ENUM('programada','confirmada','esperando','en_consulta','atendida','cancelada','no_asistio')
  NOT NULL DEFAULT 'programada';

ALTER TABLE citas ADD COLUMN IF NOT EXISTS checkin_en  DATETIME DEFAULT NULL;  -- llegó / check-in
ALTER TABLE citas ADD COLUMN IF NOT EXISTS atencion_en DATETIME DEFAULT NULL;  -- pasó a consulta
