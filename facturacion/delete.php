<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    db()->prepare('DELETE FROM facturas WHERE id = ?')->execute([$id]);
    flash('Factura eliminada.');
}
redirect('/facturacion/index.php');
