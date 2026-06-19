<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'recepcion');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM pacientes WHERE id = ?')->execute([$id]);
    flash('Paciente eliminado.');
}
redirect('/pacientes/index.php');
