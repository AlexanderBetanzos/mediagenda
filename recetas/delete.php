<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('medico', 'admin');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM recetas WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    flash('Receta eliminada.');
}
redirect('/recetas/index.php');
