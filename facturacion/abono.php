<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$monto = round((float) str_replace(',', '', (string) ($_POST['monto'] ?? '0')), 2);
$metodo = in_array($_POST['metodo'] ?? '', ['Efectivo', 'Tarjeta', 'Transferencia', 'Otro'], true) ? $_POST['metodo'] : 'Efectivo';
$fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['fecha'] ?? '')) && $_POST['fecha'] <= date('Y-m-d') ? $_POST['fecha'] : date('Y-m-d');

if ($id && $monto > 0) {
    $saldo = registrar_abono($id, $monto, $metodo, $fecha, trim((string) ($_POST['notas'] ?? '')));
    flash($saldo > 0 ? t('Abono registrado. Saldo pendiente:') . ' ' . fmt_money($saldo) : t('Pago completo: el cobro quedó liquidado.'));
} else {
    flash(t('Escribe un monto mayor a cero.'), 'danger');
}
redirect('/facturacion/ver?id=' . $id);
