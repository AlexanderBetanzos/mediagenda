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
