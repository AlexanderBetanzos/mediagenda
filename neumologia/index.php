<?php
/**
 * Neumología: control de asma y EPOC. Registra disnea (mMRC), saturación,
 * espirometría (FEV1, FVC y su relación), flujo pico y control del asma (ACT).
 * Grafica FEV1 y saturación, que es como se ve si el tratamiento sostiene la
 * función pulmonar o el paciente se está deteriorando.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_neumo_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'add') {
        $n = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        // La relación FEV1/FVC se calcula sola: es división, no dato que teclear.
        $fev1 = $n('fev1'); $fvc = $n('fvc');
        $rel  = ($fev1 !== null && $fvc !== null && (float) $fvc > 0)
              ? round((float) $fev1 / (float) $fvc, 2) : null;
        db()->prepare('INSERT INTO neumo_valoraciones
            (consultorio_id, paciente_id, fecha, mmrc, sato2, fr, fev1, fev1_pct, fvc, relacion, pef,
             act, paquetes_ano, oxigeno, auscultacion, diagnostico, tratamiento, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                $n('mmrc'), $n('sato2'), $n('fr'), $fev1, $n('fev1_pct'), $fvc, $rel, $n('pef'),
                $n('act'), $n('paquetes_ano'), isset($_POST['oxigeno']) ? 1 : 0,
                trim($_POST['auscultacion'] ?? '') ?: null, trim($_POST['diagnostico'] ?? '') ?: null,
                trim($_POST['tratamiento'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'neumologia', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración registrada.');
        redirect('/neumologia/index?paciente_id=' . $pid);
    }
    if (($_POST['accion'] ?? '') === 'del') {
        db()->prepare('DELETE FROM neumo_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/neumologia/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM neumo_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

$gLabels = $gFev = $gSat = [];
foreach ($vals as $v) {
    $gLabels[] = date('d/m/y', strtotime($v['fecha']));
    $gFev[]    = $v['fev1_pct'] !== null ? (int) $v['fev1_pct'] : null;
    $gSat[]    = $v['sato2']    !== null ? (int) $v['sato2']    : null;
}
$hayGraf = count(array_filter($gFev, fn($x) => $x !== null)) > 1 || count(array_filter($gSat, fn($x) => $x !== null)) > 1;
$ultima  = $vals ? $vals[count($vals) - 1] : null;

$titulo = t('Neumología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-lungs text-brand"></i> <?= et('Neumología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Saturación') ?></div>
        <div class="stat-num mt-2" style="color:<?= (int) $ultima['sato2'] && (int) $ultima['sato2'] < 90 ? '#ef4444' : '#22c55e' ?>">
            <?= $ultima['sato2'] !== null ? (int) $ultima['sato2'] . '%' : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">FEV1</div>
        <div class="stat-num mt-2"><?= $ultima['fev1_pct'] !== null ? (int) $ultima['fev1_pct'] . '%' : '—' ?></div>
        <?php if ($ultima['relacion'] !== null): ?><div class="small text-muted">FEV1/FVC <?= e((string) $ultima['relacion']) ?></div><?php endif; ?>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Disnea (mMRC)') ?></div>
        <div class="stat-num mt-2"><?= $ultima['mmrc'] !== null ? (int) $ultima['mmrc'] . '/4' : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">ACT</div>
        <div class="stat-num mt-2" style="color:<?= (int) $ultima['act'] && (int) $ultima['act'] < 20 ? '#f59e0b' : 'inherit' ?>">
            <?= $ultima['act'] !== null ? (int) $ultima['act'] . '/25' : '—' ?></div>
    </div></div></div>
</div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-graph-up text-brand"></i> <?= et('Función pulmonar en el tiempo') ?></div>
    <div class="card-body"><canvas id="gNeumo" height="90"></canvas></div>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nueva valoración') ?></div>
    <form method="post" class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="add">
        <input type="hidden" name="paciente_id" value="<?= $pid ?>">

        <div class="col-md-2"><label class="form-label"><?= et('Fecha') ?></label>
            <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Disnea mMRC (0-4)') ?></label>
            <input type="number" name="mmrc" class="form-control" min="0" max="4"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('SatO₂ (%)') ?></label>
            <input type="number" name="sato2" class="form-control" min="50" max="100"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Frecuencia respiratoria') ?></label>
            <input type="number" name="fr" class="form-control" min="5" max="80"></div>
        <div class="col-6 col-md-2"><label class="form-label">ACT (5-25)</label>
            <input type="number" name="act" class="form-control" min="5" max="25"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Paquetes/año') ?></label>
            <input type="number" step="0.1" name="paquetes_ano" class="form-control" min="0"></div>

        <div class="col-6 col-md-2"><label class="form-label">FEV1 (L)</label>
            <input type="number" step="0.01" name="fev1" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label">FEV1 (% pred.)</label>
            <input type="number" name="fev1_pct" class="form-control" min="0" max="200"></div>
        <div class="col-6 col-md-2"><label class="form-label">FVC (L)</label>
            <input type="number" step="0.01" name="fvc" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label">PEF (L/min)</label>
            <input type="number" name="pef" class="form-control" min="0"></div>
        <div class="col-md-4 d-flex align-items-center pt-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="oxigeno" id="oxi" value="1">
                <label class="form-check-label" for="oxi"><?= et('Oxígeno suplementario') ?></label></div>
        </div>

        <div class="col-md-6"><label class="form-label"><?= et('Auscultación') ?></label>
            <textarea name="auscultacion" class="form-control" rows="2" placeholder="<?= e(t('Ej. Sibilancias espiratorias difusas')) ?>"></textarea></div>
        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label>
            <input name="diagnostico" class="form-control" maxlength="255"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Tratamiento') ?></label>
            <textarea name="tratamiento" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label"><?= et('Notas') ?></label>
            <textarea name="notas" class="form-control" rows="2"></textarea></div>

        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar valoración') ?></button></div>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th>SatO₂</th><th>FEV1</th><th>mMRC</th><th>ACT</th><th><?= et('Diagnóstico') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($vals) as $v): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($v['fecha']) ?></td>
                    <td class="<?= $v['sato2'] !== null && (int) $v['sato2'] < 90 ? 'text-danger fw-semibold' : '' ?>">
                        <?= $v['sato2'] !== null ? (int) $v['sato2'] . '%' : '—' ?></td>
                    <td><?= $v['fev1_pct'] !== null ? (int) $v['fev1_pct'] . '%' : '—' ?>
                        <?php if ($v['relacion'] !== null): ?><br><small class="text-muted">/FVC <?= e((string) $v['relacion']) ?></small><?php endif; ?></td>
                    <td><?= $v['mmrc'] !== null ? (int) $v['mmrc'] : '—' ?></td>
                    <td><?= $v['act'] !== null ? (int) $v['act'] : '—' ?></td>
                    <td class="small"><?= e($v['diagnostico'] ?: '—') ?><?= $v['oxigeno'] ? ' <span class="badge bg-info">O₂</span>' : '' ?></td>
                    <td class="text-end">
                        <form method="post" onsubmit="return confirm('<?= e(t('¿Eliminar esta valoración?')) ?>')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="del">
                            <input type="hidden" name="paciente_id" value="<?= $pid ?>">
                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$vals): ?><tr><td colspan="7" class="text-center text-muted py-4"><?= et('Sin valoraciones todavía.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($hayGraf): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('gNeumo'), {
    type: 'line',
    data: {
        labels: <?= json_encode($gLabels) ?>,
        datasets: [
            { label: 'FEV1 (% pred.)', data: <?= json_encode($gFev) ?>, borderColor: '#2563eb', tension: .3, spanGaps: true },
            { label: 'SatO₂ (%)',      data: <?= json_encode($gSat) ?>, borderColor: '#22c55e', tension: .3, spanGaps: true }
        ]
    },
    options: { scales: { y: { min: 40, max: 110 } } }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
