<?php
/**
 * Plataforma — Métricas del negocio. Réplica del /platform/metrics de GymOS,
 * adaptado a MediAgenda: MRR/ARR/ARPU, cartera y churn, activaciones, MRR por
 * plan, GMV (dinero que mueven todos los consultorios), conversión de prueba,
 * top consultorios, pruebas por vencer y consultorios en riesgo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_platform();

$pdo = db();
$consultorios = $pdo->query("SELECT * FROM consultorios")->fetchAll();

$planes = planes_mp();
$precio = []; $planNombre = [];
foreach ($planes as $k => $p) { $precio[$k] = (float) $p['precio']; $planNombre[$k] = $p['nombre']; }

/* Cartera + MRR */
$byStatus = ['activa' => 0, 'trial' => 0, 'suspendida' => 0, 'expirada' => 0];
$mrr = 0.0; $mrrByPlan = [];
foreach ($consultorios as $c) {
    $st = $c['estado'] ?? '';
    $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
    if ($st === 'activa') {
        $p = $precio[$c['plan']] ?? 0;
        $mrr += $p;
        $mrrByPlan[$c['plan']] = ($mrrByPlan[$c['plan']] ?? 0) + $p;
    }
}
$totalCons = count($consultorios);
$arr  = $mrr * 12;
$arpa = $byStatus['activa'] > 0 ? $mrr / $byStatus['activa'] : 0;
$churnBase = $byStatus['activa'] + $byStatus['suspendida'];
$churnRate = $churnBase > 0 ? round($byStatus['suspendida'] / $churnBase * 100, 1) : 0;

/* Activaciones (altas de consultorios) últimos 12 meses */
$actByMonth = $pdo->query(
    "SELECT DATE_FORMAT(creado_en,'%Y-%m') ym, COUNT(*) n FROM consultorios
     WHERE creado_en >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$MES = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$fom = date('Y-m-01');
$actLabels = $actData = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("$fom -$i month"));
    $actLabels[] = $MES[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 2, 2);
    $actData[]   = (int) ($actByMonth[$ym] ?? 0);
}
$newThisMonth = (int) ($actByMonth[date('Y-m')] ?? 0);

/* Uso agregado (toda la plataforma) */
$totalPacientes = (int) $pdo->query("SELECT COUNT(*) FROM pacientes")->fetchColumn();
$consultas30    = (int) $pdo->query("SELECT COUNT(*) FROM consultas WHERE fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$citas30        = (int) $pdo->query("SELECT COUNT(*) FROM citas WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND estado='atendida'")->fetchColumn();

/* GMV — dinero que mueven TODOS los consultorios (facturas pagadas) */
$gmvMonth = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='pagada' AND MONTH(fecha)=MONTH(CURDATE()) AND YEAR(fecha)=YEAR(CURDATE())")->fetchColumn();
$gmv12    = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='pagada' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)")->fetchColumn();

/* Conversión prueba → activa */
$trialsWith = $totalCons;                         // todos arrancan en prueba
$trialsConv = (int) $byStatus['activa'];
$convRate   = $trialsWith > 0 ? round($trialsConv / $trialsWith * 100) : 0;

/* Top consultorios por GMV del mes */
$top = $pdo->query(
    "SELECT c.id, c.nombre, c.plan,
        (SELECT COALESCE(SUM(f.total),0) FROM facturas f WHERE f.consultorio_id=c.id AND f.estado='pagada' AND MONTH(f.fecha)=MONTH(CURDATE()) AND YEAR(f.fecha)=YEAR(CURDATE())) gmv,
        (SELECT COUNT(*) FROM pacientes p WHERE p.consultorio_id=c.id) pac
     FROM consultorios c ORDER BY gmv DESC, pac DESC LIMIT 8"
)->fetchAll();

/* Pruebas por vencer (próximos 15 días) */
$trialsSoon = $pdo->query(
    "SELECT nombre, trial_fin, DATEDIFF(trial_fin, CURDATE()) dias FROM consultorios
     WHERE estado='trial' AND trial_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
     ORDER BY trial_fin"
)->fetchAll();

