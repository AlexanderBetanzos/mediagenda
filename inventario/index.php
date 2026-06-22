<?php
/** Inventario / Farmacia: catálogo con stock total y alertas. */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('farmacia');

$tid = tenant_id();
$q   = trim($_GET['q'] ?? '');

$where  = ['p.consultorio_id = ?'];
$params = [$tid];
if ($q !== '') {
    $where[] = '(p.nombre LIKE ? OR p.sku LIKE ? OR p.categoria LIKE ?)';
    $like = "%$q%"; array_push($params, $like, $like, $like);
}

$sql = 'SELECT p.*,
               COALESCE(SUM(l.cantidad), 0) AS stock,
               MIN(CASE WHEN l.cantidad > 0 THEN l.caducidad END) AS prox_cad
        FROM productos p
        LEFT JOIN inventario_lotes l ON l.producto_id = p.id
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY p.id
        ORDER BY p.nombre';
$st = db()->prepare($sql);
$st->execute($params);
$productos = $st->fetchAll();

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
    <h1 class="h3 mb-0"><i class="bi bi-box-seam text-brand"></i> <?= et('Inventario / Farmacia') ?></h1>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=entrada" class="btn btn-outline-success"><i class="bi bi-box-arrow-in-down"></i> <?= et('Entrada') ?></a>
        <a href="<?= BASE_URL ?>/inventario/movimiento?tipo=salida" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up"></i> <?= et('Salida') ?></a>
        <?php if (has_role('admin', 'recepcion')): ?>
        <a href="<?= BASE_URL ?>/inventario/producto" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Producto') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($nBajo || $nCad): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($nBajo): ?><span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> <?= $nBajo ?> <?= et('con stock bajo') ?></span><?php endif; ?>
    <?php if ($nCad): ?><span class="badge bg-warning text-dark"><i class="bi bi-calendar-x"></i> <?= $nCad ?> <?= et('por caducar (30 días)') ?></span><?php endif; ?>
</div>
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
