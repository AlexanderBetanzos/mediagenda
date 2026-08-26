<?php
/**
 * Urología: seguimiento de vías urinarias y próstata. Registra IPSS y calidad
 * de vida, PSA total y libre, volumen prostático, flujo máximo y residuo
 * posmiccional. Grafica PSA e IPSS, que juntos son lo que decide si se vigila,
 * se medica o se biopsia.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_uro_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

$tactos = ['normal' => 'Normal', 'aumentada' => 'Aumentada de tamaño', 'nodular' => 'Nodular / indurada',
           'no_realizado' => 'No realizado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'add') {
        $n = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        db()->prepare('INSERT INTO uro_valoraciones
            (consultorio_id, paciente_id, fecha, ipss, calidad_vida, psa, psa_libre, volumen_prostatico,
             qmax, residuo_ml, tacto, ego, urocultivo, diagnostico, tratamiento, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                $n('ipss'), $n('calidad_vida'), $n('psa'), $n('psa_libre'), $n('volumen_prostatico'),
                $n('qmax'), $n('residuo_ml'),
                in_array($_POST['tacto'] ?? '', array_keys($tactos), true) ? $_POST['tacto'] : null,
                trim($_POST['ego'] ?? '') ?: null, trim($_POST['urocultivo'] ?? '') ?: null,
                trim($_POST['diagnostico'] ?? '') ?: null, trim($_POST['tratamiento'] ?? '') ?: null,
                trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'urologia', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración registrada.');
        redirect('/urologia/index?paciente_id=' . $pid);
    }
    if (($_POST['accion'] ?? '') === 'del') {
        db()->prepare('DELETE FROM uro_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/urologia/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM uro_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

$gLabels = $gPsa = $gIpss = [];
foreach ($vals as $v) {
    $gLabels[] = date('d/m/y', strtotime($v['fecha']));
    $gPsa[]    = $v['psa']  !== null ? (float) $v['psa']  : null;
    $gIpss[]   = $v['ipss'] !== null ? (int)   $v['ipss'] : null;
}
$hayGraf = count(array_filter($gPsa, fn($x) => $x !== null)) > 1 || count(array_filter($gIpss, fn($x) => $x !== null)) > 1;
$ultima  = $vals ? $vals[count($vals) - 1] : null;

/* Severidad del IPSS: es una escala fija (0-7 leve, 8-19 moderado, 20-35 severo). */
$ipssSev = function (?int $v): array {
    if ($v === null) return ['—', 'secondary'];
    if ($v <= 7)  return ['Leve', 'success'];
    if ($v <= 19) return ['Moderado', 'warning'];
    return ['Severo', 'danger'];
};

$titulo = t('Urología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-droplet-half text-brand"></i> <?= et('Urología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima): [$sevTxt, $sevCol] = $ipssSev($ultima['ipss'] !== null ? (int) $ultima['ipss'] : null); ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">IPSS</div>
        <div class="stat-num mt-2"><?= $ultima['ipss'] !== null ? (int) $ultima['ipss'] : '—' ?></div>
        <span class="badge bg-<?= $sevCol ?>"><?= et($sevTxt) ?></span>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">PSA (ng/mL)</div>
        <div class="stat-num mt-2" style="color:<?= (float) $ultima['psa'] > 4 ? '#ef4444' : 'inherit' ?>">
            <?= $ultima['psa'] !== null ? e((string) $ultima['psa']) : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Volumen prostático') ?></div>
        <div class="stat-num mt-2"><?= $ultima['volumen_prostatico'] !== null ? e((string) $ultima['volumen_prostatico']) . ' <small>cc</small>' : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Residuo posmiccional') ?></div>
        <div class="stat-num mt-2"><?= $ultima['residuo_ml'] !== null ? (int) $ultima['residuo_ml'] . ' <small>ml</small>' : '—' ?></div>
    </div></div></div>
</div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-graph-up text-brand"></i> <?= et('Evolución de PSA e IPSS') ?></div>
    <div class="card-body"><canvas id="gUro" height="90"></canvas></div>
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
        <div class="col-6 col-md-2"><label class="form-label">IPSS (0-35)</label>
            <input type="number" name="ipss" class="form-control" min="0" max="35"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Calidad de vida (0-6)') ?></label>
            <input type="number" name="calidad_vida" class="form-control" min="0" max="6"></div>
        <div class="col-6 col-md-2"><label class="form-label">PSA (ng/mL)</label>
            <input type="number" step="0.01" name="psa" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('PSA libre') ?></label>
            <input type="number" step="0.01" name="psa_libre" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Volumen (cc)') ?></label>
            <input type="number" step="0.1" name="volumen_prostatico" class="form-control" min="0"></div>

        <div class="col-6 col-md-2"><label class="form-label">Qmax (mL/s)</label>
            <input type="number" step="0.1" name="qmax" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Residuo (ml)') ?></label>
            <input type="number" name="residuo_ml" class="form-control" min="0"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Tacto rectal') ?></label>
            <select name="tacto" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($tactos as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">EGO</label>
            <input name="ego" class="form-control" maxlength="160"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Urocultivo') ?></label>
            <input name="urocultivo" class="form-control" maxlength="160"></div>

        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label>
            <input name="diagnostico" class="form-control" maxlength="255"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Tratamiento') ?></label>
            <textarea name="tratamiento" class="form-control" rows="2"></textarea></div>
        <div class="col-12"><label class="form-label"><?= et('Notas') ?></label>
            <textarea name="notas" class="form-control" rows="2"></textarea></div>

        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar valoración') ?></button></div>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th><?= et('Fecha') ?></th><th>IPSS</th><th>PSA</th><th>Qmax</th>
                <th><?= et('Residuo') ?></th><th><?= et('Diagnóstico') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_reverse($vals) as $v): [$sTxt, $sCol] = $ipssSev($v['ipss'] !== null ? (int) $v['ipss'] : null); ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($v['fecha']) ?></td>
                    <td><?= $v['ipss'] !== null ? (int) $v['ipss'] : '—' ?>
                        <?php if ($v['ipss'] !== null): ?><br><span class="badge bg-<?= $sCol ?>"><?= et($sTxt) ?></span><?php endif; ?></td>
                    <td class="<?= (float) $v['psa'] > 4 ? 'text-danger fw-semibold' : '' ?>"><?= $v['psa'] !== null ? e((string) $v['psa']) : '—' ?></td>
                    <td><?= $v['qmax'] !== null ? e((string) $v['qmax']) : '—' ?></td>
                    <td><?= $v['residuo_ml'] !== null ? (int) $v['residuo_ml'] : '—' ?></td>
                    <td class="small"><?= e($v['diagnostico'] ?: '—') ?></td>
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
            <?php if (!$vals): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><?= et('Sin valoraciones todavía.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($hayGraf): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('gUro'), {
    type: 'line',
    data: {
        labels: <?= json_encode($gLabels) ?>,
        datasets: [
            { label: 'PSA (ng/mL)', data: <?= json_encode($gPsa) ?>,  borderColor: '#2563eb', tension: .3, spanGaps: true, yAxisID: 'y' },
            { label: 'IPSS',        data: <?= json_encode($gIpss) ?>, borderColor: '#f59e0b', tension: .3, spanGaps: true, yAxisID: 'y1' }
        ]
    },
    options: {
        scales: {
            y:  { position: 'left',  title: { display: true, text: 'PSA' } },
            y1: { position: 'right', min: 0, max: 35, grid: { drawOnChartArea: false }, title: { display: true, text: 'IPSS' } }
        }
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