/* Consultorios en riesgo: activos sin actividad (consultas/citas atendidas) en 30 días */
$riskRows = $pdo->query(
    "SELECT c.id, c.nombre, c.estado,
        GREATEST(COALESCE((SELECT MAX(fecha) FROM consultas WHERE consultorio_id=c.id),'1900-01-01'),
                 COALESCE((SELECT MAX(fecha) FROM citas WHERE consultorio_id=c.id AND estado='atendida'),'1900-01-01')) ult,
        (SELECT COUNT(*) FROM pacientes p WHERE p.consultorio_id=c.id) pac
     FROM consultorios c WHERE c.estado='activa'"
)->fetchAll();
$risk = [];
foreach ($riskRows as $r) {
    $ult = $r['ult'];
    $inactivo = ($ult === null) || ($ult < date('Y-m-d', strtotime('-30 days')));
    if ($inactivo) $risk[] = $r;
}

$titulo   = 'Métricas';
$platNav  = 'metrics';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-4"><i class="bi bi-graph-up-arrow text-brand"></i> <?= et('Métricas del negocio') ?></h1>

<!-- KPIs principales -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        [et('MRR (ingreso mensual)'), fmt_money($mrr),      '#22c55e'],
        [et('ARR (anualizado)'),      fmt_money($arr),      'var(--brand)'],
        [et('ARPU (por consultorio)'),fmt_money($arpa),     '#38bdf8'],
        [et('Churn (suspendidos)'),   $churnRate . '%',     '#ef4444'],
        [et('GMV del mes'),           fmt_money($gmvMonth), '#a78bfa'],
        [et('Conversión prueba→pago'),$convRate . '%',      '#f59e0b'],
    ];
    foreach ($kpis as [$lbl, $val, $col]): ?>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="fw-bold" style="font-size:1.4rem;color:<?= $col ?>"><?= e($val) ?></div>
            <div class="stat-label mt-1"><?= e($lbl) ?></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Cartera + activaciones -->
<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-bar-chart-fill text-brand"></i> <?= et('Activaciones de consultorios · 12 meses') ?></div>
        <div class="card-body"><div style="height:260px"><canvas id="chartAct"></canvas></div></div>
    </div></div>
    <div class="col-lg-4"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-people text-brand"></i> <?= et('Cartera') ?></div>
        <div class="card-body">
            <?php foreach ([['activa', 'Activos', '#22c55e'], ['trial', 'En prueba', '#38bdf8'], ['suspendida', 'Suspendidos', '#ef4444'], ['expirada', 'Expirados', '#9aa0aa']] as [$k, $lbl, $col]): ?>
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom border-opacity-10">
                    <span class="small"><span class="d-inline-block rounded-circle me-2" style="width:9px;height:9px;background:<?= $col ?>"></span><?= et($lbl) ?></span>
                    <strong><?= (int) $byStatus[$k] ?></strong>
                </div>
            <?php endforeach; ?>
            <div class="d-flex justify-content-between align-items-center pt-2 fw-bold"><span><?= et('Total') ?></span><span><?= $totalCons ?></span></div>
            <div class="small text-muted mt-2"><i class="bi bi-plus-circle"></i> <?= $newThisMonth ?> <?= et('altas este mes') ?> · <?= et('GMV 12m') ?>: <?= fmt_money($gmv12) ?></div>
        </div>
    </div></div>
</div>

<!-- MRR por plan + uso -->
<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-stars text-brand"></i> <?= et('MRR por plan') ?></div>
        <div class="card-body">
            <?php if (!$mrrByPlan): ?><p class="text-muted small mb-0"><?= et('Sin consultorios activos aún.') ?></p>
            <?php else: foreach ($mrrByPlan as $k => $v): $pct = $mrr > 0 ? round($v / $mrr * 100) : 0; ?>
                <div class="d-flex justify-content-between small"><span><?= e($planNombre[$k] ?? $k) ?></span><strong><?= fmt_money($v) ?> <span class="text-muted">(<?= $pct ?>%)</span></strong></div>
                <div class="progress my-1" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%;background:var(--brand)"></div></div>
            <?php endforeach; endif; ?>
        </div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-activity text-brand"></i> <?= et('Uso de la plataforma') ?></div>
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-4"><div class="stat-num" style="font-size:1.5rem"><?= number_format($totalPacientes) ?></div><div class="stat-label"><?= et('Pacientes') ?></div></div>
                <div class="col-4"><div class="stat-num" style="font-size:1.5rem"><?= number_format($consultas30) ?></div><div class="stat-label"><?= et('Consultas 30d') ?></div></div>
                <div class="col-4"><div class="stat-num" style="font-size:1.5rem"><?= number_format($citas30) ?></div><div class="stat-label"><?= et('Citas atendidas 30d') ?></div></div>
            </div>
        </div>
    </div></div>
