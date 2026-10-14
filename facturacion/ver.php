<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/comprobante.php';
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

$abonos = abonos_de($id);
$abonado = (float) array_sum(array_column($abonos, 'monto'));
$total = (float) $f['total'];
// Una factura pagada de golpe (sin abonos) no tiene saldo.
$saldo = $f['estado'] === 'pagada' ? 0.0 : max(0, round($total - $abonado, 2));
$parcial = $f['estado'] === 'pendiente' && $abonado > 0;
[$etq, $col] = match (true) {
    $f['estado'] === 'cancelada' => ['Cancelado', '#dc2626'],
    $f['estado'] === 'pagada'    => ['Pagado', '#16a34a'],
    $parcial                     => ['Pago parcial', '#0891b2'],
    default                      => ['Pendiente', '#d97706'],
};

$titulo = t('Cobro') . ' ' . $f['folio'];
$activo = 'facturacion';
include __DIR__ . '/../includes/header.php';
echo comprobante_estilos();
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <nav aria-label="breadcrumb" class="mb-0"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/facturacion/index"><?= et('Facturación') ?></a></li>
        <li class="breadcrumb-item active"><?= e($f['folio']) ?></li>
    </ol></nav>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $f['paciente_id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-person"></i> <?= et('Ficha') ?></a>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
    </div>
</div>

<div class="row g-4 justify-content-center">
<div class="col-xl-8">
<div class="hoja-doc">
    <div class="franja"></div>
    <div class="cuerpo">
        <?= comprobante_membrete(t('Comprobante de pago'), $f['folio'], fmt_fecha($f['fecha']),
            '<span class="badge rounded-pill mt-1" style="background:' . $col . '">' . et($etq) . '</span>') ?>

        <div class="caja-suave mb-3 d-flex flex-wrap justify-content-between gap-2">
            <div><div class="doc-tipo"><?= et('Cliente') ?></div><strong><?= e($f['pac_nombre'] . ' ' . $f['pac_ape']) ?></strong>
                <div class="small text-muted"><?= e(implode(' · ', array_filter([$f['telefono'], $f['email']]))) ?></div></div>
            <?php if ($f['metodo_pago'] && $f['estado'] === 'pagada'): ?>
            <div class="text-sm-end"><div class="doc-tipo"><?= et('Método de pago') ?></div><strong><?= e($f['metodo_pago']) ?></strong></div>
            <?php endif; ?>
        </div>

        <table class="tabla-doc">
            <thead><tr><th class="text-start"><?= et('Descripción') ?></th><th class="text-center"><?= et('Cant.') ?></th><th class="text-end"><?= et('Precio') ?></th><th class="text-end"><?= et('Importe') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><?= e($it['descripcion']) ?></td><td class="text-center"><?= (int) $it['cantidad'] ?></td>
                    <td class="text-end"><?= fmt_money($it['precio']) ?></td><td class="text-end"><?= fmt_money($it['importe']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="d-flex justify-content-end mt-3">
            <table style="min-width:260px" class="small">
                <tr><td class="text-muted py-1"><?= et('Subtotal') ?></td><td class="text-end"><?= fmt_money($f['subtotal']) ?></td></tr>
                <?php if ($f['descuento'] > 0): ?><tr><td class="text-muted py-1"><?= et('Descuento') ?></td><td class="text-end">−<?= fmt_money($f['descuento']) ?></td></tr><?php endif; ?>
                <tr><td class="fw-bold pt-2 fs-6"><?= et('Total') ?> <span class="text-muted fw-normal">(<?= e(moneda()) ?>)</span></td><td class="text-end fw-bold fs-5 acento pt-2"><?= fmt_money($total) ?></td></tr>
                <?php if ($abonos): ?>
                <tr><td class="text-muted py-1"><?= et('Abonado') ?></td><td class="text-end text-success">−<?= fmt_money($abonado) ?></td></tr>
                <tr><td class="fw-bold py-1"><?= et('Saldo') ?></td><td class="text-end fw-bold <?= $saldo > 0 ? 'text-danger' : 'text-success' ?>"><?= fmt_money($saldo) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($abonos): ?>
        <div class="doc-tipo mt-4 mb-1"><?= et('Pagos recibidos') ?></div>
        <table class="tabla-doc">
            <thead><tr><th class="text-start"><?= et('Fecha') ?></th><th class="text-start"><?= et('Método') ?></th><th class="text-start no-print"><?= et('Recibió') ?></th><th class="text-end"><?= et('Monto') ?></th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($abonos) as $a): ?>
                <tr><td><?= fmt_fecha($a['fecha']) ?><?php if ($a['notas']): ?><div class="small text-muted"><?= e($a['notas']) ?></div><?php endif; ?></td>
                    <td><?= e($a['metodo'] ?: '—') ?></td><td class="no-print small text-muted"><?= e($a['quien'] ?? '') ?></td>
                    <td class="text-end"><?= fmt_money($a['monto']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($f['notas']): ?><p class="small text-muted mt-3 mb-0"><?= nl2br(e($f['notas'])) ?></p><?php endif; ?>

        <div class="pie-doc"><?= et('Comprobante interno de pago. No es un comprobante fiscal (CFDI).') ?></div>
    </div>
</div>
</div>

<?php if ($f['estado'] === 'pendiente'): ?>
<div class="col-xl-4 no-print" id="abonar">
    <div class="card">
        <div class="card-body">
            <h2 class="h6 mb-1"><i class="bi bi-plus-slash-minus text-primary"></i> <?= et('Registrar pago') ?></h2>
            <p class="small text-muted mb-3"><?= et('Puedes cobrar todo o solo una parte. Cada abono entra en tu cierre de caja de hoy.') ?></p>
            <div class="d-flex justify-content-between small mb-1"><span><?= et('Pagado') ?></span><span><?= fmt_money($abonado) ?> / <?= fmt_money($total) ?></span></div>
            <div class="progress mb-3" style="height:8px"><div class="progress-bar bg-success" style="width:<?= $total > 0 ? min(100, round($abonado / $total * 100)) : 0 ?>%"></div></div>
            <form action="<?= BASE_URL ?>/facturacion/abono" method="post">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
                <label class="form-label small fw-semibold"><?= et('Monto') ?></label>
                <div class="input-group mb-2"><span class="input-group-text">$</span>
                    <input type="number" name="monto" id="abMonto" class="form-control form-control-lg" step="0.01" min="0.01" max="<?= $saldo ?>" value="<?= $saldo ?>" required></div>
                <div class="d-flex gap-1 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-m="<?= $saldo ?>"><?= et('Todo el saldo') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" data-m="<?= round($saldo / 2, 2) ?>"><?= et('La mitad') ?></button>
                </div>
                <label class="form-label small fw-semibold"><?= et('Método') ?></label>
                <div class="btn-group w-100 mb-3 flex-wrap" role="group">
                    <?php foreach (['Efectivo' => 'bi-cash', 'Tarjeta' => 'bi-credit-card', 'Transferencia' => 'bi-bank', 'Otro' => 'bi-three-dots'] as $m => $ic): ?>
                    <input type="radio" class="btn-check" name="metodo" id="m<?= $m ?>" value="<?= $m ?>" <?= $m === 'Efectivo' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary btn-sm" for="m<?= $m ?>"><i class="bi <?= $ic ?>"></i> <?= et($m) ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label small fw-semibold"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
                    <div class="col-6"><label class="form-label small fw-semibold"><?= et('Nota') ?></label><input type="text" name="notas" class="form-control" maxlength="255" placeholder="<?= et('Opcional') ?>"></div>
                </div>
                <button class="btn btn-success w-100"><i class="bi bi-check2-circle"></i> <span id="abBtn"><?= et('Liquidar') ?></span></button>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var inp = document.getElementById('abMonto'), btn = document.getElementById('abBtn'), saldo = <?= json_encode($saldo) ?>;
    function upd() { btn.textContent = (parseFloat(inp.value) || 0) >= saldo - 0.004 ? <?= json_encode(t('Liquidar')) ?> : <?= json_encode(t('Registrar abono')) ?>; }
    document.querySelectorAll('[data-m]').forEach(function (b) { b.addEventListener('click', function () { inp.value = b.dataset.m; upd(); }); });
    inp.addEventListener('input', upd); upd();
})();
</script>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
