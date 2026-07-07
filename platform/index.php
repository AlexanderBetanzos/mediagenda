<?php
/**
 * Consola de PLATAFORMA — dueño del sistema. Gestiona todos los consultorios
 * suscritos: métricas de negocio, altas, planes, y acciones (extender prueba,
 * activar, suspender, eliminar, impersonar).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_platform();

$pdo = db();

/* ── Acciones sobre un consultorio ──────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cid    = (int) ($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    if ($cid === 1 && in_array($accion, ['suspender', 'eliminar'], true)) {
        flash('No puedes suspender ni eliminar el consultorio principal.', 'warning');
        redirect('/platform/index');
    }
    switch ($accion) {
        case 'extender':
            $pdo->prepare("UPDATE consultorios SET estado='trial',
                           trial_fin = DATE_ADD(GREATEST(trial_fin, CURDATE()), INTERVAL 15 DAY) WHERE id=?")->execute([$cid]);
            auditar('trial_extender', 'consultorio', $cid, null, $cid);
            flash('Prueba extendida 15 días.');
            break;
        case 'activar':
            $pdo->prepare("UPDATE consultorios SET estado='activa' WHERE id=?")->execute([$cid]);
            auditar('activar', 'consultorio', $cid, null, $cid);
            flash('Consultorio activado. Asigna su plan en «Plan y módulos».');
            break;
        case 'suspender':
            $pdo->prepare("UPDATE consultorios SET estado='suspendida' WHERE id=?")->execute([$cid]);
            auditar('suspender', 'consultorio', $cid, null, $cid);
            flash('Consultorio suspendido.');
            break;
        case 'eliminar':
            $nom = $pdo->prepare("SELECT nombre FROM consultorios WHERE id=?");
            $nom->execute([$cid]);
            $nombreCons = $nom->fetchColumn();
            if ($nombreCons === false) { flash('Consultorio no encontrado.', 'warning'); break; }
            try {
                $pdo->beginTransaction();
                $tablas = $pdo->query(
                    "SELECT TABLE_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'consultorio_id'"
                )->fetchAll(PDO::FETCH_COLUMN);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($tablas as $tabla) {
                    $pdo->prepare("DELETE FROM `$tabla` WHERE consultorio_id = ?")->execute([$cid]);
                }
                $pdo->prepare("DELETE FROM consultorios WHERE id = ?")->execute([$cid]);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                $pdo->commit();
                auditar('eliminar', 'consultorio', $cid, $nombreCons);
                flash('Consultorio «' . $nombreCons . '» eliminado permanentemente.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                flash('No se pudo eliminar el consultorio.', 'danger');
            }
            break;
    }
    redirect('/platform/index');
}

/* ── Catálogo de planes (nombre + precio) ───────────────────────────── */
$planes = planes_mp();
$planNombre = [];
$planPrecio = [];
foreach ($planes as $clave => $p) { $planNombre[$clave] = $p['nombre']; $planPrecio[$clave] = (float) $p['precio']; }

/* ── Listado con métricas ───────────────────────────────────────────── */
$consultorios = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM usuarios  u  WHERE u.consultorio_id  = c.id) n_usuarios,
            (SELECT COUNT(*) FROM pacientes p  WHERE p.consultorio_id  = c.id) n_pacientes,
            (SELECT COUNT(*) FROM citas     ci WHERE ci.consultorio_id = c.id) n_citas
     FROM consultorios c
     ORDER BY (c.estado='suspendida') DESC, c.creado_en DESC"
)->fetchAll();

/* ── Resumen + MRR estimado ─────────────────────────────────────────── */
$tot = ['total' => 0, 'trial' => 0, 'activa' => 0, 'suspendida' => 0, 'expirada' => 0];
$mrr = 0.0;
$porPlan = [];
foreach ($consultorios as $c) {
    $tot['total']++;
    $tot[$c['estado']] = ($tot[$c['estado']] ?? 0) + 1;
    if ($c['estado'] === 'activa') {
        $mrr += $planPrecio[$c['plan']] ?? 0;
        $nom = $planNombre[$c['plan']] ?? ucfirst($c['plan']);
        $porPlan[$nom] = ($porPlan[$nom] ?? 0) + 1;
    }
}