</div>

<!-- Top consultorios + pruebas por vencer -->
<div class="row g-3 mb-4">
    <div class="col-lg-7"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-trophy text-brand"></i> <?= et('Top consultorios (por GMV del mes)') ?></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th><?= et('Consultorio') ?></th><th><?= et('Plan') ?></th><th class="text-center"><?= et('Pacientes') ?></th><th class="text-end"><?= et('GMV del mes') ?></th></tr></thead>
            <tbody>
            <?php foreach ($top as $tr): ?>
                <tr>
                    <td class="fw-semibold"><a href="<?= BASE_URL ?>/platform/consultorio?id=<?= (int) $tr['id'] ?>" class="text-decoration-none"><?= e($tr['nombre']) ?></a></td>
                    <td class="small text-uppercase"><?= e($planNombre[$tr['plan']] ?? $tr['plan']) ?></td>
                    <td class="text-center"><?= (int) $tr['pac'] ?></td>
                    <td class="text-end fw-bold text-success"><?= fmt_money($tr['gmv']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$top): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= et('Sin datos.') ?></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-hourglass-split text-brand"></i> <?= et('Pruebas por vencer (15 días)') ?></div>
        <div class="card-body py-2">
            <?php if (!$trialsSoon): ?><p class="text-muted small mb-0 py-2"><?= et('Ninguna prueba vence pronto.') ?></p>
            <?php else: foreach ($trialsSoon as $x): ?>
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom border-opacity-10">
                    <span class="small fw-semibold"><?= e($x['nombre']) ?></span>
                    <span class="small <?= $x['dias'] <= 3 ? 'text-danger fw-bold' : 'text-warning' ?>"><?= (int) $x['dias'] === 0 ? et('Hoy') : ('en ' . (int) $x['dias'] . ' ' . et('días')) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div></div>
</div>

<!-- En riesgo -->
<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-exclamation-triangle text-brand"></i> <?= et('Consultorios en riesgo') ?> <span class="text-muted small">(<?= count($risk) ?>)</span> · <span class="text-muted small"><?= et('activos sin actividad en 30 días') ?></span></div>
    <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th><?= et('Consultorio') ?></th><th><?= et('Última actividad') ?></th><th class="text-center"><?= et('Pacientes') ?></th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($risk as $r): $ult = ($r['ult'] && $r['ult'] > '1900-01-01') ? fmt_fecha($r['ult']) : t('Nunca'); ?>
            <tr>
                <td class="fw-semibold"><?= e($r['nombre']) ?></td>
                <td class="small text-muted"><?= e($ult) ?></td>
                <td class="text-center"><?= (int) $r['pac'] ?></td>
                <td class="text-end"><a href="<?= BASE_URL ?>/platform/consultorio?id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"><?= et('Gestionar') ?></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$risk): ?><tr><td colspan="4" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success"></i> <?= et('Ningún consultorio activo en riesgo. ¡Bien!') ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var isLight = document.documentElement.classList.contains('app-light');
    var tick = isLight ? '#6b7c93' : '#9aa0aa', grid = isLight ? 'rgba(15,39,71,.07)' : 'rgba(255,255,255,.07)';
    Chart.defaults.color = tick; Chart.defaults.font.family = "'Inter',sans-serif";
    var a = document.getElementById('chartAct');
    if (a) new Chart(a, { type: 'bar',
        data: { labels: <?= json_encode($actLabels) ?>, datasets: [{ label: 'Altas', data: <?= json_encode($actData) ?>, backgroundColor: '#f66f14', borderRadius: 6 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { x: { grid: { color: grid }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } } });
})();
</script>

<?php include __DIR__ . '/_foot.php'; ?>
