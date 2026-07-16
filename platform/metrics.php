<?php
/**
 * Plataforma — Métricas del negocio. Réplica del /platform/metrics de GymOS,
 * adaptado a MediOS Agenda: MRR/ARR/ARPU, cartera y churn, activaciones, MRR por
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
    "SELECT c.id, c.nombre, c.plan, c.estado,
        (SELECT COALESCE(SUM(f.total),0) FROM facturas f WHERE f.consultorio_id=c.id AND f.estado='pagada' AND MONTH(f.fecha)=MONTH(CURDATE()) AND YEAR(f.fecha)=YEAR(CURDATE())) gmv,
        (SELECT COUNT(*) FROM pacientes p WHERE p.consultorio_id=c.id) pac,
        (SELECT COUNT(*) FROM consultas co WHERE co.consultorio_id=c.id AND co.fecha >= DATE_SUB(NOW(), INTERVAL 30 DAY)) cons30
     FROM consultorios c ORDER BY gmv DESC, pac DESC LIMIT 8"
)->fetchAll();
$estBadge = ['activa' => 'success', 'trial' => 'info', 'suspendida' => 'danger', 'expirada' => 'secondary'];

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

/* ── Tráfico del sitio (pageviews) ──────────────────────────────────── */
ensure_pageviews_table();
$hasViews = (int) $pdo->query("SELECT COUNT(*) FROM pageviews")->fetchColumn();
$visHoy   = (int) $pdo->query("SELECT COUNT(*) FROM pageviews WHERE area<>'plataforma' AND DATE(created_at)=CURDATE()")->fetchColumn();
$vis7     = (int) $pdo->query("SELECT COUNT(*) FROM pageviews WHERE area<>'plataforma' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$vis30    = (int) $pdo->query("SELECT COUNT(*) FROM pageviews WHERE area<>'plataforma' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$uniq30   = (int) $pdo->query("SELECT COUNT(DISTINCT visitor) FROM pageviews WHERE area<>'plataforma' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

$viewsByDay = $pdo->query("SELECT DATE(created_at) d, COUNT(*) n FROM pageviews WHERE area<>'plataforma' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY d")->fetchAll(PDO::FETCH_KEY_PAIR);
$visLabels = $visData = [];
for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $visLabels[] = date('d/m', strtotime($d)); $visData[] = (int) ($viewsByDay[$d] ?? 0); }

$topModules = $pdo->query("SELECT area, path, COUNT(*) n, COUNT(DISTINCT visitor) u FROM pageviews WHERE area<>'plataforma' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY area, path ORDER BY n DESC LIMIT 12")->fetchAll();
$byArea = $pdo->query("SELECT area, COUNT(*) n FROM pageviews WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY area ORDER BY n DESC")->fetchAll();
$areaTotal = array_sum(array_column($byArea, 'n')) ?: 1;

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
        [et('Conversión prueba→pago'),$convRate . '%',      '#3f9aa3'],
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
            <thead><tr><th><?= et('Consultorio') ?></th><th><?= et('Plan') ?></th><th class="text-end"><?= et('GMV del mes') ?></th><th class="text-end"><?= et('Pacientes') ?></th><th class="text-end"><?= et('Consultas 30d') ?></th><th class="text-end"><?= et('Acción') ?></th></tr></thead>
            <tbody>
            <?php foreach ($top as $tr): ?>
                <tr>
                    <td class="fw-semibold"><?= e($tr['nombre']) ?></td>
                    <td><span class="badge bg-<?= $estBadge[$tr['estado']] ?? 'secondary' ?>"><?= e($planNombre[$tr['plan']] ?? $tr['plan']) ?></span></td>
                    <td class="text-end fw-bold text-success"><?= fmt_money($tr['gmv']) ?></td>
                    <td class="text-end"><?= (int) $tr['pac'] ?></td>
                    <td class="text-end"><?= (int) $tr['cons30'] ?></td>
                    <td class="text-end">
                        <form method="post" action="<?= BASE_URL ?>/platform/impersonar" class="d-inline m-0">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $tr['id'] ?>">
                            <button class="btn btn-sm btn-outline-success" title="<?= e(t('Entrar como este consultorio')) ?>"><i class="bi bi-box-arrow-in-right"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$top): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin datos.') ?></td></tr><?php endif; ?>
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

