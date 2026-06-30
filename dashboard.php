<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$u        = current_user();
$esMedico = $u['rol'] === 'medico';
$pdo      = db();

/* Aislamiento por consultorio + filtro por médico (ve solo lo suyo). */
$tid       = (int) tenant_id();
$medFiltro = $esMedico ? ' AND medico_id = ' . (int) $u['id'] : '';

// --- Estadísticas ---
$citasHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha = CURDATE() AND estado <> 'cancelada' $medFiltro"
)->fetchColumn();
$citasConfHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha = CURDATE() AND estado IN ('confirmada','atendida') $medFiltro"
)->fetchColumn();

$totPacientes  = (int) $pdo->query("SELECT COUNT(*) FROM pacientes WHERE consultorio_id = $tid")->fetchColumn();
$pacientesMes  = (int) $pdo->query(
    "SELECT COUNT(*) FROM pacientes WHERE consultorio_id = $tid AND YEAR(creado_en)=YEAR(CURDATE()) AND MONTH(creado_en)=MONTH(CURDATE())"
)->fetchColumn();

$consultasMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM consultas WHERE consultorio_id = $tid AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
)->fetchColumn();

$citasPend = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha >= CURDATE() AND estado IN ('programada','confirmada') $medFiltro"
)->fetchColumn();

$recetasMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM recetas WHERE consultorio_id = $tid AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
)->fetchColumn();

$ingresosMes = (float) $pdo->query(
    "SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = $tid AND estado='pagada'
     AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())"
)->fetchColumn();

// --- Citas por semana (lunes a domingo de la semana actual) ---
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$wk = [];
for ($i = 0; $i < 7; $i++) $wk[date('Y-m-d', strtotime("$monday +$i day"))] = 0;
$stmt = $pdo->prepare(
    "SELECT DATE(fecha) d, COUNT(*) c FROM citas
     WHERE consultorio_id = ? AND fecha BETWEEN ? AND ? AND estado <> 'cancelada' $medFiltro GROUP BY DATE(fecha)"
);
$stmt->execute([$tid, $monday, $sunday]);
foreach ($stmt as $r) if (isset($wk[$r['d']])) $wk[$r['d']] = (int) $r['c'];
$chartData = array_values($wk);

// --- Agenda de hoy ---
$ag = $pdo->prepare(
    "SELECT c.*, p.nombre, p.apellidos FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     WHERE c.consultorio_id = ? AND c.fecha = CURDATE() AND c.estado <> 'cancelada' $medFiltro
     ORDER BY c.hora LIMIT 8"
);
$ag->execute([$tid]);
$agendaHoy = $ag->fetchAll();

// --- Últimos expedientes (últimas consultas) ---
$ue = $pdo->prepare(
    "SELECT co.fecha, co.diagnostico, p.id pid, p.nombre, p.apellidos,
            (SELECT COUNT(*) FROM citas ci WHERE ci.paciente_id=p.id
             AND ci.fecha>=CURDATE() AND ci.estado IN('programada','confirmada')) futuras
     FROM consultas co JOIN pacientes p ON p.id = co.paciente_id
     WHERE co.consultorio_id = ? $medFiltro
     ORDER BY co.fecha DESC LIMIT 6"
);
$ue->execute([$tid]);
$ultimos = $ue->fetchAll();

$titulo = t('Panel');
$activo = 'dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h3 mb-1"><?= et('Hola') ?>, <?= e(explode(' ', trim($u['nombre']))[0]) ?> 👋</h1>
        <p class="text-muted mb-0 text-capitalize"><?= e(fecha_hoy_larga()) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/citas/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nueva cita') ?></a>
</div>

