-- =====================================================================
--  MediOS Agenda  -  Foto del paciente guardada EN LA BASE DE DATOS
--  Ejecutar DESPUÉS de schema.sql y multitenant.sql. Idempotente.
--
--  Por qué: la foto vivía en uploads/pacientes/, que no estaba en .gitignore.
--  El despliegue (git clean) borraba los archivos sin rastrear en cada subida,
--  así que las fotos desaparecían solas. Los archivos del expediente sobrevivían
--  porque esos sí estaban ignorados. Guardándola en la base, la foto entra en los
--  respaldos y deja de depender de cómo se comporte el despliegue.
--
--  Los bytes van en su PROPIA tabla, no en una columna de `pacientes`: media
--  aplicación hace SELECT * FROM pacientes, y eso arrastraría el blob de cada
--  paciente en cada listado. En `pacientes` solo queda `foto_mime`, una marca
--  barata que dice "este paciente tiene foto" sin cargarla.
-- =====================================================================

-- 1) Los bytes de la foto, uno por paciente ---------------------------------
CREATE TABLE IF NOT EXISTS paciente_fotos (
  paciente_id    INT PRIMARY KEY,
  consultorio_id INT NOT NULL DEFAULT 1,
  mime           VARCHAR(40) NOT NULL,
  bytes          LONGBLOB    NOT NULL,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pfoto_paciente FOREIGN KEY (paciente_id)
      REFERENCES pacientes(id) ON DELETE CASCADE,
  INDEX idx_pfoto_tenant (consultorio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Marca en `pacientes`: "tiene foto y de qué tipo" ------------------------
--    MariaDB no siempre soporta ADD COLUMN IF NOT EXISTS, así que va condicional.
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pacientes'
                  AND COLUMN_NAME = 'foto_mime');
SET @sql := IF(@existe = 0,
  'ALTER TABLE pacientes ADD COLUMN foto_mime VARCHAR(40) DEFAULT NULL',
  'SELECT "pacientes.foto_mime ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) La columna vieja `foto` (ruta en disco) NO se borra --------------------
--    Se conserva para poder importar a la base las fotos que sigan en disco:
--    pacientes/foto.php las migra solo la primera vez que alguien las abre.
--    Una vez migradas todas, se puede limpiar con:
--      UPDATE pacientes SET foto = NULL WHERE foto_mime IS NOT NULL;
