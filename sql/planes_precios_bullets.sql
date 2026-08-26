-- =====================================================================
--  MediOS  -  Precios y presentación de los planes (estado actual)
--
--  Archivo suelto y CHIQUITO a propósito: deja la tabla `planes` como debe
--  estar hoy, sin arrastrar las 700 líneas de actualizar_produccion.sql.
--  Idempotente: se puede correr las veces que haga falta.
--
--  Corre esto si en la landing ves precios o viñetas que no cuadran con lo
--  que el software hace de verdad.
-- =====================================================================

-- 1) Precios ----------------------------------------------------------------
UPDATE planes SET precio =  499.00 WHERE clave = 'basico';
UPDATE planes SET precio =  999.00 WHERE clave = 'profesional';
UPDATE planes SET precio = 1999.00 WHERE clave = 'clinica';

-- 2) Presentación -----------------------------------------------------------
--    Solo módulos con código detrás. Se quitaron de las tarjetas Telemedicina
--    y SMS (Profesional) y Multi-sucursal, IA clínica, CFDI/SAT y Recursos
--    Humanos (Clínica) porque no existían; entraron laboratorio, presupuestos,
--    agenda en línea, óptica, ultrasonido e inventario, que sí.
UPDATE planes SET
    descripcion = 'Un médico, todo bajo control',
    items = '["Pacientes y citas sin papeles","Expediente clínico protegido","Recetas con tu marca","Presupuestos y control de ingresos","Órdenes de laboratorio y resultados","Recordatorios de cita por correo"]'
  WHERE clave = 'basico';

UPDATE planes SET
    descripcion = 'El que eligen los consultorios que crecen',
    items = '["Todo lo de Básico","Portal del paciente 24/7","Agenda en línea: se agendan solos","Videoconsulta sin instalar nada","Avisos por WhatsApp en un clic","Reportes para decidir con números","Plantillas y módulos por especialidad"]'
  WHERE clave = 'profesional';

UPDATE planes SET
    descripcion = 'Cuando el consultorio ya es un negocio',
    items = '["Todo lo de Profesional","Farmacia y punto de venta","Inventario con alertas de stock","Ultrasonido con informe e imágenes"]'
  WHERE clave = 'clinica';

-- 3) Comprobación -----------------------------------------------------------
--    Deja ver de un vistazo cómo quedó cada tarjeta.
SELECT clave, precio, descripcion, items FROM planes ORDER BY orden;
