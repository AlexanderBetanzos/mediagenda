<?php
/**
 * Elimina un estudio de ultrasonido. Solo admin, y solo si no hay informe ni
 * imágenes: un estudio ya informado se cancela, no se borra (queda en el
 * expediente del paciente).
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('imagenologia');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

$st = db()->prepare('SELECT folio, impresion FROM img_estudios WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$o = $st->fetch();
if (!$o) { http_response_code(404); die('Estudio no encontrado.'); }

if (trim((string) $o['impresion']) !== '') {
    flash(t('Este estudio ya tiene impresión diagnóstica: cancélalo en lugar de eliminarlo.'), 'danger');
    redirect('/imagenologia/ver?id=' . $id);
}

$hl = db()->prepare("SELECT COUNT(*) FROM img_hallazgos WHERE estudio_id = ? AND valor IS NOT NULL AND valor <> ''");
$hl->execute([$id]);
if ((int) $hl->fetchColumn() > 0) {
    flash(t('Este estudio ya tiene mediciones capturadas: cancélalo en lugar de eliminarlo.'), 'danger');
    redirect('/imagenologia/ver?id=' . $id);
}

$ar = db()->prepare('SELECT COUNT(*) FROM archivos WHERE img_estudio_id = ? AND consultorio_id = ?');
$ar->execute([$id, tenant_id()]);
if ((int) $ar->fetchColumn() > 0) {
    flash(t('Este estudio tiene imágenes en el expediente: cancélalo en lugar de eliminarlo.'), 'danger');
    redirect('/imagenologia/ver?id=' . $id);
}

// img_hallazgos cae por ON DELETE CASCADE.
db()->prepare('DELETE FROM img_estudios WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
auditar('borrar', 'img_estudio', $id, $o['folio']);
flash(t('Estudio de ultrasonido eliminado.'), 'warning');
redirect('/imagenologia/index');
