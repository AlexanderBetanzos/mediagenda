<?php
/**
 * Cardiología: valoraciones cardiovasculares. Registra TA, FC, perfil de
 * lípidos y glucosa, factores de riesgo (tabaquismo/diabetes), clase funcional
 * (NYHA), riesgo CV y hallazgos de ECG. Grafica TA y lípidos en el tiempo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_cardio_table();

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
        $nyha   = in_array($_POST['nyha'] ?? '', ['I','II','III','IV'], true) ? $_POST['nyha'] : null;
        $riesgo = in_array($_POST['riesgo'] ?? '', ['bajo','moderado','alto','muy_alto'], true) ? $_POST['riesgo'] : null;
        db()->prepare('INSERT INTO cardio_valoraciones
            (consultorio_id, paciente_id, fecha, presion, fc, colesterol_total, hdl, ldl, trigliceridos, glucosa, tabaquismo, diabetes, nyha, riesgo, ecg_hallazgos, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                trim($_POST['presion'] ?? '') ?: null, $n('fc'), $n('colesterol_total'), $n('hdl'), $n('ldl'),
                $n('trigliceridos'), $n('glucosa'), isset($_POST['tabaquismo']) ? 1 : 0, isset($_POST['diabetes']) ? 1 : 0,
                $nyha, $riesgo, trim($_POST['ecg_hallazgos'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'cardiologia', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración cardiológica registrada.');
        redirect('/cardiologia/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('DELETE FROM cardio_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/cardiologia/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM cardio_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

$gLabels = $gSis = $gDia = $gCol = $gLdl = $gHdl = [];
foreach ($vals as $v) {
    $gLabels[] = date('d/m/y', strtotime($v['fecha']));
    $s = $d = null;
    if (!empty($v['presion']) && preg_match('#(\d{2,3})\s*/\s*(\d{2,3})#', $v['presion'], $m)) { $s=(int)$m[1]; $d=(int)$m[2]; }
    $gSis[] = $s; $gDia[] = $d;
    $gCol[] = $v['colesterol_total'] !== null ? (float) $v['colesterol_total'] : null;
    $gLdl[] = $v['ldl'] !== null ? (float) $v['ldl'] : null;
    $gHdl[] = $v['hdl'] !== null ? (float) $v['hdl'] : null;
}
$hayGraf = count(array_filter($gSis, fn($x)=>$x!==null)) || count(array_filter($gCol, fn($x)=>$x!==null));
$ultima  = $vals ? $vals[count($vals) - 1] : null;
$riesgoLbl = ['bajo'=>['Bajo','success'],'moderado'=>['Moderado','warning'],'alto'=>['Alto','danger'],'muy_alto'=>['Muy alto','danger']];

$titulo = t('Cardiología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-heart-pulse text-brand"></i> <?= et('Cardiología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima): ?>
<div class="card mb-4"><div class="card-body">
    <div class="row g-4">
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Presión') ?></div><div class="h5 mb-0"><?= e($ultima['presion'] ?: '—') ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Riesgo CV') ?></div><div><?php if ($ultima['riesgo']): [$rl,$rc]=$riesgoLbl[$ultima['riesgo']]; ?><span class="badge bg-<?= $rc ?>"><?= et($rl) ?></span><?php else: ?>—<?php endif; ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Clase NYHA') ?></div><div class="fw-semibold"><?= e($ultima['nyha'] ?: '—') ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Factores') ?></div><div class="small"><?= $ultima['tabaquismo'] ? '<span class="badge bg-secondary">'.et('Tabaquismo').'</span> ' : '' ?><?= $ultima['diabetes'] ? '<span class="badge bg-secondary">'.et('Diabetes').'</span>' : '' ?><?= (!$ultima['tabaquismo'] && !$ultima['diabetes']) ? '—' : '' ?></div></div>
    </div>
    <?php if ($ultima['ecg_hallazgos']): ?><div class="mt-3 small"><strong><?= et('ECG:') ?></strong> <?= nl2br(e($ultima['ecg_hallazgos'])) ?></div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-up text-brand"></i> <?= et('Evolución') ?></h2>
    <div class="row g-3">
        <div class="col-md-6"><div class="small fw-semibold mb-1"><?= et('Presión arterial (mmHg)') ?></div><div style="height:200px"><canvas id="chTA"></canvas></div></div>
        <div class="col-md-6"><div class="small fw-semibold mb-1"><?= et('Perfil de lípidos (mg/dL)') ?></div><div style="height:200px"><canvas id="chLip"></canvas></div></div>
    </div>
