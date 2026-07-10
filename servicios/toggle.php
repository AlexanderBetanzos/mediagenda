<?php
/** Activa / desactiva un servicio del catálogo (nunca se borra: hay presupuestos que lo citan). */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('presupuestos');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id && pertenece_al_tenant('servicios', $id)) {
    db()->prepare('UPDATE servicios SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')
        ->execute([$id, tenant_id()]);
    auditar('servicio_toggle', 'servicio', $id);
    flash('Estado del servicio actualizado.');
}
redirect('/servicios/index');
