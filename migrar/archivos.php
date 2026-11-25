<?php
/**
 * Subir en lote los archivos del sistema anterior (expedientes en PDF,
 * estudios, imágenes) en un ZIP. Cada archivo se asigna a su paciente por la
 * CURP o el nombre completo que aparezca en su carpeta o en su nombre:
 *   Ana Pérez López/estudio.pdf      ✓
 *   PEPA850315MDFRNN09_receta.pdf    ✓
 * Dos pasos: vista previa (qué va a quién y qué no se reconoce) e importar.
 */
require_once __DIR__ . '/../includes/migrar.php';
require_login();
require_role('admin');

if (!class_exists('ZipArchive')) { flash('El servidor no puede abrir archivos ZIP.', 'danger'); redirect('/migrar/index'); }

$tmp = sys_get_temp_dir();
$errores = [];
$paso = 'subir';
$tipos = archivo_tipos_permitidos();
$max = archivo_max_bytes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'subir') {
        $f = $_FILES['zip'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) $errores[] = t('No se recibió el archivo (puede ser demasiado grande para el servidor; divídelo en varios ZIP).');
        elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'zip') $errores[] = t('Sube un archivo .zip.');
        if (!$errores) {
            $tok = bin2hex(random_bytes(12));
            move_uploaded_file($f['tmp_name'], "$tmp/mig_zip_$tok.zip");
            $_SESSION['mig_zip'] = ['tok' => $tok, 'nombre' => (string) $f['name'], 'tid' => tenant_id()];
        }
    }
}
$imp = $_SESSION['mig_zip'] ?? null;
$ruta = ($imp && (int) $imp['tid'] === (int) tenant_id()) ? "$tmp/mig_zip_" . preg_replace('/[^a-f0-9]/', '', $imp['tok']) . '.zip' : null;
if ($ruta && !is_file($ruta)) { $ruta = null; unset($_SESSION['mig_zip']); }
if (($_POST['accion'] ?? '') === 'cancelar') { if ($ruta) @unlink($ruta); unset($_SESSION['mig_zip']); redirect('/migrar/archivos'); }

/** Recorre el ZIP y decide a quién va cada archivo. */
function mig_zip_plan(string $ruta, array $tipos, int $max): array
{
    $z = new ZipArchive();
    if ($z->open($ruta) !== true) throw new RuntimeException(t('No se pudo abrir el ZIP.'));
    $pacs = new MigPacientes();
    $plan = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        $nombre = (string) $st['name'];
        if (str_ends_with($nombre, '/') || str_contains($nombre, '__MACOSX') || str_starts_with(basename($nombre), '.')) continue;
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $fila = ['i' => $i, 'ruta' => $nombre, 'tam' => (int) $st['size'], 'pid' => null, 'por' => ''];
        if (!isset($tipos[$ext]))      $fila['por'] = t('tipo de archivo no permitido');
        elseif ($st['size'] > $max)    $fila['por'] = t('pasa de') . ' ' . fmt_bytes($max);
        else {
            // Primero la carpeta (suele ser el paciente) y luego el nombre del archivo.
            [$pid, $por] = $pacs->enTexto(dirname($nombre) !== '.' ? dirname($nombre) : '');
            if (!$pid) [$pid, $por] = $pacs->enTexto(pathinfo($nombre, PATHINFO_FILENAME));
            if (!$pid) [$pid, $por] = $pacs->enTexto($nombre);
            $fila['pid'] = $pid; $fila['por'] = t($por);
        }
        $plan[] = $fila;
    }
    $z->close();
    return $plan;
}

/* ── Importar ─────────────────────────────────────────────────────────── */
$ok = 0; $saltos = [];
if (($_POST['accion'] ?? '') === 'importar' && $ruta) {
    @set_time_limit(900);
    try {
        $plan = mig_zip_plan($ruta, $tipos, $max);
        $z = new ZipArchive(); $z->open($ruta);
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $u = current_user();
        $ins = db()->prepare('INSERT INTO archivos (consultorio_id, paciente_id, consulta_id, subido_por, nombre_original, nombre_guardado, mime, tamano, descripcion)
                              VALUES (?,?,NULL,?,?,?,?,?,?)');
        $ya = db()->prepare('SELECT 1 FROM archivos WHERE consultorio_id = ? AND paciente_id = ? AND nombre_original = ? AND tamano = ? LIMIT 1');
        foreach ($plan as $p) {
            if (!$p['pid']) { $saltos[] = [$p['ruta'], $p['por']]; continue; }
            $orig = mb_substr(basename($p['ruta']), 0, 255);
            $ya->execute([tenant_id(), $p['pid'], $orig, $p['tam']]);
            if ($ya->fetchColumn()) { $saltos[] = [$p['ruta'], t('ya estaba en el expediente')]; continue; }
            $datos = $z->getFromIndex($p['i']);
            if ($datos === false) { $saltos[] = [$p['ruta'], t('no se pudo leer')]; continue; }
            $ext = strtolower(pathinfo($p['ruta'], PATHINFO_EXTENSION));
            $guardado = bin2hex(random_bytes(16)) . '.' . $ext;
            if (file_put_contents(archivo_dir((int) $p['pid']) . '/' . $guardado, $datos) === false) { $saltos[] = [$p['ruta'], t('no se pudo guardar')]; continue; }
            $ins->execute([tenant_id(), $p['pid'], (int) $u['id'], $orig, $guardado, $fi->buffer($datos) ?: 'application/octet-stream', strlen($datos),
                           t('Importado del sistema anterior')]);
            $ok++;
        }
        $z->close();
        auditar('importar', 'archivo', 0, "$ok archivos desde " . ($imp['nombre'] ?? 'ZIP'));
        @unlink($ruta);
        unset($_SESSION['mig_zip']);
        $paso = 'listo';
    } catch (Throwable $e) {
        $errores[] = $e->getMessage();
    }
}

/* ── Vista previa ─────────────────────────────────────────────────────── */
$plan = [];
if ($paso !== 'listo' && $ruta) {
    try { $plan = mig_zip_plan($ruta, $tipos, $max); $paso = 'revisar'; }
    catch (Throwable $e) { $errores[] = $e->getMessage(); @unlink($ruta); unset($_SESSION['mig_zip']); }
}
$asignados = array_filter($plan, fn ($p) => $p['pid']);
$nombres = [];
if ($asignados) {
    $ids = array_values(array_unique(array_map(fn ($p) => (int) $p['pid'], $asignados)));
    $st = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_merge([tenant_id()], $ids));
    foreach ($st as $r) $nombres[(int) $r['id']] = $r['nombre'] . ' ' . $r['apellidos'];
}

