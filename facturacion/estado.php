<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$estado = $_POST['estado'] ?? '';
if ($id && in_array($estado, ['pendiente','pagada','cancelada'], true)) {
    db()->prepare('UPDATE facturas SET estado = ? WHERE id = ? AND consultorio_id = ?')->execute([$estado, $id, tenant_id()]);
    auditar('factura_estado', 'factura', $id, $estado);
    flash('Factura marcada como ' . $estado . '.');
}
redirect('/facturacion/index');
