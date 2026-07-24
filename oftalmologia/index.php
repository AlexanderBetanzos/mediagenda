<?php
/**
 * Oftalmología clínica: exámenes con agudeza visual, presión intraocular (PIO),
 * segmento anterior y fondo de ojo. Complementa el módulo de Óptica (que lleva
 * las graduaciones). Grafica la PIO en el tiempo (clave para glaucoma).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_oftalmo_table();

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
        db()->prepare('INSERT INTO oftalmo_examenes
            (consultorio_id, paciente_id, fecha, av_od, av_oi, pio_od, pio_oi, segmento_ant, fondo_ojo, diagnostico, plan, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                trim($_POST['av_od'] ?? '') ?: null, trim($_POST['av_oi'] ?? '') ?: null, $n('pio_od'), $n('pio_oi'),
                trim($_POST['segmento_ant'] ?? '') ?: null, trim($_POST['fondo_ojo'] ?? '') ?: null,
                trim($_POST['diagnostico'] ?? '') ?: null, trim($_POST['plan'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'oftalmo_examen', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Examen oftalmológico registrado.');
        redirect('/oftalmologia/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('DELETE FROM oftalmo_examenes WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Examen eliminado.');
        redirect('/oftalmologia/index?paciente_id=' . $pid);
    }
}

$ex = db()->prepare('SELECT * FROM oftalmo_examenes WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$ex->execute([$pid, tenant_id()]);
$ex = $ex->fetchAll();

$gLabels = $gPioOd = $gPioOi = [];
foreach ($ex as $e) {
    $gLabels[] = date('d/m/y', strtotime($e['fecha']));
    $gPioOd[] = $e['pio_od'] !== null ? (float) $e['pio_od'] : null;
    $gPioOi[] = $e['pio_oi'] !== null ? (float) $e['pio_oi'] : null;
}
$hayGraf = count(array_filter($gPioOd, fn($x)=>$x!==null)) || count(array_filter($gPioOi, fn($x)=>$x!==null));
$ult = $ex ? $ex[count($ex) - 1] : null;

$titulo = t('Oftalmología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-eye text-brand"></i> <?= et('Oftalmología') ?></h1>
    <div class="d-flex gap-2">
        <?php if (modulo_activo('optica')): ?><a href="<?= BASE_URL ?>/optica/graduacion?paciente_id=<?= $pid ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eyeglasses"></i> <?= et('Graduación') ?></a><?php endif; ?>
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
    </div>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ult): ?>
<div class="card mb-4"><div class="card-body">
    <div class="row g-4">
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('AV (OD / OI)') ?></div><div class="fw-semibold font-monospace"><?= e($ult['av_od'] ?: '—') ?> / <?= e($ult['av_oi'] ?: '—') ?></div></div>
        <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('PIO (OD / OI)') ?></div><div class="fw-semibold"><?= $ult['pio_od'] !== null ? e($ult['pio_od']) : '—' ?> / <?= $ult['pio_oi'] !== null ? e($ult['pio_oi']) : '—' ?> mmHg</div></div>
        <div class="col-12 col-md-6"><div class="text-muted small text-uppercase"><?= et('Diagnóstico') ?></div><div class="fw-semibold"><?= e($ult['diagnostico'] ?: '—') ?></div></div>
    </div>
</div></div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-up text-brand"></i> <?= et('Presión intraocular (mmHg)') ?></h2>
    <div style="height:200px"><canvas id="chPio"></canvas></div>
    <div class="small text-muted mt-2"><?= et('Rango normal aprox. 10–21 mmHg.') ?></div>
</div></div>
<?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nuevo examen') ?></h2>
    <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="add"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('AV OD') ?></label><input type="text" name="av_od" class="form-control" maxlength="20" placeholder="20/20"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('AV OI') ?></label><input type="text" name="av_oi" class="form-control" maxlength="20" placeholder="20/20"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('PIO OD') ?></label><input type="number" step="0.1" min="0" name="pio_od" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('PIO OI') ?></label><input type="number" step="0.1" min="0" name="pio_oi" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Diagnóstico') ?></label><input type="text" name="diagnostico" class="form-control" maxlength="255"></div>
        <div class="col-md-6"><label class="form-label small"><?= et('Segmento anterior') ?></label><textarea name="segmento_ant" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label small"><?= et('Fondo de ojo') ?></label><textarea name="fondo_ojo" class="form-control" rows="2"></textarea></div>
        <div class="col-md-8"><label class="form-label small"><?= et('Plan') ?></label><input type="text" name="plan" class="form-control" maxlength="255"></div>
        <div class="col-md-4"><label class="form-label small"><?= et('Notas') ?></label><input type="text" name="notas" class="form-control" maxlength="255"></div>
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar examen') ?></button></div>
    </form>
</div></div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-brand"></i> <?= et('Historial') ?> (<?= count($ex) ?>)</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th><?= et('Fecha') ?></th><th><?= et('AV OD/OI') ?></th><th><?= et('PIO OD/OI') ?></th><th><?= et('Diagnóstico') ?></th><th></th></tr></thead>
            <tbody>
            <?php if (!$ex): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin exámenes.') ?></td></tr>
            <?php else: foreach (array_reverse($ex) as $e): ?>
                <tr>
                    <td class="small"><?= e(fmt_fecha($e['fecha'])) ?></td>
                    <td class="font-monospace small"><?= e($e['av_od'] ?: '—') ?> / <?= e($e['av_oi'] ?: '—') ?></td>
                    <td><?= $e['pio_od'] !== null ? e($e['pio_od']) : '—' ?> / <?= $e['pio_oi'] !== null ? e($e['pio_oi']) : '—' ?></td>
                    <td class="small"><?= e($e['diagnostico'] ?: '—') ?></td>
                    <td class="text-end">
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar?')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="del"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
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
    var el = document.getElementById('chPio'); if (!el) return;
    var grid = 'rgba(127,127,127,.14)';
    new Chart(el, { type: 'line', data: { labels: <?= json_encode($gLabels) ?>, datasets: [
        { label: 'PIO OD', data: <?= json_encode($gPioOd) ?>, borderColor: '#2563eb', backgroundColor: '#2563eb', tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 },
        { label: 'PIO OI', data: <?= json_encode($gPioOi) ?>, borderColor: '#10b981', backgroundColor: '#10b981', tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 }
    ] }, options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 10, font: { size: 11 } } } },
        scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 8, font: { size: 10 } } }, y: { grid: { color: grid }, ticks: { font: { size: 10 } } } } } });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
