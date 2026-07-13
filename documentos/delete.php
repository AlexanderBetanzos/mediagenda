<?php
/**
 * Elimina un documento emitido. Solo admin.
 * Queda en la auditoría: un papel que se le entregó a un paciente y luego
 * desaparece del expediente tiene que poder rastrearse.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('documentos');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

$st = db()->prepare('SELECT folio, titulo, paciente_id FROM documentos WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$d = $st->fetch();
if (!$d) { http_response_code(404); die('Documento no encontrado.'); }

db()->prepare('DELETE FROM documentos WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
auditar('borrar', 'documento', $id, $d['folio'] . ' · ' . $d['titulo']);
flash(t('Documento eliminado.'), 'warning');
redirect('/documentos/index');
