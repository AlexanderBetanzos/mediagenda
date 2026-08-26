<?php
/**
 * Traumatología y Ortopedia: valoraciones por región y lado. Registra el
 * mecanismo de lesión, dolor en escala EVA, arcos de movilidad, fuerza,
 * estabilidad y pruebas especiales. Grafica el dolor en el tiempo, que es lo
 * que dice si el tratamiento conservador está funcionando o toca operar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_trauma_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

$regiones = ['Hombro', 'Codo', 'Muñeca y mano', 'Columna cervical', 'Columna lumbar',
             'Cadera', 'Rodilla', 'Tobillo y pie'];
$lados    = ['derecho' => 'Derecho', 'izquierdo' => 'Izquierdo', 'bilateral' => 'Bilateral', 'no_aplica' => 'No aplica'];
$planes   = ['conservador' => 'Conservador', 'rehabilitacion' => 'Rehabilitación', 'infiltracion' => 'Infiltración',
             'cirugia' => 'Cirugía', 'vigilancia' => 'Vigilancia'];
$estab    = ['estable' => 'Estable', 'inestable' => 'Inestable', 'no_valorada' => 'No valorada'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'add') {
        $n   = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        $sel = fn($k, $ops) => in_array($_POST[$k] ?? '', array_keys($ops), true) ? $_POST[$k] : null;
        db()->prepare('INSERT INTO trauma_valoraciones
            (consultorio_id, paciente_id, fecha, region, lado, mecanismo, eva, flexion, extension, abduccion,
             rotacion, fuerza, estabilidad, pruebas, imagen, diagnostico, plan, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                trim($_POST['region'] ?? '') ?: null, $sel('lado', $lados),
                trim($_POST['mecanismo'] ?? '') ?: null, $n('eva'), $n('flexion'), $n('extension'),
                $n('abduccion'), $n('rotacion'), $n('fuerza'), $sel('estabilidad', $estab),
                trim($_POST['pruebas'] ?? '') ?: null, trim($_POST['imagen'] ?? '') ?: null,
                trim($_POST['diagnostico'] ?? '') ?: null, $sel('plan', $planes),
                trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'traumatologia', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Valoración registrada.');
        redirect('/traumatologia/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('DELETE FROM trauma_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Valoración eliminada.');
        redirect('/traumatologia/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM trauma_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

/* La gráfica de dolor solo tiene sentido dentro de una misma región: mezclar
   el hombro con la rodilla dibuja una línea que no significa nada. */
$porRegion = [];
foreach ($vals as $v) {
    $k = ($v['region'] ?: '—') . ' · ' . ($lados[$v['lado']] ?? '—');
    if ($v['eva'] !== null) { $porRegion[$k][] = [date('d/m/y', strtotime($v['fecha'])), (int) $v['eva']]; }
}
$porRegion = array_filter($porRegion, fn($s) => count($s) > 1);
$ultima = $vals ? $vals[count($vals) - 1] : null;

$titulo = t('Traumatología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-bandaid-fill text-brand"></i> <?= et('Traumatología y Ortopedia') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($ultima): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Dolor (EVA)') ?></div>
        <div class="stat-num mt-2" style="color:<?= (int) $ultima['eva'] >= 7 ? '#ef4444' : ((int) $ultima['eva'] >= 4 ? '#f59e0b' : '#22c55e') ?>">
            <?= $ultima['eva'] !== null ? (int) $ultima['eva'] . '/10' : '—' ?>
        </div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Región') ?></div>
        <div class="fw-semibold mt-2"><?= e($ultima['region'] ?: '—') ?></div>
        <div class="small text-muted"><?= e($lados[$ultima['lado']] ?? '') ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Plan') ?></div>
        <div class="fw-semibold mt-2"><?= e($planes[$ultima['plan']] ?? '—') ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Última valoración') ?></div>
        <div class="fw-semibold mt-2"><?= fmt_fecha($ultima['fecha']) ?></div>
    </div></div></div>
</div>
<?php endif; ?>

