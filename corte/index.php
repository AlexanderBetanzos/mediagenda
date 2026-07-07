<?php
/**
 * Corte de caja — cierre diario. Desglosa lo cobrado (facturas pagadas) del
 * día por método de pago, con # de facturas y ticket promedio. Solo lectura.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');

$u        = current_user();
$esMedico = $u['rol'] === 'medico';
$tid      = (int) tenant_id();
$medFiltro = $esMedico ? ' AND medico_id = ' . (int) $u['id'] : '';

/* Día del corte (por defecto hoy); se valida el formato. */
$dia = $_GET['dia'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) $dia = date('Y-m-d');

$pdo = db();

/* Desglose por método de pago (solo pagadas del día). */
$st = $pdo->prepare(
    "SELECT COALESCE(NULLIF(metodo_pago,''),'Sin método') m, COUNT(*) n, COALESCE(SUM(total),0) t
     FROM facturas WHERE consultorio_id = ? AND estado='pagada' AND fecha = ? $medFiltro
     GROUP BY m ORDER BY t DESC"
);
$st->execute([$tid, $dia]);
$porMetodo = $st->fetchAll();

$totalDia = 0.0; $numFacturas = 0;
foreach ($porMetodo as $r) { $totalDia += (float) $r['t']; $numFacturas += (int) $r['n']; }
$ticket = $numFacturas > 0 ? $totalDia / $numFacturas : 0;

/* Pendiente y canceladas del día (informativo). */
$pend = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id=? AND estado='pendiente' AND fecha=? $medFiltro");
$pend->execute([$tid, $dia]);
$pendienteDia = (float) $pend->fetchColumn();

/* Detalle de facturas pagadas del día. */
$det = $pdo->prepare(
    "SELECT f.folio, f.total, f.metodo_pago, f.creado_en, p.nombre, p.apellidos
     FROM facturas f JOIN pacientes p ON p.id = f.paciente_id
     WHERE f.consultorio_id = ? AND f.estado='pagada' AND f.fecha = ? $medFiltro
     ORDER BY f.creado_en"
);
$det->execute([$tid, $dia]);
$detalle = $det->fetchAll();

$titulo = t('Corte de caja');
$activo = 'corte';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-cash-stack text-brand"></i> <?= et('Corte de caja') ?></h1>
    <form class="d-flex align-items-center gap-2" method="get">
        <input type="date" name="dia" value="<?= e($dia) ?>" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
    </form>
</div>

<p class="text-muted mb-3 text-capitalize"><?= e(fmt_fecha($dia)) ?><?= $esMedico ? ' · ' . e($u['nombre']) : '' ?></p>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Total del día') ?></div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= fmt_money($totalDia) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Facturas cobradas') ?></div>
        <div class="stat-num mt-2"><?= (int) $numFacturas ?></div>
    </div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Ticket promedio') ?></div>
        <div class="stat-num mt-2" style="color:var(--brand)"><?= fmt_money($ticket) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Pendiente del día') ?></div>
        <div class="stat-num mt-2" style="color:#f59e0b"><?= fmt_money($pendienteDia) ?></div>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-wallet2 text-brand"></i> <?= et('Por método de pago') ?></div>
            <div class="card-body p-0">
                <?php if (!$porMetodo): ?>
                    <p class="text-muted text-center py-4 mb-0"><?= et('Sin cobros este día.') ?></p>
                <?php else: ?>
                <table class="table align-middle mb-0">
                    <thead><tr><th><?= et('Método') ?></th><th class="text-center"><?= et('Facturas') ?></th><th class="text-end"><?= et('Total') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($porMetodo as $r): ?>
                        <tr>
                            <td class="text-capitalize"><?= e($r['m']) ?></td>
                            <td class="text-center"><?= (int) $r['n'] ?></td>
                            <td class="text-end fw-semibold"><?= fmt_money($r['t']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-bold"><td><?= et('Total') ?></td><td class="text-center"><?= (int) $numFacturas ?></td><td class="text-end" style="color:#22c55e"><?= fmt_money($totalDia) ?></td></tr></tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-receipt text-brand"></i> <?= et('Detalle de cobros') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th><?= et('Hora') ?></th><th><?= et('Folio') ?></th><th><?= et('Paciente') ?></th><th><?= et('Método') ?></th><th class="text-end"><?= et('Total') ?></th></tr></thead>
                    <tbody>
                    <?php if (!$detalle): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin cobros este día.') ?></td></tr>
                    <?php else: foreach ($detalle as $d): ?>
                        <tr>
                            <td class="small text-muted"><?= date('H:i', strtotime($d['creado_en'])) ?></td>
                            <td class="small text-muted"><?= e($d['folio']) ?></td>
                            <td class="small fw-semibold"><?= e($d['nombre'] . ' ' . $d['apellidos']) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-50 text-capitalize"><?= e($d['metodo_pago'] ?: '—') ?></span></td>
                            <td class="text-end fw-bold"><?= fmt_money($d['total']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
