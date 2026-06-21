<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
verify_csrf();

$id     = (int) ($_POST['id'] ?? 0);
$estado = $_POST['estado'] ?? '';
$validos = ['programada','confirmada','esperando','en_consulta','atendida','cancelada','no_asistio'];

if ($id && in_array($estado, $validos, true)) {
    db()->prepare('UPDATE citas SET estado = ? WHERE id = ? AND consultorio_id = ?')->execute([$estado, $id, tenant_id()]);
    flash('Estado de la cita actualizado a “' . estado_label($estado) . '”.');
}
redirect('/citas/index');
