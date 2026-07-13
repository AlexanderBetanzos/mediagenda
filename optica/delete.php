<?php
/**
 * Elimina una orden de trabajo. Solo admin, y solo si no se ha cobrado nada ni
 * se entregó: un trabajo con dinero encima se cancela, no se borra (el anticipo
 * ya está en la caja y tiene que poder explicarse).
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('optica');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

$st = db()->prepare('SELECT folio, estado, anticipo FROM optica_trabajos WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$t = $st->fetch();
if (!$t) { http_response_code(404); die('Orden no encontrada.'); }

if ((float) $t['anticipo'] > 0) {
    flash(t('Esta orden ya tiene un anticipo cobrado: cancélala en lugar de eliminarla.'), 'danger');
    redirect('/optica/ver?id=' . $id);
}
if ($t['estado'] === 'entregado') {
    flash(t('Un trabajo ya entregado no se elimina.'), 'danger');
    redirect('/optica/ver?id=' . $id);
}

db()->prepare('DELETE FROM optica_trabajos WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
auditar('borrar', 'optica_trabajo', $id, $t['folio']);
flash(t('Orden de trabajo eliminada.'), 'warning');
redirect('/optica/index');
