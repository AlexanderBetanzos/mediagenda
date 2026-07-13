<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'recepcion');
require_modulo('citas');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM citas WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    flash('Cita eliminada.');
}
redirect('/citas/index');
