-- =====================================================================
--  Módulo de Configuración (ajustes por instalación / consultorio)
--  Tabla clave-valor. Cada consultorio personaliza su copia del sistema.
--  Importar después de schema.sql:
--    mysql -u root consultorios_db < sql/configuracion.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS configuracion (
  clave          VARCHAR(60) PRIMARY KEY,
  valor          TEXT DEFAULT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores por defecto (no se sobreescriben si ya existen).
INSERT INTO configuracion (clave, valor) VALUES
  -- Marca / white-label
  ('marca_nombre',  'MediAgenda'),
  ('marca_lema',    'Gestión integral de consultorios'),
  ('marca_logo',    ''),
  -- Apariencia
  ('tema_default',  'light'),
  ('color_acento',  '#1f6b73'),
  -- Datos del consultorio (recetas / facturas)
  ('razon_social',  ''),
  ('direccion',     ''),
  ('telefono',      ''),
  ('email',         ''),
  ('rfc',           ''),
  -- Regional
  ('moneda',        'MXN'),
  ('zona_horaria',  'America/Mexico_City'),
  ('formato_fecha', 'd/m/Y')
ON DUPLICATE KEY UPDATE clave = clave;
