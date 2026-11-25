<?php
/**
 * Facturación electrónica (CFDI): saldo de timbres, compra de paquetes,
 * cómo funcionan los timbres y datos fiscales del consultorio (emisor + CSD).
 * Solo administradores.
 */
require_once __DIR__ . '/../includes/cfdi.php';
require_role('admin');

$cid = (int) tenant_id();
$u = current_user();
$quien = (string) ($u['nombre'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'comprar') {
        try {
            $url = timbres_iniciar_compra($cid, (int) ($_POST['paquete'] ?? -1), $quien, (string) ($u['email'] ?? ''));
            auditar('timbres_compra_iniciada', 'timbres', null, 'paquete ' . (int) ($_POST['paquete'] ?? -1));
            header('Location: ' . $url);
            exit;
        } catch (Throwable $e) {
            flash($e->getMessage(), 'danger');
            redirect('/timbres/index#paquetes');
        }
    }

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
$paquetes = timbres_paquetes();
$venta = cfdi_venta_activa();
$em = cfdi_emisor($cid);
$falta = cfdi_emisor_faltantes($em);
$tn = tenant();
$planes = planes_mp();
$incPlanes = timbres_incluidos_planes();
$incPlan = $incPlanes[$tn['plan'] ?? ''] ?? 0;

$movs = db()->prepare('SELECT * FROM timbres_mov WHERE consultorio_id = ? ORDER BY id DESC LIMIT 30');
$movs->execute([$cid]);
$movs = $movs->fetchAll();
$compras = db()->prepare("SELECT * FROM timbres_compras WHERE consultorio_id = ? AND estado <> 'pendiente' OR (consultorio_id = ? AND creado_en > NOW() - INTERVAL 2 DAY) ORDER BY id DESC LIMIT 15");
$compras->execute([$cid, $cid]);
$compras = $compras->fetchAll();

$base = $paquetes ? $paquetes[0]['precio'] / $paquetes[0]['timbres'] : 0;

$titulo = t('Facturación electrónica');
$activo = 'timbres';
include __DIR__ . '/../includes/header.php';
?>
<style>
.tb-saldo{border-radius:16px;padding:1.1rem 1.25rem;height:100%}
.tb-saldo .n{font-size:2.1rem;font-weight:800;letter-spacing:-.03em;line-height:1}
.tb-saldo .l{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;opacity:.75}
.tb-paq{border:1px solid var(--bs-border-color);border-radius:16px;padding:1.25rem;height:100%;display:flex;flex-direction:column;position:relative;background:var(--bs-body-bg)}
.tb-paq.pop{border:2px solid var(--brand,#0f8dbf);box-shadow:0 10px 28px rgba(15,141,191,.14)}
.tb-paq .tag{position:absolute;top:-.7rem;left:50%;transform:translateX(-50%);background:var(--brand,#0f8dbf);color:#fff;font-size:.7rem;font-weight:700;padding:.15rem .6rem;border-radius:20px;white-space:nowrap}
.tb-paq .t{font-size:1.9rem;font-weight:800;letter-spacing:-.03em}
.tb-paq .p{font-size:1.25rem;font-weight:700}
.tb-paq ul{list-style:none;padding:0;margin:.75rem 0 1rem;font-size:.85rem}
.tb-paq li{padding:.15rem 0}
.tb-paq li i{color:#16a34a}
.tb-como dt{font-weight:650}
.tb-como dd{color:var(--bs-secondary-color);margin-bottom:.8rem}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-patch-check text-brand"></i> <?= et('Facturación electrónica (CFDI 4.0)') ?></h1>
    <a href="#como" class="btn btn-sm btn-outline-secondary"><i class="bi bi-question-circle"></i> <?= et('¿Cómo funcionan los timbres?') ?></a>
</div>

<?php if (!$venta): ?>
<div class="alert alert-info d-flex gap-2"><i class="bi bi-hourglass-split fs-5"></i>
    <div><strong><?= et('Estamos terminando de conectar el timbrado con el SAT.') ?></strong>
    <?= et('Ya puedes dejar listos tus datos fiscales y tu CSD; te avisaremos en cuanto puedas comprar timbres y facturar.') ?></div></div>
<?php endif; ?>

<!-- Saldo -->
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="tb-saldo text-white" style="background:linear-gradient(135deg,#0b6e96,#0f8dbf)">
        <div class="l"><?= et('Disponibles hoy') ?></div>
        <div class="n mt-2"><?= number_format($saldo['total']) ?></div>
        <div class="small mt-1 opacity-75"><?= et('facturas que puedes timbrar') ?></div>
    </div></div>
    <div class="col-md-4"><div class="tb-saldo border">
        <div class="l text-muted"><?= et('Incluidos en tu plan este mes') ?></div>
        <div class="n mt-2"><?= number_format($saldo['incluidos_restan']) ?> <span class="fs-6 text-muted fw-normal">/ <?= number_format($saldo['incluidos_mes']) ?></span></div>
        <div class="small text-muted mt-1">
            <?php if ($saldo['incluidos_mes'] > 0): ?>
                <?= et('Se renuevan el') ?> <?= e(fmt_fecha($saldo['renuevan'])) ?> · <?= et('no se acumulan') ?>
            <?php elseif (($tn['estado'] ?? '') !== 'activa' && $incPlan > 0): ?>
                <?= sprintf(et('Tu plan incluye %d al mes en cuanto activas tu membresía.'), $incPlan) ?>
            <?php else: ?>
                <?= et('Tu plan no incluye timbres; usa paquetes.') ?>
            <?php endif; ?>
        </div>
    </div></div>
    <div class="col-md-4"><div class="tb-saldo border">
        <div class="l text-muted"><?= et('De paquetes comprados') ?></div>
        <div class="n mt-2"><?= number_format($saldo['comprados']) ?></div>
        <div class="small text-muted mt-1"><?= et('No caducan') ?></div>
    </div></div>
</div>
<?php if ($venta && $saldo['total'] <= TIMBRES_AVISO_BAJO): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
    <?= $saldo['total'] ? sprintf(et('Te quedan solo %d timbres.'), $saldo['total']) : et('No tienes timbres.') ?>
    <?= et('Compra un paquete para no quedarte sin poder facturar.') ?></div>
<?php endif; ?>

<!-- Paquetes -->
<h2 class="h5 mb-1" id="paquetes"><?= et('Paquetes de timbres') ?></h2>
<p class="text-muted small mb-4"><?= et('Pago único con Mercado Pago (tarjeta, OXXO o transferencia). No es suscripción: compras cuando lo necesitas. Precios con IVA incluido.') ?></p>
<div class="row g-3 mb-4">
    <?php $popIx = count($paquetes) > 2 ? 1 : -1;
    foreach ($paquetes as $ix => $p): $unit = $p['precio'] / $p['timbres']; $ahorro = $base > 0 ? (int) round((1 - $unit / $base) * 100) : 0; ?>
    <div class="col-sm-6 col-lg-3">
        <div class="tb-paq <?= $ix === $popIx ? 'pop' : '' ?>">
            <?php if ($ix === $popIx): ?><span class="tag"><?= et('El más elegido') ?></span><?php endif; ?>
            <div class="t"><?= number_format($p['timbres']) ?> <span class="fs-6 fw-semibold text-muted"><?= et('timbres') ?></span></div>
            <div class="p">$<?= number_format($p['precio'], $p['precio'] == floor($p['precio']) ? 0 : 2) ?> <span class="small fw-normal text-muted">MXN</span></div>
            <div class="small text-muted">$<?= number_format($unit, 2) ?> <?= et('por factura') ?><?= $ahorro > 0 ? ' · <span class="text-success fw-semibold">' . sprintf(et('ahorras %d%%'), $ahorro) . '</span>' : '' ?></div>
            <ul>
                <li><i class="bi bi-check-lg"></i> <?= sprintf(et('%s facturas timbradas ante el SAT'), number_format($p['timbres'])) ?></li>
                <li><i class="bi bi-check-lg"></i> <?= et('Se suman a tu saldo al confirmarse el pago') ?></li>
                <li><i class="bi bi-check-lg"></i> <?= et('No caducan') ?></li>
                <li><i class="bi bi-check-lg"></i> <?= et('PDF y XML por correo y WhatsApp sin costo') ?></li>
            </ul>
            <button type="button" class="btn <?= $ix === $popIx ? 'btn-primary' : 'btn-outline-primary' ?> mt-auto w-100" <?= $venta ? '' : 'disabled' ?>
                    data-bs-toggle="modal" data-bs-target="#tbComprar" data-ix="<?= $ix ?>" data-t="<?= (int) $p['timbres'] ?>"
                    data-p="<?= e(number_format($p['precio'], 2)) ?>" data-u="<?= e(number_format($unit, 2)) ?>">
                <?= $venta ? et('Comprar') : et('Disponible muy pronto') ?>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

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
                    <?php $txt = []; foreach ($incPlanes as $k => $n) $txt[] = e($planes[$k]['nombre'] ?? $k) . ': <b>' . ($n ?: et('ninguno')) . '</b>'; ?>
                    <?= implode(' · ', $txt) ?> <?= et('al mes.') ?>
                    <?= et('Se renuevan el día 1 de cada mes y los que no uses no pasan al siguiente. Solo aplican con la membresía activa (durante la prueba gratis se usan paquetes).') ?></dd>
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
<div class="row g-3">
    <div class="col-lg-7"><div class="card h-100">
        <div class="card-header"><i class="bi bi-clock-history"></i> <?= et('Movimientos de timbres') ?></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Concepto') ?></th><th><?= et('Bolsa') ?></th><th class="text-end"><?= et('Timbres') ?></th></tr></thead>
            <tbody>
            <?php if (!$movs): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= et('Aún no hay movimientos.') ?></td></tr><?php endif; ?>
            <?php foreach ($movs as $m):
                $con = ['compra' => t('Compra de paquete'), 'consumo' => t('Factura timbrada'), 'devolucion' => t('Devolución (no se timbró)'), 'ajuste' => t('Ajuste')][$m['tipo']] ?? $m['tipo']; ?>
                <tr>
                    <td class="small text-nowrap"><?= e(fmt_fecha($m['creado_en'])) ?> <span class="text-muted"><?= e(date('H:i', strtotime($m['creado_en']))) ?></span></td>
                    <td class="small"><?= e($con) ?><?php if ($m['detalle']): ?><div class="text-muted"><?= e($m['detalle']) ?></div><?php endif; ?></td>
                    <td class="small"><?= $m['bolsa'] === 'incluido' ? et('Del plan') : et('Paquete') ?></td>
                    <td class="text-end fw-semibold <?= $m['cantidad'] > 0 ? 'text-success' : '' ?>"><?= $m['cantidad'] > 0 ? '+' : '' ?><?= number_format($m['cantidad']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header"><i class="bi bi-bag-check"></i> <?= et('Tus compras') ?></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Paquete') ?></th><th class="text-end"><?= et('Monto') ?></th><th><?= et('Estado') ?></th></tr></thead>
            <tbody>
            <?php if (!$compras): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= et('Sin compras todavía.') ?></td></tr><?php endif; ?>
            <?php foreach ($compras as $c): [$lbl, $col] = ['pagado' => [t('Pagado'), 'success'], 'pendiente' => [t('Pendiente'), 'warning'], 'rechazado' => [t('Rechazado'), 'danger']][$c['estado']] ?? [$c['estado'], 'secondary']; ?>
                <tr>
                    <td class="small"><?= e(fmt_fecha($c['creado_en'])) ?></td>
                    <td class="small"><?= number_format($c['timbres']) ?> <?= et('timbres') ?></td>
                    <td class="text-end small">$<?= number_format($c['monto'], 2) ?></td>
                    <td><span class="badge text-bg-<?= $col ?>"><?= e($lbl) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>
</div>

<!-- Confirmación de compra: todo lo que va a pasar, antes de ir a pagar -->
<div class="modal fade" id="tbComprar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="accion" value="comprar"><input type="hidden" name="paquete" id="tbIx">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-bag"></i> <?= et('Paquete de') ?> <span id="tbT"></span> <?= et('timbres') ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="d-flex justify-content-between align-items-baseline border rounded-3 p-3 mb-3">
                <div><div class="small text-muted"><?= et('Total a pagar (IVA incluido)') ?></div><div class="fs-3 fw-bold">$<span id="tbP"></span> MXN</div></div>
                <div class="text-end small text-muted">$<span id="tbU"></span><br><?= et('por factura') ?></div>
            </div>
            <ul class="small mb-0 ps-3">
                <li class="mb-1"><?= et('Te llevamos a Mercado Pago para un') ?> <strong><?= et('pago único') ?></strong> <?= et('(no es suscripción).') ?></li>
                <li class="mb-1"><?= et('Con tarjeta, los timbres se suman a tu saldo') ?> <strong><?= et('al instante') ?></strong>; <?= et('con OXXO o transferencia, cuando el pago se acredite.') ?></li>
                <li class="mb-1"><?= et('Cada factura timbrada usa 1 timbre. Si el SAT la rechaza, el timbre regresa a tu saldo.') ?></li>
                <li class="mb-1"><?= et('Primero se gastan los incluidos de tu plan en el mes y después los de este paquete.') ?></li>
                <li class="mb-1"><?= et('Los timbres de paquete') ?> <strong><?= et('no caducan') ?></strong>; <?= et('no son reembolsables ni transferibles a otra cuenta.') ?></li>
                <?php if ($falta): ?><li class="text-warning-emphasis"><?= et('Recuerda completar tus datos fiscales y subir tu CSD para poder usarlos.') ?></li><?php endif; ?>
            </ul>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= et('Cancelar') ?></button>
            <button class="btn btn-primary"><i class="bi bi-lock"></i> <?= et('Ir a pagar') ?></button>
        </div>
    </form></div>
</div>
<script>
document.getElementById('tbComprar').addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget; if (!b) return;
    document.getElementById('tbIx').value = b.dataset.ix;
    document.getElementById('tbT').textContent = Number(b.dataset.t).toLocaleString('es-MX');
    document.getElementById('tbP').textContent = b.dataset.p;
    document.getElementById('tbU').textContent = b.dataset.u;
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
