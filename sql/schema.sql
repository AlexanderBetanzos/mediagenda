-- =====================================================================
--  MediAgenda  -  Esquema de base de datos
--  Motor: MariaDB / MySQL  -  Codificación: utf8mb4
-- =====================================================================
--  Importa este archivo DENTRO de la base de datos ya seleccionada.
--  - Local (XAMPP): mysql -u root consultorios_db < sql/schema.sql
--  - Hosting compartido: en phpMyAdmin, selecciona tu BD y usa "Importar".
-- =====================================================================

-- Limpieza (orden inverso por las claves foráneas)
DROP TABLE IF EXISTS consultas;
DROP TABLE IF EXISTS citas;
DROP TABLE IF EXISTS pacientes;
DROP TABLE IF EXISTS usuarios;

-- ---------------------------------------------------------------------
--  Usuarios del sistema (personal)
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  rol           ENUM('admin','medico','recepcion') NOT NULL DEFAULT 'recepcion',
  especialidad  VARCHAR(120) DEFAULT NULL,      -- solo médicos/dentistas
  telefono      VARCHAR(40)  DEFAULT NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Pacientes
-- ---------------------------------------------------------------------
CREATE TABLE pacientes (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(120) NOT NULL,
  apellidos        VARCHAR(120) NOT NULL,
  fecha_nacimiento DATE DEFAULT NULL,
  sexo             ENUM('M','F','O') DEFAULT NULL,
  telefono         VARCHAR(40)  DEFAULT NULL,
  email            VARCHAR(150) DEFAULT NULL,
  direccion        VARCHAR(255) DEFAULT NULL,
  tipo             ENUM('medico','dental') NOT NULL DEFAULT 'medico',
  alergias         TEXT DEFAULT NULL,
  antecedentes     TEXT DEFAULT NULL,
  notas            TEXT DEFAULT NULL,
  creado_en        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pac_nombre (apellidos, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Citas
-- ---------------------------------------------------------------------
CREATE TABLE citas (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id INT NOT NULL,
  medico_id   INT NOT NULL,
  fecha       DATE NOT NULL,
  hora        TIME NOT NULL,
  duracion    INT NOT NULL DEFAULT 30,           -- minutos
  tipo        ENUM('medica','dental') NOT NULL DEFAULT 'medica',
  motivo      VARCHAR(255) DEFAULT NULL,
  estado      ENUM('programada','confirmada','atendida','cancelada','no_asistio')
              NOT NULL DEFAULT 'programada',
  notas       TEXT DEFAULT NULL,
  creado_en   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cita_paciente FOREIGN KEY (paciente_id)
      REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cita_medico FOREIGN KEY (medico_id)
      REFERENCES usuarios(id) ON DELETE RESTRICT,
  INDEX idx_cita_fecha (fecha, hora),
  INDEX idx_cita_medico (medico_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Consultas / Expediente clínico (una entrada por atención)
-- ---------------------------------------------------------------------
CREATE TABLE consultas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id     INT NOT NULL,
  medico_id       INT NOT NULL,
  cita_id         INT DEFAULT NULL,
  fecha           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  motivo          VARCHAR(255) DEFAULT NULL,
  exploracion     TEXT DEFAULT NULL,
  diagnostico     TEXT DEFAULT NULL,
  tratamiento     TEXT DEFAULT NULL,
  receta          TEXT DEFAULT NULL,
  -- signos vitales (opcionales)
  peso            DECIMAL(5,2) DEFAULT NULL,     -- kg
  estatura        DECIMAL(5,2) DEFAULT NULL,     -- cm
  presion         VARCHAR(20)  DEFAULT NULL,     -- ej. 120/80
  temperatura     DECIMAL(4,1) DEFAULT NULL,     -- °C
  notas           TEXT DEFAULT NULL,
  creado_en       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cons_paciente FOREIGN KEY (paciente_id)
      REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cons_medico FOREIGN KEY (medico_id)
      REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cons_cita FOREIGN KEY (cita_id)
      REFERENCES citas(id) ON DELETE SET NULL,
  INDEX idx_cons_paciente (paciente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  Datos de ejemplo (seed)
--  Contraseña de TODOS los usuarios demo: "password"
--  (hash bcrypt de "password")
-- =====================================================================
INSERT INTO usuarios (nombre, email, password_hash, rol, especialidad, telefono) VALUES
('Administrador',        'admin@consultorio.com',  '$2y$10$IHxillhqk1HlPp2l95kWK.ZxSvcXESW4uHEH0WPuk/7JWIzQVwlfy', 'admin',     NULL,             '555-0100'),
('Dra. Laura Méndez',    'laura@consultorio.com',  '$2y$10$IHxillhqk1HlPp2l95kWK.ZxSvcXESW4uHEH0WPuk/7JWIzQVwlfy', 'medico',    'Medicina General','555-0101'),
('Dr. Carlos Ruiz',      'carlos@consultorio.com', '$2y$10$IHxillhqk1HlPp2l95kWK.ZxSvcXESW4uHEH0WPuk/7JWIzQVwlfy', 'medico',    'Odontología',     '555-0102'),
('Recepción',            'recepcion@consultorio.com','$2y$10$IHxillhqk1HlPp2l95kWK.ZxSvcXESW4uHEH0WPuk/7JWIzQVwlfy','recepcion', NULL,             '555-0103');

INSERT INTO pacientes (nombre, apellidos, fecha_nacimiento, sexo, telefono, email, tipo, alergias) VALUES
('María',  'García López',   '1990-05-12', 'F', '555-1001', 'maria@example.com',  'medico', 'Penicilina'),
('Juan',   'Pérez Soto',     '1985-11-03', 'M', '555-1002', 'juan@example.com',   'dental', NULL),
('Ana',    'Torres Díaz',    '2001-02-28', 'F', '555-1003', 'ana@example.com',    'medico', NULL);
