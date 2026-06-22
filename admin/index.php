<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_superadmin();

$pdo = db();

// --- Acciones sobre un consultorio ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cid    = (int) ($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';

    if ($cid === 1 && in_array($accion, ['suspender'], true)) {
        flash('No puedes suspender el consultorio principal.', 'warning');
        redirect('/admin/index');
    }
    switch ($accion) {
        case 'extender':   // +15 días de prueba (a partir de hoy o del fin actual)
            $pdo->prepare("UPDATE consultorios SET estado='trial',
                           trial_fin = DATE_ADD(GREATEST(trial_fin, CURDATE()), INTERVAL 15 DAY) WHERE id=?")->execute([$cid]);
            auditar('trial_extender', 'consultorio', $cid, null, $cid);
            flash('Prueba extendida 15 días.');
            break;
        case 'activar':    // membresía activa (sin caducidad). No toca el plan:
                           // el plan/módulos se asignan en /admin/consultorio.
            $pdo->prepare("UPDATE consultorios SET estado='activa' WHERE id=?")->execute([$cid]);
            auditar('activar', 'consultorio', $cid, null, $cid);
            flash('Consultorio activado. Asigna su plan en «Plan y módulos».');
            break;
        case 'suspender':
            $pdo->prepare("UPDATE consultorios SET estado='suspendida' WHERE id=?")->execute([$cid]);
            auditar('suspender', 'consultorio', $cid, null, $cid);
            flash('Consultorio suspendido.');
            break;
    }
    redirect('/admin/index');
}

// --- Listado de consultorios con métricas ---
$consultorios = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM usuarios  u WHERE u.consultorio_id = c.id) n_usuarios,
            (SELECT COUNT(*) FROM pacientes p WHERE p.consultorio_id = c.id) n_pacientes,
            (SELECT COUNT(*) FROM citas     ci WHERE ci.consultorio_id = c.id) n_citas
     FROM consultorios c
     ORDER BY (c.estado='suspendida') DESC, c.creado_en DESC"
)->fetchAll();

// Resumen
$tot = ['total' => 0, 'trial' => 0, 'activa' => 0, 'suspendida' => 0, 'expirada' => 0];
foreach ($consultorios as $c) { $tot['total']++; $tot[$c['estado']] = ($tot[$c['estado']] ?? 0) + 1; }

$badge = ['trial' => 'info', 'activa' => 'success', 'suspendida' => 'danger', 'expirada' => 'secondary'];
$planNombres = array_map(fn($p) => $p['nombre'], planes_mp());

$titulo = t('Súper-admin');
$activo = 'admin';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-shield-lock text-brand"></i> <?= et('Consultorios') ?></h1>
        <p class="text-muted mb-0"><?= et('Gestión de todos los consultorios de') ?> <?= e(APP_NAME) ?>.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $tarjetas = [
        ['Total',        $tot['total'],        'bi-buildings',   '#0b6fb8'],
        ['En prueba',    $tot['trial'],        'bi-stopwatch',   '#6366f1'],
        ['Activos',      $tot['activa'],       'bi-check-circle','#16a34a'],
        ['Suspendidos',  $tot['suspendida'],   'bi-pause-circle','#ef4444'],
    ];
    foreach ($tarjetas as [$lbl, $num, $ic, $col]): ?>
    <div class="col-6 col-xl-3">
        <div class="card stat-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:<?= $col ?>1f;color:<?= $col ?>"><i class="bi <?= $ic ?>"></i></div>
            <div>
                <div class="stat-num" style="font-size:1.6rem"><?= (int) $num ?></div>
                <div class="stat-label"><?= et($lbl) ?></div>
            </div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th><?= et('Consultorio') ?></th><th><?= et('Estado') ?></th><th><?= et('Prueba/Vence') ?></th><th class="text-center"><?= et('Usuarios') ?></th><th class="text-center"><?= et('Pacientes') ?></th><th class="text-center"><?= et('Citas') ?></th><th><?= et('Alta') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr>
            </thead>
            <tbody>
            <?php if (!$consultorios): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= et('Aún no hay consultorios registrados.') ?></td></tr>
            <?php else: foreach ($consultorios as $c):
                $dias = (int) floor((strtotime($c['trial_fin']) - strtotime('today')) / 86400); ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($c['nombre']) ?><?php if ($c['id'] == 1): ?> <span class="badge bg-light text-dark border"><?= et('principal') ?></span><?php endif; ?></div>
                        <div class="small text-muted">
                            <i class="bi bi-envelope"></i> <?= e($c['email']) ?>
                            <?php if (!empty($c['telefono'])): ?>
                                · <i class="bi bi-telephone"></i> <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $c['telefono'])) ?>"><?= e($c['telefono']) ?></a>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-<?= $badge[$c['estado']] ?? 'secondary' ?>"><?= et(ucfirst($c['estado'])) ?></span>
                        <div class="small text-muted mt-1"><i class="bi bi-stars"></i> <?= e($planNombres[$c['plan']] ?? $c['plan']) ?></div>
                    </td>
                    <td class="small">
                        <?php if ($c['estado'] === 'trial'): ?>
                            <?= fmt_fecha($c['trial_fin']) ?>
                            <span class="badge bg-<?= $dias < 0 ? 'danger' : ($dias <= 3 ? 'warning' : 'secondary') ?>">
                                <?= $dias < 0 ? et('vencida') : $dias . ' ' . et('días') ?>
                            </span>
                        <?php elseif ($c['estado'] === 'activa'): ?>
                            <span class="text-success"><?= et('Sin caducidad') ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-center"><?= (int) $c['n_usuarios'] ?></td>
                    <td class="text-center"><?= (int) $c['n_pacientes'] ?></td>
                    <td class="text-center"><?= (int) $c['n_citas'] ?></td>
                    <td class="small"><?= fmt_fecha($c['creado_en']) ?></td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><?= et('Gestionar') ?></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>/admin/consultorio?id=<?= $c['id'] ?>"><i class="bi bi-stars me-2"></i><?= et('Plan y módulos') ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="extender" class="dropdown-item"><i class="bi bi-stopwatch me-2"></i><?= et('Extender prueba 15 días') ?></button></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="activar" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i><?= et('Activar membresía') ?></button></li>
                                <?php if ($c['id'] != 1): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><button form="f<?= $c['id'] ?>" name="accion" value="suspender" class="dropdown-item text-danger" onclick="return confirm('¿Suspender este consultorio? Perderá el acceso.');"><i class="bi bi-pause-circle me-2"></i><?= et('Suspender') ?></button></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <form id="f<?= $c['id'] ?>" method="post" class="d-none">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
