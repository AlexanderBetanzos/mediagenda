<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');

$estado = $_GET['estado'] ?? '';
$params = [tenant_id()];
$sql = "SELECT f.*, " . sql_saldo_factura() . " AS saldo, p.nombre AS pac_nombre, p.apellidos AS pac_ape, COALESCE(p.foto_mime, p.foto) AS pac_foto
        FROM facturas f JOIN pacientes p ON p.id = f.paciente_id WHERE f.consultorio_id = ?";
if (in_array($estado, ['pendiente','pagada','cancelada'], true)) {
    $sql .= " AND f.estado = ?"; $params[] = $estado;
}
$sql .= " ORDER BY f.fecha DESC, f.id DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$facturas = $stmt->fetchAll();

// Totales (del consultorio activo)
$tp = db()->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='pagada' AND consultorio_id = ?");
$tp->execute([tenant_id()]);
$totPagado = (float) $tp->fetchColumn();
$tn = db()->prepare("SELECT COALESCE(SUM(" . sql_saldo_factura() . "),0) FROM facturas f WHERE estado='pendiente' AND consultorio_id = ?");
$tn->execute([tenant_id()]);
$totPend = (float) $tn->fetchColumn();

$titulo = t('Facturación');
$activo = 'facturacion';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-receipt text-info"></i> <?= et('Facturación') ?></h1>
    <a href="<?= BASE_URL ?>/facturacion/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nueva factura') ?></a>
</div>

<?php $__u = current_user(); $__med = ($__u['rol'] ?? '') === 'medico' ? ' AND medico_id = ' . (int) $__u['id'] : ''; $__t = (int) tenant_id();
$__mes = " AND estado = 'pagada' AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
$__cobMes = 0.0; try { $q = db()->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = ?$__mes$__med"); $q->execute([$__t]); $__cobMes = (float) $q->fetchColumn(); } catch (Throwable $e) {}
$__nMes = ui_cuenta("SELECT COUNT(*) FROM facturas WHERE consultorio_id = ?$__mes$__med", [$__t]);
echo ui_stats([
    [t('Cobrado este mes'), fmt_money($__cobMes), 'bi-cash-coin', '#16a34a'],
    [t('Por cobrar (pendientes)'), fmt_money($totPend), 'bi-hourglass-split', '#f59e0b', BASE_URL . '/facturacion/index?estado=pendiente', $totPend > 0],
    [t('Cobros este mes'), number_format($__nMes), 'bi-receipt', 'var(--brand)'],
    [t('Ticket promedio (mes)'), fmt_money($__nMes ? $__cobMes / $__nMes : 0), 'bi-graph-up-arrow', '#6366f1'],
]); ?>

<form class="mb-3" method="get">
    <div class="btn-group">
        <?php
        $filtros = ['' => 'Todas', 'pendiente' => 'Pendientes', 'pagada' => 'Pagadas', 'cancelada' => 'Canceladas'];
        foreach ($filtros as $val => $lbl): ?>
            <a href="?estado=<?= $val ?>" class="btn btn-sm <?= $estado===$val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et($lbl) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Folio') ?></th><th><?= et('Fecha') ?></th><th><?= et('Paciente') ?></th><th class="text-end"><?= et('Total') ?></th><th><?= et('Estado') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
            <tbody>
            <?php if (!$facturas): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('No hay facturas.') ?></td></tr>
            <?php else: foreach ($facturas as $f):
                $badge = ['pendiente'=>'warning','pagada'=>'success','cancelada'=>'danger'][$f['estado']]; ?>
                <tr>
                    <td class="fw-semibold"><?= e($f['folio']) ?></td>
                    <td><?= fmt_fecha($f['fecha']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $f['paciente_id'], $f['pac_nombre'], $f['pac_ape'], $f['pac_foto'] ?? null, 32) ?>
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $f['paciente_id'] ?>"><?= e($f['pac_nombre'].' '.$f['pac_ape']) ?></a>
                        </div>
                    </td>
                    <td class="text-end fw-semibold"><?= fmt_money($f['total']) ?></td>
                    <td><?php if ($f['estado'] === 'pendiente' && (float) $f['saldo'] < (float) $f['total'] - 0.004): ?>
                        <span class="badge bg-info text-dark"><?= et('Parcial') ?></span> <small class="text-muted"><?= et('saldo') ?> <?= fmt_money($f['saldo']) ?></small>
                        <?php else: ?><span class="badge bg-<?= $badge ?>"><?= et(ucfirst($f['estado'])) ?></span><?php endif; ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/facturacion/ver?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Ver/Imprimir') ?>"><i class="bi bi-printer"></i></a>
                        <?php if ($f['estado'] === 'pendiente'): ?>
                        <a href="<?= BASE_URL ?>/facturacion/ver?id=<?= $f['id'] ?>#abonar" class="btn btn-sm btn-outline-primary" title="<?= et('Registrar abono') ?>"><i class="bi bi-plus-slash-minus"></i></a>
                        <form action="<?= BASE_URL ?>/facturacion/estado" method="post" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="estado" value="pagada">
                            <button class="btn btn-sm btn-outline-success" title="<?= et('Marcar pagada') ?>"><i class="bi bi-cash-coin"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (has_role('admin')): ?>
                        <form action="<?= BASE_URL ?>/facturacion/delete" method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta factura?');">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