<!-- ── Tráfico del sitio ─────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mt-4 mb-2">
    <h2 class="h4 fw-bold mb-0"><i class="bi bi-graph-up-arrow text-brand"></i> <?= et('Tráfico del sitio') ?></h2>
    <span class="text-muted small"><?= et('visitas a las páginas · sin contar la consola de plataforma') ?></span>
</div>

<?php if (!$hasViews): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-info-circle"></i> <?= et('Aún no hay visitas registradas. En cuanto alguien navegue el sitio público, el panel del consultorio o el portal del paciente, aquí aparecerán las estadísticas.') ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="font-size:1.6rem"><?= number_format($visHoy) ?></div><div class="stat-label mt-1"><?= et('Visitas hoy') ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="font-size:1.6rem"><?= number_format($vis7) ?></div><div class="stat-label mt-1"><?= et('Visitas (7 días)') ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="font-size:1.6rem"><?= number_format($vis30) ?></div><div class="stat-label mt-1"><?= et('Visitas (30 días)') ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="font-size:1.6rem;color:#38bdf8"><?= number_format($uniq30) ?></div><div class="stat-label mt-1"><?= et('Visitantes únicos (30 días)') ?></div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-activity text-brand"></i> <?= et('Visitas por día · últimos 30 días') ?></div>
    <div class="card-body"><div style="height:220px"><canvas id="chartVis"></canvas></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8"><div class="card h-100">
        <div class="card-header fw-semibold"><?= et('Módulos más visitados') ?> <span class="text-muted small">(30 <?= et('días') ?>)</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead><tr><th><?= et('Módulo') ?></th><th><?= et('Área') ?></th><th class="text-end"><?= et('Visitas') ?></th><th class="text-end"><?= et('Únicos') ?></th></tr></thead>
            <tbody>
            <?php foreach ($topModules as $m): ?>
                <tr>
                    <td class="fw-semibold"><?= e(pageview_module_label($m['area'], $m['path'])) ?></td>
                    <td class="text-muted small"><?= e(pageview_area_label($m['area'])) ?></td>
                    <td class="text-end fw-bold"><?= number_format($m['n']) ?></td>
                    <td class="text-end"><?= number_format($m['u']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topModules): ?><tr><td colspan="4" class="text-center text-muted py-3"><?= et('Sin datos aún.') ?></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-4"><div class="card h-100">
        <div class="card-header fw-semibold"><?= et('Tráfico por área') ?> <span class="text-muted small">(30 <?= et('días') ?>)</span></div>
        <div class="card-body">
            <?php if (!$byArea): ?><p class="text-muted small mb-0"><?= et('Sin datos aún.') ?></p>
            <?php else: foreach ($byArea as $a): $pct = round($a['n'] / $areaTotal * 100); ?>
                <div class="d-flex justify-content-between small"><span><?= e(pageview_area_label($a['area'])) ?></span><strong><?= number_format($a['n']) ?> · <?= $pct ?>%</strong></div>
                <div class="progress my-1" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%;background:var(--brand)"></div></div>
            <?php endforeach; endif; ?>
        </div>
    </div></div>
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
        data: { labels: <?= json_encode($actLabels) ?>, datasets: [{ label: 'Altas', data: <?= json_encode($actData) ?>, backgroundColor: '#1f6b73', borderRadius: 6 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { x: { grid: { color: grid }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } } });
    var v = document.getElementById('chartVis');
    if (v) { var g = v.getContext('2d').createLinearGradient(0, 0, 0, 200); g.addColorStop(0, 'rgba(31,107,115,.35)'); g.addColorStop(1, 'rgba(31,107,115,.02)');
        new Chart(v, { type: 'line',
            data: { labels: <?= json_encode($visLabels ?? []) ?>, datasets: [{ label: 'Visitas', data: <?= json_encode($visData ?? []) ?>, borderColor: '#1f6b73', backgroundColor: g, fill: true, tension: .35, pointRadius: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { grid: { color: grid }, border: { display: false }, ticks: { maxTicksLimit: 10 } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } } });
    }
})();
</script>

<?php include __DIR__ . '/_foot.php'; ?>