</div></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nueva valoración') ?></h2>
    <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="add"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Presión') ?></label><input type="text" name="presion" class="form-control" placeholder="120/80"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('FC (lpm)') ?></label><input type="number" min="0" name="fc" class="form-control"></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Colesterol total') ?></label><input type="number" step="0.1" min="0" name="colesterol_total" class="form-control"></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Glucosa') ?></label><input type="number" step="0.1" min="0" name="glucosa" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small">HDL</label><input type="number" step="0.1" min="0" name="hdl" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small">LDL</label><input type="number" step="0.1" min="0" name="ldl" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Triglicéridos') ?></label><input type="number" step="0.1" min="0" name="trigliceridos" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Clase NYHA') ?></label>
            <select name="nyha" class="form-select"><option value=""><?= et('—') ?></option><option>I</option><option>II</option><option>III</option><option>IV</option></select>
        </div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Riesgo CV') ?></label>
            <select name="riesgo" class="form-select"><option value=""><?= et('—') ?></option><option value="bajo"><?= et('Bajo') ?></option><option value="moderado"><?= et('Moderado') ?></option><option value="alto"><?= et('Alto') ?></option><option value="muy_alto"><?= et('Muy alto') ?></option></select>
        </div>
        <div class="col-12 d-flex gap-4 pt-1">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="tabaquismo" id="tab"><label class="form-check-label small" for="tab"><?= et('Tabaquismo') ?></label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="diabetes" id="dia"><label class="form-check-label small" for="dia"><?= et('Diabetes') ?></label></div>
        </div>
        <div class="col-12"><label class="form-label small"><?= et('Hallazgos de ECG') ?></label><textarea name="ecg_hallazgos" class="form-control" rows="2" placeholder="<?= e(t('Ritmo, frecuencia, eje, alteraciones…')) ?>"></textarea></div>
        <div class="col-12"><label class="form-label small"><?= et('Notas') ?></label><input type="text" name="notas" class="form-control" maxlength="255"></div>
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar valoración') ?></button></div>
    </form>
</div></div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-brand"></i> <?= et('Historial') ?> (<?= count($vals) ?>)</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th><?= et('Fecha') ?></th><th><?= et('PA') ?></th><th><?= et('Col.') ?></th><th>LDL</th><th>HDL</th><th><?= et('Glucosa') ?></th><th><?= et('Riesgo') ?></th><th>NYHA</th><th></th></tr></thead>
            <tbody>
            <?php if (!$vals): ?>
                <tr><td colspan="9" class="text-center text-muted py-4"><?= et('Sin valoraciones.') ?></td></tr>
            <?php else: foreach (array_reverse($vals) as $v): ?>
                <tr>
                    <td class="small"><?= e(fmt_fecha($v['fecha'])) ?></td>
                    <td><?= e($v['presion'] ?: '—') ?></td>
                    <td><?= $v['colesterol_total'] !== null ? e($v['colesterol_total']) : '—' ?></td>
                    <td><?= $v['ldl'] !== null ? e($v['ldl']) : '—' ?></td>
                    <td><?= $v['hdl'] !== null ? e($v['hdl']) : '—' ?></td>
                    <td><?= $v['glucosa'] !== null ? e($v['glucosa']) : '—' ?></td>
                    <td><?php if ($v['riesgo']): [$rl,$rc]=$riesgoLbl[$v['riesgo']]; ?><span class="badge bg-<?= $rc ?>"><?= et($rl) ?></span><?php else: ?>—<?php endif; ?></td>
                    <td><?= e($v['nyha'] ?: '—') ?></td>
                    <td class="text-end">
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar?')) ?>');">
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
    function chart(id, sets) {
        var el = document.getElementById(id); if (!el) return;
        new Chart(el, { type: 'line', data: { labels: labels, datasets: sets.map(function (s) {
            return { label: s.l, data: s.d, borderColor: s.c, backgroundColor: s.c, tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 };
        }) }, options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: sets.length > 1, labels: { boxWidth: 10, font: { size: 10 } } } },
            scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 6, font: { size: 10 } } }, y: { grid: { color: grid }, ticks: { font: { size: 10 } } } } } });
    }
    chart('chTA', [{ l: 'Sistólica', d: <?= json_encode($gSis) ?>, c: '#ef4444' }, { l: 'Diastólica', d: <?= json_encode($gDia) ?>, c: '#f59e0b' }]);
    chart('chLip', [{ l: 'Colesterol', d: <?= json_encode($gCol) ?>, c: '#2563eb' }, { l: 'LDL', d: <?= json_encode($gLdl) ?>, c: '#ef4444' }, { l: 'HDL', d: <?= json_encode($gHdl) ?>, c: '#10b981' }]);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
