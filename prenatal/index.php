<?php
/**
 * Control prenatal (Ginecología y Obstetricia). Tablero del embarazo activo:
 * FUM → SDG y FPP calculadas, GPA, y las visitas de control con sus signos
 * (peso, TA, FCF, altura uterina) graficadas contra el tiempo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_prenatal_tables();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

/* ── Acciones ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear_embarazo') {
        $fum = ($_POST['fum'] ?? '') ?: null;
        db()->prepare('INSERT INTO embarazos
            (consultorio_id, paciente_id, fum, fpp, grupo_sanguineo, gestas, partos, cesareas, abortos, riesgo, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, $fum, fpp_desde_fum($fum),
                trim($_POST['grupo_sanguineo'] ?? '') ?: null,
                ($_POST['gestas'] ?? '') !== '' ? (int) $_POST['gestas'] : null,
                ($_POST['partos'] ?? '') !== '' ? (int) $_POST['partos'] : null,
                ($_POST['cesareas'] ?? '') !== '' ? (int) $_POST['cesareas'] : null,
                ($_POST['abortos'] ?? '') !== '' ? (int) $_POST['abortos'] : null,
                ($_POST['riesgo'] ?? 'bajo') === 'alto' ? 'alto' : 'bajo',
                trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'embarazo', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Embarazo registrado. Ahora agrega las visitas de control.');
        redirect('/prenatal/index?paciente_id=' . $pid);
    }

    if ($accion === 'cerrar_embarazo') {
        db()->prepare('UPDATE embarazos SET activo = 0, desenlace = ?, cerrado_en = CURDATE()
                       WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([trim($_POST['desenlace'] ?? '') ?: null, (int) ($_POST['embarazo_id'] ?? 0), $pid, tenant_id()]);
        flash('Embarazo cerrado.');
        redirect('/prenatal/index?paciente_id=' . $pid);
    }

    if ($accion === 'add_visita') {
        $eid = (int) ($_POST['embarazo_id'] ?? 0);
        // Verifica que el embarazo sea de este paciente/consultorio.
        $chk = db()->prepare('SELECT fum FROM embarazos WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
        $chk->execute([$eid, $pid, tenant_id()]);
        $emb = $chk->fetch();
        if ($emb) {
            $fecha = ($_POST['fecha'] ?? '') ?: date('Y-m-d');
            $sdg   = ($_POST['sdg'] ?? '') !== '' ? (float) $_POST['sdg'] : null;
            // Si no se capturó SDG, se calcula de la FUM a la fecha de la visita.
            if ($sdg === null && $emb['fum']) {
                try { $sdg = round((new DateTime($emb['fum']))->diff(new DateTime($fecha))->days / 7, 1); } catch (Throwable $e) {}
            }
            $numOrNull = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
            db()->prepare('INSERT INTO prenatal_visitas
                (embarazo_id, fecha, sdg, peso, presion, fcf, altura_uterina, movimientos, edema, notas, creado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$eid, $fecha, $sdg, $numOrNull('peso'),
                    trim($_POST['presion'] ?? '') ?: null, $numOrNull('fcf'), $numOrNull('altura_uterina'),
                    isset($_POST['movimientos']) ? 1 : 0, trim($_POST['edema'] ?? '') ?: null,
                    trim($_POST['notas'] ?? '') ?: null, $u['id']]);
            flash('Visita de control agregada.');
        }
        redirect('/prenatal/index?paciente_id=' . $pid);
    }

    if ($accion === 'del_visita') {
        db()->prepare('DELETE pv FROM prenatal_visitas pv JOIN embarazos e ON e.id = pv.embarazo_id
                       WHERE pv.id = ? AND e.paciente_id = ? AND e.consultorio_id = ?')
            ->execute([(int) ($_POST['visita_id'] ?? 0), $pid, tenant_id()]);
        flash('Visita eliminada.');
        redirect('/prenatal/index?paciente_id=' . $pid);
    }
}

/* ── Datos ──────────────────────────────────────────────────────────── */
$emb = db()->prepare('SELECT * FROM embarazos WHERE paciente_id = ? AND consultorio_id = ? AND activo = 1 ORDER BY id DESC LIMIT 1');
$emb->execute([$pid, tenant_id()]);
$emb = $emb->fetch() ?: null;

