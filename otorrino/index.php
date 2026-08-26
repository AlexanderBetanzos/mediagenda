<?php
/**
 * Otorrinolaringología: exploración de oído, nariz y garganta. Registra
 * otoscopia y umbral auditivo por lado (PTA), acúfeno y vértigo, rinoscopia
 * con septum y cornetes, grado amigdalino y laringoscopia. Grafica el umbral
 * auditivo, que es como se documenta si una hipoacusia progresa.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_orl_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

$acufenos = ['no' => 'No', 'derecho' => 'Derecho', 'izquierdo' => 'Izquierdo', 'bilateral' => 'Bilateral'];
$septums  = ['central' => 'Central', 'desviado_der' => 'Desviado a la derecha', 'desviado_izq' => 'Desviado a la izquierda'];
$cornetes = ['normales' => 'Normales', 'hipertroficos' => 'Hipertróficos'];
$amigdalas = ['grado_0' => 'Grado 0', 'grado_1' => 'Grado I', 'grado_2' => 'Grado II',
              'grado_3' => 'Grado III', 'grado_4' => 'Grado IV', 'amigdalectomia' => 'Amigdalectomía previa'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'add') {
        $n   = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        $sel = fn($k, $ops) => in_array($_POST[$k] ?? '', array_keys($ops), true) ? $_POST[$k] : null;
        db()->prepare('INSERT INTO orl_valoraciones
            (consultorio_id, paciente_id, fecha, motivo, otoscopia_der, otoscopia_izq, pta_der, pta_izq,
             acufeno, vertigo, rinoscopia, septum, cornetes, faringe, amigdalas, laringoscopia,
             diagnostico, plan, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                trim($_POST['motivo'] ?? '') ?: null, trim($_POST['otoscopia_der'] ?? '') ?: null,
                trim($_POST['otoscopia_izq'] ?? '') ?: null, $n('pta_der'), $n('pta_izq'),
                $sel('acufeno', $acufenos), isset($_POST['vertigo']) ? 1 : 0,
                trim($_POST['rinoscopia'] ?? '') ?: null, $sel('septum', $septums), $sel('cornetes', $cornetes),
                trim($_POST['faringe'] ?? '') ?: null, $sel('amigdalas', $amigdalas),
                trim($_POST['laringoscopia'] ?? '') ?: null, trim($_POST['diagnostico'] ?? '') ?: null,
                trim($_POST['plan'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'otorrino', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración registrada.');
        redirect('/otorrino/index?paciente_id=' . $pid);
    }
    if (($_POST['accion'] ?? '') === 'del') {
        db()->prepare('DELETE FROM orl_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/otorrino/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM orl_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

$gLabels = $gDer = $gIzq = [];
foreach ($vals as $v) {
    $gLabels[] = date('d/m/y', strtotime($v['fecha']));
    $gDer[]    = $v['pta_der'] !== null ? (int) $v['pta_der'] : null;
    $gIzq[]    = $v['pta_izq'] !== null ? (int) $v['pta_izq'] : null;
}
$hayGraf = count(array_filter($gDer, fn($x) => $x !== null)) > 1 || count(array_filter($gIzq, fn($x) => $x !== null)) > 1;
$ultima  = $vals ? $vals[count($vals) - 1] : null;

/* Grado de hipoacusia por umbral (clasificación de la OMS, en dB HL). */
$grado = function (?int $db): array {
    if ($db === null) return ['—', 'secondary'];
    if ($db <= 25) return ['Normal', 'success'];
    if ($db <= 40) return ['Leve', 'info'];
    if ($db <= 60) return ['Moderada', 'warning'];
    if ($db <= 80) return ['Severa', 'danger'];
    return ['Profunda', 'danger'];
};

$titulo = t('Otorrinolaringología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-ear text-brand"></i> <?= et('Otorrinolaringología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima):
    [$gdTxt, $gdCol] = $grado($ultima['pta_der'] !== null ? (int) $ultima['pta_der'] : null);
    [$giTxt, $giCol] = $grado($ultima['pta_izq'] !== null ? (int) $ultima['pta_izq'] : null); ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Oído derecho') ?></div>
        <div class="stat-num mt-2"><?= $ultima['pta_der'] !== null ? (int) $ultima['pta_der'] . ' <small>dB</small>' : '—' ?></div>
        <span class="badge bg-<?= $gdCol ?>"><?= et($gdTxt) ?></span>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Oído izquierdo') ?></div>
        <div class="stat-num mt-2"><?= $ultima['pta_izq'] !== null ? (int) $ultima['pta_izq'] . ' <small>dB</small>' : '—' ?></div>
        <span class="badge bg-<?= $giCol ?>"><?= et($giTxt) ?></span>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Acúfeno') ?></div>
        <div class="fw-semibold mt-2"><?= e($acufenos[$ultima['acufeno']] ?? '—') ?></div>
        <?php if ($ultima['vertigo']): ?><span class="badge bg-warning text-dark"><?= et('Con vértigo') ?></span><?php endif; ?>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Amígdalas') ?></div>
        <div class="fw-semibold mt-2"><?= e($amigdalas[$ultima['amigdalas']] ?? '—') ?></div>
    </div></div></div>
