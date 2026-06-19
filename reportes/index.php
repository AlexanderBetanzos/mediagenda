<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin', 'medico');

$pdo = db();

// --- Citas por estado ---
$porEstado = ['programada'=>0,'confirmada'=>0,'atendida'=>0,'cancelada'=>0,'no_asistio'=>0];
foreach ($pdo->query("SELECT estado, COUNT(*) c FROM citas GROUP BY estado") as $r) {
    $porEstado[$r['estado']] = (int) $r['c'];
}

// --- Últimos 6 meses: citas e ingresos ---
$meses = [];
for ($i = 5; $i >= 0; $i--) {
    $meses[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
$citasMes = $meses;
foreach ($pdo->query("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COUNT(*) c FROM citas
                      WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym") as $r) {
    if (isset($citasMes[$r['ym']])) $citasMes[$r['ym']] = (int) $r['c'];
}
$ingresosMes = $meses;
foreach ($pdo->query("SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COALESCE(SUM(total),0) t FROM facturas
                      WHERE estado='pagada' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym") as $r) {
    if (isset($ingresosMes[$r['ym']])) $ingresosMes[$r['ym']] = (float) $r['t'];
}

// Etiquetas de mes legibles
$mesesCorto = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$labelsMes = array_map(fn($ym) => $mesesCorto[(int)substr($ym,5,2)] . ' ' . substr($ym,2,2), array_keys($meses));

// --- Pacientes por tipo ---
$porTipo = ['medico'=>0,'dental'=>0];
foreach ($pdo->query("SELECT tipo, COUNT(*) c FROM pacientes GROUP BY tipo") as $r) {
    $porTipo[$r['tipo']] = (int) $r['c'];
}

// --- Médicos con más citas ---
$topMedicos = $pdo->query(
    "SELECT u.nombre, COUNT(*) c FROM citas ci JOIN usuarios u ON u.id = ci.medico_id
     GROUP BY ci.medico_id ORDER BY c DESC LIMIT 5"
)->fetchAll();

$titulo = 'Reportes';
$activo = 'reportes';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4"><i class="bi bi-bar-chart text-info"></i> Reportes</h1>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3">Ingresos por mes (facturas pagadas)</h2>
            <canvas id="chartIngresos" height="100"></canvas>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3">Citas por estado</h2>
            <canvas id="chartEstado"></canvas>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3">Citas por mes</h2>
            <canvas id="chartCitas" height="120"></canvas>
        </div></div>
    </div>
    <div class="col-lg-3">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3">Pacientes por tipo</h2>
            <canvas id="chartTipo"></canvas>
        </div></div>
    </div>
    <div class="col-lg-3">
        <div class="card h-100">
            <div class="card-header fw-semibold">Médicos con más citas</div>
            <ul class="list-group list-group-flush">
                <?php if (!$topMedicos): ?>
                    <li class="list-group-item text-muted">Sin datos.</li>
                <?php else: foreach ($topMedicos as $m): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= e($m['nombre']) ?></span><span class="badge bg-info"><?= $m['c'] ?></span>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
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
    data: { labels: ['Programada','Confirmada','Atendida','Cancelada','No asistió'],
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
    data: { labels: ['Médico','Dental'], datasets: [{ data: <?= json_encode(array_values($porTipo)) ?>,
        backgroundColor: ['#0b6fb8','#2bc4dd'] }] },
    options: { plugins:{legend:{position:'bottom'}} }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
