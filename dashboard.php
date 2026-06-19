<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$u        = current_user();
$esMedico = $u['rol'] === 'medico';
$pdo      = db();

/* Filtro por médico cuando el usuario es médico (ve solo lo suyo). */
$medFiltro = $esMedico ? ' AND medico_id = ' . (int) $u['id'] : '';

// --- Estadísticas ---
$citasHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE fecha = CURDATE() AND estado <> 'cancelada' $medFiltro"
)->fetchColumn();
$citasConfHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE fecha = CURDATE() AND estado IN ('confirmada','atendida') $medFiltro"
)->fetchColumn();

$totPacientes  = (int) $pdo->query('SELECT COUNT(*) FROM pacientes')->fetchColumn();
$pacientesMes  = (int) $pdo->query(
    "SELECT COUNT(*) FROM pacientes WHERE YEAR(creado_en)=YEAR(CURDATE()) AND MONTH(creado_en)=MONTH(CURDATE())"
)->fetchColumn();

$consultasMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM consultas WHERE YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
)->fetchColumn();

$citasPend = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE fecha >= CURDATE() AND estado IN ('programada','confirmada') $medFiltro"
)->fetchColumn();

$recetasMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM recetas WHERE YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
)->fetchColumn();

$ingresosMes = (float) $pdo->query(
    "SELECT COALESCE(SUM(total),0) FROM facturas WHERE estado='pagada'
     AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())"
)->fetchColumn();

// --- Citas por semana (lunes a domingo de la semana actual) ---
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$wk = [];
for ($i = 0; $i < 7; $i++) $wk[date('Y-m-d', strtotime("$monday +$i day"))] = 0;
$stmt = $pdo->prepare(
    "SELECT DATE(fecha) d, COUNT(*) c FROM citas
     WHERE fecha BETWEEN ? AND ? AND estado <> 'cancelada' $medFiltro GROUP BY DATE(fecha)"
);
$stmt->execute([$monday, $sunday]);
foreach ($stmt as $r) if (isset($wk[$r['d']])) $wk[$r['d']] = (int) $r['c'];
$chartData = array_values($wk);

// --- Agenda de hoy ---
$ag = $pdo->prepare(
    "SELECT c.*, p.nombre, p.apellidos FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     WHERE c.fecha = CURDATE() AND c.estado <> 'cancelada' $medFiltro
     ORDER BY c.hora LIMIT 8"
);
$ag->execute();
$agendaHoy = $ag->fetchAll();

// --- Últimos expedientes (últimas consultas) ---
$ue = $pdo->prepare(
    "SELECT co.fecha, co.diagnostico, p.id pid, p.nombre, p.apellidos,
            (SELECT COUNT(*) FROM citas ci WHERE ci.paciente_id=p.id
             AND ci.fecha>=CURDATE() AND ci.estado IN('programada','confirmada')) futuras
     FROM consultas co JOIN pacientes p ON p.id = co.paciente_id
     WHERE 1=1 $medFiltro
     ORDER BY co.fecha DESC LIMIT 6"
);
$ue->execute();
$ultimos = $ue->fetchAll();

$titulo = 'Panel';
$activo = 'dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h3 mb-1 text-white">Panel Principal</h1>
        <p class="text-muted mb-0 text-capitalize"><?= e(fecha_hoy_larga()) ?></p>
    </div>
    <a href="<?= BASE_URL ?>/citas/create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva Cita</a>
</div>

<!-- Tarjetas de estadísticas -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div class="stat-label">Citas Hoy</div>
                <span class="delta delta-up">+<?= $citasConfHoy ?> conf.</span>
            </div>
            <div class="stat-num mt-2"><?= $citasHoy ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div class="stat-label">Pacientes</div>
                <span class="delta <?= $pacientesMes ? 'delta-up' : 'delta-flat' ?>">+<?= $pacientesMes ?> mes</span>
            </div>
            <div class="stat-num mt-2"><?= $totPacientes ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div class="stat-label">Recetas emitidas (mes)</div>
                <span class="delta delta-flat">recetas</span>
            </div>
            <div class="stat-num mt-2"><?= $recetasMes ?></div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div class="stat-label">Ingresos (<?= e(moneda()) ?>)</div>
                <span class="delta delta-up">mes</span>
            </div>
            <div class="stat-num mt-2" style="font-size:1.5rem"><?= fmt_money($ingresosMes) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- Gráfica: citas por semana -->
    <div class="col-lg-8">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-bar-chart-fill text-info"></i> Citas por Semana</h2>
            <canvas id="chartSemana" height="110"></canvas>
        </div></div>
    </div>
    <!-- Agenda de hoy -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-calendar-day text-info"></i> Agenda de Hoy</span>
                <a href="<?= BASE_URL ?>/citas/index.php" class="small">Ver completa →</a>
            </div>
            <div class="card-body p-0">
                <?php if (!$agendaHoy): ?>
                    <p class="text-muted text-center py-4 mb-0">Sin citas para hoy.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($agendaHoy as $c): ?>
                        <li class="list-group-item d-flex align-items-center justify-content-between px-3">
                            <span>
                                <span class="text-info fw-semibold me-2"><?= fmt_hora($c['hora']) ?></span>
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
    <div class="card-header fw-semibold"><i class="bi bi-folder2-open text-info"></i> Últimos Expedientes</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Paciente</th><th>Última visita</th><th>Diagnóstico</th><th class="text-end">Estado</th></tr></thead>
            <tbody>
            <?php if (!$ultimos): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Aún no hay consultas registradas.</td></tr>
            <?php else: foreach ($ultimos as $r): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $r['pid'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($r['nombre'] . ' ' . $r['apellidos']) ?>
                        </a>
                    </td>
                    <td><?= fmt_fecha($r['fecha']) ?></td>
                    <td><?= e($r['diagnostico'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ($r['futuras'] > 0): ?>
                            <span class="badge rounded-pill text-bg-info">Seguimiento</span>
                        <?php else: ?>
                            <span class="badge rounded-pill text-bg-success">Activo</span>
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
const grad = ctx.getContext('2d').createLinearGradient(0, 0, 0, 220);
grad.addColorStop(0, 'rgba(43,196,221,.95)');
grad.addColorStop(1, 'rgba(34,197,94,.55)');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
        datasets: [{
            label: 'Citas',
            data: <?= json_encode($chartData) ?>,
            backgroundColor: grad,
            borderRadius: 6,
            maxBarThickness: 38
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#93a6c4' } },
            y: { beginAtZero: true, ticks: { color: '#93a6c4', precision: 0 },
                 grid: { color: 'rgba(148,170,200,.12)' } }
        }
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
