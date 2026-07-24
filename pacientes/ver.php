<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u  = current_user();
$id = (int) ($_GET['id'] ?? $_POST['paciente_id'] ?? 0);

// Self-healing: signos vitales ampliados (glucosa, FC, SpO2, FR) en la consulta.
try {
    db()->exec("ALTER TABLE consultas
        ADD COLUMN IF NOT EXISTS glucosa DECIMAL(5,1) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS frecuencia_cardiaca SMALLINT DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS saturacion TINYINT DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS frecuencia_respiratoria TINYINT DEFAULT NULL");
} catch (Throwable $e) { /* MySQL viejo / ya existen */ }

// Self-healing: medicamentos actuales del paciente (lista estructurada).
try {
    db()->exec("CREATE TABLE IF NOT EXISTS paciente_medicamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL DEFAULT 1,
        paciente_id INT NOT NULL,
        nombre VARCHAR(160) NOT NULL,
        dosis VARCHAR(80) DEFAULT NULL,
        frecuencia VARCHAR(80) DEFAULT NULL,
        via VARCHAR(40) DEFAULT NULL,
        inicio DATE DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        suspendido_en DATE DEFAULT NULL,
        notas VARCHAR(255) DEFAULT NULL,
        creado_por INT DEFAULT NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pacmed (paciente_id, activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* ya existe */ }

$stmt = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

/* --- Alta de consulta (expediente). Solo médicos y admin. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'consulta') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }

    $numOrNull = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
    $stmt = db()->prepare(
        'INSERT INTO consultas
         (consultorio_id, paciente_id, medico_id, motivo, exploracion, diagnostico, tratamiento, receta,
          peso, estatura, presion, temperatura, glucosa, frecuencia_cardiaca, saturacion, frecuencia_respiratoria, notas)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        tenant_id(), $id, $u['id'],
        trim($_POST['motivo'] ?? '') ?: null,
        trim($_POST['exploracion'] ?? '') ?: null,
        trim($_POST['diagnostico'] ?? '') ?: null,
        trim($_POST['tratamiento'] ?? '') ?: null,
        trim($_POST['receta'] ?? '') ?: null,
        $numOrNull('peso'),
        $numOrNull('estatura'),
        trim($_POST['presion'] ?? '') ?: null,
        $numOrNull('temperatura'),
        $numOrNull('glucosa'),
        $numOrNull('frecuencia_cardiaca'),
        $numOrNull('saturacion'),
        $numOrNull('frecuencia_respiratoria'),
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

/* --- Medicamentos actuales: alta y suspensión. Solo médicos y admin. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'med_add') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }
    $nombre = trim($_POST['med_nombre'] ?? '');
    if ($nombre !== '') {
        db()->prepare('INSERT INTO paciente_medicamentos
            (consultorio_id, paciente_id, nombre, dosis, frecuencia, via, inicio, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $id, mb_substr($nombre,0,160),
                trim($_POST['med_dosis'] ?? '') ?: null,
                trim($_POST['med_frecuencia'] ?? '') ?: null,
                trim($_POST['med_via'] ?? '') ?: null,
                ($_POST['med_inicio'] ?? '') ?: null,
                trim($_POST['med_notas'] ?? '') ?: null,
                $u['id']]);
        auditar('crear', 'medicamento', (int) db()->lastInsertId(), $nombre . ' · Paciente #' . $id);
        flash('Medicamento agregado.');
    }
    redirect('/pacientes/ver?id=' . $id . '#tab-meds');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'med_baja') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }
    db()->prepare('UPDATE paciente_medicamentos SET activo = 0, suspendido_en = CURDATE()
                   WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
        ->execute([(int) ($_POST['med_id'] ?? 0), $id, tenant_id()]);
    flash('Medicamento suspendido.');
    redirect('/pacientes/ver?id=' . $id . '#tab-meds');
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

    // Provisionar acceso requiere el módulo; desactivarlo siempre se permite.
    if (($_POST['sub'] ?? '') !== 'desactivar') { require_modulo('portal'); }

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

// Consultas (expediente). Con búsqueda: en un expediente de años, encontrar
// "¿cuándo le di amoxicilina?" a ojo es imposible.
$qExp   = trim((string) ($_GET['q'] ?? ''));
$sqlExp = 'SELECT co.*, u.nombre AS med_nombre FROM consultas co
           JOIN usuarios u ON u.id = co.medico_id
           WHERE co.paciente_id = ? AND co.consultorio_id = ?';
$parExp = [$id, tenant_id()];
if ($qExp !== '') {
    $sqlExp .= ' AND (co.motivo LIKE ? OR co.exploracion LIKE ? OR co.diagnostico LIKE ?
                      OR co.tratamiento LIKE ? OR co.receta LIKE ? OR co.notas LIKE ?)';
    $like = "%$qExp%";
    array_push($parExp, $like, $like, $like, $like, $like, $like);
}
$sqlExp .= ' ORDER BY co.fecha DESC';
$cons = db()->prepare($sqlExp);
$cons->execute($parExp);
$cons = $cons->fetchAll();

// Total real (para no decir "Expediente (2)" cuando hay 40 y estás filtrando).
$totCons = db()->prepare('SELECT COUNT(*) FROM consultas WHERE paciente_id = ? AND consultorio_id = ?');
$totCons->execute([$id, tenant_id()]);
$totCons = (int) $totCons->fetchColumn();

/* ── Signos vitales: series para gráficas de evolución (todas las consultas) ── */
try {
    $vsq = db()->prepare("SELECT fecha, peso, estatura, presion, temperatura, glucosa, frecuencia_cardiaca, saturacion
                          FROM consultas WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC");
    $vsq->execute([$id, tenant_id()]);
    $vsRows = $vsq->fetchAll();
} catch (Throwable $e) {
    $vsq = db()->prepare("SELECT fecha, peso, estatura, presion, temperatura FROM consultas WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC");
    $vsq->execute([$id, tenant_id()]);
    $vsRows = $vsq->fetchAll();
}
$vsLabels = [];
$vsSeries = ['peso'=>[], 'imc'=>[], 'sistolica'=>[], 'diastolica'=>[], 'glucosa'=>[], 'temperatura'=>[], 'fc'=>[], 'spo2'=>[]];
foreach ($vsRows as $r) {
    $vsLabels[] = date('d/m/y', strtotime($r['fecha']));
    $vsSeries['peso'][] = ($r['peso'] ?? null) !== null ? (float) $r['peso'] : null;
    $imcV = imc($r['peso'] ?? 0, $r['estatura'] ?? 0);
    $vsSeries['imc'][] = $imcV ? round((float) $imcV, 1) : null;
    $sis = $dia = null;
    if (!empty($r['presion']) && preg_match('#(\d{2,3})\s*/\s*(\d{2,3})#', $r['presion'], $m)) { $sis = (int) $m[1]; $dia = (int) $m[2]; }
    $vsSeries['sistolica'][]  = $sis;
    $vsSeries['diastolica'][] = $dia;
    $vsSeries['glucosa'][]     = ($r['glucosa'] ?? null) !== null ? (float) $r['glucosa'] : null;
    $vsSeries['temperatura'][] = ($r['temperatura'] ?? null) !== null ? (float) $r['temperatura'] : null;
    $vsSeries['fc'][]          = ($r['frecuencia_cardiaca'] ?? null) !== null ? (int) $r['frecuencia_cardiaca'] : null;
    $vsSeries['spo2'][]        = ($r['saturacion'] ?? null) !== null ? (int) $r['saturacion'] : null;
}
$vsHas = fn($k) => count(array_filter($vsSeries[$k], fn($x) => $x !== null)) > 0;
$vsAny = $vsHas('peso') || $vsHas('imc') || $vsHas('sistolica') || $vsHas('glucosa')
      || $vsHas('temperatura') || $vsHas('fc') || $vsHas('spo2');

/* Medicamentos actuales del paciente (activos primero). */
$meds = [];
try {
    $mq = db()->prepare('SELECT * FROM paciente_medicamentos WHERE paciente_id = ? AND consultorio_id = ?
                         ORDER BY activo DESC, nombre');
    $mq->execute([$id, tenant_id()]);
    $meds = $mq->fetchAll();
} catch (Throwable $e) { $meds = []; }
$medsActivos = array_values(array_filter($meds, fn($m) => (int) $m['activo'] === 1));

/* Consentimientos informados firmados del paciente. */
$consents = [];
try {
    $cq = db()->prepare('SELECT id, titulo, creado_en FROM consentimientos
                         WHERE paciente_id = ? AND consultorio_id = ? ORDER BY creado_en DESC');
    $cq->execute([$id, tenant_id()]);
    $consents = $cq->fetchAll();
} catch (Throwable $e) { $consents = []; }

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

// Resumen clínico: lo que un médico necesita saber ANTES de entrar, calculado
// con lo que ya está capturado. Sin IA: aquí no hay nada que adivinar.
$resumen = paciente_resumen($id, $p);

// Documentos emitidos (constancias, incapacidades…).
$documentos = [];
if (modulo_activo('documentos')) {
    $sd = db()->prepare('SELECT d.*, u.nombre AS medico_nombre FROM documentos d
                         LEFT JOIN usuarios u ON u.id = d.medico_id
                         WHERE d.paciente_id = ? AND d.consultorio_id = ?
                         ORDER BY d.fecha DESC, d.id DESC');
    $sd->execute([$id, tenant_id()]);
    $documentos = $sd->fetchAll();
}

// Graduaciones (óptica). El historial es el valor clínico: se ve cómo avanza
// la miopía año con año, así que se listan todas, de la más nueva a la más vieja.
$graduaciones = [];
if (modulo_activo('optica')) {
    $sg = db()->prepare('SELECT * FROM optica_graduaciones WHERE paciente_id = ? AND consultorio_id = ?
                         ORDER BY fecha DESC, id DESC');
    $sg->execute([$id, tenant_id()]);
    $graduaciones = $sg->fetchAll();
}

// Plantillas de consulta (para pre-llenar el formulario; solo médico/admin).
// Se agrupan por especialidad en el selector. La consulta es resiliente por si
// la columna `especialidad` aún no existe (migración no aplicada).
$plantillas = [];
if (has_role('medico', 'admin')) {
    try {
        $stp = db()->prepare('SELECT nombre, especialidad, motivo, exploracion, diagnostico, tratamiento, receta, notas
                              FROM plantillas_consulta WHERE consultorio_id = ?
                              ORDER BY (especialidad IS NULL), especialidad, nombre');
        $stp->execute([tenant_id()]);
        $plantillas = $stp->fetchAll();
    } catch (Throwable $e) {
        $stp = db()->prepare('SELECT nombre, motivo, exploracion, diagnostico, tratamiento, receta, notas
                              FROM plantillas_consulta WHERE consultorio_id = ? ORDER BY nombre');
        $stp->execute([tenant_id()]);
        $plantillas = $stp->fetchAll();
    }
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
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index"><?= et('Pacientes') ?></a></li>
        <li class="breadcrumb-item active"><?= e($p['nombre'].' '.$p['apellidos']) ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div class="d-flex align-items-center gap-3">
        <?= avatar_paciente((int) $p['id'], $p['nombre'], $p['apellidos'], ($p['foto_mime'] ?? null) ?: ($p['foto'] ?? null), 72) ?>
        <div>
        <h1 class="h3 mb-1">
            <?= e($p['nombre'].' '.$p['apellidos']) ?>
            <span class="badge bg-<?= $p['tipo'] === 'dental' ? 'info' : 'primary' ?> align-middle">
                <?= e(tipo_paciente_label($p['tipo'])) ?>
            </span>
        </h1>
        <span class="text-muted">
            <?= e(edad($p['fecha_nacimiento'])) ?> ·
            <?= $p['sexo'] === 'F' ? et('Femenino') : ($p['sexo'] === 'M' ? et('Masculino') : et('Sexo n/d')) ?>
            <?php if (!empty($p['tipo_sangre'])): ?> · <span class="badge bg-danger-subtle text-danger border"><i class="bi bi-droplet-half"></i> <?= e($p['tipo_sangre']) ?></span><?php endif; ?>
            <?php if ($bmi): ?> · <span class="badge bg-<?= $bmi['color'] ?>-subtle text-<?= $bmi['color'] ?> border" title="<?= et('Índice de masa corporal (última consulta)') ?>">IMC <?= $bmi['valor'] ?> · <?= e(t($bmi['categoria'])) ?></span><?php endif; ?>
        </span>
        </div>
    </div>
    <div class="text-nowrap">
        <a href="<?= BASE_URL ?>/citas/create?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-calendar-plus"></i> <?= et('Cita') ?></a>
        <?php if (has_role('medico', 'admin')): ?>
        <a href="<?= BASE_URL ?>/recetas/create?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-capsule"></i> <?= et('Receta') ?></a>
        <?php endif; ?>
        <?php if (modulo_activo('presupuestos')): ?>
        <a href="<?= BASE_URL ?>/presupuestos/index?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-clipboard2-check"></i> <?= et('Presupuestos') ?></a>
        <?php endif; ?>
        <?php if (modulo_activo('laboratorio')): ?>
        <a href="<?= BASE_URL ?>/laboratorio/index?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-eyedropper"></i> <?= et('Laboratorio') ?></a>
        <?php endif; ?>
        <?php if (modulo_activo('optica')): ?>
        <a href="<?= BASE_URL ?>/optica/graduacion?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-eyeglasses"></i> <?= et('Graduación') ?></a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/facturacion/create?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-receipt"></i> <?= et('Factura') ?></a>
        <?php if (modulo_activo('especialidades') && has_role('medico', 'admin')): ?>
        <?php if ($p['tipo'] === 'dental'): ?>
        <a href="<?= BASE_URL ?>/odontograma/index?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-emoji-smile"></i> <?= et('Odontograma') ?></a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/crecimiento/index?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-graph-up-arrow"></i> <?= et('Crecimiento') ?></a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pacientes/edit?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> <?= et('Editar') ?></a>
    </div>
</div>

<?php /* Resumen clínico: lo primero son las ALERGIAS, a propósito. Es el dato
         que, si se pasa por alto, hace daño de verdad. */ ?>
<div class="card mb-3 border-<?= $resumen['alergias'] ? 'danger' : '' ?>">
    <div class="card-body py-2">
        <?php if ($resumen['alergias']): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><strong><?= et('ALERGIAS') ?>:</strong> <?= e($resumen['alergias']) ?></div>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-3 align-items-center small">
            <?php if ($resumen['antecedentes']): ?>
                <span><i class="bi bi-clipboard-pulse text-brand"></i>
                    <span class="text-muted"><?= et('Antecedentes') ?>:</span> <?= e($resumen['antecedentes']) ?></span>
            <?php endif; ?>

            <?php foreach ($resumen['diagnosticos'] as $dx): ?>
                <span class="badge bg-secondary bg-opacity-25 text-body border">
                    <?= e($dx['diagnostico']) ?><?= (int) $dx['n'] > 1 ? ' ×' . (int) $dx['n'] : '' ?>
                </span>
            <?php endforeach; ?>

            <?php if ($resumen['vitales']): $v = $resumen['vitales']; ?>
                <span class="text-muted">
                    <i class="bi bi-heart-pulse"></i>
                    <?php if ($v['presion']): ?><?= e($v['presion']) ?> · <?php endif; ?>
                    <?php if ($v['peso']): ?><?= e($v['peso']) ?> kg<?php endif; ?>
                    <?php if ($resumen['imc']): ?> · IMC <?= e((string) $resumen['imc']) ?><?php endif; ?>
                    <span class="opacity-75">(<?= fmt_fecha($v['fecha']) ?>)</span>
                </span>
            <?php endif; ?>

            <span class="ms-auto text-muted">
                <i class="bi bi-file-medical"></i> <?= (int) $resumen['consultas'] ?> <?= et('consultas') ?>
                <?php if ($resumen['ultima']): ?>
                    · <?= et('última') ?> <?= fmt_fecha($resumen['ultima']) ?>
                <?php endif; ?>
                <?php if ($resumen['proxima']): ?>
                    · <span class="text-success fw-semibold">
                        <i class="bi bi-calendar-check"></i> <?= et('próxima cita') ?>
                        <?= fmt_fecha($resumen['proxima']['fecha']) ?> <?= fmt_hora($resumen['proxima']['hora']) ?>
                      </span>
                <?php endif; ?>
            </span>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Ficha del paciente -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-vcard text-brand"></i> <?= et('Datos de contacto') ?></div>
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
            <div class="card-header bg-white fw-semibold"><i class="bi bi-telephone-plus text-brand"></i> <?= et('Contacto de emergencia') ?></div>
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
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Información clínica') ?></div>
            <div class="card-body">
                <?php
                $clin = [
                    'Alergias' => $p['alergias'], 'Enfermedades crónicas' => $p['enf_cronicas'] ?? '',
                    'Antecedentes personales' => $p['antecedentes'], 'Antecedentes familiares' => $p['antecedentes_familiares'] ?? '',
                    'Cirugías' => $p['cirugias'] ?? '', 'Vacunas' => $p['vacunas'] ?? '',
                    'Hábitos' => $p['habitos'] ?? '', 'Notas' => $p['notas'],
                ];
                foreach ($clin as $lbl => $val): ?>
                    <p class="mb-2"><strong><?= et($lbl) ?>:</strong><br><?= nl2br(e($val ?: '—')) ?></p>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (modulo_activo('portal') && has_role('admin', 'medico', 'recepcion')): ?>
        <div class="card mt-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-lock text-brand"></i> <?= et('Portal del paciente') ?></div>
            <div class="card-body">
                <?php if ($p['portal_activo']): ?>
                    <p class="mb-2"><span class="badge bg-success"><?= et('Activo') ?></span> <?= et('El paciente entra con su correo.') ?></p>
                <?php else: ?>
                    <p class="text-muted small mb-2"><?= et('Da acceso para que vea sus citas, recetas y estudios.') ?></p>
                <?php endif; ?>
                <?php if (!$p['email']): ?>
                    <div class="alert alert-warning py-2 small mb-2"><?= et('Agrega un correo al paciente para habilitar el portal.') ?></div>
                <?php endif; ?>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="portal">
                    <input type="hidden" name="paciente_id" value="<?= $id ?>">
                    <div class="col-12">
                        <input type="text" name="portal_password" class="form-control form-control-sm" autocomplete="new-password"
                               placeholder="<?= $p['portal_activo'] ? et('Nueva contraseña') : et('Contraseña (mín. 6)') ?>" <?= !$p['email'] ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-sm btn-primary" <?= !$p['email'] ? 'disabled' : '' ?>>
                            <i class="bi bi-key"></i> <?= $p['portal_activo'] ? et('Cambiar contraseña') : et('Activar acceso') ?>
                        </button>
                        <?php if ($p['portal_activo']): ?>
                        <button name="sub" value="desactivar" class="btn btn-sm btn-outline-danger"><?= et('Desactivar') ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="col-12"><small class="text-muted"><?= et('Correo:') ?> <?= e($p['email'] ?: '—') ?> · <a href="<?= BASE_URL ?>/portal/login" target="_blank"><?= et('Abrir portal') ?></a></small></div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Expediente / citas -->
    <div class="col-lg-8">
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-exp"><i class="bi bi-file-medical"></i> <?= et('Expediente') ?> (<?= $totCons ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-meds"><i class="bi bi-capsule"></i> <?= et('Medicamentos') ?> (<?= count($medsActivos) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-archivos"><i class="bi bi-paperclip"></i> <?= et('Archivos') ?> (<?= count($archivos) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-citas"><i class="bi bi-calendar-check"></i> <?= et('Citas') ?> (<?= count($citas) ?>)</button></li>
            <?php if (modulo_activo('documentos')): ?>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-docs"><i class="bi bi-file-earmark-medical"></i> <?= et('Documentos') ?> (<?= count($documentos) ?>)</button></li>
            <?php endif; ?>
            <?php if (modulo_activo('optica')): ?>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-grad"><i class="bi bi-eyeglasses"></i> <?= et('Graduaciones') ?> (<?= count($graduaciones) ?>)</button></li>
            <?php endif; ?>
        </ul>

        <div class="tab-content">
            <!-- Expediente -->
            <div class="tab-pane fade show active" id="tab-exp">

                <?php
                /* Signos vitales: gráficas de evolución. Solo si hay datos. */
                $vsColores = [
                    'peso'=>['Peso','kg','#2563eb'], 'imc'=>['IMC','','#7c3aed'],
                    'ta'=>['Presión arterial','mmHg','#ef4444'], 'glucosa'=>['Glucosa','mg/dL','#f59e0b'],
                    'fc'=>['Frecuencia cardiaca','lpm','#ec4899'], 'spo2'=>['Saturación','%','#0ea5e9'],
                    'temp'=>['Temperatura','°C','#10b981'],
                ];
                $vsPayload = [];
                if ($vsHas('peso')) $vsPayload['peso'] = ['ds'=>[['label'=>t('Peso'),'data'=>$vsSeries['peso'],'color'=>'#2563eb']]];
                if ($vsHas('imc'))  $vsPayload['imc']  = ['ds'=>[['label'=>'IMC','data'=>$vsSeries['imc'],'color'=>'#7c3aed']]];
                if ($vsHas('sistolica')) $vsPayload['ta'] = ['ds'=>[
                        ['label'=>t('Sistólica'),'data'=>$vsSeries['sistolica'],'color'=>'#ef4444'],
                        ['label'=>t('Diastólica'),'data'=>$vsSeries['diastolica'],'color'=>'#f59e0b']]];
                if ($vsHas('glucosa')) $vsPayload['glucosa'] = ['ds'=>[['label'=>t('Glucosa'),'data'=>$vsSeries['glucosa'],'color'=>'#f59e0b']]];
                if ($vsHas('fc'))   $vsPayload['fc']   = ['ds'=>[['label'=>'FC','data'=>$vsSeries['fc'],'color'=>'#ec4899']]];
                if ($vsHas('spo2')) $vsPayload['spo2'] = ['ds'=>[['label'=>'SpO₂','data'=>$vsSeries['spo2'],'color'=>'#0ea5e9']]];
                if ($vsHas('temperatura')) $vsPayload['temp'] = ['ds'=>[['label'=>t('Temp'),'data'=>$vsSeries['temperatura'],'color'=>'#10b981']]];
                ?>
                <?php if ($vsAny): ?>
                <div class="card mb-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-activity text-brand"></i> <?= et('Signos vitales · evolución') ?></span>
                        <button class="btn btn-sm btn-link text-decoration-none p-0" data-bs-toggle="collapse" data-bs-target="#vsCharts"><?= et('Mostrar/ocultar') ?></button>
                    </div>
                    <div class="collapse show" id="vsCharts">
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($vsPayload as $key => $_): [$tit,$uni,$col] = $vsColores[$key]; ?>
                                <div class="col-md-6 col-xl-4">
                                    <div class="small fw-semibold mb-1"><?= e($tit) ?> <?php if ($uni): ?><span class="text-muted fw-normal">(<?= e($uni) ?>)</span><?php endif; ?></div>
                                    <div style="height:170px"><canvas id="vsChart_<?= e($key) ?>"></canvas></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php /* Buscar dentro del expediente: en uno de años, encontrar
                         "¿cuándo le receté amoxicilina?" a ojo es imposible. */ ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <form class="d-flex gap-2 flex-grow-1" method="get" style="max-width:420px">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="search" name="q" class="form-control form-control-sm" value="<?= e($qExp) ?>"
                               placeholder="<?= e(t('Buscar en el expediente: síntoma, diagnóstico, medicamento…')) ?>">
                        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
                        <?php if ($qExp !== ''): ?>
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $id ?>" class="btn btn-sm btn-link px-1"><?= et('Limpiar') ?></a>
                        <?php endif; ?>
                    </form>
                    <?php if (has_role('medico', 'admin')): ?>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (modulo_activo('documentos')): ?>
                        <a href="<?= BASE_URL ?>/documentos/nuevo?paciente_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-earmark-medical"></i> <?= et('Documento') ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/consentimientos/nuevo?paciente_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-vector-pen"></i> <?= et('Consentimiento') ?>
                        </a>
                        <?php if (strncasecmp((string) ($p['sexo'] ?? ''), 'F', 1) === 0): ?>
                        <a href="<?= BASE_URL ?>/prenatal/index?paciente_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-gender-female"></i> <?= et('Control prenatal') ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/nutricion/index?paciente_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-egg-fried"></i> <?= et('Nutrición') ?>
                        </a>
                        <a href="<?= BASE_URL ?>/cardiologia/index?paciente_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-heart-pulse"></i> <?= et('Cardiología') ?>
                        </a>
                        <a href="<?= BASE_URL ?>/dermatologia/index?paciente_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-bandaid"></i> <?= et('Dermatología') ?>
                        </a>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formConsulta">
                            <i class="bi bi-plus-lg"></i> <?= et('Nueva consulta') ?>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($qExp !== ''): ?>
                <div class="alert alert-info py-2 small">
                    <i class="bi bi-search"></i>
                    <?= count($cons) ?> <?= et('de') ?> <?= $totCons ?> <?= et('consultas coinciden con') ?>
                    <strong><?= e($qExp) ?></strong>.
                </div>
                <?php endif; ?>

                <?php if ($consents): ?>
                <div class="mb-3">
                    <div class="small text-muted mb-1"><i class="bi bi-vector-pen"></i> <?= et('Consentimientos firmados') ?></div>
                    <?php foreach ($consents as $cs): ?>
                        <a href="<?= BASE_URL ?>/consentimientos/ver?id=<?= (int) $cs['id'] ?>" target="_blank" rel="noopener"
                           class="badge text-bg-light border text-decoration-none me-1 mb-1" title="<?= e(fmt_fecha($cs['creado_en'])) ?>">
                            <i class="bi bi-file-earmark-check"></i> <?= e($cs['titulo']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (has_role('medico', 'admin')): ?>
                <div class="collapse mb-4" id="formConsulta">
                    <div class="card card-body">
                        <form method="post" enctype="multipart/form-data">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="consulta">
                            <input type="hidden" name="paciente_id" value="<?= $id ?>">
                            <?php if ($plantillas): ?>
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <label class="form-label mb-0 small text-muted"><i class="bi bi-file-earmark-text"></i> <?= et('Plantilla:') ?></label>
                                <select id="selPlantilla" class="form-select form-select-sm" style="max-width:280px">
                                    <option value=""><?= et('— ninguna —') ?></option>
                                    <?php
                                    $espGrp = '__x__';
                                    foreach ($plantillas as $i => $pl):
                                        $espPl = $pl['especialidad'] ?? '';
                                        if ($espPl !== $espGrp) {
                                            if ($espGrp !== '__x__') echo '</optgroup>';
                                            echo '<optgroup label="' . e($espPl !== '' ? $espPl : t('General')) . '">';
                                            $espGrp = $espPl;
                                        }
                                    ?><option value="<?= $i ?>"><?= e($pl['nombre']) ?></option><?php
                                    endforeach;
                                    if ($espGrp !== '__x__') echo '</optgroup>';
                                    ?>
                                </select>
                                <a href="<?= BASE_URL ?>/plantillas/index" class="small text-decoration-none"><?= et('Gestionar') ?></a>
                            </div>
                            <?php else: ?>
                            <div class="mb-2 small"><a href="<?= BASE_URL ?>/plantillas/index" class="text-decoration-none"><i class="bi bi-file-earmark-text"></i> <?= et('Crear plantillas de consulta') ?></a></div>
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label"><?= et('Motivo de consulta') ?></label>
                                    <input type="text" name="motivo" class="form-control">
                                </div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Peso (kg)') ?></label><input type="number" step="0.01" min="0" name="peso" class="form-control"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Estatura (cm)') ?></label><input type="number" step="0.01" min="0" name="estatura" class="form-control"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Presión') ?></label><input type="text" name="presion" class="form-control" placeholder="120/80"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Temp. (°C)') ?></label><input type="number" step="0.1" min="0" name="temperatura" class="form-control"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Glucosa (mg/dL)') ?></label><input type="number" step="0.1" min="0" name="glucosa" class="form-control"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Frec. cardiaca (lpm)') ?></label><input type="number" min="0" name="frecuencia_cardiaca" class="form-control"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Saturación (%)') ?></label><input type="number" min="0" max="100" name="saturacion" class="form-control"></div>
                                <div class="col-6 col-md-3"><label class="form-label"><?= et('Frec. respiratoria (rpm)') ?></label><input type="number" min="0" name="frecuencia_respiratoria" class="form-control"></div>
                                <div class="col-md-6"><label class="form-label"><?= et('Exploración') ?></label><textarea name="exploracion" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label><textarea name="diagnostico" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label"><?= et('Tratamiento') ?></label><textarea name="tratamiento" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label"><?= et('Receta') ?></label><textarea name="receta" class="form-control" rows="2"></textarea></div>
                                <div class="col-12"><label class="form-label"><?= et('Notas') ?></label><textarea name="notas" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-7">
                                    <label class="form-label"><?= et('Adjuntar archivo') ?> <span class="text-muted fw-normal"><?= et('(opcional)') ?></span></label>
                                    <input type="file" name="archivo" class="form-control">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label"><?= et('Descripción del archivo') ?></label>
                                    <input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="<?= et('Ej. Estudio de laboratorio') ?>">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted"><?= et('Adjunto: PDF, imagen, Word, Excel o texto. Máx.') ?> <?= fmt_bytes(archivo_max_bytes()) ?>.</small>
                                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar consulta') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php if ($plantillas): ?>
                <script>
                (function () {
                    var P = <?= json_encode(array_map(fn($pl) => [
                        'motivo'=>$pl['motivo'],'exploracion'=>$pl['exploracion'],'diagnostico'=>$pl['diagnostico'],
                        'tratamiento'=>$pl['tratamiento'],'receta'=>$pl['receta'],'notas'=>$pl['notas'],
                    ], $plantillas), JSON_UNESCAPED_UNICODE) ?>;
                    var sel = document.getElementById('selPlantilla');
                    var box = document.getElementById('formConsulta');
                    if (!sel || !box) return;
                    sel.addEventListener('change', function () {
                        var p = P[this.value]; if (!p) return;
                        Object.keys(p).forEach(function (k) {
                            var el = box.querySelector('[name="' + k + '"]');
                            if (el && p[k] != null && p[k] !== '') el.value = p[k];
                        });
                    });
                })();
                </script>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (!$cons): ?>
                    <p class="text-muted text-center py-4"><?= et('Sin consultas registradas.') ?></p>
                <?php else: foreach ($cons as $c): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-white d-flex justify-content-between">
                            <span class="fw-semibold"><i class="bi bi-file-medical text-brand"></i> <?= fmt_fecha($c['fecha']) ?> · <?= date('H:i', strtotime($c['fecha'])) ?></span>
                            <span class="text-muted small"><?= e($c['med_nombre']) ?></span>
                        </div>
                        <div class="card-body">
                            <?php if ($c['motivo']): ?><p class="mb-2"><strong><?= et('Motivo:') ?></strong> <?= e($c['motivo']) ?></p><?php endif; ?>
                            <?php
                            $vitales = array_filter([
                                $c['peso'] ? t('Peso') . ": {$c['peso']} kg" : null,
                                $c['estatura'] ? t('Estatura') . ": {$c['estatura']} cm" : null,
                                $c['presion'] ? t('PA') . ": {$c['presion']}" : null,
                                $c['temperatura'] ? t('Temp') . ": {$c['temperatura']} °C" : null,
                                !empty($c['glucosa']) ? t('Glucosa') . ": {$c['glucosa']} mg/dL" : null,
                                !empty($c['frecuencia_cardiaca']) ? 'FC: ' . $c['frecuencia_cardiaca'] . ' lpm' : null,
                                !empty($c['saturacion']) ? 'SpO₂: ' . $c['saturacion'] . '%' : null,
                                !empty($c['frecuencia_respiratoria']) ? 'FR: ' . $c['frecuencia_respiratoria'] . ' rpm' : null,
                            ]);
                            if ($vitales): ?>
                                <p class="mb-2"><span class="badge bg-light text-dark border me-1"><?= implode('</span> <span class="badge bg-light text-dark border me-1">', array_map('e', $vitales)) ?></span></p>
                            <?php endif; ?>
                            <div class="row">
                                <?php foreach (['exploracion'=>'Exploración','diagnostico'=>'Diagnóstico','tratamiento'=>'Tratamiento','receta'=>'Receta'] as $k=>$lbl):
                                    if ($c[$k]): ?>
                                    <div class="col-md-6 mb-2"><strong><?= et($lbl) ?>:</strong><br><?= nl2br(e($c[$k])) ?></div>
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

            <!-- Medicamentos actuales -->
            <div class="tab-pane fade" id="tab-meds">
                <?php if (has_role('medico', 'admin')): ?>
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle text-brand"></i> <?= et('Agregar medicamento') ?></div>
                    <div class="card-body">
                        <form method="post" class="row g-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="med_add">
                            <div class="col-md-4"><label class="form-label small"><?= et('Medicamento') ?> *</label><input type="text" name="med_nombre" class="form-control" required maxlength="160" placeholder="<?= e(t('Ej. Metformina 850 mg')) ?>"></div>
                            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Dosis') ?></label><input type="text" name="med_dosis" class="form-control" maxlength="80" placeholder="1 tab"></div>
                            <div class="col-6 col-md-3"><label class="form-label small"><?= et('Frecuencia') ?></label><input type="text" name="med_frecuencia" class="form-control" maxlength="80" placeholder="<?= e(t('cada 12 h')) ?>"></div>
                            <div class="col-6 col-md-2"><label class="form-label small"><?= et('Vía') ?></label><input type="text" name="med_via" class="form-control" maxlength="40" placeholder="Oral"></div>
                            <div class="col-6 col-md-1"><label class="form-label small"><?= et('Inicio') ?></label><input type="date" name="med_inicio" class="form-control"></div>
                            <div class="col-md-10"><label class="form-label small"><?= et('Notas') ?></label><input type="text" name="med_notas" class="form-control" maxlength="255"></div>
                            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= et('Agregar') ?></button></div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-capsule text-brand"></i> <?= et('Medicamentos actuales') ?></div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th><?= et('Medicamento') ?></th><th><?= et('Dosis') ?></th><th><?= et('Frecuencia') ?></th><th><?= et('Vía') ?></th><th><?= et('Desde') ?></th><th><?= et('Estado') ?></th><?php if (has_role('medico','admin')): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php if (!$meds): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4"><?= et('Sin medicamentos registrados.') ?></td></tr>
                            <?php else: foreach ($meds as $m): $act = (int) $m['activo'] === 1; ?>
                                <tr class="<?= $act ? '' : 'text-muted' ?>">
                                    <td class="fw-semibold"><?= e($m['nombre']) ?><?php if ($m['notas']): ?><div class="small text-muted fw-normal"><?= e($m['notas']) ?></div><?php endif; ?></td>
                                    <td><?= e($m['dosis'] ?: '—') ?></td>
                                    <td><?= e($m['frecuencia'] ?: '—') ?></td>
                                    <td><?= e($m['via'] ?: '—') ?></td>
                                    <td class="small"><?= $m['inicio'] ? e(fmt_fecha($m['inicio'])) : '—' ?></td>
                                    <td><?php if ($act): ?><span class="badge bg-success"><?= et('Activo') ?></span><?php else: ?><span class="badge bg-secondary"><?= et('Suspendido') ?><?= $m['suspendido_en'] ? ' ' . e(fmt_fecha($m['suspendido_en'])) : '' ?></span><?php endif; ?></td>
                                    <?php if (has_role('medico','admin')): ?>
                                    <td class="text-end">
                                        <?php if ($act): ?>
                                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Suspender este medicamento?')) ?>');">
                                            <?= csrf_field() ?><input type="hidden" name="accion" value="med_baja"><input type="hidden" name="med_id" value="<?= (int) $m['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="<?= e(t('Suspender')) ?>"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Archivos -->
            <div class="tab-pane fade" id="tab-archivos">
                <?php if (has_role('medico', 'admin')): ?>
                <div class="text-end mb-3">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formArchivo">
                        <i class="bi bi-cloud-upload"></i> <?= et('Subir archivo') ?>
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
                                    <label class="form-label"><?= et('Archivo') ?></label>
                                    <input type="file" name="archivo" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?= et('Descripción (opcional)') ?></label>
                                    <input type="text" name="descripcion" class="form-control" maxlength="255" placeholder="<?= et('Ej. Radiografía de tórax') ?>">
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted"><?= et('PDF, imágenes, Word, Excel o texto. Máx.') ?> <?= fmt_bytes(archivo_max_bytes()) ?>.</small>
                                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Subir') ?></button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$archivos): ?>
                    <p class="text-muted text-center py-4"><?= et('Sin archivos en el expediente.') ?></p>
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
                                <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= $a['id'] ?>&ver=1" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="<?= et('Ver') ?>"><i class="bi bi-eye"></i></a>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Descargar') ?>"><i class="bi bi-download"></i></a>
                                <?php if (has_role('medico', 'admin')): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este archivo? No se puede deshacer.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="borrar_archivo">
                                    <input type="hidden" name="paciente_id" value="<?= $id ?>">
                                    <input type="hidden" name="archivo_id" value="<?= $a['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="<?= et('Eliminar') ?>"><i class="bi bi-trash"></i></button>
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
                            <thead class="table-light"><tr><th><?= et('Fecha') ?></th><th><?= et('Hora') ?></th><th><?= et('Médico') ?></th><th><?= et('Motivo') ?></th><th><?= et('Estado') ?></th></tr></thead>
                            <tbody>
                            <?php if (!$citas): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin citas.') ?></td></tr>
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

            <?php if (modulo_activo('documentos')): ?>
            <!-- Documentos clínicos -->
            <div class="tab-pane fade" id="tab-docs">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><?= et('Constancias, justificantes, referencias y resúmenes emitidos.') ?></span>
                    <?php if (has_role('medico', 'admin')): ?>
                    <a href="<?= BASE_URL ?>/documentos/nuevo?paciente_id=<?= $id ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> <?= et('Nuevo documento') ?>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr>
                                <th><?= et('Folio') ?></th>
                                <th><?= et('Fecha') ?></th>
                                <th><?= et('Documento') ?></th>
                                <th><?= et('Firma') ?></th>
                                <th class="text-end"><?= et('Acciones') ?></th>
                            </tr></thead>
                            <tbody>
                            <?php if (!$documentos): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin documentos emitidos.') ?></td></tr>
                            <?php else: foreach ($documentos as $d): ?>
                                <tr>
                                    <td class="small fw-semibold"><?= e($d['folio']) ?></td>
                                    <td class="small text-muted"><?= fmt_fecha($d['fecha']) ?></td>
                                    <td><?= e($d['titulo']) ?></td>
                                    <td class="small text-muted"><?= e($d['medico_nombre'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/documentos/imprimir?id=<?= (int) $d['id'] ?>" target="_blank"
                                           class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Imprimir')) ?>">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (modulo_activo('optica')): ?>
            <!-- Graduaciones (óptica) -->
            <div class="tab-pane fade" id="tab-grad">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">
                        <?= et('Cada revisión es una graduación nueva: el historial muestra cómo cambia la vista.') ?>
                    </span>
                    <a href="<?= BASE_URL ?>/optica/graduacion?paciente_id=<?= $id ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> <?= et('Nueva graduación') ?>
                    </a>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr>
                                <th><?= et('Fecha') ?></th>
                                <th><?= et('Graduación') ?></th>
                                <th><?= et('Tipo') ?></th>
                                <th><?= et('Diagnóstico') ?></th>
                                <th class="text-end"></th>
                            </tr></thead>
                            <tbody>
                            <?php if (!$graduaciones): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">
                                    <?= et('Sin graduaciones registradas.') ?>
                                </td></tr>
                            <?php else: foreach ($graduaciones as $g): ?>
                                <tr>
                                    <td class="small"><?= fmt_fecha($g['fecha']) ?></td>
                                    <td class="small font-monospace"><?= e(optica_graduacion_resumen($g)) ?></td>
                                    <td class="small text-muted">
                                        <?= $g['tipo_lente'] ? et(optica_tipos_lente()[$g['tipo_lente']]) : '—' ?>
                                    </td>
                                    <td class="small text-muted"><?= e($g['diagnostico'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>/optica/trabajo?paciente_id=<?= $id ?>&graduacion_id=<?= (int) $g['id'] ?>"
                                           class="btn btn-sm btn-outline-primary py-0" title="<?= e(t('Armar el trabajo')) ?>">
                                            <i class="bi bi-bag-plus"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($vsAny): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var labels = <?= json_encode($vsLabels) ?>;
    var charts = <?= json_encode($vsPayload, JSON_UNESCAPED_UNICODE) ?>;
    var isLight = !document.documentElement.classList.contains('dark') &&
                  !document.documentElement.classList.contains('app-dark');
    var grid = 'rgba(127,127,127,.14)';
    Object.keys(charts).forEach(function (key) {
        var el = document.getElementById('vsChart_' + key);
        if (!el) return;
        var ds = charts[key].ds.map(function (d) {
            return { label: d.label, data: d.data, borderColor: d.color,
                     backgroundColor: d.color, tension: .3, spanGaps: true,
                     pointRadius: 3, borderWidth: 2 };
        });
        new Chart(el, { type: 'line', data: { labels: labels, datasets: ds },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: ds.length > 1, labels: { boxWidth: 10, font: { size: 10 } } } },
                scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 6, font: { size: 10 } } },
                          y: { grid: { color: grid }, ticks: { font: { size: 10 } } } } } });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