$hist = db()->prepare('SELECT * FROM embarazos WHERE paciente_id = ? AND consultorio_id = ? AND activo = 0 ORDER BY id DESC');
$hist->execute([$pid, tenant_id()]);
$hist = $hist->fetchAll();

$visitas = [];
if ($emb) {
    $vq = db()->prepare('SELECT * FROM prenatal_visitas WHERE embarazo_id = ? ORDER BY fecha ASC, id ASC');
    $vq->execute([(int) $emb['id']]);
    $visitas = $vq->fetchAll();
}
// Series para gráficas.
$gLabels = $gPeso = $gAltura = $gSis = $gDia = [];
foreach ($visitas as $v) {
    $gLabels[] = $v['sdg'] !== null ? ($v['sdg'] . ' SDG') : date('d/m', strtotime($v['fecha']));
    $gPeso[]   = $v['peso'] !== null ? (float) $v['peso'] : null;
    $gAltura[] = $v['altura_uterina'] !== null ? (float) $v['altura_uterina'] : null;
    $s = $d = null;
    if (!empty($v['presion']) && preg_match('#(\d{2,3})\s*/\s*(\d{2,3})#', $v['presion'], $m)) { $s=(int)$m[1]; $d=(int)$m[2]; }
    $gSis[] = $s; $gDia[] = $d;
}
$hayGrafica = count(array_filter($gAltura, fn($x)=>$x!==null)) || count(array_filter($gPeso, fn($x)=>$x!==null)) || count(array_filter($gSis, fn($x)=>$x!==null));

$titulo = t('Control prenatal');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-gender-female text-brand"></i> <?= et('Control prenatal') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if (!$emb): ?>
    <!-- Iniciar embarazo -->
    <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Registrar embarazo') ?></h2>
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear_embarazo">
            <input type="hidden" name="paciente_id" value="<?= $pid ?>">
            <div class="col-md-3"><label class="form-label"><?= et('FUM') ?> <span class="text-muted small">(<?= et('última menstruación') ?>)</span></label><input type="date" name="fum" class="form-control" max="<?= date('Y-m-d') ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label"><?= et('Grupo sanguíneo') ?></label><input type="text" name="grupo_sanguineo" class="form-control" maxlength="6" placeholder="O+"></div>
            <div class="col-6 col-md-2"><label class="form-label"><?= et('Riesgo') ?></label>
                <select name="riesgo" class="form-select"><option value="bajo"><?= et('Bajo') ?></option><option value="alto"><?= et('Alto') ?></option></select>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label"><?= et('Antecedentes (G / P / C / A)') ?></label>
                <div class="d-flex gap-2">
                    <input type="number" min="0" name="gestas" class="form-control" placeholder="G" title="<?= e(t('Gestas')) ?>">
                    <input type="number" min="0" name="partos" class="form-control" placeholder="P" title="<?= e(t('Partos')) ?>">
                    <input type="number" min="0" name="cesareas" class="form-control" placeholder="C" title="<?= e(t('Cesáreas')) ?>">
                    <input type="number" min="0" name="abortos" class="form-control" placeholder="A" title="<?= e(t('Abortos')) ?>">
                </div>
            </div>
            <div class="col-12"><label class="form-label"><?= et('Notas') ?></label><input type="text" name="notas" class="form-control" maxlength="255"></div>
            <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Registrar embarazo') ?></button></div>
        </form>
    </div></div>
