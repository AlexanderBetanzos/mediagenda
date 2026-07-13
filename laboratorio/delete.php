<?php
/**
 * Elimina una orden de laboratorio. Solo admin, y solo si no hay resultados
 * capturados ni archivos adjuntos: un resultado clínico ya emitido se cancela,
 * no se borra (queda en el expediente del paciente).
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('laboratorio');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

$st = db()->prepare('SELECT folio FROM lab_ordenes WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$o = $st->fetch();
if (!$o) { http_response_code(404); die('Orden no encontrada.'); }

$con = db()->prepare(
    "SELECT COUNT(*) FROM lab_orden_items
     WHERE orden_id = ? AND resultado IS NOT NULL AND resultado <> ''"
);
$con->execute([$id]);
if ((int) $con->fetchColumn() > 0) {
    flash(t('Esta orden ya tiene resultados capturados: cancélala en lugar de eliminarla.'), 'danger');
    redirect('/laboratorio/ver?id=' . $id);
}

$ar = db()->prepare('SELECT COUNT(*) FROM archivos WHERE lab_orden_id = ? AND consultorio_id = ?');
$ar->execute([$id, tenant_id()]);
if ((int) $ar->fetchColumn() > 0) {
    flash(t('Esta orden tiene archivos de resultado en el expediente: cancélala en lugar de eliminarla.'), 'danger');
    redirect('/laboratorio/ver?id=' . $id);
}

// lab_orden_items cae por ON DELETE CASCADE.
db()->prepare('DELETE FROM lab_ordenes WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
auditar('borrar', 'lab_orden', $id, $o['folio']);
flash(t('Orden de laboratorio eliminada.'), 'warning');
redirect('/laboratorio/index');
