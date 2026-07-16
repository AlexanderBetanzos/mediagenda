-- =====================================================================
--  MediOS  -  Pagos con Mercado Pago (suscripciones recurrentes)
--  Ejecutar DESPUÉS de multitenant.sql. Idempotente en MariaDB.
-- =====================================================================

-- Datos de la suscripción de cada consultorio.
ALTER TABLE consultorios ADD COLUMN IF NOT EXISTS mp_suscripcion_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE consultorios ADD COLUMN IF NOT EXISTS mp_estado         VARCHAR(30) DEFAULT NULL; -- pending/authorized/paused/cancelled
ALTER TABLE consultorios ADD COLUMN IF NOT EXISTS proximo_cobro     DATE        DEFAULT NULL;

-- Bitácora de eventos de pago (auditoría de webhooks).
CREATE TABLE IF NOT EXISTS pagos_log (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT DEFAULT NULL,
  tipo           VARCHAR(40)  DEFAULT NULL,     -- preapproval / payment
  referencia     VARCHAR(80)  DEFAULT NULL,     -- id del recurso en Mercado Pago
  estado         VARCHAR(40)  DEFAULT NULL,
  monto          DECIMAL(10,2) DEFAULT NULL,
  payload        TEXT         DEFAULT NULL,     -- JSON recibido (para depurar)
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pagos_consultorio (consultorio_id),
  INDEX idx_pagos_ref (referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
