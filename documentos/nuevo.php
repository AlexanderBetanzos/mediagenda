<?php
/**
 * Emitir un documento clínico.
 *
 * El flujo es: se elige la plantilla, el sistema resuelve los marcadores con lo
 * que YA está capturado (nombre, edad, sexo, diagnóstico de su última consulta)
 * y el médico corrige el texto antes de guardar. Nunca se emite algo que el
 * médico no haya leído: el texto queda editable hasta el último momento.
 *
 * Lo que se guarda es el texto RESUELTO, no la plantilla: si mañana se edita la
 * plantilla, el papel que ya se le entregó al paciente no cambia.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('medico', 'admin');
require_modulo('documentos');

$pid  = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$plid = (int) ($_GET['plantilla_id'] ?? 0);
$u    = current_user();

$pac = null;
if ($pid) {
    $st = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $st->execute([$pid, tenant_id()]);
    $pac = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$pac) { flash('Selecciona un paciente.', 'warning'); redirect('/documentos/index'); }

    $titulo_doc = trim((string) ($_POST['titulo'] ?? ''));
    $cuerpo     = trim((string) ($_POST['cuerpo'] ?? ''));

    if ($titulo_doc === '' || $cuerpo === '') {
        flash('El documento necesita un título y un cuerpo.', 'warning');
        redirect('/documentos/nuevo?paciente_id=' . $pid);
    }

    $folio = documento_siguiente_folio();
    db()->prepare(
        'INSERT INTO documentos
         (consultorio_id, folio, paciente_id, plantilla_id, medico_id, titulo, cuerpo, fecha, creado_por)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        tenant_id(), $folio, $pid,
        ((int) ($_POST['plantilla_id'] ?? 0)) ?: null,
        ((int) ($_POST['medico_id'] ?? 0)) ?: null,
        mb_substr($titulo_doc, 0, 120), $cuerpo,
        ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
        (int) $u['id'],
    ]);
    $id = (int) db()->lastInsertId();

    auditar('documento_crear', 'documento', $id, $folio . ' · ' . $titulo_doc);
    flash('Documento emitido. Ya está en el expediente del paciente.');
    redirect('/documentos/imprimir?id=' . $id);
}

/* Plantillas del consultorio. */
$st = db()->prepare('SELECT * FROM documento_plantillas WHERE consultorio_id = ? AND activo = 1 ORDER BY orden, nombre');
$st->execute([tenant_id()]);
$plantillas = $st->fetchAll();

$plantilla = null;
foreach ($plantillas as $p) { if ((int) $p['id'] === $plid) { $plantilla = $p; break; } }

/* Médicos que pueden firmar. */
$medicos = db()->prepare(
    "SELECT id, nombre, especialidad FROM usuarios
     WHERE consultorio_id = ? AND rol IN ('medico','admin') AND activo = 1 ORDER BY nombre"
);
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$medicoSel = null;
foreach ($medicos as $m) { if ((int) $m['id'] === (int) $u['id']) { $medicoSel = $m; break; } }

/* Diagnóstico de la última consulta: es el dato que casi siempre va en estos
   papeles y que el médico acabaría copiando a mano. */
$dx = null;
if ($pac) {
    $q = db()->prepare(
        "SELECT diagnostico FROM consultas
         WHERE paciente_id = ? AND consultorio_id = ? AND diagnostico IS NOT NULL AND diagnostico <> ''
         ORDER BY fecha DESC LIMIT 1"
    );
    $q->execute([$pid, tenant_id()]);
    $dx = $q->fetchColumn() ?: null;
}

/* Cuerpo ya resuelto (el médico lo edita antes de guardar). */
$cuerpo = '';
if ($plantilla && $pac) {
    $cuerpo = documento_resolver($plantilla['cuerpo'], $pac, $medicoSel, $dx,
                                 ['dias' => (int) ($_GET['dias'] ?? 3)]);
}

$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();

$titulo = t('Nuevo documento');
$activo = 'documentos';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/documentos/index"><?= et('Documentos') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Nuevo documento') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-file-earmark-medical text-brand"></i> <?= et('Nuevo documento') ?></h1>

<?php if (!$pac): ?>
<div class="card">
    <form class="card-body row g-2 align-items-end" method="get">
        <div class="col-md-6">
            <label class="form-label"><?= et('Paciente') ?></label>
            <select name="paciente_id" class="form-select" required>
                <option value=""><?= et('Selecciona…') ?></option>
                <?php foreach ($pacientes as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['apellidos'] . ', ' . $p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-primary"><?= et('Continuar') ?></button></div>
    </form>
</div>

<?php elseif (!$plantillas): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span><i class="bi bi-info-circle"></i> <?= et('Todavía no tienes plantillas de documento.') ?></span>
    <?php if (has_role('admin')): ?>
    <a href="<?= BASE_URL ?>/documentos/plantillas" class="btn btn-sm btn-primary"><?= et('Crear plantillas') ?></a>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="d-flex align-items-center gap-3 mb-3">
    <?= avatar_paciente((int) $pac['id'], $pac['nombre'], $pac['apellidos'], ($pac['foto_mime'] ?? null) ?: ($pac['foto'] ?? null), 48) ?>
    <div>
        <div class="fw-semibold"><?= e($pac['nombre'] . ' ' . $pac['apellidos']) ?></div>
        <div class="small text-muted"><?= e(edad($pac['fecha_nacimiento'])) ?></div>
    </div>
    <a href="<?= BASE_URL ?>/documentos/nuevo" class="btn btn-sm btn-light ms-auto"><?= et('Cambiar paciente') ?></a>
</div>

<?php if ($pac['alergias']): ?>
<div class="alert alert-danger py-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong><?= et('Alergias') ?>:</strong> <?= e($pac['alergias']) ?>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <label class="form-label"><?= et('Plantilla') ?></label>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($plantillas as $p): ?>
                <a href="?paciente_id=<?= $pid ?>&plantilla_id=<?= (int) $p['id'] ?>"
                   class="btn btn-sm <?= $plid === (int) $p['id'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= e($p['nombre']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($plantilla): ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">
    <input type="hidden" name="plantilla_id" value="<?= (int) $plantilla['id'] ?>">

    <div class="card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-5">
                <label class="form-label"><?= et('Título') ?></label>
                <input name="titulo" class="form-control" maxlength="120" required
                       value="<?= e($plantilla['nombre']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Firma') ?></label>
                <select name="medico_id" class="form-select">
                    <option value=""><?= et('Sin firma') ?></option>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (int) $u['id'] === (int) $m['id'] ? 'selected' : '' ?>>
                            <?= e($m['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Fecha') ?></label>
                <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <?php if (strpos($plantilla['cuerpo'], '{dias}') !== false): ?>
            <div class="col-md-3">
                <label class="form-label"><?= et('Días de reposo') ?></label>
                <input type="number" min="1" max="90" class="form-control"
                       value="<?= (int) ($_GET['dias'] ?? 3) ?>"
                       onchange="location='?paciente_id=<?= $pid ?>&plantilla_id=<?= $plid ?>&dias=' + this.value">
                <div class="form-text"><?= et('Al cambiarlo se reescribe el texto.') ?></div>
            </div>
            <?php endif; ?>

            <div class="col-12">
                <label class="form-label"><?= et('Texto del documento') ?></label>
                <textarea name="cuerpo" class="form-control font-monospace" rows="16" required><?= e($cuerpo) ?></textarea>
                <div class="form-text">
                    <?= et('Ya se rellenaron los datos del paciente. Revísalo y ajústalo antes de emitirlo.') ?>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Emitir e imprimir') ?></button>
    </div>
</form>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
