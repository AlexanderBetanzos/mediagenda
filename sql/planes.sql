-- =====================================================================
--  MediAgenda  -  Planes y entitlements (módulos por plan)
--  Ejecutar DESPUÉS de multitenant.sql. Idempotente en MariaDB.
--
--  Modelo: cada consultorio tiene un `plan`; el plan incluye un conjunto de
--  módulos (plan_modulos). consultorio_modulos permite activar/desactivar
--  módulos puntuales por consultorio (add-ons o cortesías). El código consulta
--  modulo_activo('clave') para mostrar/ocultar y gatear cada función.
-- =====================================================================

-- 1) Catálogo de planes (fuente de verdad de precios y presentación) --------
CREATE TABLE IF NOT EXISTS planes (
  clave        VARCHAR(30)  PRIMARY KEY,
  nombre       VARCHAR(60)  NOT NULL,
  precio       DECIMAL(10,2) NOT NULL DEFAULT 0,
  descripcion  VARCHAR(160) DEFAULT NULL,
  items        TEXT         DEFAULT NULL,         -- JSON: bullets de presentación
  destacado    TINYINT(1)   NOT NULL DEFAULT 0,   -- "Más popular"
  mp_plan_id   VARCHAR(64)  DEFAULT NULL,         -- id de plan en Mercado Pago (opcional)
  orden        INT          NOT NULL DEFAULT 0,
  activo       TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Catálogo de módulos ----------------------------------------------------
CREATE TABLE IF NOT EXISTS modulos (
  clave   VARCHAR(40) PRIMARY KEY,
  nombre  VARCHAR(80) NOT NULL,
  fase    TINYINT     NOT NULL DEFAULT 1,
  orden   INT         NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Qué módulos incluye cada plan ------------------------------------------
CREATE TABLE IF NOT EXISTS plan_modulos (
  plan_clave   VARCHAR(30) NOT NULL,
  modulo_clave VARCHAR(40) NOT NULL,
  PRIMARY KEY (plan_clave, modulo_clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Overrides por consultorio (add-ons / cortesías / bloqueos) -------------
CREATE TABLE IF NOT EXISTS consultorio_modulos (
  consultorio_id INT NOT NULL,
  modulo_clave   VARCHAR(40) NOT NULL,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (consultorio_id, modulo_clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  Seed: 3 planes
-- ---------------------------------------------------------------------------
INSERT INTO planes (clave, nombre, precio, descripcion, items, destacado, orden) VALUES
 ('basico', 'Básico', 299.00, 'Médico o consultorio pequeño',
  '["Pacientes y citas","Expediente clínico","Recetas","Facturación simple","Recordatorios por correo"]', 0, 1),
 ('profesional', 'Profesional', 599.00, 'Consultorio en crecimiento',
  '["Todo lo de Básico","WhatsApp y SMS","Portal del paciente","Telemedicina","Reportes y BI","Plantillas por especialidad"]', 1, 2),
 ('clinica', 'Clínica', 1199.00, 'Clínica / multi-sucursal',
  '["Todo lo de Profesional","Farmacia y POS","Laboratorio","Multi-sucursal","IA clínica","CFDI / SAT","Recursos Humanos"]', 0, 3)
ON DUPLICATE KEY UPDATE
  nombre=VALUES(nombre), precio=VALUES(precio), descripcion=VALUES(descripcion),
  items=VALUES(items), destacado=VALUES(destacado), orden=VALUES(orden);

-- ---------------------------------------------------------------------------
--  Seed: módulos (gateables). Los administrativos del núcleo (dashboard,
--  configuración, usuarios, pagos) NO se gatean: están siempre disponibles.
-- ---------------------------------------------------------------------------
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('pacientes',     'Pacientes',                1, 1),
 ('citas',         'Agenda y citas',           1, 2),
 ('expediente',    'Expediente clínico',       1, 3),
 ('recetas',       'Recetas',                  1, 4),
 ('facturacion',   'Facturación',              1, 5),
 ('reportes',      'Reportes y BI',            2, 6),
 ('portal',        'Portal del paciente',      1, 7),
 ('whatsapp',      'WhatsApp / SMS',           1, 8),
 ('telemedicina',  'Telemedicina',             2, 9),
 ('especialidades','Especialidades',           2, 10),
 ('farmacia',      'Farmacia y POS',           2, 11),
 ('laboratorio',   'Laboratorio',              4, 12),
 ('multisucursal', 'Multi-sucursal',           4, 13),
 ('ia',            'IA clínica',               3, 14),
 ('rh',            'Recursos Humanos',         4, 15),
 ('cfdi',          'CFDI / SAT',               1, 16),
 ('crm',           'CRM y seguimientos',       1, 17),
 ('plantillas',    'Plantillas de consulta',   1, 18)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), fase=VALUES(fase), orden=VALUES(orden);

-- ---------------------------------------------------------------------------
--  Seed: módulos por plan (acumulativo)
-- ---------------------------------------------------------------------------
INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 -- Básico (CRM incluido en todos los planes)
 ('basico','pacientes'),('basico','citas'),('basico','expediente'),
 ('basico','recetas'),('basico','facturacion'),('basico','crm'),
 -- Profesional = Básico + comunicación/portal/reportes/especialidades/plantillas
 ('profesional','pacientes'),('profesional','citas'),('profesional','expediente'),
 ('profesional','recetas'),('profesional','facturacion'),('profesional','reportes'),
 ('profesional','portal'),('profesional','whatsapp'),('profesional','telemedicina'),
 ('profesional','especialidades'),('profesional','crm'),('profesional','plantillas'),
 -- Clínica = todo
 ('clinica','pacientes'),('clinica','citas'),('clinica','expediente'),
 ('clinica','recetas'),('clinica','facturacion'),('clinica','reportes'),
 ('clinica','portal'),('clinica','whatsapp'),('clinica','telemedicina'),
 ('clinica','especialidades'),('clinica','farmacia'),('clinica','laboratorio'),
 ('clinica','multisucursal'),('clinica','ia'),('clinica','rh'),('clinica','cfdi'),
 ('clinica','crm'),('clinica','plantillas')
ON DUPLICATE KEY UPDATE plan_clave=VALUES(plan_clave);

-- ---------------------------------------------------------------------------
--  Migración de planes antiguos al nuevo modelo
--    estandar -> profesional · premium/activa -> clinica
--  (el consultorio principal #1 quedó con plan 'activa' en multitenant.sql)
-- ---------------------------------------------------------------------------
UPDATE consultorios SET plan='profesional' WHERE plan='estandar';
UPDATE consultorios SET plan='clinica'     WHERE plan IN ('premium','activa');
