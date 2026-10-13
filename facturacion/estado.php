<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
$estado = $_POST['estado'] ?? '';
if ($id && in_array($estado, ['pendiente','pagada','cancelada'], true)) {
    db()->prepare('UPDATE facturas SET estado = ? WHERE id = ? AND consultorio_id = ?')->execute([$estado, $id, tenant_id()]);
    /* Cierre de caja: pagada → cuenta para quien la marcó; si deja de estar
       pagada, sale del cierre pendiente (un cierre ya hecho guarda sus totales). */
    require_once __DIR__ . '/../includes/cortes.php';
    asegurar_cortes();
    if ($estado === 'pagada') {
        registrar_cobro($id);
    } else {
        db()->prepare('UPDATE facturas SET cobrado_por = NULL, cobrado_en = NULL WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    }
    auditar('factura_estado', 'factura', $id, $estado);
    flash('Factura marcada como ' . $estado . '.');
}
redirect('/facturacion/index');
