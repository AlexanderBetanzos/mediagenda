<?php
/**
 * Nutrición: valoraciones antropométricas con evolución. Cada visita registra
 * peso, % grasa/músculo, cintura/cadera, IMC (calculado), meta de peso y el
 * plan (kcal + indicaciones). Las series se grafican contra el tiempo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_nutricion_table();

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
        $n = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        db()->prepare('INSERT INTO nutricion_valoraciones
            (consultorio_id, paciente_id, fecha, peso, estatura, grasa_pct, musculo_pct, cintura, cadera, meta_peso, kcal_plan, plan, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                $n('peso'), $n('estatura'), $n('grasa_pct'), $n('musculo_pct'), $n('cintura'), $n('cadera'),
                $n('meta_peso'), $n('kcal_plan'), trim($_POST['plan'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'nutricion', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración registrada.');
        redirect('/nutricion/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('DELETE FROM nutricion_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/nutricion/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM nutricion_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

// Estatura de referencia: la última capturada (se arrastra si no la recapturan).
$estatura = 0.0;
foreach (array_reverse($vals) as $v) { if ($v['estatura'] > 0) { $estatura = (float) $v['estatura']; break; } }

$gLabels = $gPeso = $gImc = $gGrasa = $gCintura = [];
foreach ($vals as $v) {
    $gLabels[] = date('d/m/y', strtotime($v['fecha']));
    $gPeso[]   = $v['peso'] !== null ? (float) $v['peso'] : null;
    $est = ($v['estatura'] > 0) ? (float) $v['estatura'] : $estatura;
    $b = ($v['peso'] > 0 && $est > 0) ? imc((float) $v['peso'], $est) : 0;
    $gImc[]     = $b ? round((float) $b, 1) : null;
    $gGrasa[]   = $v['grasa_pct'] !== null ? (float) $v['grasa_pct'] : null;
    $gCintura[] = $v['cintura'] !== null ? (float) $v['cintura'] : null;
}
$hayGraf = count(array_filter($gPeso, fn($x)=>$x!==null)) || count(array_filter($gImc, fn($x)=>$x!==null));
$ultima  = $vals ? $vals[count($vals) - 1] : null;

$imcAct = 0.0; $imcCls = ['—','secondary'];
if ($ultima) {
    $estU = ($ultima['estatura'] > 0) ? (float) $ultima['estatura'] : $estatura;
    if ($ultima['peso'] > 0 && $estU > 0) { $imcAct = (float) imc((float) $ultima['peso'], $estU); $imcCls = imc_clasificacion($imcAct); }
}

$titulo = t('Nutrición');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-egg-fried text-brand"></i> <?= et('Nutrición') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima): ?>
<div class="card mb-4"><div class="card-body">
    <div class="row g-4">
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('IMC actual') ?></div><div class="h4 mb-0"><?= $imcAct ? number_format($imcAct,1) : '—' ?> <span class="badge bg-<?= $imcCls[1] ?> align-middle"><?= et($imcCls[0]) ?></span></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Peso') ?></div><div class="fw-semibold"><?= $ultima['peso'] !== null ? e($ultima['peso']) . ' kg' : '—' ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Meta de peso') ?></div><div class="fw-semibold"><?= $ultima['meta_peso'] !== null ? e($ultima['meta_peso']) . ' kg' : '—' ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Plan (kcal)') ?></div><div class="fw-semibold"><?= $ultima['kcal_plan'] !== null ? e($ultima['kcal_plan']) . ' kcal' : '—' ?></div></div>
    </div>
    <?php if ($ultima['plan']): ?><div class="mt-3 small"><strong><?= et('Plan alimenticio:') ?></strong> <?= nl2br(e($ultima['plan'])) ?></div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-up text-brand"></i> <?= et('Evolución') ?></h2>
    <div class="row g-3">
        <div class="col-md-3"><div class="small fw-semibold mb-1"><?= et('Peso (kg)') ?></div><div style="height:170px"><canvas id="chPeso"></canvas></div></div>
        <div class="col-md-3"><div class="small fw-semibold mb-1"><?= et('IMC') ?></div><div style="height:170px"><canvas id="chImc"></canvas></div></div>
        <div class="col-md-3"><div class="small fw-semibold mb-1"><?= et('% Grasa') ?></div><div style="height:170px"><canvas id="chGrasa"></canvas></div></div>
        <div class="col-md-3"><div class="small fw-semibold mb-1"><?= et('Cintura (cm)') ?></div><div style="height:170px"><canvas id="chCintura"></canvas></div></div>
    </div>
</div></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nueva valoración') ?></h2>
    <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="add"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Peso (kg)') ?></label><input type="number" step="0.01" min="0" name="peso" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Estatura (cm)') ?></label><input type="number" step="0.01" min="0" name="estatura" class="form-control" value="<?= $estatura ?: '' ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('% Grasa') ?></label><input type="number" step="0.1" min="0" name="grasa_pct" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('% Músculo') ?></label><input type="number" step="0.1" min="0" name="musculo_pct" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Cintura (cm)') ?></label><input type="number" step="0.1" min="0" name="cintura" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Cadera (cm)') ?></label><input type="number" step="0.1" min="0" name="cadera" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Meta peso (kg)') ?></label><input type="number" step="0.01" min="0" name="meta_peso" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Plan (kcal)') ?></label><input type="number" min="0" name="kcal_plan" class="form-control"></div>
        <div class="col-12"><label class="form-label small"><?= et('Plan alimenticio / indicaciones') ?></label><textarea name="plan" class="form-control" rows="2"></textarea></div>
        <div class="col-12"><label class="form-label small"><?= et('Notas') ?></label><input type="text" name="notas" class="form-control" maxlength="255"></div>
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar valoración') ?></button></div>
    </form>
</div></div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-brand"></i> <?= et('Historial de valoraciones') ?> (<?= count($vals) ?>)</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th><?= et('Fecha') ?></th><th><?= et('Peso') ?></th><th><?= et('IMC') ?></th><th><?= et('% Grasa') ?></th><th><?= et('Cintura') ?></th><th><?= et('Cadera') ?></th><th><?= et('Meta') ?></th><th></th></tr></thead>
            <tbody>
            <?php if (!$vals): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= et('Sin valoraciones.') ?></td></tr>
            <?php else: foreach (array_reverse($vals) as $v):
                $estV = ($v['estatura'] > 0) ? (float) $v['estatura'] : $estatura;
                $bV = ($v['peso'] > 0 && $estV > 0) ? imc((float) $v['peso'], $estV) : 0;
            ?>
                <tr>
                    <td class="small"><?= e(fmt_fecha($v['fecha'])) ?></td>
                    <td><?= $v['peso'] !== null ? e($v['peso']) . ' kg' : '—' ?></td>
                    <td><?= $bV ? number_format((float)$bV,1) : '—' ?></td>
                    <td><?= $v['grasa_pct'] !== null ? e($v['grasa_pct']) . '%' : '—' ?></td>
                    <td><?= $v['cintura'] !== null ? e($v['cintura']) . ' cm' : '—' ?></td>
                    <td><?= $v['cadera'] !== null ? e($v['cadera']) . ' cm' : '—' ?></td>
                    <td><?= $v['meta_peso'] !== null ? e($v['meta_peso']) . ' kg' : '—' ?></td>
                    <td class="text-end">
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta valoración?')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="del"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($hayGraf): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var labels = <?= json_encode($gLabels) ?>;
    var grid = 'rgba(127,127,127,.14)';
    function line(id, data, color) {
        var el = document.getElementById(id); if (!el) return;
        new Chart(el, { type: 'line', data: { labels: labels, datasets: [{ data: data, borderColor: color, backgroundColor: color, tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 6, font: { size: 10 } } }, y: { grid: { color: grid }, ticks: { font: { size: 10 } } } } } });
    }
    line('chPeso', <?= json_encode($gPeso) ?>, '#2563eb');
    line('chImc', <?= json_encode($gImc) ?>, '#7c3aed');
    line('chGrasa', <?= json_encode($gGrasa) ?>, '#f59e0b');
    line('chCintura', <?= json_encode($gCintura) ?>, '#10b981');
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
