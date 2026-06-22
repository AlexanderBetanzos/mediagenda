<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'medico');
require_modulo('reportes');

$pdo = db();
$tid = tenant_id();   // entero del consultorio activo (seguro para interpolar)

/* ---------------- KPIs del mes ---------------- */
$mesIni    = date('Y-m-01');
$mesAntIni = date('Y-m-01', strtotime('-1 month'));

$ingMes    = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id=$tid AND estado='pagada' AND fecha >= '$mesIni'")->fetchColumn();
$ingMesAnt = (float) $pdo->query("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id=$tid AND estado='pagada' AND fecha >= '$mesAntIni' AND fecha < '$mesIni'")->fetchColumn();
$deltaIng  = $ingMesAnt > 0 ? round(100 * ($ingMes - $ingMesAnt) / $ingMesAnt) : null;

$citasMesAct = (int) $pdo->query("SELECT COUNT(*) FROM citas WHERE consultorio_id=$tid AND fecha >= '$mesIni'")->fetchColumn();
$nuevosMes   = (int) $pdo->query("SELECT COUNT(*) FROM pacientes WHERE consultorio_id=$tid AND creado_en >= '$mesIni'")->fetchColumn();

// Tasa de inasistencia (no-show) de los últimos 90 días (citas ya pasadas).
$ns = $pdo->query("SELECT SUM(estado='no_asistio') ns, COUNT(*) tot FROM citas
                   WHERE consultorio_id=$tid AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND fecha < CURDATE()")->fetch();
$noShow = ($ns && $ns['tot'] > 0) ? round(100 * $ns['ns'] / $ns['tot'], 1) : 0.0;

/* ---------------- Horas pico ---------------- */
$horas = array_fill_keys(range(7, 20), 0);
foreach ($pdo->query("SELECT HOUR(hora) h, COUNT(*) c FROM citas WHERE consultorio_id=$tid GROUP BY h") as $r) {
    if (isset($horas[(int) $r['h']])) $horas[(int) $r['h']] = (int) $r['c'];
}
$horasLabels = array_map(fn($h) => sprintf('%02d:00', $h), array_keys($horas));

// --- Citas por estado ---
$porEstado = ['programada'=>0,'confirmada'=>0,'atendida'=>0,'cancelada'=>0,'no_asistio'=>0];
foreach ($pdo->query("SELECT estado, COUNT(*) c FROM citas WHERE consultorio_id = $tid GROUP BY estado") as $r) {
    $porEstado[$r['estado']] = (int) $r['c'];
}

// --- Últimos 6 meses: citas e ingresos ---
$meses = [];
for ($i = 5; $i >= 0; $i--) {
    $meses[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
$citasMes = $meses;
foreach ($pdo->query("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COUNT(*) c FROM citas
                      WHERE consultorio_id = $tid AND fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym") as $r) {
    if (isset($citasMes[$r['ym']])) $citasMes[$r['ym']] = (int) $r['c'];
}
$ingresosMes = $meses;
foreach ($pdo->query("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COALESCE(SUM(total),0) t FROM facturas
                      WHERE consultorio_id = $tid AND estado='pagada' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym") as $r) {
    if (isset($ingresosMes[$r['ym']])) $ingresosMes[$r['ym']] = (float) $r['t'];
}

// Pacientes nuevos por mes (mismos 6 meses)
$nuevosMesArr = $meses;
foreach ($pdo->query("SELECT DATE_FORMAT(creado_en,'%Y-%m') ym, COUNT(*) c FROM pacientes
                      WHERE consultorio_id = $tid AND creado_en >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym") as $r) {
    if (isset($nuevosMesArr[$r['ym']])) $nuevosMesArr[$r['ym']] = (int) $r['c'];
}

// Etiquetas de mes legibles
$mesesCorto = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$labelsMes = array_map(fn($ym) => $mesesCorto[(int)substr($ym,5,2)] . ' ' . substr($ym,2,2), array_keys($meses));

// --- Pacientes por tipo ---
$porTipo = ['medico'=>0,'dental'=>0];
foreach ($pdo->query("SELECT tipo, COUNT(*) c FROM pacientes WHERE consultorio_id = $tid GROUP BY tipo") as $r) {
    $porTipo[$r['tipo']] = (int) $r['c'];
}

// --- Médicos con más citas ---
$topMedicos = $pdo->query(
    "SELECT u.nombre, COUNT(*) c FROM citas ci JOIN usuarios u ON u.id = ci.medico_id
     WHERE ci.consultorio_id = $tid
     GROUP BY ci.medico_id ORDER BY c DESC LIMIT 5"
)->fetchAll();

$titulo = t('Reportes');
$activo = 'reportes';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4"><i class="bi bi-bar-chart text-info"></i> <?= et('Reportes') ?></h1>

<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Ingresos del mes', fmt_money($ingMes), 'bi-cash-coin', '#22c55e',
         $deltaIng === null ? '' : (($deltaIng >= 0 ? "+$deltaIng% " : "$deltaIng% ") . t('vs mes anterior')), $deltaIng !== null && $deltaIng < 0],
        ['Citas este mes', number_format($citasMesAct), 'bi-calendar-check', '#0b6fb8', '', false],
        ['Pacientes nuevos (mes)', number_format($nuevosMes), 'bi-person-plus', '#6366f1', '', false],
        ['Inasistencia (90 días)', $noShow . '%', 'bi-person-x', '#ef4444', t('No-show'), $noShow > 15],
    ];
    foreach ($kpis as [$lbl, $val, $ic, $col, $sub, $malo]): ?>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:<?= $col ?>1f;color:<?= $col ?>"><i class="bi <?= $ic ?>"></i></div>
            <div>
                <div class="stat-num" style="font-size:1.5rem"><?= e($val) ?></div>
                <div class="stat-label"><?= et($lbl) ?></div>
                <?php if ($sub): ?><div class="small <?= $malo ? 'text-danger' : 'text-muted' ?>"><?= e($sub) ?></div><?php endif; ?>
            </div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><?= et('Ingresos por mes (facturas pagadas)') ?></h2>
            <canvas id="chartIngresos" height="100"></canvas>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><?= et('Citas por estado') ?></h2>
            <canvas id="chartEstado"></canvas>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><?= et('Citas por mes') ?></h2>
            <canvas id="chartCitas" height="120"></canvas>
        </div></div>
    </div>
    <div class="col-lg-3">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><?= et('Pacientes por tipo') ?></h2>
            <canvas id="chartTipo"></canvas>
        </div></div>
    </div>
    <div class="col-lg-3">
        <div class="card h-100">
            <div class="card-header fw-semibold"><?= et('Médicos con más citas') ?></div>
            <ul class="list-group list-group-flush">
                <?php if (!$topMedicos): ?>
                    <li class="list-group-item text-muted"><?= et('Sin datos.') ?></li>
                <?php else: foreach ($topMedicos as $m): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($m['nombre']) ?></span><span class="badge bg-info"><?= $m['c'] ?></span>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><?= et('Horas pico (citas por hora)') ?></h2>
            <canvas id="chartHoras" height="110"></canvas>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><?= et('Pacientes nuevos por mes') ?></h2>
            <canvas id="chartNuevos" height="110"></canvas>
        </div></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.color = '#93a6c4';
Chart.defaults.borderColor = 'rgba(148,170,200,.12)';

new Chart(document.getElementById('chartIngresos'), {
    type: 'line',
    data: { labels: <?= json_encode($labelsMes) ?>, datasets: [{
        label: 'Ingresos', data: <?= json_encode(array_values($ingresosMes)) ?>,
        borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.15)', fill: true, tension: .3 }] },
    options: { plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, ticks:{ callback:v=>'$'+v } } } }
});

new Chart(document.getElementById('chartEstado'), {
    type: 'doughnut',
    data: { labels: <?= json_encode([t('Programada'),t('Confirmada'),t('Atendida'),t('Cancelada'),t('No asistió')]) ?>,
        datasets: [{ data: <?= json_encode(array_values($porEstado)) ?>,
        backgroundColor: ['#94a6c4','#2bc4dd','#22c55e','#ef4444','#f59e0b'] }] },
    options: { plugins:{legend:{position:'bottom'}} }
});

new Chart(document.getElementById('chartCitas'), {
    type: 'bar',
    data: { labels: <?= json_encode($labelsMes) ?>, datasets: [{
        label: 'Citas', data: <?= json_encode(array_values($citasMes)) ?>,
        backgroundColor: '#2bc4dd', borderRadius: 6 }] },
    options: { plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, ticks:{precision:0} } } }
});

new Chart(document.getElementById('chartTipo'), {
    type: 'doughnut',
    data: { labels: <?= json_encode([tipo_paciente_label('medico'), tipo_paciente_label('dental')]) ?>, datasets: [{ data: <?= json_encode(array_values($porTipo)) ?>,
        backgroundColor: ['#0b6fb8','#2bc4dd'] }] },
    options: { plugins:{legend:{position:'bottom'}} }
});

new Chart(document.getElementById('chartHoras'), {
    type: 'bar',
    data: { labels: <?= json_encode($horasLabels) ?>, datasets: [{
        label: 'Citas', data: <?= json_encode(array_values($horas)) ?>,
        backgroundColor: '#6366f1', borderRadius: 5 }] },
    options: { plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, ticks:{precision:0} } } }
});

new Chart(document.getElementById('chartNuevos'), {
    type: 'line',
    data: { labels: <?= json_encode($labelsMes) ?>, datasets: [{
        label: 'Nuevos', data: <?= json_encode(array_values($nuevosMesArr)) ?>,
        borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.15)', fill: true, tension: .3 }] },
    options: { plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, ticks:{precision:0} } } }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
