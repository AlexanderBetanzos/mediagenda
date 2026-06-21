-- =====================================================================
--  MediAgenda  -  Inventario / Farmacia
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
--  Stock por LOTES (con caducidad). El total de un producto = suma de lotes.
--  Cada cambio de stock deja un movimiento (entrada/salida/ajuste).
-- =====================================================================

-- Catálogo de productos / insumos / medicamentos
CREATE TABLE IF NOT EXISTS productos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(160) NOT NULL,
  sku            VARCHAR(60)  DEFAULT NULL,        -- código / código de barras
  categoria      VARCHAR(60)  DEFAULT NULL,
  unidad         VARCHAR(30)  NOT NULL DEFAULT 'pieza',
  precio         DECIMAL(10,2) NOT NULL DEFAULT 0, -- precio de venta
  stock_minimo   INT NOT NULL DEFAULT 0,           -- para alertas
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_prod_tenant (consultorio_id),
  INDEX idx_prod_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lotes (existencias). caducidad NULL = sin caducidad.
CREATE TABLE IF NOT EXISTS inventario_lotes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  producto_id    INT NOT NULL,
  lote           VARCHAR(60) DEFAULT NULL,
  caducidad      DATE        DEFAULT NULL,
  cantidad       INT NOT NULL DEFAULT 0,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_lote_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  INDEX idx_lote_producto (producto_id, caducidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Movimientos (bitácora de stock)
CREATE TABLE IF NOT EXISTS inventario_movimientos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  producto_id    INT NOT NULL,
  tipo           ENUM('entrada','salida','ajuste') NOT NULL,
  cantidad       INT NOT NULL,                     -- positivo siempre; el tipo da el signo
  motivo         VARCHAR(160) DEFAULT NULL,
  proveedor      VARCHAR(120) DEFAULT NULL,
  costo          DECIMAL(10,2) DEFAULT NULL,
  usuario_id     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mov_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  INDEX idx_mov_producto (producto_id, creado_en),
  INDEX idx_mov_tenant (consultorio_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
