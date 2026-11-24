-- =====================================================================
--  MediOS  -  Programa residencial y Admisiones. Referencia: todo se crea
--  solo al abrir los módulos (asegurar_hosp_residencial / asegurar_admisiones).
-- =====================================================================
CREATE TABLE IF NOT EXISTS hosp_cuartos (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL,
  nombre VARCHAR(80) NOT NULL, tipo VARCHAR(60) NULL,
  modalidad VARCHAR(12) NOT NULL DEFAULT 'noche',      -- noche | dia | hora | ninguna
  tarifa DECIMAL(10,2) NOT NULL DEFAULT 0, camas INT NOT NULL DEFAULT 1,
  descripcion VARCHAR(255) NULL, activo TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
  INDEX idx_hc (consultorio_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hosp_estancias
  ADD COLUMN IF NOT EXISTS cuarto_id INT NULL,
  ADD COLUMN IF NOT EXISTS modalidad VARCHAR(12) NULL,
  ADD COLUMN IF NOT EXISTS tarifa DECIMAL(10,2) NULL,              -- tarifa pactada (copia)
  ADD COLUMN IF NOT EXISTS factura_id INT NULL,                    -- cuenta del internado
  ADD COLUMN IF NOT EXISTS cargado_hasta DATE NULL,                -- último día ya cargado
  ADD COLUMN IF NOT EXISTS alta_estimada DATE NULL,
  ADD COLUMN IF NOT EXISTS responsable VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS responsable_tel VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS responsable_parentesco VARCHAR(60) NULL;
ALTER TABLE hosp_notas ADD COLUMN IF NOT EXISTS turno VARCHAR(12) NULL, ADD COLUMN IF NOT EXISTS tipo VARCHAR(16) NULL;

CREATE TABLE IF NOT EXISTS hosp_visitas (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL, estancia_id INT NOT NULL,
  fecha DATETIME NOT NULL, visitante VARCHAR(120) NOT NULL, parentesco VARCHAR(60) NULL,
  notas VARCHAR(255) NULL, registrado_por INT NULL, INDEX idx_hv (consultorio_id, estancia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hosp_actividades (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL, fecha DATE NOT NULL, hora TIME NULL,
  nombre VARCHAR(120) NOT NULL, tipo VARCHAR(40) NULL, responsable VARCHAR(120) NULL, notas VARCHAR(255) NULL,
  creado_por INT NULL, INDEX idx_hact (consultorio_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hosp_asistencias (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL, actividad_id INT NOT NULL, estancia_id INT NOT NULL,
  asistio TINYINT(1) NOT NULL DEFAULT 1, nota VARCHAR(255) NULL, registrado_por INT NULL,
  UNIQUE KEY uq_hasis (actividad_id, estancia_id), INDEX idx_hasis (consultorio_id, estancia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admisiones (
  id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL,
  paciente_nombre VARCHAR(160) NOT NULL, paciente_edad VARCHAR(20) NULL,
  solicitante VARCHAR(160) NULL, parentesco VARCHAR(60) NULL, telefono VARCHAR(40) NULL, email VARCHAR(150) NULL,
  situacion TEXT NULL, servicio VARCHAR(40) NULL, urgencia VARCHAR(20) NULL, preferencia VARCHAR(20) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'nuevo',          -- nuevo | contactado | valoracion | ingreso | no_procede
  notas TEXT NULL, paciente_id INT NULL, asignado_a INT NULL, origen VARCHAR(20) NOT NULL DEFAULT 'web',
  ip VARCHAR(45) NULL, creado_en DATETIME NOT NULL, actualizado_en DATETIME NULL,
  INDEX idx_adm (consultorio_id, estado, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
