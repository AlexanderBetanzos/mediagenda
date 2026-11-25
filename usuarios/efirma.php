<?php
/**
 * Mi firma electrónica: cada médico sube SU e.firma del SAT para firmar sus
 * notas clínicas. La .key se guarda cifrada; la contraseña no se guarda nunca
 * (se pide cada vez que firma).
 */
require_once __DIR__ . '/../includes/ece.php';
require_login();
if (!has_role('medico', 'admin')) { flash('Solo el personal médico firma notas.', 'warning'); redirect('/dashboard'); }
asegurar_ece();
$u = current_user();
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'borrar') {
        db()->prepare('DELETE FROM medico_efirma WHERE usuario_id = ? AND consultorio_id = ?')->execute([$uid, tenant_id()]);
        auditar('efirma_borrada', 'usuario', $uid);
        flash('Se borró tu e.firma guardada. Tus notas ya firmadas siguen firmadas.', 'warning');
        redirect('/usuarios/efirma');
    }
    $cer = $_FILES['cer'] ?? null; $key = $_FILES['key'] ?? null;
    try {
        if (!$cer || $cer['error'] !== UPLOAD_ERR_OK || !$key || $key['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Sube los dos archivos: el .cer y el .key.');
        if ($cer['size'] > 20000 || $key['size'] > 20000) throw new RuntimeException('Esos archivos son demasiado grandes para ser una e.firma.');
        $cerBin = (string) file_get_contents($cer['tmp_name']);
        $keyBin = (string) file_get_contents($key['tmp_name']);
        $info = ece_leer_efirma($cerBin, $keyBin, (string) ($_POST['pass'] ?? ''));
        db()->prepare('REPLACE INTO medico_efirma (usuario_id, consultorio_id, cer, key_enc, rfc, nombre, no_cert, hasta, actualizado_en) VALUES (?,?,?,?,?,?,?,?,NOW())')
            ->execute([$uid, tenant_id(), base64_encode($cerBin), cfdi_cifrar($keyBin), $info['rfc'], $info['nombre'], $info['no_cert'], $info['hasta']]);
        auditar('efirma_subida', 'usuario', $uid, 'cert ' . $info['no_cert'] . ' vence ' . $info['hasta']);
        flash('Tu e.firma quedó lista. Ya puedes firmar tus notas desde el expediente.');
    } catch (Throwable $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('/usuarios/efirma');
}

$ef = ece_efirma($uid);
$st = db()->prepare('SELECT COUNT(*) FROM consultas WHERE consultorio_id = ? AND medico_id = ? AND firma IS NOT NULL');
try { $st->execute([tenant_id(), $uid]); $firmadas = (int) $st->fetchColumn(); } catch (Throwable $e) { $firmadas = 0; }

$titulo = t('Mi firma electrónica');
$activo = 'efirma';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-pen text-brand"></i> <?= et('Mi firma electrónica') ?></h1>
<p class="text-muted mb-4"><?= et('Firma tus notas clínicas con tu e.firma del SAT: la firma electrónica avanzada tiene la misma validez que la autógrafa.') ?></p>

<div class="row g-4">
    <div class="col-lg-7">
        <?php if ($ef): ?>
        <div class="card mb-3 border-success"><div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-patch-check-fill text-success fs-4"></i><strong><?= et('e.firma lista para firmar') ?></strong></div>
            <div class="small">
                <?= e($ef['nombre']) ?> · RFC <?= e($ef['rfc']) ?><br>
                <?= et('Certificado') ?> <code><?= e($ef['no_cert']) ?></code> · <?= et('vence el') ?> <strong><?= e(fmt_fecha($ef['hasta'])) ?></strong><br>
                <?= sprintf(et('%d notas firmadas'), $firmadas) ?>
            </div>
            <form method="post" class="mt-2" onsubmit="return confirm('<?= e(t('¿Borrar tu e.firma guardada?')) ?>')">
                <?= csrf_field() ?><input type="hidden" name="accion" value="borrar">
                <button class="btn btn-sm btn-link text-danger px-0"><i class="bi bi-trash"></i> <?= et('Borrar mi e.firma de este sistema') ?></button>
            </form>
        </div></div>
        <?php endif; ?>
        <div class="card"><div class="card-body">
            <h2 class="h6"><?= $ef ? et('Reemplazar e.firma') : et('Subir mi e.firma') ?></h2>
            <form method="post" enctype="multipart/form-data" class="row g-3" autocomplete="off">
                <?= csrf_field() ?>
                <div class="col-md-6"><label class="form-label"><?= et('Archivo .cer') ?></label><input type="file" name="cer" accept=".cer" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label"><?= et('Archivo .key') ?></label><input type="file" name="key" accept=".key" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label"><?= et('Contraseña de tu e.firma') ?></label><input type="password" name="pass" class="form-control" required autocomplete="new-password">
                    <div class="form-text"><?= et('Solo para comprobar que los archivos son tuyos. No se guarda.') ?></div></div>
                <div class="col-12"><button class="btn btn-primary"><i class="bi bi-upload"></i> <?= et('Guardar') ?></button></div>
            </form>
        </div></div>
    </div>
    <div class="col-lg-5"><div class="card"><div class="card-body small">
        <div class="fw-semibold mb-2"><?= et('Cómo funciona') ?></div>
        <ol class="ps-3 mb-3">
            <li class="mb-1"><?= et('Subes tu .cer y tu .key una sola vez. La .key se guarda cifrada.') ?></li>
            <li class="mb-1"><?= et('En el expediente, cada nota tuya tiene el botón "Firmar". Escribes tu contraseña y queda firmada.') ?></li>
            <li class="mb-1"><?= et('Tu contraseña nunca se guarda: sin ella nadie puede firmar en tu nombre, ni siquiera un administrador.') ?></li>
            <li class="mb-1"><?= et('La firma cubre el contenido completo de la nota. Si alguien la cambiara, la firma dejaría de ser válida y se vería en rojo.') ?></li>
        </ol>
        <div class="alert alert-light border mb-0"><i class="bi bi-info-circle"></i>
            <?= et('Es tu e.firma (antes FIEL), no el sello digital (CSD) que se usa para facturar. Si no la tienes o venció, se tramita en el SAT.') ?></div>
    </div></div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