$titulo = t('Subir archivos en lote');
$activo = 'migrar';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/migrar/index"><?= et('Migrar desde otro sistema') ?></a></li>
    <li class="breadcrumb-item active"><?= e($titulo) ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi bi-file-earmark-zip"></i> <?= e($titulo) ?></h1>
<?php foreach ($errores as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($paso === 'subir'): ?>
<div class="row g-4">
    <div class="col-lg-7"><div class="card"><div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="accion" value="subir">
            <label class="form-label fw-semibold"><?= et('Archivo ZIP con los documentos') ?></label>
            <input type="file" name="zip" class="form-control mb-3" accept=".zip" required>
            <button class="btn btn-primary"><i class="bi bi-arrow-right"></i> <?= et('Ver a quién va cada archivo') ?></button>
        </form>
    </div></div></div>
    <div class="col-lg-5"><div class="card"><div class="card-body small">
        <div class="fw-semibold mb-2"><?= et('Cómo armar el ZIP') ?></div>
        <p class="mb-2"><?= et('Lo más fácil: una carpeta por paciente con su nombre completo, y adentro sus archivos.') ?></p>
        <div class="font-monospace p-2 rounded mb-2" style="background:rgba(127,127,127,.1)">Ana Pérez López/estudio.pdf<br>Juan Ramírez Soto/rayos-x.jpg</div>
        <p class="mb-2"><?= et('También sirve que el nombre del archivo traiga la CURP o el nombre completo del paciente.') ?></p>
        <div class="text-muted"><?= sprintf(et('Se aceptan PDF, imágenes, Word, Excel y texto de hasta %s cada uno. Importa primero a tus pacientes.'), fmt_bytes($max)) ?></div>
    </div></div></div>
</div>

<?php elseif ($paso === 'revisar'): ?>
<div class="alert alert-info"><i class="bi bi-file-earmark-zip"></i> <strong><?= e($imp['nombre'] ?? '') ?></strong>:
    <?= count($plan) ?> <?= et('archivos') ?> · <strong class="text-success"><?= count($asignados) ?></strong> <?= et('con paciente') ?> ·
    <strong class="text-danger"><?= count($plan) - count($asignados) ?></strong> <?= et('sin reconocer') ?>.</div>
<div class="card mb-3"><div class="table-responsive" style="max-height:480px"><table class="table table-sm align-middle mb-0">
    <thead><tr><th><?= et('Archivo') ?></th><th><?= et('Va al expediente de') ?></th><th><?= et('Cómo') ?></th></tr></thead>
    <tbody>
    <?php foreach ($plan as $p): ?>
        <tr class="<?= $p['pid'] ? '' : 'table-warning' ?>">
            <td class="small text-break"><?= e($p['ruta']) ?></td>
            <td><?= $p['pid'] ? e($nombres[$p['pid']] ?? '#' . $p['pid']) : '<span class="text-muted">—</span>' ?></td>
            <td class="small text-muted"><?= e($p['por']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="small text-muted"><?= et('Los que no se reconocen no se importan. Renombra su carpeta con el nombre completo del paciente y vuelve a subir solo esos.') ?></p>
<form method="post" class="d-flex justify-content-between">
    <?= csrf_field() ?>
    <button name="accion" value="cancelar" class="btn btn-light"><?= et('Cancelar') ?></button>
    <button name="accion" value="importar" class="btn btn-primary" <?= $asignados ? '' : 'disabled' ?>><i class="bi bi-cloud-upload"></i> <?= sprintf(et('Importar %d archivos'), count($asignados)) ?></button>
</form>

<?php else: ?>
<div class="card"><div class="card-body p-4 text-center">
    <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
    <h2 class="h4 mt-2"><?= sprintf(et('%d archivos agregados a los expedientes'), $ok) ?></h2>
    <?php if ($saltos): ?><p class="text-muted"><?= sprintf(et('%d no se importaron:'), count($saltos)) ?></p>
    <div class="text-start mx-auto small" style="max-width:620px;max-height:260px;overflow:auto">
        <?php foreach (array_slice($saltos, 0, 400) as [$f, $why]): ?><div><?= e($f) ?> — <?= e($why) ?></div><?php endforeach; ?>
    </div><?php endif; ?>
    <a href="<?= BASE_URL ?>/migrar/index" class="btn btn-primary mt-3"><?= et('Volver a migración') ?></a>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
