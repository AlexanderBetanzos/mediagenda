-- =====================================================================
--  MediOS Agenda  -  Multi-tenant (varios consultorios en una BD)
--  Ejecutar DESPUÉS de schema.sql, modulos.sql y configuracion.sql.
--  Modelo: aislamiento por fila con la columna `consultorio_id`.
--  Idempotente en MariaDB (usa IF NOT EXISTS).
-- =====================================================================

-- 1) Tabla de consultorios (tenants) -----------------------------------
CREATE TABLE IF NOT EXISTS consultorios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  slug          VARCHAR(60)  NOT NULL UNIQUE,        -- identificador legible
  email         VARCHAR(150) NOT NULL,               -- contacto del consultorio
  telefono      VARCHAR(40)  DEFAULT NULL,           -- teléfono de contacto
  plan          VARCHAR(20)  NOT NULL DEFAULT 'trial',
  estado        ENUM('trial','activa','suspendida','expirada') NOT NULL DEFAULT 'trial',
  trial_inicio  DATE NOT NULL,
  trial_fin     DATE NOT NULL,                        -- fin de la prueba (15 días)
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Consultorio #1 para los datos existentes (queda activo, sin caducar)
INSERT INTO consultorios (id, nombre, slug, email, plan, estado, trial_inicio, trial_fin)
VALUES (1, 'Consultorio principal', 'principal', 'admin@consultorio.com',
        'activa', 'activa', CURDATE(), '2099-12-31')
ON DUPLICATE KEY UPDATE id = id;

-- 3) Columna consultorio_id en cada tabla (backfill a 1) ----------------
ALTER TABLE usuarios   ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;
ALTER TABLE pacientes  ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;
ALTER TABLE citas      ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;
ALTER TABLE consultas  ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;
ALTER TABLE recetas    ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;
ALTER TABLE facturas   ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;
ALTER TABLE configuracion ADD COLUMN IF NOT EXISTS consultorio_id INT NOT NULL DEFAULT 1;

-- 4) Índices para filtrar rápido por consultorio -----------------------
ALTER TABLE usuarios   ADD INDEX IF NOT EXISTS idx_usuarios_tenant   (consultorio_id);
ALTER TABLE pacientes  ADD INDEX IF NOT EXISTS idx_pacientes_tenant  (consultorio_id);
ALTER TABLE citas      ADD INDEX IF NOT EXISTS idx_citas_tenant      (consultorio_id);
ALTER TABLE consultas  ADD INDEX IF NOT EXISTS idx_consultas_tenant  (consultorio_id);
ALTER TABLE recetas    ADD INDEX IF NOT EXISTS idx_recetas_tenant    (consultorio_id);
ALTER TABLE facturas   ADD INDEX IF NOT EXISTS idx_facturas_tenant   (consultorio_id);

-- 5) configuracion: clave única POR consultorio (PK compuesta) ---------
--    La PK actual es (clave); pasa a (consultorio_id, clave).
ALTER TABLE configuracion DROP PRIMARY KEY, ADD PRIMARY KEY (consultorio_id, clave);

-- Nota: el email de usuario se mantiene ÚNICO GLOBAL (índice `email`
-- existente). Así el login resuelve el consultorio a partir del email,
-- sin necesidad de subdominios.
