<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');
verify_csrf();

$id     = (int) ($_POST['id'] ?? 0);
$estado = $_POST['estado'] ?? '';
$validos = ['programada','confirmada','esperando','en_consulta','atendida','cancelada','no_asistio'];

if ($id && in_array($estado, $validos, true)) {
    db()->prepare('UPDATE citas SET estado = ? WHERE id = ? AND consultorio_id = ?')->execute([$estado, $id, tenant_id()]);
    // Cita atendida = ya hay algo que opinar. La invitación se crea aquí
    // para que el enlace exista sin que nadie tenga que acordarse de
    // generarlo: lo que depende de la memoria del mostrador no pasa.
    // Es idempotente, así que marcar dos veces no duplica nada.
    if ($estado === 'atendida') { resena_invitar($id); }
    flash('Estado de la cita actualizado a “' . estado_label($estado) . '”.');
}
redirect('/citas/index');
