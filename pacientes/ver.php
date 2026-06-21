<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u  = current_user();
$id = (int) ($_GET['id'] ?? $_POST['paciente_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

/* --- Alta de consulta (expediente). Solo médicos y admin. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'consulta') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }

    $stmt = db()->prepare(
        'INSERT INTO consultas
         (consultorio_id, paciente_id, medico_id, motivo, exploracion, diagnostico, tratamiento, receta,
          peso, estatura, presion, temperatura, notas)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        tenant_id(), $id, $u['id'],
        trim($_POST['motivo'] ?? '') ?: null,
        trim($_POST['exploracion'] ?? '') ?: null,
        trim($_POST['diagnostico'] ?? '') ?: null,
        trim($_POST['tratamiento'] ?? '') ?: null,
        trim($_POST['receta'] ?? '') ?: null,
        $_POST['peso'] !== '' ? $_POST['peso'] : null,
        $_POST['estatura'] !== '' ? $_POST['estatura'] : null,
        trim($_POST['presion'] ?? '') ?: null,
        $_POST['temperatura'] !== '' ? $_POST['temperatura'] : null,
        trim($_POST['notas'] ?? '') ?: null,
    ]);
    $consulta_id = (int) db()->lastInsertId();
    auditar('crear', 'consulta', $consulta_id, 'Paciente #' . $id);

    // Adjunto opcional ligado a esta consulta.
    $r = guardar_archivo_expediente($_FILES['archivo'] ?? null, $id, $u['id'], $_POST['descripcion'] ?? '', $consulta_id);
    if ($r['estado'] === 'error') {
        flash('Consulta guardada, pero el archivo no se adjuntó: ' . $r['mensaje'], 'warning');
    } else {
        flash('Consulta agregada al expediente.');
    }
    redirect('/pacientes/ver?id=' . $id);
}

/* --- Subir archivo al expediente. Solo médicos y admin. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir_archivo') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }

    $r = guardar_archivo_expediente($_FILES['archivo'] ?? null, $id, $u['id'], $_POST['descripcion'] ?? '');
    if ($r['estado'] === 'vacio') {
        flash('Selecciona un archivo para subir.', 'warning');
    } else {
        if ($r['estado'] === 'ok') { auditar('subir', 'archivo', $id, $_FILES['archivo']['name'] ?? null); }
        flash($r['mensaje'], $r['estado'] === 'ok' ? 'success' : 'danger');
    }
    redirect('/pacientes/ver?id=' . $id);
}

/* --- Borrar archivo del expediente. Solo médicos y admin. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'borrar_archivo') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }

    $aid  = (int) ($_POST['archivo_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM archivos WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
    $stmt->execute([$aid, $id, tenant_id()]);
    if ($a = $stmt->fetch()) {
        $ruta = archivo_dir($id) . '/' . basename($a['nombre_guardado']);
        if (is_file($ruta)) { @unlink($ruta); }
        db()->prepare('DELETE FROM archivos WHERE id = ? AND consultorio_id = ?')
            ->execute([$aid, tenant_id()]);
        auditar('borrar', 'archivo', $aid, $a['nombre_original'] ?? null);
        flash('Archivo eliminado.');
    }
    redirect('/pacientes/ver?id=' . $id);
}

/* --- Portal del paciente: habilitar acceso / cambiar contraseña. Staff. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'portal') {
    verify_csrf();
    if (!has_role('admin', 'medico', 'recepcion')) { http_response_code(403); die('Sin permiso.'); }

    if (($_POST['sub'] ?? '') === 'desactivar') {
        db()->prepare('UPDATE pacientes SET portal_activo = 0 WHERE id = ? AND consultorio_id = ?')
            ->execute([$id, tenant_id()]);
        auditar('portal_desactivar', 'paciente', $id);
        flash('Acceso al portal desactivado.');
    } else {
        $pw = $_POST['portal_password'] ?? '';
        if (!$p['email']) {
            flash('El paciente necesita un correo para acceder al portal.', 'warning');
        } elseif (strlen($pw) < 6) {
            flash('La contraseña del portal debe tener al menos 6 caracteres.', 'warning');
        } else {
            db()->prepare('UPDATE pacientes SET portal_password_hash = ?, portal_activo = 1 WHERE id = ? AND consultorio_id = ?')
                ->execute([password_hash($pw, PASSWORD_BCRYPT), $id, tenant_id()]);
            auditar('portal_provision', 'paciente', $id);
            flash('Acceso al portal activado. Comparte la contraseña con el paciente.');
        }
    }
    redirect('/pacientes/ver?id=' . $id);
}

// Citas del paciente
$citas = db()->prepare(
    'SELECT c.*, u.nombre AS med_nombre FROM citas c
     JOIN usuarios u ON u.id = c.medico_id
     WHERE c.paciente_id = ? AND c.consultorio_id = ? ORDER BY c.fecha DESC, c.hora DESC'
);
$citas->execute([$id, tenant_id()]);
$citas = $citas->fetchAll();

// Consultas (expediente)
$cons = db()->prepare(
    'SELECT co.*, u.nombre AS med_nombre FROM consultas co
     JOIN usuarios u ON u.id = co.medico_id
     WHERE co.paciente_id = ? AND co.consultorio_id = ? ORDER BY co.fecha DESC'
);
$cons->execute([$id, tenant_id()]);
$cons = $cons->fetchAll();

// Archivos del expediente
$archivos = db()->prepare(
    'SELECT a.*, u.nombre AS sub_nombre FROM archivos a
     LEFT JOIN usuarios u ON u.id = a.subido_por
     WHERE a.paciente_id = ? AND a.consultorio_id = ? ORDER BY a.creado_en DESC'
);
$archivos->execute([$id, tenant_id()]);
$archivos = $archivos->fetchAll();

// Agrupa los archivos por consulta para mostrarlos dentro de cada tarjeta.
$archivos_por_consulta = [];
foreach ($archivos as $a) {
    if ($a['consulta_id']) { $archivos_por_consulta[$a['consulta_id']][] = $a; }
}

// IMC automático: de la consulta más reciente que tenga peso y estatura.
$bmi = null;
foreach ($cons as $co) { if (($b = imc($co['peso'] ?? 0, $co['estatura'] ?? 0))) { $bmi = $b; break; } }

$titulo = $p['nombre'] . ' ' . $p['apellidos'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index">Pacientes</a></li>
        <li class="breadcrumb-item active"><?= e($p['nombre'].' '.$p['apellidos']) ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h1 class="h3 mb-1">
            <?= e($p['nombre'].' '.$p['apellidos']) ?>
            <span class="badge bg-<?= $p['tipo'] === 'dental' ? 'info' : 'primary' ?> align-middle">
                <?= $p['tipo'] === 'dental' ? 'Dental' : 'Médico' ?>
            </span>
        </h1>
        <span class="text-muted">
            <?= e(edad($p['fecha_nacimiento'])) ?> ·
            <?= $p['sexo'] === 'F' ? 'Femenino' : ($p['sexo'] === 'M' ? 'Masculino' : 'Sexo n/d') ?>
            <?php if (!empty($p['tipo_sangre'])): ?> · <span class="badge bg-danger-subtle text-danger border"><i class="bi bi-droplet-half"></i> <?= e($p['tipo_sangre']) ?></span><?php endif; ?>
            <?php if ($bmi): ?> · <span class="badge bg-<?= $bmi['color'] ?>-subtle text-<?= $bmi['color'] ?> border" title="Índice de masa corporal (última consulta)">IMC <?= $bmi['valor'] ?> · <?= e($bmi['categoria']) ?></span><?php endif; ?>
        </span>
    </div>
    <div class="text-nowrap">
        <a href="<?= BASE_URL ?>/citas/create?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-calendar-plus"></i> Cita</a>
        <?php if (has_role('medico', 'admin')): ?>
        <a href="<?= BASE_URL ?>/recetas/create?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-capsule"></i> Receta</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/facturacion/create?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-receipt"></i> Factura</a>
        <a href="<?= BASE_URL ?>/pacientes/edit?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
    </div>
</div>

<div class="row g-4">
    <!-- Ficha del paciente -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-vcard text-brand"></i> Datos de contacto</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><i class="bi bi-telephone me-2 text-muted"></i><?= e($p['telefono'] ?: '—') ?></li>
                <li class="list-group-item"><i class="bi bi-envelope me-2 text-muted"></i><?= e($p['email'] ?: '—') ?></li>
                <li class="list-group-item"><i class="bi bi-geo-alt me-2 text-muted"></i><?= e($p['direccion'] ?: '—') ?></li>
                <li class="list-group-item"><i class="bi bi-calendar me-2 text-muted"></i><?= fmt_fecha($p['fecha_nacimiento']) ?></li>
                <?php if (!empty($p['curp'])): ?><li class="list-group-item small"><span class="text-muted">CURP:</span> <?= e($p['curp']) ?></li><?php endif; ?>
                <?php if (!empty($p['rfc'])): ?><li class="list-group-item small"><span class="text-muted">RFC:</span> <?= e($p['rfc']) ?></li><?php endif; ?>
                <?php if (!empty($p['ine'])): ?><li class="list-group-item small"><span class="text-muted">INE:</span> <?= e($p['ine']) ?></li><?php endif; ?>
            </ul>
        </div>

        <?php if (!empty($p['contacto_nombre']) || !empty($p['contacto_telefono'])): ?>
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-telephone-plus text-brand"></i> Contacto de emergencia</div>
            <div class="card-body">
                <div class="fw-semibold"><?= e($p['contacto_nombre'] ?: '—') ?>
                    <?php if (!empty($p['contacto_parentesco'])): ?><span class="text-muted fw-normal">· <?= e($p['contacto_parentesco']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($p['contacto_telefono'])): ?>
                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $p['contacto_telefono'])) ?>"><i class="bi bi-telephone"></i> <?= e($p['contacto_telefono']) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> Información clínica</div>
            <div class="card-body">
                <?php
                $clin = [
                    'Alergias' => $p['alergias'], 'Enfermedades crónicas' => $p['enf_cronicas'] ?? '',
                    'Antecedentes personales' => $p['antecedentes'], 'Antecedentes familiares' => $p['antecedentes_familiares'] ?? '',
                    'Cirugías' => $p['cirugias'] ?? '', 'Vacunas' => $p['vacunas'] ?? '',
                    'Hábitos' => $p['habitos'] ?? '', 'Notas' => $p['notas'],
                ];
                foreach ($clin as $lbl => $val): ?>
                    <p class="mb-2"><strong><?= $lbl ?>:</strong><br><?= nl2br(e($val ?: '—')) ?></p>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (modulo_activo('portal') && has_role('admin', 'medico', 'recepcion')): ?>
        <div class="card mt-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lock text-brand"></i> Portal del paciente</div>
            <div class="card-body">
                <?php if ($p['portal_activo']): ?>
                    <p class="mb-2"><span class="badge bg-success">Activo</span> El paciente entra con su correo.</p>
                <?php else: ?>
                    <p class="text-muted small mb-2">Da acceso para que vea sus citas, recetas y estudios.</p>
                <?php endif; ?>
                <?php if (!$p['email']): ?>
                    <div class="alert alert-warning py-2 small mb-2">Agrega un correo al paciente para habilitar el portal.</div>
                <?php endif; ?>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="portal">
                    <input type="hidden" name="paciente_id" value="<?= $id ?>">
                    <div class="col-12">
                        <input type="text" name="portal_password" class="form-control form-control-sm" autocomplete="new-password"
                               placeholder="<?= $p['portal_activo'] ? 'Nueva contraseña' : 'Contraseña (mín. 6)' ?>" <?= !$p['email'] ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-sm btn-primary" <?= !$p['email'] ? 'disabled' : '' ?>>
                            <i class="bi bi-key"></i> <?= $p['portal_activo'] ? 'Cambiar contraseña' : 'Activar acceso' ?>
                        </button>
                        <?php if ($p['portal_activo']): ?>
                        <button name="sub" value="desactivar" class="btn btn-sm btn-outline-danger">Desactivar</button>
                        <?php endif; ?>
                    </div>
                    <div class="col-12"><small class="text-muted">Correo: <?= e($p['email'] ?: '—') ?> · <a href="<?= BASE_URL ?>/portal/login" target="_blank">Abrir portal</a></small></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Expediente / citas -->
    <div class="col-lg-8">
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-exp"><i class="bi bi-file-medical"></i> Expediente (<?= count($cons) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-archivos"><i class="bi bi-paperclip"></i> Archivos (<?= count($archivos) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-citas"><i class="bi bi-calendar-check"></i> Citas (<?= count($citas) ?>)</button></li>
        </ul>

        <div class="tab-content">
            <!-- Expediente -->
            <div class="tab-pane fade show active" id="tab-exp">
                <?php if (has_role('medico', 'admin')): ?>
                <div class="text-end mb-3">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formConsulta">
                        <i class="bi bi-plus-lg"></i> Nueva consulta
                    </button>
                </div>
                <div class="collapse mb-4" id="formConsulta">
                    <div class="card card-body">
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="consulta">
                            <input type="hidden" name="paciente_id" value="<?= $id ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Motivo de consulta</label>
                                    <input type="text" name="motivo" class="form-control">
                                </div>
                                <div class="col-md-3"><label class="form-label">Peso (kg)</label><input type="number" step="0.01" name="peso" class="form-control"></div>
                                <div class="col-md-3"><label class="form-label">Estatura (cm)</label><input type="number" step="0.01" name="estatura" class="form-control"></div>
                                <div class="col-md-3"><label class="form-label">Presión</label><input type="text" name="presion" class="form-control" placeholder="120/80"></div>
                                <div class="col-md-3"><label class="form-label">Temp. (°C)</label><input type="number" step="0.1" name="temperatura" class="form-control"></div>
                                <div class="col-md-6"><label class="form-label">Exploración</label><textarea name="exploracion" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Diagnóstico</label><textarea name="diagnostico" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Tratamiento</label><textarea name="tratamiento" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Receta</label><textarea name="receta" class="form-control" rows="2"></textarea></div>
                                <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-7">
                                    <label class="form-label">Adjuntar archivo <span class="text-muted fw-normal">(opcional)</span></label>
                                    <input type="file" name="archivo" class="form-control">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Descripción del archivo</label>
                                    <input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="Ej. Estudio de laboratorio">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted">Adjunto: PDF, imagen, Word, Excel o texto. Máx. <?= fmt_bytes(archivo_max_bytes()) ?>.</small>
                                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar consulta</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$cons): ?>
                    <p class="text-muted text-center py-4">Sin consultas registradas.</p>
                <?php else: foreach ($cons as $c): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-white d-flex justify-content-between">
                            <span class="fw-semibold"><i class="bi bi-file-medical text-brand"></i> <?= fmt_fecha($c['fecha']) ?> · <?= date('H:i', strtotime($c['fecha'])) ?></span>
                            <span class="text-muted small"><?= e($c['med_nombre']) ?></span>
                        </div>
                        <div class="card-body">
                            <?php if ($c['motivo']): ?><p class="mb-2"><strong>Motivo:</strong> <?= e($c['motivo']) ?></p><?php endif; ?>
                            <?php
                            $vitales = array_filter([
                                $c['peso'] ? "Peso: {$c['peso']} kg" : null,
                                $c['estatura'] ? "Estatura: {$c['estatura']} cm" : null,
                                $c['presion'] ? "PA: {$c['presion']}" : null,
                                $c['temperatura'] ? "Temp: {$c['temperatura']} °C" : null,
                            ]);
                            if ($vitales): ?>
                                <p class="mb-2"><span class="badge bg-light text-dark border me-1"><?= implode('</span> <span class="badge bg-light text-dark border me-1">', array_map('e', $vitales)) ?></span></p>
                            <?php endif; ?>
                            <div class="row">
                                <?php foreach (['exploracion'=>'Exploración','diagnostico'=>'Diagnóstico','tratamiento'=>'Tratamiento','receta'=>'Receta'] as $k=>$lbl):
                                    if ($c[$k]): ?>
                                    <div class="col-md-6 mb-2"><strong><?= $lbl ?>:</strong><br><?= nl2br(e($c[$k])) ?></div>
                                <?php endif; endforeach; ?>
                            </div>
                            <?php if ($c['notas']): ?><p class="mb-2 text-muted"><small><?= nl2br(e($c['notas'])) ?></small></p><?php endif; ?>
                            <?php if (!empty($archivos_por_consulta[$c['id']])): ?>
                            <div class="mt-2 pt-2 border-top">
                                <?php foreach ($archivos_por_consulta[$c['id']] as $a): ?>
                                <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= $a['id'] ?>" class="badge text-bg-light border text-decoration-none me-1" title="<?= e($a['descripcion'] ?: $a['nombre_original']) ?>">
                                    <i class="bi <?= archivo_icono($a['nombre_original']) ?>"></i> <?= e($a['nombre_original']) ?>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Archivos -->
            <div class="tab-pane fade" id="tab-archivos">
                <?php if (has_role('medico', 'admin')): ?>
                <div class="text-end mb-3">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formArchivo">
                        <i class="bi bi-cloud-upload"></i> Subir archivo
                    </button>
                </div>
                <div class="collapse mb-4" id="formArchivo">
                    <div class="card card-body">
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="subir_archivo">
                            <input type="hidden" name="paciente_id" value="<?= $id ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Archivo</label>
                                    <input type="file" name="archivo" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Descripción (opcional)</label>
                                    <input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="Ej. Radiografía de tórax">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted">PDF, imágenes, Word, Excel o texto. Máx. <?= fmt_bytes(archivo_max_bytes()) ?>.</small>
                                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Subir</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$archivos): ?>
                    <p class="text-muted text-center py-4">Sin archivos en el expediente.</p>
                <?php else: ?>
                <div class="card">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($archivos as $a):
                            $mime = $a['mime'] ?? '';
                            $previa = strpos($mime, 'image/') === 0 || $mime === 'application/pdf';
                        ?>
                        <li class="list-group-item d-flex align-items-center gap-3">
                            <i class="bi <?= archivo_icono($a['nombre_original']) ?> fs-3 text-brand"></i>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate"><?= e($a['nombre_original']) ?></div>
                                <small class="text-muted">
                                    <?php if ($a['descripcion']): ?><?= e($a['descripcion']) ?> · <?php endif; ?>
                                    <?= fmt_bytes($a['tamano']) ?> · <?= fmt_fecha($a['creado_en']) ?>
                                    <?php if ($a['sub_nombre']): ?> · <?= e($a['sub_nombre']) ?><?php endif; ?>
                                </small>
                            </div>
                            <div class="text-nowrap">
                                <?php if ($previa): ?>
                                <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= $a['id'] ?>&ver=1" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Ver"><i class="bi bi-eye"></i></a>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Descargar"><i class="bi bi-download"></i></a>
                                <?php if (has_role('medico', 'admin')): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este archivo? No se puede deshacer.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="borrar_archivo">
                                    <input type="hidden" name="paciente_id" value="<?= $id ?>">
                                    <input type="hidden" name="archivo_id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Citas -->
            <div class="tab-pane fade" id="tab-citas">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Motivo</th><th>Estado</th></tr></thead>
                            <tbody>
                            <?php if (!$citas): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Sin citas.</td></tr>
                            <?php else: foreach ($citas as $c): ?>
                                <tr>
                                    <td><?= fmt_fecha($c['fecha']) ?></td>
                                    <td><?= fmt_hora($c['hora']) ?></td>
                                    <td class="small"><?= e($c['med_nombre']) ?></td>
                                    <td><?= e($c['motivo'] ?: '—') ?></td>
                                    <td><span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
