-- =====================================================================
--  MediOS Agenda — Egresos (módulo Egresos e ingresos)
--  Ejecutar DESPUÉS de schema.sql y multitenant.sql.
--  Registra gastos del consultorio para calcular la utilidad real del mes
--  (ingresos de facturas pagadas − egresos).
--  Idempotente (IF NOT EXISTS). La app también crea esta tabla sola la
--  primera vez que se abre /egresos, así que este archivo es opcional.
-- =====================================================================

CREATE TABLE IF NOT EXISTS egresos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  fecha          DATE NOT NULL,
  categoria      VARCHAR(60) DEFAULT NULL,          -- Renta, Insumos, Sueldos, Servicios…
  concepto       VARCHAR(200) NOT NULL,
  monto          DECIMAL(10,2) NOT NULL DEFAULT 0,
  metodo_pago    VARCHAR(40) DEFAULT NULL,          -- Efectivo / Tarjeta / Transferencia / Otro
  usuario_id     INT DEFAULT NULL,                  -- quién lo registró
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_egr_tenant (consultorio_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo de categorías de egreso, editable por cada consultorio.
-- (El egreso guarda la categoría como texto, así que borrar una categoría
--  del catálogo no afecta egresos ya registrados.)
CREATE TABLE IF NOT EXISTS egreso_categorias (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(60) NOT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_egrcat_tenant (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