<?php else: ?>
    <!-- Resumen del embarazo activo -->
    <?php $sdgHoy = sdg_desde_fum($emb['fum']); ?>
    <div class="card mb-4"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div class="row g-4 flex-grow-1">
                <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Semanas (SDG)') ?></div><div class="h4 mb-0 text-brand"><?= e($sdgHoy ?: '—') ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('FUM') ?></div><div class="fw-semibold"><?= $emb['fum'] ? e(fmt_fecha($emb['fum'])) : '—' ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('FPP') ?></div><div class="fw-semibold"><?= $emb['fpp'] ? e(fmt_fecha($emb['fpp'])) : '—' ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted small text-uppercase"><?= et('Riesgo') ?></div><div><span class="badge bg-<?= $emb['riesgo']==='alto'?'danger':'success' ?>"><?= $emb['riesgo']==='alto'?et('Alto'):et('Bajo') ?></span></div></div>
            </div>
        </div>
        <div class="small text-muted mt-3">
            <?php if ($emb['grupo_sanguineo']): ?><span class="me-3"><?= et('Grupo:') ?> <strong><?= e($emb['grupo_sanguineo']) ?></strong></span><?php endif; ?>
            <span class="me-3">G<?= (int)$emb['gestas'] ?> P<?= (int)$emb['partos'] ?> C<?= (int)$emb['cesareas'] ?> A<?= (int)$emb['abortos'] ?></span>
            <?php if ($emb['notas']): ?><span><?= e($emb['notas']) ?></span><?php endif; ?>
        </div>
        <form method="post" class="mt-3 d-flex gap-2 flex-wrap align-items-end" onsubmit="return confirm('<?= e(t('¿Cerrar este embarazo?')) ?>');">
            <?= csrf_field() ?><input type="hidden" name="accion" value="cerrar_embarazo"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="embarazo_id" value="<?= (int)$emb['id'] ?>">
            <div><label class="form-label small mb-0"><?= et('Cerrar embarazo (desenlace)') ?></label>
                <input type="text" name="desenlace" class="form-control form-control-sm" style="max-width:260px" placeholder="<?= e(t('Parto / cesárea / …')) ?>"></div>
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-flag"></i> <?= et('Cerrar') ?></button>
        </form>
    </div></div>

    <?php if ($hayGrafica): ?>
    <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-graph-up text-brand"></i> <?= et('Evolución del embarazo') ?></h2>
        <div class="row g-3">
            <div class="col-md-4"><div class="small fw-semibold mb-1"><?= et('Altura uterina (cm)') ?></div><div style="height:180px"><canvas id="chAltura"></canvas></div></div>
            <div class="col-md-4"><div class="small fw-semibold mb-1"><?= et('Peso (kg)') ?></div><div style="height:180px"><canvas id="chPeso"></canvas></div></div>
            <div class="col-md-4"><div class="small fw-semibold mb-1"><?= et('Presión arterial') ?></div><div style="height:180px"><canvas id="chTA"></canvas></div></div>
        </div>
    </div></div>
    <?php endif; ?>

    <!-- Nueva visita -->
    <div class="card mb-4"><div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nueva visita de control') ?></h2>
        <form method="post" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="accion" value="add_visita"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="embarazo_id" value="<?= (int)$emb['id'] ?>">
            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-6 col-md-1"><label class="form-label small"><?= et('SDG') ?></label><input type="number" step="0.1" min="0" name="sdg" class="form-control" placeholder="<?= e(t('auto')) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Peso (kg)') ?></label><input type="number" step="0.01" min="0" name="peso" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Presión') ?></label><input type="text" name="presion" class="form-control" placeholder="120/80"></div>
            <div class="col-6 col-md-2"><label class="form-label small"><?= et('FCF (lpm)') ?></label><input type="number" min="0" name="fcf" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Altura uterina (cm)') ?></label><input type="number" step="0.1" min="0" name="altura_uterina" class="form-control"></div>
            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Edema') ?></label><input type="text" name="edema" class="form-control" maxlength="40" placeholder="<?= e(t('no / +/…')) ?>"></div>
            <div class="col-6 col-md-3 d-flex align-items-center pt-3"><div class="form-check"><input class="form-check-input" type="checkbox" name="movimientos" id="mov" checked><label class="form-check-label small" for="mov"><?= et('Movimientos fetales') ?></label></div></div>
            <div class="col-md-5"><label class="form-label small"><?= et('Notas') ?></label><input type="text" name="notas" class="form-control" maxlength="255"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= et('Agregar') ?></button></div>
        </form>
    </div></div>

    <!-- Historial de visitas -->
    <div class="card mb-4">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-brand"></i> <?= et('Visitas de control') ?> (<?= count($visitas) ?>)</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th><?= et('Fecha') ?></th><th><?= et('SDG') ?></th><th><?= et('Peso') ?></th><th><?= et('PA') ?></th><th><?= et('FCF') ?></th><th><?= et('Altura uterina') ?></th><th><?= et('Mov.') ?></th><th><?= et('Edema') ?></th><th></th></tr></thead>
                <tbody>
                <?php if (!$visitas): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4"><?= et('Aún no hay visitas.') ?></td></tr>
                <?php else: foreach (array_reverse($visitas) as $v): ?>
                    <tr>
                        <td class="small"><?= e(fmt_fecha($v['fecha'])) ?></td>
                        <td><?= $v['sdg'] !== null ? e($v['sdg']) : '—' ?></td>
                        <td><?= $v['peso'] !== null ? e($v['peso']) . ' kg' : '—' ?></td>
                        <td><?= e($v['presion'] ?: '—') ?></td>
                        <td><?= $v['fcf'] !== null ? e($v['fcf']) . ' lpm' : '—' ?></td>
                        <td><?= $v['altura_uterina'] !== null ? e($v['altura_uterina']) . ' cm' : '—' ?></td>
                        <td><?= $v['movimientos'] ? '<i class="bi bi-check-lg text-success"></i>' : '—' ?></td>
                        <td class="small"><?= e($v['edema'] ?: '—') ?></td>
                        <td class="text-end">
                            <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta visita?')) ?>');">
                                <?= csrf_field() ?><input type="hidden" name="accion" value="del_visita"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="visita_id" value="<?= (int)$v['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($hist): ?>