</div>
<?php endif; ?>

<?php if ($hayGraf): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-graph-up text-brand"></i> <?= et('Umbral auditivo (PTA)') ?></div>
    <div class="card-body"><canvas id="gOrl" height="90"></canvas></div>
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
        <div class="col-md-10"><label class="form-label"><?= et('Motivo') ?></label>
            <input name="motivo" class="form-control" maxlength="255"></div>

        <div class="col-12"><hr class="my-1"><span class="small fw-semibold text-muted text-uppercase"><i class="bi bi-ear"></i> <?= et('Oído') ?></span></div>
        <div class="col-md-4"><label class="form-label"><?= et('Otoscopia derecha') ?></label>
            <textarea name="otoscopia_der" class="form-control" rows="2"></textarea></div>
        <div class="col-md-4"><label class="form-label"><?= et('Otoscopia izquierda') ?></label>
            <textarea name="otoscopia_izq" class="form-control" rows="2"></textarea></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('PTA derecho (dB)') ?></label>
            <input type="number" name="pta_der" class="form-control" min="0" max="120"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('PTA izquierdo (dB)') ?></label>
            <input type="number" name="pta_izq" class="form-control" min="0" max="120"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Acúfeno') ?></label>
            <select name="acufeno" class="form-select"><?php foreach ($acufenos as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex align-items-center pt-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="vertigo" id="vert" value="1">
                <label class="form-check-label" for="vert"><?= et('Refiere vértigo') ?></label></div>
        </div>

        <div class="col-12"><hr class="my-1"><span class="small fw-semibold text-muted text-uppercase"><i class="bi bi-wind"></i> <?= et('Nariz y garganta') ?></span></div>
        <div class="col-md-3"><label class="form-label"><?= et('Septum') ?></label>
            <select name="septum" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($septums as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label"><?= et('Cornetes') ?></label>
            <select name="cornetes" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($cornetes as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label"><?= et('Amígdalas') ?></label>
            <select name="amigdalas" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($amigdalas as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label"><?= et('Rinoscopia') ?></label>
            <input name="rinoscopia" class="form-control"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Faringe') ?></label>
            <textarea name="faringe" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label"><?= et('Laringoscopia') ?></label>
            <textarea name="laringoscopia" class="form-control" rows="2"></textarea></div>

        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label>
            <input name="diagnostico" class="form-control" maxlength="255"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Plan') ?></label>
            <textarea name="plan" class="form-control" rows="2"></textarea></div>
        <div class="col-12"><label class="form-label"><?= et('Notas') ?></label>
            <textarea name="notas" class="form-control" rows="2"></textarea></div>

        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar valoración') ?></button></div>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Motivo') ?></th><th>PTA D/I</th><th><?= et('Acúfeno') ?></th><th><?= et('Diagnóstico') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($vals) as $v): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($v['fecha']) ?></td>
                    <td class="small"><?= e($v['motivo'] ?: '—') ?></td>
                    <td><?= $v['pta_der'] !== null ? (int) $v['pta_der'] : '—' ?> / <?= $v['pta_izq'] !== null ? (int) $v['pta_izq'] : '—' ?></td>
                    <td class="small"><?= e($acufenos[$v['acufeno']] ?? '—') ?><?= $v['vertigo'] ? ' <span class="badge bg-warning text-dark">' . et('Vértigo') . '</span>' : '' ?></td>
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
            <?php if (!$vals): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin valoraciones todavía.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($hayGraf): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('gOrl'), {
    type: 'line',
    data: {
        labels: <?= json_encode($gLabels) ?>,
        datasets: [
            { label: '<?= e(t('Oído derecho')) ?>',   data: <?= json_encode($gDer) ?>, borderColor: '#2563eb', tension: .3, spanGaps: true },
            { label: '<?= e(t('Oído izquierdo')) ?>', data: <?= json_encode($gIzq) ?>, borderColor: '#ef4444', tension: .3, spanGaps: true }
        ]
    },
    // Más dB = peor audición, así que el eje va invertido: la línea baja cuando empeora.
    options: { scales: { y: { reverse: true, min: 0, max: 120, title: { display: true, text: 'dB HL' } } } }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
