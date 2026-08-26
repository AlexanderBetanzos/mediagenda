<?php
/**
 * Endocrinología: control metabólico y tiroideo. Registra peso, cintura, IMC,
 * glucosa, HbA1c, insulina con HOMA-IR y perfil tiroideo, más el tamizaje de
 * complicaciones del diabético. Grafica HbA1c y glucosa, que es lo que dice si
 * el paciente está controlado.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_endo_table();

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
        $peso = $n('peso');
        // IMC y HOMA se derivan: teclearlos a mano es una fuente de error.
        $tallaM = ((float) ($_POST['estatura'] ?? 0)) / 100;
        $imc  = ($peso !== null && $tallaM > 0) ? round((float) $peso / ($tallaM * $tallaM), 2) : null;
        $glu  = $n('glucosa'); $ins = $n('insulina');
        $homa = ($glu !== null && $ins !== null)
              ? round(((float) $glu * (float) $ins) / 405, 2) : null;
        db()->prepare('INSERT INTO endo_valoraciones
            (consultorio_id, paciente_id, fecha, peso, cintura, imc, glucosa, hba1c, insulina, homa,
             tsh, t4l, t3, retinopatia, nefropatia, neuropatia, pie_diabetico, diagnostico, tratamiento, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                $peso, $n('cintura'), $imc, $glu, $n('hba1c'), $ins, $homa,
                $n('tsh'), $n('t4l'), $n('t3'),
                isset($_POST['retinopatia']) ? 1 : 0, isset($_POST['nefropatia']) ? 1 : 0,
                isset($_POST['neuropatia']) ? 1 : 0, isset($_POST['pie_diabetico']) ? 1 : 0,
                trim($_POST['diagnostico'] ?? '') ?: null, trim($_POST['tratamiento'] ?? '') ?: null,
                trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'endocrinologia', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración registrada.');
        redirect('/endocrinologia/index?paciente_id=' . $pid);
    }
    if (($_POST['accion'] ?? '') === 'del') {
        db()->prepare('DELETE FROM endo_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/endocrinologia/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM endo_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

$gLabels = $gA1c = $gGlu = [];
foreach ($vals as $v) {
    $gLabels[] = date('d/m/y', strtotime($v['fecha']));
    $gA1c[]    = $v['hba1c']   !== null ? (float) $v['hba1c']   : null;
    $gGlu[]    = $v['glucosa'] !== null ? (float) $v['glucosa'] : null;
}
$hayGraf = count(array_filter($gA1c, fn($x) => $x !== null)) > 1 || count(array_filter($gGlu, fn($x) => $x !== null)) > 1;
$ultima  = $vals ? $vals[count($vals) - 1] : null;

$titulo = t('Endocrinología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-activity text-brand"></i> <?= et('Endocrinología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">HbA1c</div>
        <div class="stat-num mt-2" style="color:<?= (float) $ultima['hba1c'] >= 7 ? '#ef4444' : '#22c55e' ?>">
            <?= $ultima['hba1c'] !== null ? e((string) $ultima['hba1c']) . '%' : '—' ?></div>
        <div class="small text-muted"><?= et('Meta < 7%') ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Glucosa') ?></div>
        <div class="stat-num mt-2"><?= $ultima['glucosa'] !== null ? e((string) $ultima['glucosa']) . ' <small>mg/dL</small>' : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">IMC</div>
        <div class="stat-num mt-2"><?= $ultima['imc'] !== null ? e((string) $ultima['imc']) : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">HOMA-IR</div>
        <div class="stat-num mt-2" style="color:<?= (float) $ultima['homa'] > 2.5 ? '#f59e0b' : 'inherit' ?>">
            <?= $ultima['homa'] !== null ? e((string) $ultima['homa']) : '—' ?></div>
    </div></div></div>
</div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-graph-up text-brand"></i> <?= et('Control metabólico') ?></div>
    <div class="card-body"><canvas id="gEndo" height="90"></canvas></div>
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
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Peso (kg)') ?></label>
            <input type="number" step="0.01" name="peso" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Estatura (cm)') ?></label>
            <input type="number" step="0.1" name="estatura" class="form-control" min="0">
            <div class="form-text"><?= et('Para calcular el IMC') ?></div></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Cintura (cm)') ?></label>
            <input type="number" step="0.1" name="cintura" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Glucosa (mg/dL)') ?></label>
            <input type="number" step="0.1" name="glucosa" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label">HbA1c (%)</label>
            <input type="number" step="0.01" name="hba1c" class="form-control" min="0" max="20"></div>

        <div class="col-6 col-md-2"><label class="form-label"><?= et('Insulina (µU/mL)') ?></label>
            <input type="number" step="0.01" name="insulina" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label">TSH</label>
            <input type="number" step="0.001" name="tsh" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label">T4 libre</label>
            <input type="number" step="0.01" name="t4l" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label">T3</label>
            <input type="number" step="0.01" name="t3" class="form-control" min="0"></div>
        <div class="col-md-4"><label class="form-label"><?= et('Diagnóstico') ?></label>
            <input name="diagnostico" class="form-control" maxlength="255"></div>

        <div class="col-12">
            <label class="form-label"><?= et('Tamizaje de complicaciones') ?></label>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach (['retinopatia' => 'Retinopatía', 'nefropatia' => 'Nefropatía',
                                'neuropatia' => 'Neuropatía', 'pie_diabetico' => 'Pie diabético'] as $k => $l): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $k ?>" id="c<?= $k ?>" value="1">
                    <label class="form-check-label" for="c<?= $k ?>"><?= et($l) ?></label></div>
                <?php endforeach; ?>
            </div>
        </div>

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
            <thead><tr><th><?= et('Fecha') ?></th><th>HbA1c</th><th><?= et('Glucosa') ?></th><th>IMC</th><th>TSH</th><th><?= et('Complicaciones') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($vals) as $v): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($v['fecha']) ?></td>
                    <td class="<?= (float) $v['hba1c'] >= 7 ? 'text-danger fw-semibold' : '' ?>">
                        <?= $v['hba1c'] !== null ? e((string) $v['hba1c']) . '%' : '—' ?></td>
                    <td><?= $v['glucosa'] !== null ? e((string) $v['glucosa']) : '—' ?></td>
                    <td><?= $v['imc'] !== null ? e((string) $v['imc']) : '—' ?></td>
                    <td><?= $v['tsh'] !== null ? e((string) $v['tsh']) : '—' ?></td>
                    <td class="small">
                        <?php $comp = array_keys(array_filter(['Retinopatía' => $v['retinopatia'], 'Nefropatía' => $v['nefropatia'],
                                                               'Neuropatía' => $v['neuropatia'], 'Pie diabético' => $v['pie_diabetico']]));
                        echo $comp ? '<span class="text-danger">' . e(implode(', ', $comp)) . '</span>' : '<span class="text-muted">—</span>'; ?>
                    </td>
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
new Chart(document.getElementById('gEndo'), {
    type: 'line',
    data: {
        labels: <?= json_encode($gLabels) ?>,
        datasets: [
            { label: 'HbA1c (%)',       data: <?= json_encode($gA1c) ?>, borderColor: '#ef4444', tension: .3, spanGaps: true, yAxisID: 'y' },
            { label: 'Glucosa (mg/dL)', data: <?= json_encode($gGlu) ?>, borderColor: '#2563eb', tension: .3, spanGaps: true, yAxisID: 'y1' }
        ]
    },
    options: {
        scales: {
            y:  { position: 'left',  title: { display: true, text: 'HbA1c %' } },
            y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'mg/dL' } }
        }
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
