-- =====================================================================
--  MediAgenda  -  Feedback / sugerencias de los usuarios del sistema
--  Ejecutar DESPUÉS de multitenant.sql. Idempotente.
--  Cualquier usuario (cualquier consultorio) puede dejar comentarios;
--  el súper-admin los ve todos para priorizar mejoras.
-- =====================================================================

CREATE TABLE IF NOT EXISTS feedback (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT DEFAULT NULL,
  usuario_id     INT DEFAULT NULL,
  usuario_nombre VARCHAR(120) DEFAULT NULL,   -- snapshot legible
  tipo           ENUM('sugerencia','problema','otro') NOT NULL DEFAULT 'sugerencia',
  mensaje        TEXT NOT NULL,
  url            VARCHAR(255) DEFAULT NULL,    -- página desde la que se envió
  estado         ENUM('nuevo','visto','resuelto') NOT NULL DEFAULT 'nuevo',
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fb_estado (estado, creado_en),
  INDEX idx_fb_consultorio (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
