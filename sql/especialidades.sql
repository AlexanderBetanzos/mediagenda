-- =====================================================================
--  MediOS  -  Especialidades: odontograma
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
