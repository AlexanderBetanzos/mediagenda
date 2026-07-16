-- =====================================================================
--  MediOS  -  Pago en línea de la reserva de cita
--  Ejecutar DESPUÉS de sql/cobros.sql y sql/agenda_online.sql. Idempotente.
--
--  Liga un cobro a una cita: así, cuando el paciente paga su reserva en línea,
--  el webhook de Mercado Pago puede confirmar la cita solo. El precio de la cita
--  vive en `configuracion` (agenda_online_precio); 0 = no se cobra por reservar.
-- =====================================================================

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cobros' AND COLUMN_NAME = 'cita_id') = 0,
  'ALTER TABLE cobros
     ADD COLUMN cita_id INT DEFAULT NULL AFTER presupuesto_id,
     ADD CONSTRAINT fk_cobro_cita FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE SET NULL',
  'SELECT "cobros.cita_id ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Precio por omisión: 0 (no cobrar por reservar). Cada consultorio lo ajusta.
INSERT INTO configuracion (consultorio_id, clave, valor)
SELECT c.id, 'agenda_online_precio', '0' FROM consultorios c
ON DUPLICATE KEY UPDATE valor = valor;
