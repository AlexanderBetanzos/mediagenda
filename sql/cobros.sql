-- =====================================================================
--  MediAgenda  -  Cobros en línea (link de pago al paciente)
--  Ejecutar DESPUÉS de presupuestos.sql. Idempotente.
--
--  Un cobro es una petición de pago con un token público: el paciente abre
--  /pago/index?t=TOKEN, paga con la cuenta de Mercado Pago DEL CONSULTORIO, y
--  el webhook marca el cobro como pagado. Si el cobro venía de un presupuesto,
--  además se registra el abono en `presupuesto_pagos`.
--
--  El token es la única credencial del enlace: es aleatorio de 128 bits y no
--  expone ids internos.
-- =====================================================================

CREATE TABLE IF NOT EXISTS cobros (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id   INT NOT NULL DEFAULT 1,
  paciente_id      INT NOT NULL,
  presupuesto_id   INT DEFAULT NULL,
  token            CHAR(32) NOT NULL,
  concepto         VARCHAR(160) NOT NULL,
  monto            DECIMAL(10,2) NOT NULL,
  estado           ENUM('pendiente','pagado','cancelado') NOT NULL DEFAULT 'pendiente',
  mp_preference_id VARCHAR(64) DEFAULT NULL,
  mp_payment_id    VARCHAR(64) DEFAULT NULL,
  mp_init_point    VARCHAR(255) DEFAULT NULL,   -- url de checkout, se reutiliza
  creado_por       INT DEFAULT NULL,
  creado_en        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en        DATETIME DEFAULT NULL,
  UNIQUE KEY uq_cobro_token (token),
  CONSTRAINT fk_cobro_paciente    FOREIGN KEY (paciente_id)    REFERENCES pacientes(id)    ON DELETE CASCADE,
  CONSTRAINT fk_cobro_presupuesto FOREIGN KEY (presupuesto_id) REFERENCES presupuestos(id) ON DELETE SET NULL,
  CONSTRAINT fk_cobro_creador     FOREIGN KEY (creado_por)     REFERENCES usuarios(id)     ON DELETE SET NULL,
  INDEX idx_cobro_tenant (consultorio_id, estado),
  INDEX idx_cobro_presupuesto (presupuesto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El abono registrado por el webhook no tiene usuario: lo generó Mercado Pago.
ALTER TABLE presupuesto_pagos ADD COLUMN IF NOT EXISTS cobro_id INT DEFAULT NULL;