<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Embarazos anteriores') ?></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($hist as $h): ?>
        <li class="list-group-item d-flex justify-content-between">
            <span><?= $h['fum'] ? e(fmt_fecha($h['fum'])) : '—' ?> → <?= $h['cerrado_en'] ? e(fmt_fecha($h['cerrado_en'])) : '—' ?></span>
            <span class="text-muted small"><?= e($h['desenlace'] ?: t('Cerrado')) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($hayGrafica)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var labels = <?= json_encode($gLabels) ?>;
    var grid = 'rgba(127,127,127,.14)';
    function line(id, sets) {
        var el = document.getElementById(id); if (!el) return;
        new Chart(el, { type: 'line', data: { labels: labels, datasets: sets.map(function (s) {
            return { label: s.l, data: s.d, borderColor: s.c, backgroundColor: s.c, tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 };
        }) }, options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: sets.length > 1, labels: { boxWidth: 10, font: { size: 10 } } } },
            scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 6, font: { size: 10 } } },
                      y: { grid: { color: grid }, ticks: { font: { size: 10 } } } } } });
    }
    line('chAltura', [{ l: 'Altura', d: <?= json_encode($gAltura) ?>, c: '#2563eb' }]);
    line('chPeso',   [{ l: 'Peso',   d: <?= json_encode($gPeso) ?>,   c: '#10b981' }]);
    line('chTA',     [{ l: 'Sistólica',  d: <?= json_encode($gSis) ?>, c: '#ef4444' },
                      { l: 'Diastólica', d: <?= json_encode($gDia) ?>, c: '#f59e0b' }]);
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
