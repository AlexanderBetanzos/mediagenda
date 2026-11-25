<?php
/**
 * Facturación electrónica (CFDI): saldo de timbres, compra de paquetes,
 * cómo funcionan los timbres y datos fiscales del consultorio (emisor + CSD).
 * Solo administradores.
 */
require_once __DIR__ . '/../includes/cfdi.php';
require_once __DIR__ . '/../includes/creditos_ui.php';
require_role('admin');
credito_ui_post_compra('timbre');

$cid = (int) tenant_id();
$u = current_user();
$quien = (string) ($u['nombre'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'emisor') {
        $rfc = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['rfc'] ?? '')));
        $razon = trim(preg_replace('/\s+/', ' ', (string) ($_POST['razon_social'] ?? '')));
        $reg = (string) ($_POST['regimen'] ?? '');
        $cp = preg_replace('/\D/', '', (string) ($_POST['cp'] ?? ''));
        $serie = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['serie'] ?? 'A'))) ?: 'A';
        $folio = max(1, (int) ($_POST['folio_sig'] ?? 1));
        $err = [];
        if (!preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}$/u', $rfc)) $err[] = 'El RFC no tiene un formato válido (12 caracteres para empresa, 13 para persona física).';
        if ($razon === '') $err[] = 'Escribe tu nombre o razón social tal como aparece en tu Constancia de Situación Fiscal.';
        if (!isset(cfdi_regimenes_emisor()[$reg])) $err[] = 'Elige tu régimen fiscal.';
        if (strlen($cp) !== 5) $err[] = 'El código postal debe tener 5 dígitos.';

        $csd = null;
        $cer = $_FILES['cer'] ?? null; $key = $_FILES['key'] ?? null;
        $subeCer = $cer && $cer['error'] === UPLOAD_ERR_OK; $subeKey = $key && $key['error'] === UPLOAD_ERR_OK;
        if ($subeCer || $subeKey) {
            if (!$subeCer || !$subeKey) {
                $err[] = 'Sube los dos archivos del CSD: el .cer y el .key.';
            } elseif ($cer['size'] > 20000 || $key['size'] > 20000) {
                $err[] = 'Esos archivos son demasiado grandes para ser un CSD.';
            } else {
                try {
                    $cerBin = (string) file_get_contents($cer['tmp_name']);
                    $keyBin = (string) file_get_contents($key['tmp_name']);
                    $pass = (string) ($_POST['csd_pass'] ?? '');
                    $info = cfdi_leer_csd($cerBin, $keyBin, $pass);
                    if ($info['rfc'] !== '' && $info['rfc'] !== $rfc) {
                        $err[] = 'El CSD es del RFC ' . $info['rfc'] . ', pero escribiste ' . $rfc . '.';
                    } else {
                        $csd = ['cer' => base64_encode($cerBin), 'key' => cfdi_cifrar($keyBin), 'pass' => cfdi_cifrar($pass)] + $info;
                    }
                } catch (Throwable $e) {
                    $err[] = $e->getMessage();
                }
            }
        }
        if ($err) {
            foreach ($err as $x) flash($x, 'danger');
            redirect('/timbres/index#emisor');
        }
        $pdo = db();
        $pdo->prepare('INSERT INTO cfdi_emisor (consultorio_id, rfc, razon_social, regimen, cp, serie, folio_sig, actualizado_en)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE rfc=VALUES(rfc), razon_social=VALUES(razon_social), regimen=VALUES(regimen),
                         cp=VALUES(cp), serie=VALUES(serie), folio_sig=VALUES(folio_sig), actualizado_en=NOW()')
            ->execute([$cid, $rfc, mb_strtoupper($razon), $reg, $cp, $serie, $folio]);
        if ($csd) {
            $pdo->prepare('UPDATE cfdi_emisor SET csd_cer=?, csd_key_enc=?, csd_pass_enc=?, csd_rfc=?, csd_no_cert=?, csd_desde=?, csd_hasta=? WHERE consultorio_id=?')
                ->execute([$csd['cer'], $csd['key'], $csd['pass'], $csd['rfc'] ?: $rfc, $csd['no_cert'], $csd['desde'], $csd['hasta'], $cid]);
            auditar('cfdi_csd_subido', 'cfdi_emisor', $cid, 'CSD ' . $csd['no_cert'] . ' vence ' . $csd['hasta']);
        }
        auditar('cfdi_emisor_guardar', 'cfdi_emisor', $cid, $rfc);
        flash($csd ? 'Datos fiscales y CSD guardados. Tu sello digital es válido.' : 'Datos fiscales guardados.');
        redirect('/timbres/index#emisor');
    }

    if ($accion === 'quitar_csd') {
        db()->prepare('UPDATE cfdi_emisor SET csd_cer=NULL, csd_key_enc=NULL, csd_pass_enc=NULL, csd_rfc=NULL, csd_no_cert=NULL, csd_desde=NULL, csd_hasta=NULL WHERE consultorio_id=?')
            ->execute([$cid]);
        auditar('cfdi_csd_borrado', 'cfdi_emisor', $cid);
        flash('Se borró el CSD guardado.', 'warning');
        redirect('/timbres/index#emisor');
    }
}

