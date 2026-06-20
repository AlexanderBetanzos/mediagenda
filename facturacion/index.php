<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$estado = $_GET['estado'] ?? '';
$params = [tenant_id()];
$sql = "SELECT f.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape
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
$tn = db()->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='pendiente' AND consultorio_id = ?");
$tn->execute([tenant_id()]);
$totPend = (float) $tn->fetchColumn();

$titulo = 'Facturación';
$activo = 'facturacion';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-receipt text-info"></i> Facturación</h1>
    <a href="<?= BASE_URL ?>/facturacion/create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva factura</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Cobrado (pagadas)</div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= fmt_money($totPagado) ?></div>
    </div></div></div>
    <div class="col-sm-6"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Por cobrar (pendientes)</div>
        <div class="stat-num mt-2" style="color:#f59e0b"><?= fmt_money($totPend) ?></div>
    </div></div></div>
</div>

<form class="mb-3" method="get">
    <div class="btn-group">
        <?php
        $filtros = ['' => 'Todas', 'pendiente' => 'Pendientes', 'pagada' => 'Pagadas', 'cancelada' => 'Canceladas'];
        foreach ($filtros as $val => $lbl): ?>
            <a href="?estado=<?= $val ?>" class="btn btn-sm <?= $estado===$val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Folio</th><th>Fecha</th><th>Paciente</th><th class="text-end">Total</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php if (!$facturas): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No hay facturas.</td></tr>
            <?php else: foreach ($facturas as $f):
                $badge = ['pendiente'=>'warning','pagada'=>'success','cancelada'=>'danger'][$f['estado']]; ?>
                <tr>
                    <td class="fw-semibold"><?= e($f['folio']) ?></td>
                    <td><?= fmt_fecha($f['fecha']) ?></td>
                    <td><a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $f['paciente_id'] ?>"><?= e($f['pac_nombre'].' '.$f['pac_ape']) ?></a></td>
                    <td class="text-end fw-semibold"><?= fmt_money($f['total']) ?></td>
                    <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($f['estado']) ?></span></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/facturacion/ver.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver/Imprimir"><i class="bi bi-printer"></i></a>
                        <?php if ($f['estado'] === 'pendiente'): ?>
                        <form action="<?= BASE_URL ?>/facturacion/estado.php" method="post" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $f['id'] ?>"><input type="hidden" name="estado" value="pagada">
                            <button class="btn btn-sm btn-outline-success" title="Marcar pagada"><i class="bi bi-cash-coin"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php if (has_role('admin')): ?>
                        <form action="<?= BASE_URL ?>/facturacion/delete.php" method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta factura?');">
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