/* ── Altas por mes (últimos 12 meses) ───────────────────────────────── */
$MESES = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$altasByMonth = $pdo->query(
    "SELECT DATE_FORMAT(creado_en,'%Y-%m') ym, COUNT(*) n FROM consultorios
     WHERE creado_en >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$altasLabels = $altasData = [];
$fom = date('Y-m-01');
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("$fom -$i month"));
    $altasLabels[] = $MESES[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 2, 2);
    $altasData[]   = (int) ($altasByMonth[$ym] ?? 0);
}

$badge = ['trial' => 'info', 'activa' => 'success', 'suspendida' => 'danger', 'expirada' => 'secondary'];

$titulo = 'Plataforma';
include __DIR__ . '/_head.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-diagram-3 text-brand"></i> <?= et('Consola de plataforma') ?></h1>
        <p class="text-muted mb-0"><?= et('Gestión de todos los consultorios suscritos a') ?> <?= e(APP_NAME) ?>.</p>
    </div>
</div>

<?php foreach (get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>

<!-- KPIs de negocio -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        [et('Consultorios'), (string) $tot['total'],       'bi-buildings',      'var(--brand)'],
        [et('MRR estimado'), fmt_money($mrr),              'bi-graph-up-arrow', '#22c55e'],
        [et('Activos'),      (string) $tot['activa'],       'bi-check-circle',   '#22c55e'],
        [et('En prueba'),    (string) $tot['trial'],        'bi-stopwatch',      '#6366f1'],
        [et('Suspendidos'),  (string) $tot['suspendida'],   'bi-pause-circle',   '#ef4444'],
    ];
    foreach ($kpis as [$lbl, $val, $ic, $col]): ?>
    <div class="col-6 col-xl">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:color-mix(in srgb,<?= $col ?> 16%,transparent);color:<?= $col ?>"><i class="bi <?= $ic ?>"></i></div>
            <div><div class="stat-num" style="font-size:1.5rem"><?= e($val) ?></div><div class="stat-label"><?= e($lbl) ?></div></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Gráficas -->
<div class="row g-3 mb-4">
    <div class="col-lg-8"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-bar-chart-fill text-brand"></i> <?= et('Altas de consultorios · 12 meses') ?></div>
        <div class="card-body"><div style="height:280px"><canvas id="chartAltas"></canvas></div></div>
    </div></div>
    <div class="col-lg-4"><div class="card h-100">
        <div class="card-header fw-semibold"><i class="bi bi-pie-chart-fill text-brand"></i> <?= et('Activos por plan') ?></div>
        <div class="card-body d-flex align-items-center justify-content-center">
            <?php if ($porPlan): ?><div style="height:280px;width:100%"><canvas id="chartPlanes"></canvas></div>
            <?php else: ?><p class="text-muted small mb-0"><?= et('Sin consultorios activos aún.') ?></p><?php endif; ?>
        </div>
    </div></div>
</div>

