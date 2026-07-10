<?php
/**
 * Elimina un presupuesto. Solo admin, y solo si no tiene abonos registrados:
 * un documento con dinero cobrado se cancela, no se borra.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('presupuestos');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if (!$id || !pertenece_al_tenant('presupuestos', $id)) {
    http_response_code(404);
    die('Presupuesto no encontrado.');
}

$st = db()->prepare('SELECT folio, estado FROM presupuestos WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$pre = $st->fetch();

if (presupuesto_pagado($id) > 0) {
    flash(t('Este presupuesto tiene abonos: cancélalo en lugar de eliminarlo.'), 'danger');
    redirect('/presupuestos/ver?id=' . $id);
}
if ($pre['estado'] === 'terminado') {
    flash(t('Un tratamiento terminado no se elimina.'), 'danger');
    redirect('/presupuestos/ver?id=' . $id);
}

// presupuesto_items cae por ON DELETE CASCADE.
db()->prepare('DELETE FROM presupuestos WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
auditar('borrar', 'presupuesto', $id, $pre['folio']);
flash(t('Presupuesto eliminado.'), 'warning');
redirect('/presupuestos/index');
