-- =====================================================================
--  MediOS Agenda  -  Documentos clínicos (constancias, incapacidades, resúmenes)
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql y planes.sql. Idempotente.
--
--  El médico ya escribe estos papeles a mano en Word, con el membrete pegado y
--  los datos del paciente copiados. Aquí: se elige una plantilla, el sistema
--  resuelve los marcadores con los datos que YA tiene ({paciente}, {edad},
--  {diagnostico}…), el médico ajusta el texto y se imprime con el membrete
--  white-label del consultorio. El documento emitido queda en el expediente.
--
--  Dos tablas y no una: la PLANTILLA es del consultorio y se reutiliza; el
--  DOCUMENTO es de un paciente y guarda el texto YA RESUELTO. Si mañana se
--  edita la plantilla, lo que se le entregó al paciente no cambia — que es
--  justo lo que uno quiere de un papel firmado.
-- =====================================================================

-- 1) Plantillas de documento -------------------------------------------------
CREATE TABLE IF NOT EXISTS documento_plantillas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  nombre         VARCHAR(120) NOT NULL,          -- "Constancia de buena salud"
  cuerpo         TEXT NOT NULL,                  -- con marcadores {paciente}, {edad}…
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  orden          INT NOT NULL DEFAULT 0,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_docpl_tenant (consultorio_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Documentos emitidos -----------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  folio          VARCHAR(30) NOT NULL,
  paciente_id    INT NOT NULL,
  consulta_id    INT DEFAULT NULL,               -- opcional: liga a la consulta
  plantilla_id   INT DEFAULT NULL,               -- de dónde salió (informativo)
  medico_id      INT DEFAULT NULL,               -- quién lo firma
  titulo         VARCHAR(120) NOT NULL,
  cuerpo         TEXT NOT NULL,                  -- texto YA resuelto y editado
  fecha          DATE NOT NULL,
  creado_por     INT DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_doc_folio (consultorio_id, folio),
  CONSTRAINT fk_doc_paciente  FOREIGN KEY (paciente_id)  REFERENCES pacientes(id)            ON DELETE CASCADE,
  CONSTRAINT fk_doc_consulta  FOREIGN KEY (consulta_id)  REFERENCES consultas(id)            ON DELETE SET NULL,
  CONSTRAINT fk_doc_plantilla FOREIGN KEY (plantilla_id) REFERENCES documento_plantillas(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_medico    FOREIGN KEY (medico_id)    REFERENCES usuarios(id)             ON DELETE SET NULL,
  CONSTRAINT fk_doc_creador   FOREIGN KEY (creado_por)   REFERENCES usuarios(id)             ON DELETE SET NULL,
  INDEX idx_doc_paciente (paciente_id, fecha),
  INDEX idx_doc_tenant   (consultorio_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) El módulo --------------------------------------------------------------
--    Va con el expediente: un médico que lleva expediente necesita extender
--    constancias e incapacidades. Está en los tres planes.
INSERT INTO modulos (clave, nombre, fase, orden) VALUES
 ('documentos', 'Documentos clínicos', 1, 20)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO plan_modulos (plan_clave, modulo_clave) VALUES
 ('basico', 'documentos'), ('profesional', 'documentos'), ('clinica', 'documentos')
ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave);
