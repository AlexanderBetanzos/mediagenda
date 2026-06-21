-- =====================================================================
--  MediAgenda  -  Recordatorios automáticos de cita
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Marca cuándo se envió el recordatorio a una cita (para no repetirlo).
-- =====================================================================

ALTER TABLE citas ADD COLUMN IF NOT EXISTS recordatorio_en DATETIME DEFAULT NULL;
ALTER TABLE citas ADD INDEX IF NOT EXISTS idx_cita_recordatorio (consultorio_id, fecha, recordatorio_en);
