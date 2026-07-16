<?php
/**
 * Corte de caja — cierre por rango de fechas. Réplica del corte de GymOS,
 * adaptado a MediOS: cobros = facturas pagadas (incluye ventas del POS),
 * egresos del periodo para el neto, detalle e historial de cortes del mes.
 * Exporta a CSV; PDF/Imprimir usan la impresión del navegador.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');

$u        = current_user();
$esMedico = $u['rol'] === 'medico';
$esAdmin  = has_role('admin');
$pdo      = db();
$tid      = (int) tenant_id();
$medFiltro = $esMedico ? ' AND medico_id = ' . (int) $u['id'] : '';

/* Rango: por defecto hoy. Acepta ?from=YYYY-MM-DD&to=YYYY-MM-DD */
$rx   = '/^\d{4}-\d{2}-\d{2}$/';
$from = $_GET['from'] ?? date('Y-m-d');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match($rx, $from)) $from = date('Y-m-d');
if (!preg_match($rx, $to))   $to   = date('Y-m-d');
if ($to < $from) { $tmp = $from; $from = $to; $to = $tmp; }
$sameDay = $from === $to;

/* Normaliza el método de pago (texto libre) a un grupo fijo. */
function corte_metodo_key(?string $m): string
{
    $m = mb_strtolower(trim((string) $m));
    if ($m === '') return 'otro';
    if (str_contains($m, 'efec')) return 'efectivo';
    if (str_contains($m, 'tarj')) return 'tarjeta';
    if (str_contains($m, 'transf')) return 'transferencia';
    if (str_contains($m, 'linea') || str_contains($m, 'línea') || str_contains($m, 'online') || str_contains($m, 'mercado')) return 'en_linea';
    return 'otro';
}
$metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'en_linea' => 'En línea', 'otro' => 'Otro'];

/* Detalle de cobros del periodo (facturas pagadas). */
$st = $pdo->prepare(
    "SELECT f.fecha d, f.creado_en, f.folio, f.total amount, f.metodo_pago method, f.notas, p.nombre, p.apellidos
     FROM facturas f JOIN pacientes p ON p.id = f.paciente_id
     WHERE f.consultorio_id = ? AND f.estado = 'pagada' AND f.fecha BETWEEN ? AND ? $medFiltro
     ORDER BY f.creado_en DESC, f.id DESC"
);
$st->execute([$tid, $from, $to]);
$rows = $st->fetchAll();

$total = 0.0; $count = 0; $methodMap = [];
$detail = [];
foreach ($rows as $r) {
    $mk = corte_metodo_key($r['method']);
    $total += (float) $r['amount']; $count++;
    if (!isset($methodMap[$mk])) $methodMap[$mk] = ['n' => 0, 'total' => 0];
    $methodMap[$mk]['n']++; $methodMap[$mk]['total'] += (float) $r['amount'];
    $who = trim($r['nombre'] . ' ' . $r['apellidos']);
    $detail[] = [
        'd' => $r['d'], 'who' => $who !== '' ? $who : t('Público general'),
        'concept' => $r['folio'], 'method' => $mk, 'amount' => (float) $r['amount'],
        'tag' => (stripos((string) $r['notas'], 'punto de venta') !== false) ? t('Venta') : t('Factura'),
    ];
}

/* Egresos del periodo (solo admin) para el neto. */
$egresos = 0.0; $egEfectivo = 0.0;
if ($esAdmin) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS egresos (id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, fecha DATE NOT NULL, categoria VARCHAR(60) DEFAULT NULL, concepto VARCHAR(200) NOT NULL, monto DECIMAL(10,2) NOT NULL DEFAULT 0, metodo_pago VARCHAR(40) DEFAULT NULL, usuario_id INT DEFAULT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_egr_tenant (consultorio_id, fecha)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $eq = $pdo->prepare("SELECT metodo_pago, COALESCE(SUM(monto),0) t FROM egresos WHERE consultorio_id=? AND fecha BETWEEN ? AND ? GROUP BY metodo_pago");
    $eq->execute([$tid, $from, $to]);
    foreach ($eq->fetchAll() as $r) {
        $egresos += (float) $r['t'];
        if (corte_metodo_key($r['metodo_pago']) === 'efectivo') $egEfectivo += (float) $r['t'];
    }
}
$neto = $total - $egresos;

