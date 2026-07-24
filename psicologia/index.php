<?php
/**
 * Psicología: bitácora de sesiones + escalas validadas PHQ-9 (depresión) y
 * GAD-7 (ansiedad) con su interpretación y evolución en el tiempo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_psico_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'add') {
        $n = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? (int) $_POST[$k] : null;
        $riesgo = in_array($_POST['riesgo'] ?? '', ['ninguno','bajo','moderado','alto'], true) ? $_POST['riesgo'] : null;
        db()->prepare('INSERT INTO psico_sesiones (consultorio_id, paciente_id, fecha, enfoque, notas, tareas, phq9, gad7, riesgo, creado_por)
                       VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                trim($_POST['enfoque'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, trim($_POST['tareas'] ?? '') ?: null,
                $n('phq9'), $n('gad7'), $riesgo, $u['id']]);
        auditar('crear', 'psico_sesion', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Sesión registrada.');
        redirect('/psicologia/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('DELETE FROM psico_sesiones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Sesión eliminada.');
        redirect('/psicologia/index?paciente_id=' . $pid);
    }
}

$ses = db()->prepare('SELECT * FROM psico_sesiones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$ses->execute([$pid, tenant_id()]);
$ses = $ses->fetchAll();

$gLabels = $gPhq = $gGad = [];
foreach ($ses as $s) {
    $gLabels[] = date('d/m/y', strtotime($s['fecha']));
    $gPhq[] = $s['phq9'] !== null ? (int) $s['phq9'] : null;
    $gGad[] = $s['gad7'] !== null ? (int) $s['gad7'] : null;
}
$hayGraf = count(array_filter($gPhq, fn($x)=>$x!==null)) || count(array_filter($gGad, fn($x)=>$x!==null));
$ultima  = $ses ? $ses[count($ses) - 1] : null;

$titulo = t('Psicología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-chat-heart text-brand"></i> <?= et('Psicología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong> · <?= count($ses) ?> <?= et('sesiones') ?></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima && ($ultima['phq9'] !== null || $ultima['gad7'] !== null)): ?>
<?php [$pl,$pc] = phq9_nivel($ultima['phq9'] !== null ? (int)$ultima['phq9'] : null); [$gl,$gc] = gad7_nivel($ultima['gad7'] !== null ? (int)$ultima['gad7'] : null); ?>
<div class="card mb-4"><div class="card-body">
    <div class="row g-4">
        <div class="col-6"><div class="text-muted small text-uppercase"><?= et('PHQ-9 (depresión)') ?></div><div class="h5 mb-0"><?= $ultima['phq9'] !== null ? (int)$ultima['phq9'].'/27' : '—' ?> <span class="badge bg-<?= $pc ?> align-middle"><?= et($pl) ?></span></div></div>
        <div class="col-6"><div class="text-muted small text-uppercase"><?= et('GAD-7 (ansiedad)') ?></div><div class="h5 mb-0"><?= $ultima['gad7'] !== null ? (int)$ultima['gad7'].'/21' : '—' ?> <span class="badge bg-<?= $gc ?> align-middle"><?= et($gl) ?></span></div></div>
    </div>
</div></div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-up text-brand"></i> <?= et('Evolución de escalas') ?></h2>
    <div style="height:220px"><canvas id="chEscalas"></canvas></div>
</div></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nueva sesión') ?></h2>
    <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="add"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6 col-md-6"><label class="form-label small"><?= et('Enfoque / tema') ?></label><input type="text" name="enfoque" class="form-control" maxlength="160"></div>
        <div class="col-4 col-md-1"><label class="form-label small">PHQ-9</label><input type="number" min="0" max="27" name="phq9" class="form-control" title="<?= e(t('Depresión 0-27')) ?>"></div>
        <div class="col-4 col-md-1"><label class="form-label small">GAD-7</label><input type="number" min="0" max="21" name="gad7" class="form-control" title="<?= e(t('Ansiedad 0-21')) ?>"></div>
        <div class="col-4 col-md-2"><label class="form-label small"><?= et('Riesgo') ?></label>
            <select name="riesgo" class="form-select"><option value=""><?= et('—') ?></option><option value="ninguno"><?= et('Ninguno') ?></option><option value="bajo"><?= et('Bajo') ?></option><option value="moderado"><?= et('Moderado') ?></option><option value="alto"><?= et('Alto') ?></option></select>
        </div>
        <div class="col-12"><label class="form-label small"><?= et('Notas / evolución') ?></label><textarea name="notas" class="form-control" rows="3"></textarea></div>
        <div class="col-12"><label class="form-label small"><?= et('Tareas para el paciente') ?></label><input type="text" name="tareas" class="form-control" maxlength="255"></div>
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar sesión') ?></button></div>
    </form>
</div></div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-brand"></i> <?= et('Sesiones') ?></div>
    <?php if (!$ses): ?>
        <div class="card-body text-center text-muted py-4"><?= et('Sin sesiones registradas.') ?></div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach (array_reverse($ses) as $i => $s): $num = count($ses) - $i; [$pl,$pc]=phq9_nivel($s['phq9']!==null?(int)$s['phq9']:null); [$gl,$gc]=gad7_nivel($s['gad7']!==null?(int)$s['gad7']:null); ?>
        <li class="list-group-item">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= et('Sesión') ?> #<?= $num ?> · <?= e(fmt_fecha($s['fecha'])) ?><?php if ($s['enfoque']): ?> · <span class="text-muted fw-normal"><?= e($s['enfoque']) ?></span><?php endif; ?></div>
                    <div class="small mt-1">
                        <?php if ($s['phq9'] !== null): ?><span class="badge bg-<?= $pc ?> me-1">PHQ-9 <?= (int)$s['phq9'] ?> · <?= et($pl) ?></span><?php endif; ?>
                        <?php if ($s['gad7'] !== null): ?><span class="badge bg-<?= $gc ?> me-1">GAD-7 <?= (int)$s['gad7'] ?> · <?= et($gl) ?></span><?php endif; ?>
                        <?php if ($s['riesgo']): ?><span class="badge bg-<?= $s['riesgo']==='alto'?'danger':($s['riesgo']==='moderado'?'warning':'secondary') ?> me-1"><?= et('Riesgo') ?>: <?= et(ucfirst($s['riesgo'])) ?></span><?php endif; ?>
                    </div>
                    <?php if ($s['notas']): ?><div class="small mt-2"><?= nl2br(e($s['notas'])) ?></div><?php endif; ?>
                    <?php if ($s['tareas']): ?><div class="small text-muted mt-1"><i class="bi bi-check2-square"></i> <?= e($s['tareas']) ?></div><?php endif; ?>
                </div>
                <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta sesión?')) ?>');">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="del"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if ($hayGraf): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('chEscalas'); if (!el) return;
    var grid = 'rgba(127,127,127,.14)';
    new Chart(el, { type: 'line', data: { labels: <?= json_encode($gLabels) ?>, datasets: [
        { label: 'PHQ-9', data: <?= json_encode($gPhq) ?>, borderColor: '#7c3aed', backgroundColor: '#7c3aed', tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 },
        { label: 'GAD-7', data: <?= json_encode($gGad) ?>, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 }
    ] }, options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 10, font: { size: 11 } } } },
        scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 8, font: { size: 10 } } }, y: { grid: { color: grid }, beginAtZero: true, ticks: { font: { size: 10 } } } } } });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
