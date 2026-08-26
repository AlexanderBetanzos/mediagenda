-- =====================================================================
--  MediOS  -  IA clínica (medidor de uso)
--  Ejecutar DESPUÉS de multitenant.sql y planes.sql. Idempotente.
--
--  La llave de la API es de la PLATAFORMA (plataforma_config), no del
--  consultorio: un médico no va a administrar una API key. La contrapartida es
--  que el consumo lo paga la plataforma, así que se mide por consultorio y mes
--  y hay tope. Sin tope, un solo cliente entusiasta se lleva el margen.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ia_uso (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT     NOT NULL,
  periodo        CHAR(7) NOT NULL,                 -- 'AAAA-MM'
  usos           INT     NOT NULL DEFAULT 0,       -- consultas procesadas
  tokens_in      BIGINT  NOT NULL DEFAULT 0,
  tokens_out     BIGINT  NOT NULL DEFAULT 0,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ia_uso (consultorio_id, periodo),
  INDEX idx_ia_periodo (periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El módulo ya está en el catálogo (planes.sql) y solo lo incluye Clínica.
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('ia', 'IA clínica', 3, 14)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('clinica', 'ia')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