/* Historial de cortes: total por día del último mes. */
$hq = $pdo->prepare(
    "SELECT fecha d, COUNT(*) n, COALESCE(SUM(total),0) total FROM facturas
     WHERE consultorio_id = ? AND estado='pagada' $medFiltro AND fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
     GROUP BY fecha ORDER BY fecha DESC"
);
$hq->execute([$tid]);
$history = $hq->fetchAll();

$egByDay = [];
if ($esAdmin) {
    $ed = $pdo->prepare("SELECT fecha d, COALESCE(SUM(monto),0) t FROM egresos WHERE consultorio_id=? AND fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) GROUP BY fecha");
    $ed->execute([$tid]);
    foreach ($ed->fetchAll() as $r) $egByDay[$r['d']] = (float) $r['t'];
}

/* Exportar a CSV (antes de imprimir el layout). */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="corte_' . $from . ($sameDay ? '' : '_a_' . $to) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Fecha', 'Paciente', 'Folio', 'Metodo', 'Tipo', 'Monto']);
    foreach ($detail as $d) {
        fputcsv($out, [$d['d'], $d['who'], $d['concept'], $metodos[$d['method']] ?? $d['method'], $d['tag'], number_format((float) $d['amount'], 2, '.', '')]);
    }
    fputcsv($out, ['', '', '', '', 'TOTAL', number_format($total, 2, '.', '')]);
    if ($esAdmin) {
        fputcsv($out, ['', '', '', '', 'EGRESOS', number_format($egresos, 2, '.', '')]);
        fputcsv($out, ['', '', '', '', 'NETO', number_format($neto, 2, '.', '')]);
    }
    exit;
}

