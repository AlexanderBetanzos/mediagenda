-- =====================================================================
--  MediOS Agenda  -  Seguridad: auditoría + 2FA (TOTP)
--  Ejecutar DESPUÉS de multitenant.sql. Idempotente en MariaDB.
-- =====================================================================

-- 1) Bitácora de auditoría --------------------------------------------------
--    Sin FK a usuarios: conservamos el registro aunque el usuario se borre
--    (guardamos un snapshot del nombre). Una fila por evento relevante.
CREATE TABLE IF NOT EXISTS auditoria (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT          DEFAULT NULL,
  usuario_id     INT          DEFAULT NULL,
  usuario_nombre VARCHAR(120) DEFAULT NULL,   -- snapshot legible
  accion         VARCHAR(60)  NOT NULL,        -- login, logout, crear, editar, borrar, 2fa_activar…
  entidad        VARCHAR(40)  DEFAULT NULL,    -- paciente, consulta, archivo, receta…
  entidad_id     INT          DEFAULT NULL,
  detalle        VARCHAR(255) DEFAULT NULL,
  ip             VARCHAR(45)  DEFAULT NULL,    -- IPv4/IPv6
  user_agent     VARCHAR(255) DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aud_tenant  (consultorio_id, creado_en),
  INDEX idx_aud_usuario (usuario_id),
  INDEX idx_aud_entidad (entidad, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Doble factor (TOTP) por usuario ----------------------------------------
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS twofa_secret VARCHAR(64) DEFAULT NULL;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS twofa_activo TINYINT(1) NOT NULL DEFAULT 0;
