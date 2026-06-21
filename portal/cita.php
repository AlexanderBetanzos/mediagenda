<?php
/**
 * Acciones del paciente sobre sus citas: reagendar o cancelar.
 * Solo sus propias citas, futuras y aún activas (programada/confirmada).
 */
require_once __DIR__ . '/../includes/functions.php';
require_paciente();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('/portal/index'); }
verify_csrf();

$pac    = current_paciente();
$pid    = $pac['id'];
$cid    = (int) ($_POST['cita_id'] ?? 0);
$accion = $_POST['accion'] ?? '';
$hoy    = date('Y-m-d');

// La cita debe ser del paciente, de su consultorio, futura y activa.
$st = db()->prepare(
    "SELECT * FROM citas WHERE id = ? AND paciente_id = ? AND consultorio_id = ?
       AND fecha >= ? AND estado IN ('programada','confirmada')"
);
$st->execute([$cid, $pid, tenant_id(), $hoy]);
$cita = $st->fetch();
if (!$cita) { flash('Esa cita ya no se puede modificar. Contacta a tu consultorio.', 'warning'); redirect('/portal/index'); }

if ($accion === 'cancelar') {
    db()->prepare("UPDATE citas SET estado='cancelada' WHERE id=? AND consultorio_id=?")->execute([$cid, tenant_id()]);
    auditar('portal_cancela', 'cita', $cid, null, tenant_id(), ['id' => $pid, 'nombre' => $pac['nombre'].' '.$pac['apellidos']]);
    flash('Tu cita fue cancelada.');
    redirect('/portal/index');
}

if ($accion === 'reagendar') {
    $fecha = $_POST['fecha'] ?? '';
    $hora  = $_POST['hora'] ?? '';
    $df = DateTime::createFromFormat('Y-m-d', $fecha);
    $okFecha = $df && $df->format('Y-m-d') === $fecha && $fecha >= $hoy;
    $okHora  = preg_match('/^\d{2}:\d{2}$/', $hora);
    if (!$okFecha || !$okHora) {
        flash('Elige una fecha (de hoy en adelante) y una hora válidas.', 'warning');
        redirect('/portal/index');
    }
    // Vuelve a 'programada' para que el consultorio la reconfirme; reinicia recordatorio.
    db()->prepare("UPDATE citas SET fecha=?, hora=?, estado='programada', recordatorio_en=NULL WHERE id=? AND consultorio_id=?")
        ->execute([$fecha, $hora . ':00', $cid, tenant_id()]);
    auditar('portal_reagenda', 'cita', $cid, "$fecha $hora", tenant_id(), ['id' => $pid, 'nombre' => $pac['nombre'].' '.$pac['apellidos']]);
    flash('Tu cita fue reagendada. El consultorio la confirmará.');
    redirect('/portal/index');
}

redirect('/portal/index');
