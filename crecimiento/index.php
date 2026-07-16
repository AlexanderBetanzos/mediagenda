<?php
/**
 * Curvas de crecimiento (pediatría). Grafica peso, talla e IMC del paciente
 * contra su edad, usando las mediciones registradas en las consultas, con
 * una mediana de referencia aproximada (OMS/CDC) por sexo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');

$pid = (int) ($_GET['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }

// Mediciones del expediente (consultas con peso o estatura).
$cons = db()->prepare(
    'SELECT fecha, peso, estatura FROM consultas
     WHERE paciente_id = ? AND consultorio_id = ? AND (peso IS NOT NULL OR estatura IS NOT NULL)
     ORDER BY fecha'
);
$cons->execute([$pid, tenant_id()]);
$cons = $cons->fetchAll();

$nac = $pac['fecha_nacimiento'] ? strtotime($pac['fecha_nacimiento']) : null;
$peso = $talla = $imc = [];
foreach ($cons as $c) {
    if (!$nac) break;
    $edad = round((strtotime($c['fecha']) - $nac) / 86400 / 365.25, 2);
    if ($edad < 0 || $edad > 20) continue;
    if ($c['peso'] > 0)     $peso[]  = ['x' => $edad, 'y' => (float) $c['peso']];
    if ($c['estatura'] > 0) $talla[] = ['x' => $edad, 'y' => (float) $c['estatura']];
    if ($c['peso'] > 0 && $c['estatura'] > 0) {
        $b = imc($c['peso'], $c['estatura']);
        if ($b) $imc[] = ['x' => $edad, 'y' => $b['valor']];
    }
}

// Mediana de referencia aproximada (P50) por edad 0..18, por sexo.
$ref = [
    'M' => [
        'peso'  => [3.3,9.6,12.2,14.3,16.3,18.3,20.5,22.9,25.4,28.1,31.2,34.7,38.7,43.4,48.8,54.5,59.8,64.0,67.0],
        'talla' => [49.9,75.7,87.1,96.1,103.3,110.0,116.0,121.7,127.3,132.6,137.8,143.1,149.1,156.0,163.2,169.0,172.9,175.2,176.1],
    ],
    'F' => [
        'peso'  => [3.2,8.9,11.5,13.9,16.1,18.2,20.2,22.4,25.0,28.2,31.9,36.0,40.0,44.0,47.6,50.5,52.5,53.5,54.4],
        'talla' => [49.1,74.0,85.7,95.1,102.7,109.4,115.1,120.8,126.6,132.5,138.6,144.0,151.2,157.0,160.4,162.1,162.7,163.0,163.2],
    ],
];
$sx = ($pac['sexo'] === 'F') ? 'F' : 'M';
$refPeso = $refTalla = [];
foreach ($ref[$sx]['peso'] as $a => $v)  $refPeso[]  = ['x' => $a, 'y' => $v];
foreach ($ref[$sx]['talla'] as $a => $v) $refTalla[] = ['x' => $a, 'y' => $v];

$titulo = t('Curvas de crecimiento') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($pac['nombre'].' '.$pac['apellidos']) ?></a></li>
    <li class="breadcrumb-item active"><?= et('Curvas de crecimiento') ?></li>
</ol></nav>

<h1 class="h3 mb-1"><i class="bi bi-graph-up-arrow text-brand"></i> <?= et('Curvas de crecimiento') ?></h1>
<p class="text-muted"><?= et('Edad') ?>: <?= e(edad($pac['fecha_nacimiento'])) ?> · <?= $pac['sexo'] === 'F' ? et('Femenino') : et('Masculino') ?>
   · <span class="small"><?= et('Línea punteada = mediana de referencia (OMS aprox.).') ?></span></p>

<?php if (!$nac): ?>
    <div class="alert alert-warning"><?= et('Agrega la fecha de nacimiento del paciente para calcular la edad.') ?></div>
<?php elseif (!$peso && !$talla): ?>
    <div class="alert alert-info"><?= et('Aún no hay mediciones de peso o talla en las consultas.') ?></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h2 class="h6 mb-3"><?= et('Peso para la edad') ?> (kg)</h2><canvas id="chPeso" height="150"></canvas>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h2 class="h6 mb-3"><?= et('Talla para la edad') ?> (cm)</h2><canvas id="chTalla" height="150"></canvas>
    </div></div></div>
    <div class="col-lg-6"><div class="card h-100"><div class="card-body">
        <h2 class="h6 mb-3"><?= et('IMC para la edad') ?></h2><canvas id="chImc" height="150"></canvas>
    </div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ejeX = { type: 'linear', title: { display: true, text: <?= json_encode(t('Edad (años)')) ?> }, min: 0 };
function curva(id, label, datos, ref, color) {
    const ds = [{ label: label, data: datos, borderColor: color, backgroundColor: color, showLine: true, tension: .3, pointRadius: 4 }];
    if (ref) ds.push({ label: <?= json_encode(t('Referencia')) ?>, data: ref, borderColor: '#9aa7b8', borderDash: [6,4], pointRadius: 0, tension: .3 });
    new Chart(document.getElementById(id), {
        type: 'line',
        data: { datasets: ds },
        options: { plugins: { legend: { position: 'bottom' } }, scales: { x: ejeX, y: { beginAtZero: false } } }
    });
}
curva('chPeso',  <?= json_encode(t('Peso')) ?>,    <?= json_encode($peso) ?>,  <?= json_encode($refPeso) ?>,  '#22c55e');
curva('chTalla', <?= json_encode(t('Talla')) ?>,   <?= json_encode($talla) ?>, <?= json_encode($refTalla) ?>, '#2563eb');
curva('chImc',   'IMC',                            <?= json_encode($imc) ?>,   null,                          '#6366f1');
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
