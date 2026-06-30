-- =====================================================================
--  MediAgenda — Actualización de BD (todas las migraciones de features)
--  Idempotente: seguro de importar varias veces.
--  Orden importa: planes ANTES de crm. Importar en phpMyAdmin.
--  Generado: 2026-06-24
-- =====================================================================

-- ============ sql/archivos.sql ============
-- =====================================================================
--  MediAgenda  -  Archivos adjuntos del expediente
--  Ejecutar DESPUÉS de schema.sql y multitenant.sql.
--  Permite subir documentos (estudios, radiografías, PDFs, fotos…)
--  asociados a un paciente y, opcionalmente, a una consulta.
--  Idempotente en MariaDB (usa IF NOT EXISTS).
-- =====================================================================

CREATE TABLE IF NOT EXISTS archivos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id  INT NOT NULL DEFAULT 1,
  paciente_id     INT NOT NULL,
  consulta_id     INT DEFAULT NULL,                 -- opcional: liga a una consulta
  subido_por      INT DEFAULT NULL,                 -- usuario que lo subió
  nombre_original VARCHAR(255) NOT NULL,            -- nombre con el que llegó
  nombre_guardado VARCHAR(120) NOT NULL,            -- nombre aleatorio en disco
  mime            VARCHAR(120) DEFAULT NULL,
  tamano          INT NOT NULL DEFAULT 0,           -- bytes
  descripcion     VARCHAR(255) DEFAULT NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arch_paciente FOREIGN KEY (paciente_id)
      REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_arch_consulta FOREIGN KEY (consulta_id)
      REFERENCES consultas(id) ON DELETE SET NULL,
  CONSTRAINT fk_arch_usuario FOREIGN KEY (subido_por)
      REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_arch_paciente (paciente_id, creado_en),
  INDEX idx_arch_tenant   (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ sql/planes.sql ============
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
 ('cfdi',          'CFDI / SAT',               1, 16)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), fase=VALUES(fase), orden=VALUES(orden);

-- ---------------------------------------------------------------------------
--  Seed: módulos por plan (acumulativo)
-- ---------------------------------------------------------------------------
INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 -- Básico
 ('basico','pacientes'),('basico','citas'),('basico','expediente'),
 ('basico','recetas'),('basico','facturacion'),
 -- Profesional = Básico + comunicación/portal/reportes/especialidades
 ('profesional','pacientes'),('profesional','citas'),('profesional','expediente'),
 ('profesional','recetas'),('profesional','facturacion'),('profesional','reportes'),
 ('profesional','portal'),('profesional','whatsapp'),('profesional','telemedicina'),
 ('profesional','especialidades'),
 -- Clínica = todo
 ('clinica','pacientes'),('clinica','citas'),('clinica','expediente'),
 ('clinica','recetas'),('clinica','facturacion'),('clinica','reportes'),
 ('clinica','portal'),('clinica','whatsapp'),('clinica','telemedicina'),
 ('clinica','especialidades'),('clinica','farmacia'),('clinica','laboratorio'),
 ('clinica','multisucursal'),('clinica','ia'),('clinica','rh'),('clinica','cfdi')
ON DUPLICATE KEY UPDATE plan_clave=VALUES(plan_clave);

