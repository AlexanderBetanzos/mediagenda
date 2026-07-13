<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'recepcion');
require_modulo('pacientes');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM pacientes WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    auditar('borrar', 'paciente', $id);
    flash('Paciente eliminado.');
}
redirect('/pacientes/index');