asegurar_cfdi();
$saldo = timbres_saldo($cid);
$venta = cfdi_venta_activa();
$em = cfdi_emisor($cid);
$falta = cfdi_emisor_faltantes($em);
$planes = planes_mp();
$incPlanes = credito_incluidos('timbre');

$titulo = t('Facturación electrónica');
$activo = 'timbres';
include __DIR__ . '/../includes/header.php';
?>
<?php credito_ui_estilos(); ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-patch-check text-brand"></i> <?= et('Facturación electrónica (CFDI 4.0)') ?></h1>
    <a href="#como" class="btn btn-sm btn-outline-secondary"><i class="bi bi-question-circle"></i> <?= et('¿Cómo funcionan los timbres?') ?></a>
</div>

<?php if (!$venta): ?>
<div class="alert alert-info d-flex gap-2"><i class="bi bi-hourglass-split fs-5"></i>
    <div><strong><?= et('Estamos terminando de conectar el timbrado con el SAT.') ?></strong>
    <?= et('Ya puedes dejar listos tus datos fiscales y tu CSD; te avisaremos en cuanto puedas comprar timbres y facturar.') ?></div></div>
<?php endif; ?>

<!-- Saldo y paquetes -->
<?php credito_ui_saldo('timbre', $saldo);
credito_ui_paquetes('timbre', [
    t('%n facturas timbradas ante el SAT'),
    t('Se suman a tu saldo al confirmarse el pago'),
    t('No caducan'),
    t('PDF y XML por correo y WhatsApp sin costo'),
], array_filter([
    e(t('Cada factura timbrada usa 1 timbre. Si el SAT la rechaza, el timbre regresa a tu saldo.')),
    $falta ? '<span class="text-warning-emphasis">' . e(t('Recuerda completar tus datos fiscales y subir tu CSD para poder usarlos.')) . '</span>' : '',
])); ?>

<!-- Cómo funcionan -->
<div class="card mb-4" id="como">
    <div class="card-header"><i class="bi bi-info-circle"></i> <?= et('Cómo funcionan los timbres') ?></div>
    <div class="card-body tb-como">
        <div class="row g-4">
            <div class="col-lg-6"><dl class="mb-0">
                <dt>1. <?= et('¿Qué es un timbre?') ?></dt>
                <dd><?= et('Es el registro de una factura ante el SAT. Cada CFDI que emites pasa por un proveedor autorizado (PAC) que lo valida y le pone su folio fiscal (UUID). Cada factura timbrada usa 1 timbre.') ?></dd>
                <dt>2. <?= et('Qué sí gasta 1 timbre') ?></dt>
                <dd><?= et('Una factura a un paciente o empresa, la factura global del mes a "público en general", un complemento de pago (cuando cobras en parcialidades) y una nota de crédito.') ?></dd>
                <dt>3. <?= et('Qué NO gasta timbres') ?></dt>
                <dd><?= et('Guardar borradores, ver la vista previa, volver a enviar el PDF o el XML, y las facturas que el SAT rechaza por un error en los datos: si no se timbró, el timbre regresa a tu saldo.') ?>
                    <?= timbres_cancelar_cobra() ? et('Cancelar una factura sí gasta 1 timbre.') : et('Cancelar una factura tampoco gasta timbre.') ?></dd>
                <dt>4. <?= et('En qué orden se usan') ?></dt>
                <dd><?= et('Primero los incluidos en tu plan del mes en curso y, cuando se acaban, los de tus paquetes.') ?></dd>
            </dl></div>
            <div class="col-lg-6"><dl class="mb-0">
                <dt>5. <?= et('Timbres incluidos en tu plan') ?></dt>
                <dd>
                    <?php $txt = []; foreach ($incPlanes as $k => $n) { if ($k === 'prueba') continue; $txt[] = e($planes[$k]['nombre'] ?? $k) . ': <b>' . ($n ?: et('ninguno')) . '</b>'; } ?>
                    <?= implode(' · ', $txt) ?> <?= et('al mes.') ?>
                    <?= et('Se renuevan el día 1 de cada mes y los que no uses no pasan al siguiente. Solo aplican con la membresía activa.') ?></dd>
                <dt>6. <?= et('Timbres de paquete') ?></dt>
                <dd><?= et('Los pagas una sola vez y no caducan mientras tu cuenta exista. Se suman a tu saldo en cuanto Mercado Pago confirma el pago (con tarjeta es al instante; en OXXO o transferencia, cuando se acredita). No son reembolsables ni se pasan a otra cuenta.') ?></dd>
                <dt>7. <?= et('Si se te acaban') ?></dt>
                <dd><?= sprintf(et('Te avisamos cuando te queden %d o menos. Con saldo en cero no se puede timbrar hasta que compres otro paquete, pero puedes seguir cobrando y dar tickets normalmente.'), TIMBRES_AVISO_BAJO) ?></dd>
                <dt>8. <?= et('Qué necesitas para facturar') ?></dt>
                <dd><?= et('Tu Certificado de Sello Digital (CSD) del SAT —archivo .cer, archivo .key y su contraseña— y tus datos tal como aparecen en tu Constancia de Situación Fiscal. El CSD es distinto de la e.firma; se tramita gratis en el portal del SAT.') ?></dd>
            </dl></div>
        </div>
    </div>