$titulo = t('Corte de caja');
$activo = 'corte';
include __DIR__ . '/../includes/header.php';
$rango = $sameDay ? fmt_fecha($from) : (fmt_fecha($from) . ' → ' . fmt_fecha($to));
?>
<style>
@media print {
    .app-navbar, .sidebar, footer, .d-print-none { display: none !important; }
    main { padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    body, html.app-dark, html.app-light { background: #fff !important; color: #000 !important; }
    .print-only { display: block !important; }
}
.print-only { display: none; }
</style>

<div class="print-only mb-3">
    <h4 class="fw-bold mb-1"><?= e(marca_nombre()) ?> — <?= et('Corte de caja') ?></h4>
    <div class="text-muted"><?= e($rango) ?></div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2 d-print-none">
    <h1 class="h3 mb-0"><i class="bi bi-cash-stack text-brand"></i> <?= et('Corte de caja') ?></h1>
</div>

<!-- Filtros + exportar -->
<div class="card mb-3 d-print-none">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1"><?= et('Desde') ?></label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1"><?= et('Hasta') ?></label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>">
            </div>
            <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> <?= et('Aplicar') ?></button></div>
            <div class="col-auto"><a href="<?= BASE_URL ?>/corte/index" class="btn btn-outline-secondary btn-sm"><?= et('Hoy') ?></a></div>
            <div class="col-auto ms-auto d-flex gap-2">
                <a href="<?= BASE_URL ?>/corte/index?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv" class="btn btn-outline-success btn-sm"><i class="bi bi-filetype-csv"></i> CSV</a>
                <button type="button" onclick="window.print()" class="btn btn-outline-danger btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Total cobrado + por método -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="stat-label"><?= et('Total cobrado') ?></div>
            <div class="fw-bold mt-2" style="font-size:2rem"><?= fmt_money($total) ?></div>
            <div class="text-muted small"><?= $count ?> <?= et('pago(s)') ?> · <?= e($rango) ?></div>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="card h-100"><div class="card-body">
            <div class="stat-label mb-2"><?= et('Por método de pago') ?></div>
            <div class="row g-2">
                <?php foreach ($metodos as $key => $label): $r = $methodMap[$key] ?? null; ?>
                    <div class="col-6 col-md-4">
                        <div class="border rounded p-2" style="border-color:var(--d-border)!important">
                            <div class="small text-muted"><?= e($label) ?></div>
                            <div class="fw-bold"><?= fmt_money($r['total'] ?? 0) ?></div>
                            <div class="small text-muted"><?= (int) ($r['n'] ?? 0) ?> <?= et('pago(s)') ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>
    </div>
</div>

<?php if ($esAdmin): ?>
<!-- Ingresos / Egresos / Neto -->
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label"><?= et('Ingresos') ?></div><div class="fw-bold mt-2" style="font-size:1.6rem;color:#22c55e"><?= fmt_money($total) ?></div></div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label"><?= et('Egresos') ?></div><div class="fw-bold mt-2" style="font-size:1.6rem;color:#ef4444"><?= fmt_money($egresos) ?></div><?php if ($egEfectivo > 0): ?><div class="text-muted small"><?= et('Efectivo') ?>: <?= fmt_money($egEfectivo) ?></div><?php endif; ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label"><?= et('Neto (ingresos − egresos)') ?></div><div class="fw-bold mt-2" style="font-size:1.6rem;color:<?= $neto >= 0 ? '#22c55e' : '#ef4444' ?>"><?= fmt_money($neto) ?></div></div></div></div>
</div>
<?php endif; ?>

<!-- Detalle de pagos -->
<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-receipt text-brand"></i> <?= et('Detalle de pagos') ?></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Paciente') ?></th><th><?= et('Concepto') ?></th><th><?= et('Método') ?></th><th class="text-end"><?= et('Monto') ?></th></tr></thead>
            <tbody>
            <?php foreach ($detail as $d): ?>
                <tr>
                    <td class="small text-muted"><?= fmt_fecha($d['d']) ?></td>
                    <td class="small fw-semibold"><?= e($d['who']) ?></td>
                    <td class="small text-muted"><?= e($d['concept']) ?> <span class="badge bg-secondary bg-opacity-50"><?= e($d['tag']) ?></span></td>
                    <td><span class="badge bg-secondary bg-opacity-50"><?= e($metodos[$d['method']] ?? $d['method']) ?></span></td>
                    <td class="text-end fw-bold text-success"><?= fmt_money($d['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$detail): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin movimientos en este periodo.') ?></td></tr>
            <?php endif; ?>
            </tbody>
            <?php if ($detail): ?>
            <tfoot><tr class="fw-bold"><td colspan="4" class="text-end"><?= et('Total') ?></td><td class="text-end text-success"><?= fmt_money($total) ?></td></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Historial de cortes -->
<?php $histCols = $esAdmin ? 6 : 4; ?>
<div class="card mt-3 d-print-none">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar3 text-brand"></i> <?= et('Historial de cortes (último mes)') ?></span>
        <span class="badge bg-secondary bg-opacity-50 fw-normal"><?= count($history) ?> <?= et('día(s) con movimientos') ?></span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th><?= et('Fecha') ?></th><th><?= et('Movimientos') ?></th><th class="text-end"><?= et('Total del día') ?></th>
                <?php if ($esAdmin): ?><th class="text-end"><?= et('Egresos') ?></th><th class="text-end"><?= et('Neto') ?></th><?php endif; ?>
                <th class="text-end"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($history as $h): $isSel = ($sameDay && $h['d'] === $from); $eg = $egByDay[$h['d']] ?? 0.0; $net = (float) $h['total'] - $eg; ?>
                <tr class="<?= $isSel ? 'table-active' : '' ?>">
                    <td class="fw-semibold"><?= fmt_fecha($h['d']) ?></td>
                    <td class="small text-muted"><?= (int) $h['n'] ?> <?= et('pago(s)') ?></td>
                    <td class="text-end fw-bold text-success"><?= fmt_money($h['total']) ?></td>
                    <?php if ($esAdmin): ?>
                        <td class="text-end <?= $eg > 0 ? 'text-danger' : 'text-muted' ?>"><?= fmt_money($eg) ?></td>
                        <td class="text-end fw-bold" style="color:<?= $net >= 0 ? '#22c55e' : '#ef4444' ?>"><?= fmt_money($net) ?></td>
                    <?php endif; ?>
                    <td class="text-end"><a href="<?= BASE_URL ?>/corte/index?from=<?= e($h['d']) ?>&to=<?= e($h['d']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> <?= et('Ver corte') ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$history): ?>
                <tr><td colspan="<?= $histCols ?>" class="text-center text-muted py-4"><?= et('Sin cortes en el último mes.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
