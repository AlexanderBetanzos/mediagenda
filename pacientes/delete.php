<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'recepcion');
require_modulo('pacientes');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
/* Un paciente con expediente clínico no se borra: la NOM-004-SSA3-2012 pide
   conservar el expediente al menos 5 años, y el borrado arrastraría en cascada
   sus consultas. Solo se eliminan registros sin historia (p. ej. duplicados). */
$clinico = 0;
foreach (['consultas', 'recetas', 'hosp_estancias'] as $tabla) {
    try {
        $q = db()->prepare("SELECT COUNT(*) FROM $tabla WHERE paciente_id = ?" . ($tabla === 'consultas' ? '' : ' AND consultorio_id = ?'));
        $q->execute($tabla === 'consultas' ? [$id] : [$id, tenant_id()]);
        $clinico += (int) $q->fetchColumn();
    } catch (Throwable $e) { /* tabla aún no creada */ }
}
if ($id && $clinico > 0 && pertenece_al_tenant('pacientes', $id)) {
    auditar('borrar_bloqueado', 'paciente', $id, 'Tiene expediente clínico');
    flash(t('No se puede eliminar: este paciente tiene expediente clínico (consultas, recetas u hospitalizaciones), que debe conservarse al menos 5 años.'), 'warning');
    redirect('/pacientes/ver?id=' . $id);
}
if ($id) {
    db()->prepare('DELETE FROM pacientes WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    auditar('borrar', 'paciente', $id);
    flash('Paciente eliminado.');
}
redirect('/pacientes/index');
