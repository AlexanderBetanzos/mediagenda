-- =====================================================================
--  MediAgenda  -  Catálogo de servicios + Presupuestos / planes de tratamiento
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql/planes.sql. Idempotente.
--
--  Flujo: el consultorio define su catálogo de `servicios` (con precio y
--  duración). Un `presupuesto` agrupa `presupuesto_items` (un procedimiento,
--  opcionalmente sobre una pieza dental y sus caras). Los cobros se registran
--  como abonos en `presupuesto_pagos`; el saldo es total - suma de abonos.
--
--  NO toca `facturas`: el presupuesto es un documento independiente. Si en el
--  futuro se factura un presupuesto, se liga por `presupuestos.factura_id`.
-- =====================================================================

-- 1) Catálogo de servicios / procedimientos ---------------------------------
CREATE TABLE IF NOT EXISTS servicios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(160) NOT NULL,
  codigo         VARCHAR(40)  DEFAULT NULL,        -- clave interna del consultorio
  categoria      VARCHAR(60)  DEFAULT NULL,        -- Preventivo, Endodoncia, Consulta…
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0,
  duracion_min   INT NOT NULL DEFAULT 30,          -- para pre-llenar la cita
  aplica_diente  TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = se cotiza por pieza dental
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_serv_tenant (consultorio_id, activo),
  INDEX idx_serv_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Presupuesto / plan de tratamiento (cabecera) ---------------------------
CREATE TABLE IF NOT EXISTS presupuestos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  folio          VARCHAR(30) NOT NULL,
  paciente_id    INT NOT NULL,
  medico_id      INT DEFAULT NULL,
  fecha          DATE NOT NULL,
  vigencia       DATE DEFAULT NULL,                -- hasta cuándo se respeta el precio
  estado         ENUM('borrador','propuesto','aceptado','terminado','rechazado','cancelado')
                 NOT NULL DEFAULT 'borrador',
  subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0,
  descuento      DECIMAL(10,2) NOT NULL DEFAULT 0,
  total          DECIMAL(10,2) NOT NULL DEFAULT 0,
  notas          TEXT DEFAULT NULL,
  aceptado_en    DATETIME DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pre_folio (consultorio_id, folio),
  CONSTRAINT fk_pre_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pre_medico   FOREIGN KEY (medico_id)   REFERENCES usuarios(id)  ON DELETE SET NULL,
  CONSTRAINT fk_pre_creador  FOREIGN KEY (creado_por)  REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_pre_tenant (consultorio_id, estado),
  INDEX idx_pre_paciente (paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Conceptos del presupuesto ----------------------------------------------
--    `diente` en notación FDI (11..48). `caras` = lista separada por comas
--    (O,M,D,V,L) para restauraciones parciales.
CREATE TABLE IF NOT EXISTS presupuesto_items (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  presupuesto_id INT NOT NULL,
  servicio_id    INT DEFAULT NULL,                 -- NULL = concepto libre
  descripcion    VARCHAR(200) NOT NULL,
  diente         VARCHAR(8)  DEFAULT NULL,
  caras          VARCHAR(40) DEFAULT NULL,
  cantidad       INT NOT NULL DEFAULT 1,
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0,
  importe        DECIMAL(10,2) NOT NULL DEFAULT 0,
  estado         ENUM('pendiente','realizado') NOT NULL DEFAULT 'pendiente',
  realizado_en   DATETIME DEFAULT NULL,
  realizado_por  INT DEFAULT NULL,
  orden          INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_preit_pre      FOREIGN KEY (presupuesto_id) REFERENCES presupuestos(id) ON DELETE CASCADE,
  CONSTRAINT fk_preit_servicio FOREIGN KEY (servicio_id)    REFERENCES servicios(id)    ON DELETE SET NULL,
  CONSTRAINT fk_preit_medico   FOREIGN KEY (realizado_por)  REFERENCES usuarios(id)     ON DELETE SET NULL,
  INDEX idx_preit_pre (presupuesto_id, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Abonos (libro de pagos del presupuesto) --------------------------------
CREATE TABLE IF NOT EXISTS presupuesto_pagos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  presupuesto_id INT NOT NULL,
  fecha          DATE NOT NULL,
  monto          DECIMAL(10,2) NOT NULL,
  metodo         VARCHAR(40)  DEFAULT NULL,        -- efectivo, tarjeta, transferencia…
  referencia     VARCHAR(80)  DEFAULT NULL,
  notas          VARCHAR(200) DEFAULT NULL,
  usuario_id     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_prepago_pre     FOREIGN KEY (presupuesto_id) REFERENCES presupuestos(id) ON DELETE CASCADE,
  CONSTRAINT fk_prepago_usuario FOREIGN KEY (usuario_id)     REFERENCES usuarios(id)     ON DELETE SET NULL,
  INDEX idx_prepago_pre (presupuesto_id, fecha),
  INDEX idx_prepago_tenant (consultorio_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) Entitlements: módulo nuevo, incluido en los tres planes ----------------
--    (el catálogo de servicios vive bajo el mismo módulo).
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('presupuestos', 'Presupuestos y planes de tratamiento', 1, 19)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), fase=VALUES(fase), orden=VALUES(orden);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('basico','presupuestos'), ('profesional','presupuestos'), ('clinica','presupuestos')
ON DUPLICATE KEY UPDATE plan_clave=VALUES(plan_clave);
