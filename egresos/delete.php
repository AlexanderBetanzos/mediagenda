<?php
/** Elimina un egreso del consultorio activo. */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/egresos/index');
verify_csrf();

$id  = (int) ($_POST['id'] ?? 0);
$mes = $_POST['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');

if ($id > 0) {
    $del = db()->prepare('DELETE FROM egresos WHERE id = ? AND consultorio_id = ?');
    $del->execute([$id, tenant_id()]);
    if ($del->rowCount() > 0) {
        auditar('eliminar', 'egreso', $id);
        flash('Egreso eliminado.');
    }
}
redirect('/egresos/index?mes=' . $mes);
