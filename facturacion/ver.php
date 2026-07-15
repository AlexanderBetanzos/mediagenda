<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT f.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.email, p.telefono, p.direccion
     FROM facturas f JOIN pacientes p ON p.id = f.paciente_id WHERE f.id = ? AND f.consultorio_id = ?'
);
$stmt->execute([$id, tenant_id()]);
$f = $stmt->fetch();
if (!$f) { http_response_code(404); die('Factura no encontrada.'); }

$items = db()->prepare('SELECT * FROM factura_items WHERE factura_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();
$badge = ['pendiente'=>'warning','pagada'=>'success','cancelada'=>'danger'][$f['estado']];

$titulo = t('Factura') . ' ' . $f['folio'];
$activo = 'facturacion';
include __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .app-navbar, .sidebar, .breadcrumb, .no-print, footer { display: none !important; }
    body, .fac-print { background:#fff !important; color:#000 !important; }
    main { width:100% !important; max-width:100% !important; flex:0 0 100% !important; }
    .fac-print .card { box-shadow:none !important; border:1px solid #999 !important; }
}
</style>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <nav aria-label="breadcrumb" class="mb-0"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/facturacion/index"><?= et('Facturación') ?></a></li>
        <li class="breadcrumb-item active"><?= e($f['folio']) ?></li>
    </ol></nav>
    <div>
        <?php if ($f['estado'] === 'pendiente'): ?>
        <form action="<?= BASE_URL ?>/facturacion/estado" method="post" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="estado" value="pagada">
            <button class="btn btn-success"><i class="bi bi-cash-coin"></i> <?= et('Marcar pagada') ?></button>
        </form>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
    </div>
</div>

<div class="fac-print">
<div class="card mx-auto" style="max-width:800px">
    <div class="card-body p-4" style="color:#1f2d3d;background:#fff">
        <div class="d-flex justify-content-between border-bottom pb-3 mb-3">
            <div>
                <h2 class="h4 mb-0" style="color:#1f6b73"><i class="bi bi-heart-pulse-fill"></i> <?= e(marca_nombre()) ?></h2>
                <small class="text-muted"><?= et('Comprobante de pago') ?></small>
            </div>
            <div class="text-end">
                <div class="h5 mb-0"><?= e($f['folio']) ?></div>
                <div><strong><?= et('Fecha') ?>:</strong> <?= fmt_fecha($f['fecha']) ?></div>
                <span class="badge bg-<?= $badge ?>"><?= et(ucfirst($f['estado'])) ?></span>
            </div>
        </div>

        <div class="mb-3">
            <strong><?= et('Cliente:') ?></strong> <?= e($f['pac_nombre'].' '.$f['pac_ape']) ?><br>
            <?php if ($f['telefono']): ?><small class="text-muted"><?= e($f['telefono']) ?></small> · <?php endif; ?>
            <?php if ($f['email']): ?><small class="text-muted"><?= e($f['email']) ?></small><?php endif; ?>
        </div>

        <table class="table table-bordered">
            <thead><tr><th><?= et('Descripción') ?></th><th class="text-center"><?= et('Cant.') ?></th><th class="text-end"><?= et('Precio') ?></th><th class="text-end"><?= et('Importe') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><?= e($it['descripcion']) ?></td>
                    <td class="text-center"><?= (int)$it['cantidad'] ?></td>
                    <td class="text-end"><?= fmt_money($it['precio']) ?></td>
                    <td class="text-end"><?= fmt_money($it['importe']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="3" class="text-end"><?= et('Subtotal') ?></td><td class="text-end"><?= fmt_money($f['subtotal']) ?></td></tr>
                <?php if ($f['descuento'] > 0): ?>
                <tr><td colspan="3" class="text-end"><?= et('Descuento') ?></td><td class="text-end">-<?= fmt_money($f['descuento']) ?></td></tr>
                <?php endif; ?>
                <tr class="fw-bold"><td colspan="3" class="text-end"><?= et('Total') ?> (<?= e(moneda()) ?>)</td><td class="text-end" style="color:#1f6b73"><?= fmt_money($f['total']) ?></td></tr>
            </tfoot>
        </table>

        <?php if ($f['metodo_pago']): ?><p><strong><?= et('Método de pago:') ?></strong> <?= e($f['metodo_pago']) ?></p><?php endif; ?>
        <?php if ($f['notas']): ?><p class="text-muted"><small><?= nl2br(e($f['notas'])) ?></small></p><?php endif; ?>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