<!-- Tarjetas de estadísticas -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="stat-label"><?= et('Citas hoy') ?></div>
                <div class="stat-icon" style="background:color-mix(in srgb,var(--brand) 14%,transparent);color:var(--brand)"><i class="bi bi-calendar-check"></i></div>
            </div>
            <div class="stat-num"><?= $citasHoy ?></div>
            <span class="delta <?= $citasConfHoy ? 'delta-up' : 'delta-flat' ?> mt-1 d-inline-block"><i class="bi bi-check2-circle"></i> <?= $citasConfHoy ?> <?= et('confirmadas') ?></span>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="stat-label"><?= et('Pacientes') ?></div>
                <div class="stat-icon" style="background:rgba(20,184,166,.14);color:#0d9488"><i class="bi bi-people"></i></div>
            </div>
            <div class="stat-num"><?= $totPacientes ?></div>
            <span class="delta <?= $pacientesMes ? 'delta-up' : 'delta-flat' ?> mt-1 d-inline-block"><i class="bi bi-person-plus"></i> <?= $pacientesMes ?> <?= et('este mes') ?></span>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="stat-label"><?= et('Recetas (mes)') ?></div>
                <div class="stat-icon" style="background:rgba(99,102,241,.14);color:#6366f1"><i class="bi bi-capsule"></i></div>
            </div>
            <div class="stat-num"><?= $recetasMes ?></div>
            <span class="delta delta-flat mt-1 d-inline-block"><i class="bi bi-file-medical"></i> <?= $consultasMes ?> <?= et('consultas') ?></span>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="stat-label"><?= et('Ingresos') ?> (<?= e(moneda()) ?>)</div>
                <div class="stat-icon" style="background:rgba(34,197,94,.14);color:#16a34a"><i class="bi bi-cash-coin"></i></div>
            </div>
            <div class="stat-num" style="font-size:1.6rem"><?= fmt_money($ingresosMes) ?></div>
            <span class="delta delta-up mt-1 d-inline-block"><i class="bi bi-graph-up-arrow"></i> <?= et('facturado este mes') ?></span>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Gráfica: citas por semana -->
    <div class="col-lg-8">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-bar-chart-fill text-brand"></i> <?= et('Citas por Semana') ?></h2>
            <canvas id="chartSemana" height="110"></canvas>
        </div></div>
    </div>
    <!-- Agenda de hoy -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-calendar-day text-brand"></i> <?= et('Agenda de Hoy') ?></span>
                <a href="<?= BASE_URL ?>/citas/index" class="small"><?= et('Ver completa →') ?></a>
            </div>
            <div class="card-body p-0">
                <?php if (!$agendaHoy): ?>
                    <p class="text-muted text-center py-4 mb-0"><?= et('Sin citas para hoy.') ?></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($agendaHoy as $c): ?>
                        <li class="list-group-item d-flex align-items-center justify-content-between px-3">
                            <span>
                                <span class="text-brand fw-semibold me-2"><?= fmt_hora($c['hora']) ?></span>
                                <?= e($c['apellidos'] . ', ' . $c['nombre']) ?>
                            </span>
                            <span class="dot-estado dot-<?= e($c['estado']) ?>" title="<?= estado_label($c['estado']) ?>"></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Últimos expedientes -->
<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-folder2-open text-brand"></i> <?= et('Últimos Expedientes') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Paciente') ?></th><th><?= et('Última visita') ?></th><th><?= et('Diagnóstico') ?></th><th class="text-end"><?= et('Estado') ?></th></tr></thead>
            <tbody>
            <?php if (!$ultimos): ?>
                <tr><td colspan="4" class="text-center text-muted py-4"><?= et('Aún no hay consultas registradas.') ?></td></tr>
            <?php else: foreach ($ultimos as $r): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $r['pid'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($r['nombre'] . ' ' . $r['apellidos']) ?>
                        </a>
                    </td>
                    <td><?= fmt_fecha($r['fecha']) ?></td>
                    <td><?= e($r['diagnostico'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ($r['futuras'] > 0): ?>
                            <span class="badge rounded-pill text-bg-info"><?= et('Seguimiento') ?></span>
                        <?php else: ?>
                            <span class="badge rounded-pill text-bg-success"><?= et('Activo') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('chartSemana');
const css = getComputedStyle(document.documentElement);
const brand = (css.getPropertyValue('--brand').trim() || '#0b6fb8');
const isLight = document.documentElement.classList.contains('app-light');
const tickColor = isLight ? '#6b7c93' : '#93a6c4';
const gridColor = isLight ? 'rgba(15,39,71,.07)' : 'rgba(148,170,200,.12)';

// Convierte un color (#rgb / #rrggbb) a rgba con la opacidad indicada.
function fade(c, a) {
    c = c.trim();
    if (c[0] === '#') {
        let h = c.slice(1);
        if (h.length === 3) h = h.split('').map(x => x + x).join('');
        const n = parseInt(h, 16);
        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
    }
    return c;
}

// Degradado vertical con el color de marca (se desvanece hacia abajo).
const grad = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
grad.addColorStop(0, fade(brand, 1));
grad.addColorStop(1, fade(brand, .28));

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode([t('Lun'), t('Mar'), t('Mié'), t('Jue'), t('Vie'), t('Sáb'), t('Dom')]) ?>,
        datasets: [{
            label: <?= json_encode(t('Citas')) ?>,
            data: <?= json_encode($chartData) ?>,
            backgroundColor: grad,
            hoverBackgroundColor: brand,
            borderRadius: 8,
            maxBarThickness: 40
        }]
    },
    options: {
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: '#0f2747', padding: 10, cornerRadius: 8, displayColors: false }
        },
        scales: {
            x: { grid: { display: false }, border: { display: false }, ticks: { color: tickColor, font: { weight: '500' } } },
            y: { beginAtZero: true, ticks: { color: tickColor, precision: 0, stepSize: 1 },
                 grid: { color: gridColor }, border: { display: false } }
        }
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