<?php if ($porRegion): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-graph-down text-brand"></i> <?= et('Evolución del dolor') ?></div>
    <div class="card-body"><canvas id="gEva" height="90"></canvas></div>
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
        <div class="col-md-3"><label class="form-label"><?= et('Región') ?></label>
            <input name="region" class="form-control" list="regionesTrauma" maxlength="40">
            <datalist id="regionesTrauma"><?php foreach ($regiones as $r): ?><option value="<?= e($r) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="col-md-2"><label class="form-label"><?= et('Lado') ?></label>
            <select name="lado" class="form-select"><?php foreach ($lados as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label"><?= et('Mecanismo de lesión') ?></label>
            <input name="mecanismo" class="form-control" maxlength="255" placeholder="<?= e(t('Ej. Caída de propia altura con apoyo en mano')) ?>"></div>

        <div class="col-6 col-md-2"><label class="form-label"><?= et('Dolor EVA (0-10)') ?></label>
            <input type="number" name="eva" class="form-control" min="0" max="10"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Flexión (°)') ?></label>
            <input type="number" name="flexion" class="form-control" min="0" max="180"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Extensión (°)') ?></label>
            <input type="number" name="extension" class="form-control" min="-30" max="180"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Abducción (°)') ?></label>
            <input type="number" name="abduccion" class="form-control" min="0" max="180"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Rotación (°)') ?></label>
            <input type="number" name="rotacion" class="form-control" min="0" max="180"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Fuerza (0-5)') ?></label>
            <input type="number" name="fuerza" class="form-control" min="0" max="5"></div>

        <div class="col-md-3"><label class="form-label"><?= et('Estabilidad') ?></label>
            <select name="estabilidad" class="form-select"><?php foreach ($estab as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label"><?= et('Plan') ?></label>
            <select name="plan" class="form-select"><?php foreach ($planes as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label>
            <input name="diagnostico" class="form-control" maxlength="255"></div>

        <div class="col-md-6"><label class="form-label"><?= et('Pruebas especiales') ?></label>
            <textarea name="pruebas" class="form-control" rows="2" placeholder="<?= e(t('Ej. Lachman positivo, McMurray negativo')) ?>"></textarea></div>
        <div class="col-md-6"><label class="form-label"><?= et('Estudios de imagen') ?></label>
            <textarea name="imagen" class="form-control" rows="2" placeholder="<?= e(t('Ej. Rx AP y lateral: sin trazo de fractura')) ?>"></textarea></div>
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
                <th><?= et('Fecha') ?></th><th><?= et('Región') ?></th><th><?= et('EVA') ?></th>
                <th><?= et('Movilidad') ?></th><th><?= et('Diagnóstico') ?></th><th><?= et('Plan') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_reverse($vals) as $v): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($v['fecha']) ?></td>
                    <td><?= e($v['region'] ?: '—') ?><br><small class="text-muted"><?= e($lados[$v['lado']] ?? '') ?></small></td>
                    <td><?= $v['eva'] !== null ? (int) $v['eva'] . '/10' : '—' ?></td>
                    <td class="small text-muted">
                        <?php $mov = array_filter(['F' => $v['flexion'], 'E' => $v['extension'], 'Ab' => $v['abduccion'], 'R' => $v['rotacion']],
                                                  fn($x) => $x !== null);
                        echo $mov ? e(implode(' · ', array_map(fn($k, $x) => "$k $x°", array_keys($mov), $mov))) : '—'; ?>
                    </td>
                    <td><?= e($v['diagnostico'] ?: '—') ?></td>
                    <td><?= e($planes[$v['plan']] ?? '—') ?></td>
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

<?php if ($porRegion): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    var series = <?= json_encode(array_map(
        fn($k, $s) => ['label' => $k, 'labels' => array_column($s, 0), 'data' => array_column($s, 1)],
        array_keys($porRegion), $porRegion), JSON_UNESCAPED_UNICODE) ?>;
    var colores = ['#2563eb', '#ef4444', '#22c55e', '#f59e0b', '#8b5cf6'];
    new Chart(document.getElementById('gEva'), {
        type: 'line',
        data: {
            labels: series[0].labels,
            datasets: series.map(function (s, i) {
                return { label: s.label, data: s.data, borderColor: colores[i % colores.length],
                         tension: .3, spanGaps: true };
            })
        },
        options: { scales: { y: { min: 0, max: 10, title: { display: true, text: 'EVA' } } } }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
