<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
// No permitir desactivarse a uno mismo.
if ($id && $id !== current_user()['id']) {
    db()->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    flash('Estado del usuario actualizado.');
}
redirect('/usuarios/index');
