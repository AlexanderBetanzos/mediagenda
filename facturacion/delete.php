<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('facturacion');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM facturas WHERE id = ? AND consultorio_id = ?')->execute([$id, tenant_id()]);
    auditar('borrar', 'factura', $id);
    flash('Factura eliminada.');
}
redirect('/facturacion/index');
