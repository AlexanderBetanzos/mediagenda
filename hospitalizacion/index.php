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
    $pdo->prepare('INSERT INTO hosp_estancias (consultorio_id, paciente_id, medico_id, cama, motivo, diagnostico, ingreso, creado_por) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$tid, $pac, $med && pertenece_al_tenant('usuarios', $med) ? $med : null,
                   mb_substr(trim((string) ($_POST['cama'] ?? '')), 0, 60) ?: null,
                   mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 255) ?: null,
                   mb_substr(trim((string) ($_POST['diagnostico'] ?? '')), 0, 255) ?: null,
                   $ing, (int) $u['id']]);
    $eid = (int) $pdo->lastInsertId();
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

$titulo = t('Hospitalización');
$activo = 'hospitalizacion';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-hospital text-primary"></i> <?= et('Hospitalización') ?></h1>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#ingreso"><i class="bi bi-box-arrow-in-right"></i> <?= et('Ingresar paciente') ?></button>
</div>

<?= ui_stats([
    [t('Hospitalizados'), number_format(count($activas)), 'bi-hospital', 'var(--brand)'],
    [t('Dosis atrasadas'), number_format(array_sum($atras)), 'bi-alarm', '#dc2626', null, array_sum($atras) > 0],
    [t('Indicaciones activas'), number_format(array_sum(array_column($activas, 'n_ind'))), 'bi-capsule', '#6366f1'],
    [t('Altas recientes'), number_format(count($altas)), 'bi-box-arrow-right', '#16a34a'],
]) ?>

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
            <div class="col-md-3"><label class="form-label"><?= et(es_veterinaria() ? 'Jaula / área' : 'Cama / área') ?></label><input name="cama" class="form-control" maxlength="60" placeholder="<?= et('Ej. 3') ?>"></div>
            <div class="col-md-5"><label class="form-label"><?= et('Motivo de ingreso') ?></label><input name="motivo" class="form-control" maxlength="255"></div>
            <div class="col-md-4"><label class="form-label"><?= et('Diagnóstico') ?></label><input name="diagnostico" class="form-control" maxlength="255"></div>
            <div class="col-md-3"><label class="form-label"><?= et('Fecha y hora de ingreso') ?></label><input type="datetime-local" name="ingreso" class="form-control" value="<?= date('Y-m-d\TH:i') ?>"></div>
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
