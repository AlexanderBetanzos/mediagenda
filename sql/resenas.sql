-- =====================================================================
--  MediOS  -  Reseñas de pacientes
--  Ejecutar DESPUÉS de schema.sql, multitenant.sql y agenda_online.sql.
--  Idempotente en MariaDB.
--
--  Una reseña SIEMPRE nace de una cita atendida: no hay formulario abierto en
--  la página pública. Eso es lo que las hace creíbles y lo que impide que la
--  competencia —o un bot— llene el perfil de estrellas falsas.
--
--  El paciente califica con un enlace de token, sin cuenta ni contraseña,
--  igual que para confirmar su cita. Pedirle que se registre para dejar una
--  reseña es la forma más segura de no recibir ninguna.
-- =====================================================================

CREATE TABLE IF NOT EXISTS resenas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  cita_id        INT NOT NULL,
  paciente_id    INT NOT NULL,
  medico_id      INT DEFAULT NULL,
  token          VARCHAR(32) NOT NULL,
  estrellas      TINYINT DEFAULT NULL,          -- 1 a 5. NULL = invitada, sin responder
  comentario     VARCHAR(1000) DEFAULT NULL,
  -- Se publica al responder. 'oculta' es SOLO para abuso (insultos, spam,
  -- reseña del médico equivocado): si se usa para tapar lo malo, el promedio
  -- deja de decir la verdad y la sección entera pierde su razón de ser.
  estado         ENUM('pendiente','publicada','oculta') NOT NULL DEFAULT 'pendiente',
  motivo_oculta  VARCHAR(160) DEFAULT NULL,     -- queda constancia de por qué
  respuesta      VARCHAR(600) DEFAULT NULL,     -- el consultorio contesta en público
  invitada_en    DATETIME DEFAULT NULL,
  respondida_en  DATETIME DEFAULT NULL,
  creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Una reseña por cita: sin esto, un enlace reenviado deja diez estrellas.
  UNIQUE KEY uq_resena_cita  (cita_id),
  UNIQUE KEY uq_resena_token (token),
  CONSTRAINT fk_res_cita     FOREIGN KEY (cita_id)     REFERENCES citas(id)     ON DELETE CASCADE,
  CONSTRAINT fk_res_paciente FOREIGN KEY (paciente_id) REFERENCES pacientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_medico   FOREIGN KEY (medico_id)   REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_res_tenant (consultorio_id, estado, respondida_en),
  INDEX idx_res_medico (medico_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