-- ---------------------------------------------------------------------------
--  Migración de planes antiguos al nuevo modelo
--    estandar -> profesional · premium/activa -> clinica
--  (el consultorio principal #1 quedó con plan 'activa' en multitenant.sql)
-- ---------------------------------------------------------------------------
UPDATE consultorios SET plan='profesional' WHERE plan='estandar';
UPDATE consultorios SET plan='clinica'     WHERE plan IN ('premium','activa');

-- ============ sql/seguridad.sql ============
-- =====================================================================
--  MediAgenda  -  Seguridad: auditoría + 2FA (TOTP)
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

-- ============ sql/portal.sql ============
-- =====================================================================
--  MediAgenda  -  Portal del paciente (acceso de pacientes)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
--  El paciente inicia sesión con su correo y una contraseña que le asigna
--  el consultorio. Sesión separada de la del personal.
-- =====================================================================

ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_password_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS portal_activo TINYINT(1) NOT NULL DEFAULT 0;

-- ============ sql/expediente.sql ============
-- =====================================================================
--  MediAgenda  -  Expediente inteligente (campos clínicos e identificación)
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
--  Amplía `pacientes` con datos alineados a la NOM-024 (expediente clínico).
-- =====================================================================

-- Identificación
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS curp        VARCHAR(18) DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS rfc         VARCHAR(13) DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS ine         VARCHAR(20) DEFAULT NULL;  -- clave de elector
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS tipo_sangre VARCHAR(5)  DEFAULT NULL;  -- O+, A-, etc.

-- Contacto de emergencia
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS contacto_nombre      VARCHAR(120) DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS contacto_telefono    VARCHAR(40)  DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS contacto_parentesco  VARCHAR(40)  DEFAULT NULL;

-- Antecedentes (alergias / antecedentes [personales] / notas ya existen)
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS antecedentes_familiares TEXT DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS cirugias                TEXT DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS vacunas                 TEXT DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS enf_cronicas            TEXT DEFAULT NULL;
ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS habitos                 TEXT DEFAULT NULL;

-- ============ sql/agenda.sql ============
-- =====================================================================
--  MediAgenda  -  Agenda pro: horarios por médico y bloqueos
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente en MariaDB.
-- =====================================================================

-- Horario laboral semanal por médico (puede haber varios tramos por día).
-- dia_semana: 0=domingo … 6=sábado (igual que PHP date('w') y JS getDay()).
CREATE TABLE IF NOT EXISTS medico_horarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  medico_id      INT NOT NULL,
  dia_semana     TINYINT NOT NULL,
  hora_inicio    TIME NOT NULL,
  hora_fin       TIME NOT NULL,
  CONSTRAINT fk_mh_medico FOREIGN KEY (medico_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_mh_medico (medico_id, dia_semana),
  INDEX idx_mh_tenant (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bloqueos de agenda (vacaciones, comidas, días festivos…).
-- medico_id NULL = bloqueo para todo el consultorio.
CREATE TABLE IF NOT EXISTS bloqueos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  medico_id      INT DEFAULT NULL,
  inicio         DATETIME NOT NULL,
  fin            DATETIME NOT NULL,
  motivo         VARCHAR(120) DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bloq_medico FOREIGN KEY (medico_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_bloq_tenant (consultorio_id, inicio),
  INDEX idx_bloq_medico (medico_id, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ sql/inventario.sql ============
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

-- ============ sql/crm.sql ============
-- =====================================================================
--  MediAgenda  -  CRM (seguimiento de pacientes)
--  Ejecutar DESPUÉS de planes.sql y schema/multitenant. Idempotente.
-- =====================================================================

-- Seguimientos / tareas de relación con el paciente.
CREATE TABLE IF NOT EXISTS seguimientos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  tipo           ENUM('llamada','mensaje','revision','otro') NOT NULL DEFAULT 'otro',
  titulo         VARCHAR(160) NOT NULL,
  fecha_objetivo DATE DEFAULT NULL,
  estado         ENUM('pendiente','hecho') NOT NULL DEFAULT 'pendiente',
  nota           TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completado_en  DATETIME DEFAULT NULL,
  CONSTRAINT fk_seg_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_seg_pendiente (consultorio_id, estado, fecha_objetivo),
  INDEX idx_seg_paciente (paciente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrar módulos de entitlements: 'crm' (todos los planes) y
-- 'plantillas' (Profesional+). Idempotente.
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('crm',        'CRM y seguimientos',     1, 17),
 ('plantillas', 'Plantillas de consulta', 1, 18)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), fase = VALUES(fase), orden = VALUES(orden);
INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('basico', 'crm'), ('profesional', 'crm'), ('clinica', 'crm'),
 ('profesional', 'plantillas'), ('clinica', 'plantillas')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);

-- ============ sql/recordatorios.sql ============
-- =====================================================================
--  MediAgenda  -  Recordatorios automáticos de cita
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Marca cuándo se envió el recordatorio a una cita (para no repetirlo).
-- =====================================================================

ALTER TABLE citas ADD COLUMN IF NOT EXISTS recordatorio_en DATETIME DEFAULT NULL;
ALTER TABLE citas ADD INDEX IF NOT EXISTS idx_cita_recordatorio (consultorio_id, fecha, recordatorio_en);

-- ============ sql/sala.sql ============
-- =====================================================================
--  MediAgenda  -  Sala de espera / flujo del día
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Agrega los estados operativos del día y marcas de tiempo para medir espera.
-- =====================================================================

ALTER TABLE citas MODIFY COLUMN estado
  ENUM('programada','confirmada','esperando','en_consulta','atendida','cancelada','no_asistio')
  NOT NULL DEFAULT 'programada';

ALTER TABLE citas ADD COLUMN IF NOT EXISTS checkin_en  DATETIME DEFAULT NULL;  -- llegó / check-in
ALTER TABLE citas ADD COLUMN IF NOT EXISTS atencion_en DATETIME DEFAULT NULL;  -- pasó a consulta

-- ============ sql/plantillas.sql ============
-- =====================================================================
--  MediAgenda  -  Plantillas de consulta
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  Formatos reutilizables que pre-llenan la nueva consulta del expediente.
-- =====================================================================

CREATE TABLE IF NOT EXISTS plantillas_consulta (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(120) NOT NULL,
  tipo           ENUM('general','medico','dental') NOT NULL DEFAULT 'general',
  motivo         VARCHAR(255) DEFAULT NULL,
  exploracion    TEXT DEFAULT NULL,
  diagnostico    TEXT DEFAULT NULL,
  tratamiento    TEXT DEFAULT NULL,
  receta         TEXT DEFAULT NULL,
  notas          TEXT DEFAULT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_plant_tenant (consultorio_id, tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ sql/especialidades.sql ============
-- =====================================================================
--  MediAgenda  -  Especialidades: odontograma
--  Ejecutar DESPUÉS de schema.sql/multitenant.sql. Idempotente.
--  El odontograma se guarda como JSON (mapa diente => estado) por paciente.
--  Las curvas de crecimiento NO necesitan tabla: usan consultas (peso/estatura)
--  + pacientes.fecha_nacimiento.
-- =====================================================================

CREATE TABLE IF NOT EXISTS odontogramas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  paciente_id    INT NOT NULL,
  datos          TEXT DEFAULT NULL,          -- JSON: { "18": "caries", "26": "obturado", ... }
  actualizado_por INT DEFAULT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_odo_paciente (paciente_id),
  CONSTRAINT fk_odo_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_odo_tenant (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ sql/feedback.sql ============
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
