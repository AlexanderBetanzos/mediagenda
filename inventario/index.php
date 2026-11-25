<?php
/** Inventario / Farmacia: catálogo con stock (por sucursal si las hay) y alertas. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventario.php';
require_login();
require_modulo('farmacia');
inv_asegurar();

$tid = tenant_id();
$q   = trim($_GET['q'] ?? '');

$porSuc = inv_por_sucursal();
$sucs   = $porSuc ? sucursales() : [];
$suc    = $porSuc ? sucursal_actual() : 0;          // 0 = todas

// Lo que había antes de dar de alta sucursales se asigna a una, una sola vez.
if ($porSuc && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'asignar' && has_role('admin', 'recepcion')) {
    verify_csrf();
    if ($dest = inv_sucursal_valida($_POST['sucursal_id'] ?? 0)) {
        $n = inv_asignar_sin_sucursal($dest);
        auditar('editar', 'inventario', null, "Existencias sin sucursal → " . inv_sucursal_nombre($dest));
        flash(sprintf(t('Listo: %d lotes ahora son de %s.'), $n, inv_sucursal_nombre($dest)));
    }
    redirect('/inventario/index');
}
$sinSuc = $porSuc ? inv_sin_sucursal() : 0;

$where  = ['p.consultorio_id = ?'];
$params = [$tid];
if ($q !== '') {
    $where[] = '(p.nombre LIKE ? OR p.sku LIKE ? OR p.categoria LIKE ?)';
    $like = "%$q%"; array_push($params, $like, $like, $like);
}
$joinSuc = $suc ? ' AND l.sucursal_id = ' . (int) $suc : '';

$sql = 'SELECT p.*,
               COALESCE(SUM(l.cantidad), 0) AS stock,
               MIN(CASE WHEN l.cantidad > 0 THEN l.caducidad END) AS prox_cad
        FROM productos p
        LEFT JOIN inventario_lotes l ON l.producto_id = p.id' . $joinSuc . '
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY p.id
        ORDER BY p.nombre';
$st = db()->prepare($sql);
$st->execute($params);
$productos = $st->fetchAll();
$desglose = ($porSuc && !$suc) ? inv_stock_por_sucursal() : [];

// Resumen de alertas.
$nBajo = $nCad = 0;
$limiteCad = date('Y-m-d', strtotime('+30 days'));
foreach ($productos as $p) {
    if ($p['stock_minimo'] > 0 && $p['stock'] <= $p['stock_minimo']) $nBajo++;
    if ($p['prox_cad'] && $p['prox_cad'] <= $limiteCad) $nCad++;
}

$titulo = t('Inventario');
$activo = 'inventario';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h1 class="h3 mb-0"><i class="bi bi-boxes text-brand"></i> <?= et('Inventario / Farmacia') ?></h1>
        <?php if ($porSuc): ?>
        <form class="d-inline"><?php if ($q !== '') echo '<input type="hidden" name="q" value="' . e($q) . '">'; ?>
            <select name="sucursal" class="form-select form-select-sm" style="width:auto;min-width:12rem" onchange="this.form.submit()">
                <option value="0"><?= et('Todas las sucursales') ?></option>
                <?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $suc === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=entrada" class="btn btn-outline-success"><i class="bi bi-box-arrow-in-down"></i> <?= et('Entrada') ?></a>
        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=salida" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up"></i> <?= et('Salida') ?></a>
        <?php if (count($sucs) > 1): ?>
        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=traspaso" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right"></i> <?= et('Traspaso') ?></a>
        <?php endif; ?>
        <?php if (has_role('admin', 'recepcion')): ?>
        <a href="<?= BASE_URL ?>/inventario/producto" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Producto') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php $__t = (int) tenant_id();
$__venta = 0.0; try { $__qv = db()->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = ? AND estado = 'pagada' AND fecha = CURDATE() AND notas = 'Venta en punto de venta'"); $__qv->execute([$__t]); $__venta = (float) $__qv->fetchColumn(); } catch (Throwable $e) {}
echo ui_stats([
    [t('Productos activos'), ui_cuenta("SELECT COUNT(*) FROM productos WHERE consultorio_id = ? AND activo = 1", [$__t]), 'bi-boxes', 'var(--brand)'],
    [t('Con stock bajo'), (int) $nBajo, 'bi-exclamation-triangle', '#ef4444', null, $nBajo > 0],
    [t('Por caducar (30 días)'), (int) $nCad, 'bi-calendar-x', '#f59e0b', null, $nCad > 0],
    [t('Vendido hoy'), fmt_money($__venta), 'bi-shop', '#16a34a', BASE_URL . '/pos/index'],
]); ?>

<?php if ($sinSuc > 0 && has_role('admin', 'recepcion')): ?>
<form method="post" class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
    <?= csrf_field() ?><input type="hidden" name="accion" value="asignar">
    <i class="bi bi-geo-alt"></i>
    <span><?= sprintf(et('Hay %d piezas de antes de tener sucursales. ¿En qué sucursal están?'), $sinSuc) ?></span>
    <select name="sucursal_id" class="form-select form-select-sm" style="width:auto" required>
        <?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-warning"><?= et('Asignar') ?></button>
</form>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-md-4">
        <input type="search" name="q" class="form-control" placeholder="<?= et('Buscar por nombre, código o categoría…') ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> <?= et('Buscar') ?></button>
        <a href="<?= BASE_URL ?>/inventario/index" class="btn btn-link"><?= et('Limpiar') ?></a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th><?= et('Producto') ?></th><th><?= et('Categoría') ?></th><th class="text-end"><?= et('Precio') ?></th><th class="text-center"><?= et('Stock') ?></th><th><?= et('Próx. caducidad') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr>
            </thead>
            <tbody>
            <?php if (!$productos): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin productos. Crea el primero.') ?></td></tr>
            <?php else: foreach ($productos as $p):
                $bajo = $p['stock_minimo'] > 0 && $p['stock'] <= $p['stock_minimo'];
                $cad  = $p['prox_cad'] && $p['prox_cad'] <= $limiteCad; ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($p['nombre']) ?></div>
                        <?php if ($p['sku']): ?><small class="text-muted"><?= e($p['sku']) ?></small><?php endif; ?>
                    </td>
                    <td class="small"><?= e($p['categoria'] ?: '—') ?></td>
                    <td class="text-end"><?= fmt_money($p['precio']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $bajo ? 'danger' : 'success' ?>"><?= (int) $p['stock'] ?> <?= e($p['unidad']) ?></span>
                        <?php if ($p['stock_minimo'] > 0): ?><div class="small text-muted"><?= et('mín.') ?> <?= (int) $p['stock_minimo'] ?></div><?php endif; ?>
                        <?php if ($desglose && !empty($desglose[(int) $p['id']])): ?>
                        <div class="small text-muted"><?php $pz = []; foreach ($desglose[(int) $p['id']] as $sid => $n) if ($n) $pz[] = e(inv_sucursal_nombre($sid ?: null)) . ': ' . (int) $n; echo implode(' · ', $pz); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small <?= $cad ? 'text-danger fw-semibold' : '' ?>"><?= $p['prox_cad'] ? fmt_fecha($p['prox_cad']) : '—' ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=salida&producto=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Salida') ?>"><i class="bi bi-dash-lg"></i></a>
                        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=entrada&producto=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success" title="<?= et('Entrada') ?>"><i class="bi bi-plus-lg"></i></a>
                        <?php if (has_role('admin', 'recepcion')): ?>
                        <a href="<?= BASE_URL ?>/inventario/producto?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= et('Editar') ?>"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
