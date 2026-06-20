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
