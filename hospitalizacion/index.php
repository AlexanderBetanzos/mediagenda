<?php
/**
 * Hospitalización: quién está internado ahora (con sus dosis atrasadas),
 * ingreso de un paciente y altas recientes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/hospitalizacion.php';
require_login();
require_modulo('expediente');
if (!giro_aplica('hospitalizacion')) redirect('/dashboard');
asegurar_hosp();
hosp_cargar_activas();   // noches/días pendientes de cargar a cada cuenta

$pdo = db();
$tid = (int) tenant_id();
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'ingresar') {
    verify_csrf();
    $pac = (int) ($_POST['paciente_id'] ?? 0);
    $med = (int) ($_POST['medico_id'] ?? 0);
    if (!$pac || !pertenece_al_tenant('pacientes', $pac)) {
        flash(t('Selecciona un paciente.'), 'warning');
        redirect('/hospitalizacion/index');
    }
    $ya = $pdo->prepare("SELECT id FROM hosp_estancias WHERE consultorio_id = ? AND paciente_id = ? AND estado = 'activa'");
    $ya->execute([$tid, $pac]);
    if ($eid = (int) $ya->fetchColumn()) {
        flash(t('Ese paciente ya está hospitalizado.'), 'info');
        redirect('/hospitalizacion/estancia?id=' . $eid);
    }
    $ing = str_replace('T', ' ', (string) ($_POST['ingreso'] ?? ''));
    $ing = strtotime($ing) ? date('Y-m-d H:i:s', strtotime($ing)) : date('Y-m-d H:i:s');
    // Cuarto: la tarifa y la modalidad se COPIAN a la estancia, así cambiar el
    // precio del cuarto después no altera lo ya pactado con este paciente.
    $cuarto = null;
    if ($cid = (int) ($_POST['cuarto_id'] ?? 0)) {
        $q = $pdo->prepare('SELECT * FROM hosp_cuartos WHERE id = ? AND consultorio_id = ?');
        $q->execute([$cid, $tid]);
        $cuarto = $q->fetch() ?: null;
    }
    $tarifa = ($_POST['tarifa'] ?? '') !== '' ? max(0, (float) $_POST['tarifa']) : ($cuarto ? (float) $cuarto['tarifa'] : null);
    $txt = fn ($k, $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n) ?: null;
    $pdo->prepare('INSERT INTO hosp_estancias (consultorio_id, paciente_id, medico_id, cama, motivo, diagnostico, ingreso, creado_por) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$tid, $pac, $med && pertenece_al_tenant('usuarios', $med) ? $med : null,
                   $txt('cama', 60) ?? ($cuarto['nombre'] ?? null),
                   $txt('motivo', 255), $txt('diagnostico', 255),
                   $ing, (int) $u['id']]);
    $eid = (int) $pdo->lastInsertId();
    try {
        $pdo->prepare('UPDATE hosp_estancias SET cuarto_id = ?, modalidad = ?, tarifa = ?, alta_estimada = ?, responsable = ?, responsable_tel = ?, responsable_parentesco = ?
                       WHERE id = ? AND consultorio_id = ?')
            ->execute([$cuarto['id'] ?? null, $cuarto['modalidad'] ?? null, $tarifa,
                       preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['alta_estimada'] ?? '')) ? $_POST['alta_estimada'] : null,
                       $txt('responsable', 120), $txt('responsable_tel', 40), $txt('responsable_parentesco', 60), $eid, $tid]);
        // Si se cobra por estancia, la cuenta se abre ya con el primer día.
        $st = $pdo->prepare('SELECT * FROM hosp_estancias WHERE id = ?');
        $st->execute([$eid]);
        hosp_cargar($st->fetch());
        // Admisiones: si venía de una solicitud, queda marcada como ingresada.
        if ($adm = (int) ($_POST['admision_id'] ?? 0)) {
            require_once __DIR__ . '/../includes/admisiones.php';
            admision_marcar($adm, 'ingreso', $pac);
        }
    } catch (Throwable $ex) { /* sin columnas nuevas: la estancia queda sin tarifa */ }
    auditar('hosp_ingreso', 'paciente', $pac, 'Estancia #' . $eid);
    flash(t('Paciente ingresado. Agrega sus indicaciones para armar el kardex.'));
    redirect('/hospitalizacion/estancia?id=' . $eid);
}

