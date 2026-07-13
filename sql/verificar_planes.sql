-- =====================================================================
--  Verificación del gating por plan (solo lectura, no modifica nada).
--  Correr en producción después de desplegar. Las 4 consultas deben salir
--  como se indica; cualquier otra cosa es un consultorio con acceso mal dado.
-- =====================================================================

-- 1) Planes sin módulos mapeados --------------------------------------------
--    DEBE SALIR VACÍO. Un plan sin filas en plan_modulos hace que
--    modulos_activos() haga fail-open y le abra TODO al consultorio.
SELECT p.clave AS plan_sin_modulos, p.nombre, COUNT(pm.modulo_clave) AS modulos
FROM planes p
LEFT JOIN plan_modulos pm ON pm.plan_clave = p.clave
WHERE p.activo = 1
GROUP BY p.clave, p.nombre
HAVING modulos = 0;

-- 2) Consultorios con un plan que no existe en el catálogo -------------------
--    DEBE SALIR VACÍO. Mismo efecto: sin mapeo, acceso total.
SELECT c.id, c.nombre, c.plan AS plan_desconocido, c.estado
FROM consultorios c
LEFT JOIN planes p ON p.clave = c.plan
WHERE p.clave IS NULL;

-- 3) Módulos que el código exige pero que no están en el catálogo ------------
--    DEBE SALIR VACÍO. Si falta una clave aquí, require_modulo() la bloquea
--    para TODOS los planes (fail-closed): la función desaparece del panel.
--    Mantener esta lista alineada con las llamadas a require_modulo() del código.
SELECT clave AS modulo_que_el_codigo_usa_pero_no_existe
FROM (
  SELECT 'pacientes' AS clave UNION ALL SELECT 'citas'        UNION ALL
  SELECT 'expediente'         UNION ALL SELECT 'recetas'      UNION ALL
  SELECT 'facturacion'        UNION ALL SELECT 'reportes'     UNION ALL
  SELECT 'portal'             UNION ALL SELECT 'whatsapp'     UNION ALL
  SELECT 'especialidades'     UNION ALL SELECT 'farmacia'     UNION ALL
  SELECT 'crm'                UNION ALL SELECT 'plantillas'   UNION ALL
  SELECT 'presupuestos'
) AS usados
WHERE clave NOT IN (SELECT clave FROM modulos);

-- 4) Foto real: qué tiene contratado cada consultorio ------------------------
--    Revisar a ojo. Ojo con estado='trial': durante la prueba el código abre
--    TODO ('*'), sin importar lo que diga esta tabla.
SELECT c.id, c.nombre, c.plan, c.estado,
       GROUP_CONCAT(pm.modulo_clave ORDER BY pm.modulo_clave SEPARATOR ', ') AS modulos_del_plan
FROM consultorios c
LEFT JOIN plan_modulos pm ON pm.plan_clave = c.plan
GROUP BY c.id, c.nombre, c.plan, c.estado
ORDER BY c.id;

-- 5) Overrides por consultorio (cortesías y add-ons manuales) ----------------
--    Revisar a ojo: aquí es donde se cuela un módulo caro regalado sin querer.
SELECT cm.consultorio_id, c.nombre, c.plan, cm.modulo_clave,
       IF(cm.activo, 'ACTIVADO a mano', 'BLOQUEADO a mano') AS override
FROM consultorio_modulos cm
JOIN consultorios c ON c.id = cm.consultorio_id
ORDER BY cm.consultorio_id, cm.modulo_clave;
