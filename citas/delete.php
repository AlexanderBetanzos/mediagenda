<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'recepcion');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM citas WHERE id = ?')->execute([$id]);
    flash('Cita eliminada.');
}
redirect('/citas/index.php');