$st = $pdo->prepare("SELECT e.*, p.nombre, p.apellidos, COALESCE(p.foto_mime, p.foto) foto, u.nombre medico,
                            (SELECT COUNT(*) FROM hosp_indicaciones i WHERE i.estancia_id = e.id AND i.suspendida_en IS NULL) n_ind
                     FROM hosp_estancias e JOIN pacientes p ON p.id = e.paciente_id LEFT JOIN usuarios u ON u.id = e.medico_id
                     WHERE e.consultorio_id = ? AND e.estado = 'activa' ORDER BY e.ingreso");
$st->execute([$tid]);
$activas = $st->fetchAll();
$atras = hosp_atrasadas();

$st = $pdo->prepare("SELECT e.*, p.nombre, p.apellidos FROM hosp_estancias e JOIN pacientes p ON p.id = e.paciente_id
                     WHERE e.consultorio_id = ? AND e.estado = 'alta' ORDER BY e.alta_en DESC LIMIT 15");
$st->execute([$tid]);
$altas = $st->fetchAll();

$pacientes = $pdo->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([$tid]);
$pacientes = $pacientes->fetchAll();
$medicos = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE rol IN ('medico','admin') AND activo = 1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([$tid]);
$medicos = $medicos->fetchAll();
$presel = (int) ($_GET['paciente_id'] ?? 0);
$cuartos = hosp_cuartos();
$ocup = hosp_ocupacion();
$mods = hosp_modalidades();
$porCuarto = [];
foreach ($activas as $a) if (!empty($a['cuarto_id'])) $porCuarto[(int) $a['cuarto_id']][] = $a;
$camasTot = array_sum(array_map(fn ($c) => (int) $c['camas'], $cuartos));
$hoyAct = 0;
try { $q = $pdo->prepare('SELECT COUNT(*) FROM hosp_actividades WHERE consultorio_id = ? AND fecha = CURDATE()'); $q->execute([$tid]); $hoyAct = (int) $q->fetchColumn(); } catch (Throwable $e) {}

$titulo = t('Hospitalización');
$activo = 'hospitalizacion';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-hospital text-primary"></i> <?= et('Hospitalización') ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/hospitalizacion/actividades" class="btn btn-light"><i class="bi bi-calendar2-week"></i> <?= et('Programa del día') ?><?php if ($hoyAct): ?> <span class="badge text-bg-primary"><?= $hoyAct ?></span><?php endif; ?></a>
        <?php if (has_role('admin')): ?><a href="<?= BASE_URL ?>/hospitalizacion/cuartos" class="btn btn-light"><i class="bi bi-door-closed"></i> <?= et('Cuartos y tarifas') ?></a><?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#ingreso"><i class="bi bi-box-arrow-in-right"></i> <?= et('Ingresar paciente') ?></button>
    </div>
</div>

<?= ui_stats([
    [t('Hospitalizados'), number_format(count($activas)), 'bi-hospital', 'var(--brand)'],
    [t('Dosis atrasadas'), number_format(array_sum($atras)), 'bi-alarm', '#dc2626', null, array_sum($atras) > 0],
    [t('Indicaciones activas'), number_format(array_sum(array_column($activas, 'n_ind'))), 'bi-capsule', '#6366f1'],
    $camasTot ? [t('Camas ocupadas'), array_sum($ocup) . ' / ' . $camasTot, 'bi-door-closed', '#0d9488'] : [t('Altas recientes'), number_format(count($altas)), 'bi-box-arrow-right', '#16a34a'],
]) ?>

<?php if ($cuartos): ?>
<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0 d-flex align-items-center gap-1"><i class="bi bi-door-closed"></i> <?= et('Cuartos') ?> <?= ayuda_btn('hosp.cuartos') ?></h2>
        <span class="small text-muted"><?= et('Toca un cuarto libre para ingresar a alguien ahí') ?></span>
    </div>
    <div class="hc-grid">
        <?php foreach ($cuartos as $c): $occ = $porCuarto[(int) $c['id']] ?? []; $libres = max(0, (int) $c['camas'] - count($occ)); ?>
        <div class="hc <?= $libres ? 'libre' : 'lleno' ?>">
            <div class="d-flex justify-content-between align-items-start">
                <div><b><?= e($c['nombre']) ?></b><div class="small text-muted"><?= e($c['tipo'] ?: t($mods[$c['modalidad']] ?? '')) ?></div></div>
                <span class="badge <?= $libres ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $libres ? sprintf(et('%d libre(s)'), $libres) : et('Lleno') ?></span>
            </div>
            <div class="mt-2 d-flex flex-column gap-1">
                <?php foreach ($occ as $o): ?><a class="hc-oc" href="<?= BASE_URL ?>/hospitalizacion/estancia?id=<?= (int) $o['id'] ?>"><i class="bi bi-person-fill"></i> <?= e($o['nombre'] . ' ' . $o['apellidos']) ?> <span class="text-muted">· <?= e(hosp_tiempo($o['ingreso'])) ?></span></a><?php endforeach; ?>
                <?php if ($libres): ?><button type="button" class="hc-in" data-cuarto="<?= (int) $c['id'] ?>"><i class="bi bi-plus-lg"></i> <?= et('Ingresar aquí') ?></button><?php endif; ?>
            </div>
            <?php if ((float) $c['tarifa'] > 0): ?><div class="small text-muted mt-2"><?= fmt_money($c['tarifa']) ?> <?= $c['modalidad'] === 'noche' ? et('por noche') : ($c['modalidad'] === 'dia' ? et('por día') : ($c['modalidad'] === 'hora' ? et('por hora') : '')) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div></div>
<style>
.hc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.8rem}
.hc{border:1px solid var(--bs-border-color);border-radius:14px;padding:.85rem 1rem;background:var(--bs-body-bg)}
.hc.libre{border-color:color-mix(in srgb,#16a34a 35%,var(--bs-border-color))}
.hc-oc{font-size:.86rem;text-decoration:none;color:inherit;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hc-oc:hover{color:var(--brand)}
.hc-in{border:1px dashed color-mix(in srgb,var(--brand) 45%,transparent);background:transparent;color:var(--brand);border-radius:9px;padding:.3rem .6rem;font-size:.84rem;font-weight:600;text-align:left}
.hc-in:hover{background:color-mix(in srgb,var(--brand) 8%,transparent)}
</style>
<?php elseif (has_role('admin')): ?>
<div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span><i class="bi bi-lightbulb"></i> <?= et('¿Manejas internamiento con cuartos y tarifa por noche o por día? Da de alta tus cuartos y la cuenta de cada paciente se cargará sola.') ?></span>
    <a href="<?= BASE_URL ?>/hospitalizacion/cuartos" class="btn btn-sm btn-primary"><?= et('Configurar cuartos') ?></a>
</div>
<?php endif; ?>

<div class="collapse <?= $presel ? 'show' : '' ?> mb-4" id="ingreso">
    <div class="card"><div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-box-arrow-in-right"></i> <?= et('Ingreso') ?></h2>
        <form method="post" class="row g-3">
            <?= csrf_field() ?><input type="hidden" name="accion" value="ingresar">
            <div class="col-md-5"><label class="form-label"><?= et('Paciente') ?> *</label>
                <select name="paciente_id" class="form-select" required><option value=""><?= et('— Selecciona —') ?></option>
                <?php foreach ($pacientes as $p): ?><option value="<?= $p['id'] ?>" <?= $presel === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['apellidos'] . ', ' . $p['nombre']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label"><?= et('Médico responsable') ?></label>
                <select name="medico_id" class="form-select"><?php foreach ($medicos as $m): ?><option value="<?= $m['id'] ?>" <?= (int) $u['id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?></select></div>
            <?php if ($cuartos): ?>
            <div class="col-md-3"><label class="form-label"><?= et('Cuarto') ?></label>
                <select name="cuarto_id" id="inCuarto" class="form-select"><option value=""><?= et('— Sin cuarto —') ?></option>
                <?php foreach ($cuartos as $c): $libres = (int) $c['camas'] - ($ocup[(int) $c['id']] ?? 0); ?>
                    <option value="<?= (int) $c['id'] ?>" data-tarifa="<?= e((string) (float) $c['tarifa']) ?>" <?= $libres <= 0 ? 'disabled' : '' ?>><?= e($c['nombre']) ?> · <?= $libres > 0 ? sprintf(et('%d libre(s)'), $libres) : et('lleno') ?><?= (float) $c['tarifa'] > 0 ? ' · ' . fmt_money($c['tarifa']) : '' ?></option>
                <?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label"><?= et('Tarifa pactada') ?></label><input type="number" step="0.01" min="0" name="tarifa" id="inTarifa" class="form-control" placeholder="<?= et('La del cuarto') ?>"></div>
            <div class="col-md-2"><label class="form-label"><?= et('Cama') ?></label><input name="cama" class="form-control" maxlength="60" placeholder="<?= et('Opcional') ?>"></div>
            <?php else: ?>
            <div class="col-md-3"><label class="form-label"><?= et(es_veterinaria() ? 'Jaula / área' : 'Cama / área') ?></label><input name="cama" class="form-control" maxlength="60" placeholder="<?= et('Ej. 3') ?>"></div>
            <?php endif; ?>
            <div class="col-md-5"><label class="form-label"><?= et('Motivo de ingreso') ?></label><input name="motivo" class="form-control" maxlength="255"></div>
            <div class="col-md-4"><label class="form-label"><?= et('Diagnóstico') ?></label><input name="diagnostico" class="form-control" maxlength="255"></div>
            <div class="col-md-3"><label class="form-label"><?= et('Fecha y hora de ingreso') ?></label><input type="datetime-local" name="ingreso" class="form-control" value="<?= date('Y-m-d\TH:i') ?>"></div>
            <div class="col-md-3"><label class="form-label"><?= et('Alta estimada') ?></label><input type="date" name="alta_estimada" class="form-control"></div>
            <div class="col-md-4"><label class="form-label"><?= et('Familiar responsable') ?></label><input name="responsable" class="form-control" maxlength="120" value="<?= e($_GET['responsable'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label"><?= et('Parentesco') ?></label><input name="responsable_parentesco" class="form-control" maxlength="60" value="<?= e($_GET['parentesco'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label"><?= et('Teléfono del responsable') ?></label><input name="responsable_tel" class="form-control" maxlength="40" value="<?= e($_GET['tel'] ?? '') ?>"></div>
            <input type="hidden" name="admision_id" value="<?= (int) ($_GET['admision'] ?? 0) ?>">
            <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check2"></i> <?= et('Ingresar') ?></button></div>
        </form>
    </div></div>
</div>

<?php if (!$activas): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-hospital fs-1 d-block mb-2 opacity-50"></i><?= et('No hay pacientes hospitalizados.') ?>
    </div></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($activas as $e): $n = $atras[$e['id']] ?? 0; ?>
    <div class="col-md-6 col-xl-4">
        <a href="<?= BASE_URL ?>/hospitalizacion/estancia?id=<?= $e['id'] ?>" class="card h-100 text-reset text-decoration-none<?= $n ? ' border-danger' : '' ?>">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <?= avatar_paciente((int) $e['paciente_id'], $e['nombre'], $e['apellidos'], $e['foto'] ?? null, 42) ?>
                    <div class="min-w-0 flex-grow-1"><div class="fw-semibold text-truncate"><?= e($e['nombre'] . ' ' . $e['apellidos']) ?></div>
                        <div class="small text-muted text-truncate"><?= e($e['diagnostico'] ?: ($e['motivo'] ?: '—')) ?></div></div>
                    <?php if ($e['cama']): ?><span class="badge text-bg-light border fs-6"><?= e($e['cama']) ?></span><?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2 small">
                    <span class="badge rounded-pill text-bg-light border"><i class="bi bi-clock"></i> <?= e(hosp_tiempo($e['ingreso'])) ?></span>
                    <span class="badge rounded-pill text-bg-light border"><i class="bi bi-capsule"></i> <?= (int) $e['n_ind'] ?> <?= et('indicaciones') ?></span>
                    <?php if ($n): ?><span class="badge rounded-pill text-bg-danger"><i class="bi bi-alarm"></i> <?= $n ?> <?= et('dosis atrasadas') ?></span>
                    <?php elseif ($e['n_ind']): ?><span class="badge rounded-pill text-bg-success"><i class="bi bi-check2"></i> <?= et('Al día') ?></span><?php endif; ?>
                </div>
                <?php if ($e['medico']): ?><div class="small text-muted mt-2"><i class="bi bi-person-badge"></i> <?= e($e['medico']) ?></div><?php endif; ?>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($altas): ?>
<h2 class="h6 mt-4 mb-2 text-muted"><?= et('Altas recientes') ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th><?= et('Paciente') ?></th><th><?= et('Ingreso') ?></th><th><?= et('Alta') ?></th><th><?= et('Estancia') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($altas as $e): ?>
        <tr><td><?= e($e['nombre'] . ' ' . $e['apellidos']) ?></td><td class="small"><?= fmt_fecha($e['ingreso']) ?></td><td class="small"><?= fmt_fecha($e['alta_en']) ?></td>
            <td class="small"><?= e(hosp_tiempo($e['ingreso'], $e['alta_en'])) ?></td>
            <td class="text-end"><a href="<?= BASE_URL ?>/hospitalizacion/estancia?id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= et('Ver') ?></a></td></tr>
    <?php endforeach; ?></tbody>
</table></div></div>
<?php endif; ?>
<script>
(function () {
    // "Ingresar aquí" en el mapa: abre el formulario con ese cuarto elegido.
    var sel = document.getElementById('inCuarto'), tar = document.getElementById('inTarifa');
    document.querySelectorAll('.hc-in').forEach(function (b) {
        b.addEventListener('click', function () {
            if (sel) { sel.value = b.dataset.cuarto; sel.dispatchEvent(new Event('change')); }
            var c = document.getElementById('ingreso');
            if (c && window.bootstrap) bootstrap.Collapse.getOrCreateInstance(c).show();
            setTimeout(function () { c.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150);
        });
    });
    if (sel && tar) sel.addEventListener('change', function () { var o = sel.selectedOptions[0]; tar.placeholder = o && o.dataset.tarifa ? o.dataset.tarifa : ''; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
