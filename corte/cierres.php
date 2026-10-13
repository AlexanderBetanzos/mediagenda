<?php
/**
 * Historial de cierres de caja. El admin ve los de todos (con filtro por
 * persona); los demás, solo los suyos. Por mes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cortes.php';
require_login();
require_modulo('facturacion');
asegurar_cortes();

$u       = current_user();
$esAdmin = has_role('admin');
$pdo     = db();
$tid     = (int) tenant_id();

$mes = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');
$quien = $esAdmin ? (int) ($_GET['u'] ?? 0) : (int) $u['id'];

$sql = "SELECT * FROM cortes_caja WHERE consultorio_id = ? AND DATE_FORMAT(cerrado_en,'%Y-%m') = ?";
$p = [$tid, $mes];
if ($quien) { $sql .= ' AND usuario_id = ?'; $p[] = $quien; }
$st = $pdo->prepare($sql . ' ORDER BY cerrado_en DESC, id DESC');
$st->execute($p);
$cortes = $st->fetchAll();

$personas = [];
if ($esAdmin) {
    $q = $pdo->prepare('SELECT DISTINCT usuario_id, usuario_nombre FROM cortes_caja WHERE consultorio_id = ? ORDER BY usuario_nombre');
    $q->execute([$tid]);
    $personas = $q->fetchAll();
}

$sumCobrado = array_sum(array_column($cortes, 'total_cobrado'));
$sumDif     = array_sum(array_column($cortes, 'diferencia'));
$conDif     = count(array_filter($cortes, fn ($c) => abs((float) $c['diferencia']) >= 0.005));

$titulo = t('Historial de cierres');
$activo = 'corte';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial de cierres') ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/corte/index" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart"></i> <?= et('Reporte por fechas') ?></a>
        <a href="<?= BASE_URL ?>/corte/cerrar" class="btn btn-sm btn-primary"><i class="bi bi-lock-fill"></i> <?= et('Cerrar mi corte') ?></a>
    </div>
</div>

<form class="d-flex flex-wrap gap-2 mb-3" method="get">
    <input type="month" name="mes" value="<?= e($mes) ?>" class="form-control form-control-sm" style="max-width:180px">
    <?php if ($esAdmin): ?>
    <select name="u" class="form-select form-select-sm" style="max-width:220px">
        <option value="0"><?= et('Todas las personas') ?></option>
        <?php foreach ($personas as $pp): ?>
            <option value="<?= (int) $pp['usuario_id'] ?>" <?= $quien === (int) $pp['usuario_id'] ? 'selected' : '' ?>><?= e($pp['usuario_nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-secondary"><?= et('Ver') ?></button>
</form>

<div class="row g-3 mb-3">
    <div class="col-4"><div class="card"><div class="card-body py-2 text-center"><div class="fw-bold fs-5"><?= count($cortes) ?></div><div class="small text-muted"><?= et('Cierres') ?></div></div></div></div>
    <div class="col-4"><div class="card"><div class="card-body py-2 text-center"><div class="fw-bold fs-5"><?= fmt_money($sumCobrado) ?></div><div class="small text-muted"><?= et('Cobrado') ?></div></div></div></div>
    <div class="col-4"><div class="card"><div class="card-body py-2 text-center">
        <div class="fw-bold fs-5 <?= abs($sumDif) < 0.005 ? 'text-success' : ($sumDif < 0 ? 'text-danger' : 'text-warning') ?>"><?= fmt_money($sumDif) ?></div>
        <div class="small text-muted"><?= et('Diferencia neta') ?> · <?= $conDif ?> <?= et('con diferencia') ?></div></div></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th><?= et('Cerrado') ?></th><th><?= et('Quién') ?></th><th><?= et('Tipo') ?></th>
                <th class="text-end"><?= et('Cobrado') ?></th><th class="text-end"><?= et('Esperado') ?></th>
                <th class="text-end"><?= et('Contado') ?></th><th class="text-end"><?= et('Diferencia') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$cortes): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= et('Sin cierres en este mes.') ?></td></tr>
            <?php else: foreach ($cortes as $c): $d = (float) $c['diferencia']; ?>
                <tr>
                    <td class="small"><?= e(date('d/m/Y H:i', strtotime($c['cerrado_en']))) ?></td>
                    <td class="small fw-semibold"><?= e($c['usuario_nombre']) ?></td>
                    <td><span class="badge rounded-pill <?= $c['alcance'] === 'general' ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= $c['alcance'] === 'general' ? et('General') : et('Propio') ?></span></td>
                    <td class="text-end small"><?= fmt_money($c['total_cobrado']) ?> <span class="text-muted">· <?= (int) $c['num_cobros'] ?></span></td>
                    <td class="text-end small"><?= fmt_money($c['efectivo_esperado']) ?></td>
                    <td class="text-end small"><?= fmt_money($c['efectivo_contado']) ?></td>
                    <td class="text-end"><span class="badge rounded-pill <?= abs($d) < 0.005 ? 'text-bg-success' : ($d < 0 ? 'text-bg-danger' : 'text-bg-warning') ?>">
                        <?= abs($d) < 0.005 ? '✓' : fmt_money($d) ?></span></td>
                    <td class="text-end"><a href="<?= BASE_URL ?>/corte/cierre?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"><?= et('Ver') ?></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