<!-- Listado de consultorios -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-buildings text-brand"></i> <?= et('Consultorios') ?></span>
        <input type="search" id="buscar" class="form-control form-control-sm" style="max-width:240px" placeholder="<?= e(t('Buscar consultorio…')) ?>">
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tabla">
            <thead>
                <tr><th><?= et('Consultorio') ?></th><th><?= et('Plan') ?></th><th><?= et('Estado') ?></th><th><?= et('Prueba/Vence') ?></th><th class="text-center"><?= et('Usuarios') ?></th><th class="text-center"><?= et('Pacientes') ?></th><th class="text-center"><?= et('Citas') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr>
            </thead>
            <tbody>
            <?php if (!$consultorios): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= et('Aún no hay consultorios registrados.') ?></td></tr>
            <?php else: foreach ($consultorios as $c):
                $dias = (int) floor((strtotime($c['trial_fin']) - strtotime('today')) / 86400); ?>
                <tr data-buscar="<?= e(mb_strtolower($c['nombre'] . ' ' . $c['email'])) ?>">
                    <td>
                        <div class="fw-semibold"><?= e($c['nombre']) ?><?php if ($c['id'] == 1): ?> <span class="badge bg-secondary bg-opacity-50"><?= et('principal') ?></span><?php endif; ?></div>
                        <div class="small text-muted"><i class="bi bi-envelope"></i> <?= e($c['email']) ?><?php if (!empty($c['telefono'])): ?> · <i class="bi bi-telephone"></i> <?= e($c['telefono']) ?><?php endif; ?></div>
                    </td>
                    <td class="small"><i class="bi bi-stars text-brand"></i> <?= e($planNombre[$c['plan']] ?? ucfirst($c['plan'])) ?><?php if (($planPrecio[$c['plan']] ?? 0) > 0): ?><div class="text-muted"><?= fmt_money($planPrecio[$c['plan']]) ?>/mes</div><?php endif; ?></td>
                    <td><span class="badge bg-<?= $badge[$c['estado']] ?? 'secondary' ?>"><?= et(ucfirst($c['estado'])) ?></span></td>
                    <td class="small">
                        <?php if ($c['estado'] === 'trial'): ?>
                            <?= fmt_fecha($c['trial_fin']) ?>
                            <span class="badge bg-<?= $dias < 0 ? 'danger' : ($dias <= 3 ? 'warning' : 'secondary') ?>"><?= $dias < 0 ? et('vencida') : $dias . ' ' . et('días') ?></span>
                        <?php elseif ($c['estado'] === 'activa'): ?><span class="text-success"><?= et('Sin caducidad') ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int) $c['n_usuarios'] ?></td>
                    <td class="text-center"><?= (int) $c['n_pacientes'] ?></td>
                    <td class="text-center"><?= (int) $c['n_citas'] ?></td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><?= et('Gestionar') ?></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <form method="post" action="<?= BASE_URL ?>/platform/impersonar" class="m-0">
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button class="dropdown-item"><i class="bi bi-box-arrow-in-right me-2"></i><?= et('Entrar como este consultorio') ?></button>
                                    </form>
                                </li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>/platform/consultorio?id=<?= $c['id'] ?>"><i class="bi bi-stars me-2"></i><?= et('Plan y módulos') ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="extender" class="dropdown-item"><i class="bi bi-stopwatch me-2"></i><?= et('Extender prueba 15 días') ?></button></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="activar" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i><?= et('Activar membresía') ?></button></li>
                                <?php if ($c['id'] != 1): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="suspender" class="dropdown-item text-danger" onclick="return confirm('¿Suspender este consultorio? Perderá el acceso.');"><i class="bi bi-pause-circle me-2"></i><?= et('Suspender') ?></button></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="eliminar" class="dropdown-item text-danger fw-semibold" onclick="return confirm('¿ELIMINAR «<?= e(addslashes($c['nombre'])) ?>» y TODOS sus datos?\n\nEsta acción NO se puede deshacer.');"><i class="bi bi-trash me-2"></i><?= et('Eliminar definitivamente') ?></button></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <form id="f<?= $c['id'] ?>" method="post" class="d-none"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>"></form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.getElementById('buscar').oninput = function () {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#tabla tbody tr[data-buscar]').forEach(function (tr) {
        tr.style.display = tr.dataset.buscar.indexOf(q) !== -1 ? '' : 'none';
    });
};
(function () {
    if (typeof Chart === 'undefined') return;
    var isLight = document.documentElement.classList.contains('app-light');
    var tick = isLight ? '#6b7c93' : '#9aa0aa', grid = isLight ? 'rgba(15,39,71,.07)' : 'rgba(255,255,255,.07)';
    Chart.defaults.color = tick; Chart.defaults.font.family = "'Inter',sans-serif";
    var PAL = ['#f66f14', '#ff9a4d', '#ffd60a', '#38bdf8', '#22c55e', '#a78bfa'];
    var a = document.getElementById('chartAltas');
    if (a) new Chart(a, { type: 'bar',
        data: { labels: <?= json_encode($altasLabels) ?>, datasets: [{ label: 'Altas', data: <?= json_encode($altasData) ?>, backgroundColor: '#f66f14', borderRadius: 6 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { x: { grid: { color: grid }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } } });
    var pl = document.getElementById('chartPlanes');
    if (pl) new Chart(pl, { type: 'doughnut',
        data: { labels: <?= json_encode(array_keys($porPlan)) ?>, datasets: [{ data: <?= json_encode(array_values($porPlan)) ?>, backgroundColor: PAL, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12 } } } } });
})();
</script>

<?php include __DIR__ . '/_foot.php'; ?>