</div>

<!-- Datos fiscales -->
<div class="card mb-4" id="emisor">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building-check"></i> <?= et('Tus datos fiscales (emisor)') ?></span>
        <?php if (!$falta): ?><span class="badge text-bg-success"><i class="bi bi-check-circle"></i> <?= et('Listo para facturar') ?></span>
        <?php else: ?><span class="badge text-bg-warning"><?= et('Incompleto') ?></span><?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($falta): ?><div class="small text-muted mb-3"><?= et('Falta:') ?> <?= e(implode(' · ', $falta)) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="row g-3" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="accion" value="emisor">
            <div class="col-md-4"><label class="form-label"><?= et('RFC') ?></label>
                <input name="rfc" class="form-control text-uppercase" maxlength="13" required value="<?= e($em['rfc']) ?>"></div>
            <div class="col-md-8"><label class="form-label"><?= et('Nombre o razón social') ?></label>
                <input name="razon_social" class="form-control text-uppercase" required value="<?= e($em['razon_social']) ?>">
                <div class="form-text"><?= et('Igual que en tu Constancia de Situación Fiscal. Si es empresa, sin "S.A. de C.V." ni el régimen de capital.') ?></div></div>
            <div class="col-md-6"><label class="form-label"><?= et('Régimen fiscal') ?></label>
                <select name="regimen" class="form-select" required><option value=""><?= et('Elige…') ?></option>
                <?php foreach (cfdi_regimenes_emisor() as $k => $l): ?><option value="<?= $k ?>" <?= $em['regimen'] === (string) $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-2"><label class="form-label"><?= et('C.P. fiscal') ?></label>
                <input name="cp" class="form-control" inputmode="numeric" maxlength="5" required value="<?= e($em['cp']) ?>"></div>
            <div class="col-3 col-md-2"><label class="form-label"><?= et('Serie') ?></label>
                <input name="serie" class="form-control text-uppercase" maxlength="10" value="<?= e($em['serie']) ?>"></div>
            <div class="col-3 col-md-2"><label class="form-label"><?= et('Siguiente folio') ?></label>
                <input name="folio_sig" type="number" min="1" class="form-control" value="<?= (int) $em['folio_sig'] ?>"></div>

            <div class="col-12"><hr class="my-1"></div>
            <div class="col-12">
                <h3 class="h6 mb-1"><i class="bi bi-key"></i> <?= et('Certificado de Sello Digital (CSD)') ?></h3>
                <?php if (!empty($em['csd_cer'])): ?>
                    <div class="alert alert-success py-2 small mb-2"><i class="bi bi-shield-check"></i>
                        <?= et('CSD guardado') ?> · <?= et('No.') ?> <code><?= e($em['csd_no_cert']) ?></code> · RFC <?= e($em['csd_rfc']) ?> ·
                        <?= et('vence el') ?> <strong><?= e(fmt_fecha($em['csd_hasta'])) ?></strong>.
                        <?= et('Para reemplazarlo, sube los archivos nuevos.') ?></div>
                <?php else: ?>
                    <p class="small text-muted mb-2"><?= et('Lo descargas del portal del SAT (Certisat) con tu e.firma. Se guarda cifrado y solo se usa para sellar tus facturas.') ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-4"><label class="form-label"><?= et('Archivo .cer') ?></label><input type="file" name="cer" accept=".cer" class="form-control"></div>
            <div class="col-md-4"><label class="form-label"><?= et('Archivo .key') ?></label><input type="file" name="key" accept=".key" class="form-control"></div>
            <div class="col-md-4"><label class="form-label"><?= et('Contraseña del CSD') ?></label><input type="password" name="csd_pass" class="form-control" autocomplete="new-password"></div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-save"></i> <?= et('Guardar datos fiscales') ?></button>
            </div>
        </form>
        <?php if (!empty($em['csd_cer'])): ?>
        <form method="post" class="mt-2" onsubmit="return confirm('<?= e(t('¿Borrar el CSD guardado? No podrás facturar hasta subir otro.')) ?>')">
            <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_csd">
            <button class="btn btn-sm btn-link text-danger px-0"><i class="bi bi-trash"></i> <?= et('Borrar CSD guardado') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Historial -->
<?php credito_ui_historial('timbre', ['consumo' => t('Factura timbrada')]); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
