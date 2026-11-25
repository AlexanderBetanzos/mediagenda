<?php
/**
 * Seguridad de mi cuenta: verificación en dos pasos (2FA), cambio de
 * contraseña y mis últimos accesos. Para el administrador, además: exigir
 * 2FA a todo el personal del consultorio.
 */
require_once __DIR__ . '/../includes/totp.php';
require_login();
asegurar_totp();
$u = current_user();
$uid = (int) $u['id'];
$nuevos = null;   // códigos de respaldo recién generados (se muestran una vez)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'iniciar_2fa') {
        $_SESSION['totp_nuevo'] = b32_encode(random_bytes(20));
        redirect('/usuarios/seguridad#dos-pasos');
    }
    if ($accion === 'confirmar_2fa') {
        $sec = (string) ($_SESSION['totp_nuevo'] ?? '');
        if ($sec !== '' && totp_valido($sec, (string) ($_POST['codigo'] ?? ''))) {
            db()->prepare('UPDATE usuarios SET totp_secret = ?, totp_activo = 1 WHERE id = ?')->execute([cfdi_cifrar($sec), $uid]);
            unset($_SESSION['totp_nuevo']);
            $_SESSION['usuario']['totp'] = 1;
            $nuevos = totp_generar_respaldo($uid);
            auditar('2fa_activado', 'usuario', $uid);
            flash('Verificación en dos pasos activada. Guarda tus códigos de respaldo.');
        } else {
            flash('El código no coincide. Revisa que la hora de tu celular sea automática e inténtalo de nuevo.', 'danger');
            redirect('/usuarios/seguridad#dos-pasos');
        }
    }
    if ($accion === 'desactivar_2fa') {
        if (cfg('exigir_2fa') === '1') { flash('Tu consultorio exige la verificación en dos pasos; no se puede desactivar.', 'warning'); redirect('/usuarios/seguridad'); }
        if (totp_verificar_usuario($uid, (string) ($_POST['codigo'] ?? ''))) {
            db()->prepare('UPDATE usuarios SET totp_secret = NULL, totp_activo = 0, totp_respaldo = NULL WHERE id = ?')->execute([$uid]);
            $_SESSION['usuario']['totp'] = 0;
            auditar('2fa_desactivado', 'usuario', $uid);
            flash('Verificación en dos pasos desactivada.', 'warning');
        } else flash('Código incorrecto.', 'danger');
        redirect('/usuarios/seguridad');
    }
    if ($accion === 'respaldo') {
        if (totp_verificar_usuario($uid, (string) ($_POST['codigo'] ?? ''))) {
            $nuevos = totp_generar_respaldo($uid);
            auditar('2fa_respaldo_nuevo', 'usuario', $uid);
        } else { flash('Código incorrecto.', 'danger'); redirect('/usuarios/seguridad'); }
    }
    if ($accion === 'password') {
        $st = db()->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $st->execute([$uid]);
        $h = (string) $st->fetchColumn();
        $n1 = (string) ($_POST['nueva'] ?? ''); $n2 = (string) ($_POST['nueva2'] ?? '');
        if (!password_verify((string) ($_POST['actual'] ?? ''), $h)) flash('Tu contraseña actual no es correcta.', 'danger');
        elseif ($ep = password_politica($n1, (string) ($u['email'] ?? ''))) flash($ep, 'danger');
        elseif ($n1 !== $n2) flash('Las contraseñas nuevas no coinciden.', 'danger');
        else {
            db()->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($n1, PASSWORD_DEFAULT), $uid]);
            auditar('password_cambiada', 'usuario', $uid);
            flash('Contraseña actualizada.');
        }
        redirect('/usuarios/seguridad');
    }
    if ($accion === 'exigir' && ($u['rol'] ?? '') === 'admin') {
        $on = !empty($_POST['exigir_2fa']);
        $yo = totp_usuario($uid);
        if ($on && !(int) ($yo['totp_activo'] ?? 0)) { flash('Primero activa la verificación en dos pasos en tu propia cuenta.', 'warning'); redirect('/usuarios/seguridad'); }
        guardar_cfg(['exigir_2fa' => $on ? '1' : '0']);
        auditar('2fa_exigir', 'configuracion', null, $on ? 'activado' : 'desactivado');
        flash($on ? 'Desde ahora todo el personal debe usar verificación en dos pasos.' : 'Ya no se exige la verificación en dos pasos.');
        redirect('/usuarios/seguridad');
    }
}

$yo = totp_usuario($uid);
$activo2fa = (int) ($yo['totp_activo'] ?? 0) === 1;
$restantes = count(json_decode((string) ($yo['totp_respaldo'] ?? ''), true) ?: []);
$secNuevo = $_SESSION['totp_nuevo'] ?? null;

$acc = db()->prepare("SELECT accion, ip, user_agent, creado_en FROM auditoria WHERE usuario_id = ? AND accion IN ('login','login_fallido','2fa_fallido','logout','sesion_expirada') ORDER BY id DESC LIMIT 10");
$acc->execute([$uid]);
$accesos = $acc->fetchAll();
$sinDos = [];
if (($u['rol'] ?? '') === 'admin') {
    $q = db()->prepare("SELECT nombre FROM usuarios WHERE consultorio_id = ? AND activo = 1 AND password_hash IS NOT NULL AND COALESCE(totp_activo,0) = 0 ORDER BY nombre");
    $q->execute([tenant_id()]);
    $sinDos = $q->fetchAll(PDO::FETCH_COLUMN);
}

$titulo = t('Seguridad de mi cuenta');
$activo = 'seguridad';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-shield-lock text-brand"></i> <?= et('Seguridad de mi cuenta') ?></h1>
<p class="text-muted mb-4"><?= et('Protege el acceso a los expedientes de tus pacientes.') ?></p>

<?php if ($nuevos): ?>
<div class="alert alert-warning">
    <div class="fw-semibold mb-1"><i class="bi bi-key"></i> <?= et('Tus códigos de respaldo (guárdalos ahora; no se vuelven a mostrar)') ?></div>
    <div class="small mb-2"><?= et('Cada uno sirve una sola vez para entrar si pierdes tu celular.') ?></div>
    <div class="font-monospace d-flex flex-wrap gap-2"><?php foreach ($nuevos as $c): ?><span class="badge text-bg-light border fs-6"><?= e($c) ?></span><?php endforeach; ?></div>
    <button class="btn btn-sm btn-outline-dark mt-2" onclick="navigator.clipboard.writeText(<?= e(json_encode(implode("\n", $nuevos))) ?>);this.textContent='<?= e(t('¡Copiados!')) ?>'"><i class="bi bi-clipboard"></i> <?= et('Copiar') ?></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4" id="dos-pasos">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-phone"></i> <?= et('Verificación en dos pasos') ?></span>
                <?= $activo2fa ? '<span class="badge text-bg-success">' . et('Activa') . '</span>' : '<span class="badge text-bg-secondary">' . et('Inactiva') . '</span>' ?>
            </div>
            <div class="card-body">
                <?php if ($activo2fa): ?>
                    <p class="small"><?= et('Al entrar te pedimos, además de tu contraseña, el código de tu app autenticadora. Aunque alguien sepa tu contraseña, no podrá entrar sin tu celular.') ?></p>
                    <p class="small text-muted"><?= sprintf(et('Te quedan %d códigos de respaldo.'), $restantes) ?></p>
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <div class="col-sm-5"><label class="form-label small"><?= et('Código actual de tu app') ?></label><input name="codigo" class="form-control" inputmode="numeric" required autocomplete="one-time-code"></div>
                        <div class="col-sm-7 d-flex gap-2">
                            <button name="accion" value="respaldo" class="btn btn-outline-primary"><?= et('Nuevos códigos de respaldo') ?></button>
                            <?php if (cfg('exigir_2fa') !== '1'): ?><button name="accion" value="desactivar_2fa" class="btn btn-outline-danger"><?= et('Desactivar') ?></button><?php endif; ?>
                        </div>
                    </form>
                <?php elseif ($secNuevo): ?>
                    <ol class="small ps-3">
                        <li><?= et('Abre Google Authenticator, Microsoft Authenticator o Authy en tu celular.') ?></li>
                        <li><?= et('Toca "Agregar" y escanea este código QR.') ?></li>
                        <li><?= et('Escribe abajo el código de 6 dígitos que te muestra la app.') ?></li>
                    </ol>
                    <div class="d-flex flex-wrap gap-4 align-items-center mb-3">
                        <div id="qr2fa" style="width:180px;height:180px;background:#fff;padding:8px;border-radius:12px" data-uri="<?= e(totp_uri($secNuevo, (string) $u['email'])) ?>"></div>
                        <div class="small"><?= et('¿No puedes escanear? Escribe esta clave en la app:') ?><br><code class="fs-6"><?= e(trim(chunk_split($secNuevo, 4, ' '))) ?></code></div>
                    </div>
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="confirmar_2fa">
                        <div class="col-sm-5"><input name="codigo" class="form-control form-control-lg text-center" inputmode="numeric" maxlength="6" required autofocus placeholder="123456" autocomplete="one-time-code"></div>
                        <div class="col-sm-7"><button class="btn btn-primary btn-lg"><?= et('Activar') ?></button></div>
                    </form>
                    <script>
                    (function () { var el = document.getElementById('qr2fa'); if (!window.qrcode) return;
                        var q = qrcode(0, 'M'); q.addData(el.dataset.uri); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true }); })();
                    </script>
                <?php else: ?>
                    <p class="small"><?= et('Además de tu contraseña, te pediremos un código que cambia cada 30 segundos en tu celular. Es la forma más efectiva de evitar que alguien entre con una contraseña robada.') ?></p>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="iniciar_2fa">
                        <button class="btn btn-primary"><i class="bi bi-shield-plus"></i> <?= et('Activar verificación en dos pasos') ?></button></form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (($u['rol'] ?? '') === 'admin'): ?>
        <div class="card mb-4"><div class="card-header"><i class="bi bi-people"></i> <?= et('Para todo el consultorio') ?></div><div class="card-body">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="exigir">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ex2" name="exigir_2fa" value="1" onchange="this.form.submit()" <?= cfg('exigir_2fa') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="ex2"><?= et('Exigir verificación en dos pasos a todo el personal') ?></label>
                </div>
                <div class="small text-muted mt-1"><?= et('Quien no la tenga activa deberá configurarla antes de poder usar el sistema.') ?></div>
            </form>
            <?php if ($sinDos): ?><div class="small mt-2"><?= et('Aún sin activarla:') ?> <?= e(implode(', ', $sinDos)) ?></div><?php endif; ?>
        </div></div>
        <?php endif; ?>

        <div class="card"><div class="card-header"><i class="bi bi-key"></i> <?= et('Cambiar contraseña') ?></div><div class="card-body">
            <form method="post" class="row g-2" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="password">
                <div class="col-md-4"><input type="password" name="actual" class="form-control" placeholder="<?= e(t('Actual')) ?>" required autocomplete="current-password"></div>
                <div class="col-md-4"><input type="password" name="nueva" class="form-control" placeholder="<?= e(t('Nueva (10+, letras y números)')) ?>" required minlength="10" autocomplete="new-password"></div>
                <div class="col-md-4"><input type="password" name="nueva2" class="form-control" placeholder="<?= e(t('Repite la nueva')) ?>" required minlength="10" autocomplete="new-password"></div>
                <div class="col-12"><button class="btn btn-outline-primary"><?= et('Actualizar contraseña') ?></button></div>
            </form>
        </div></div>
    </div>

    <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> <?= et('Mis últimos accesos') ?></div>
        <ul class="list-group list-group-flush small">
            <?php if (!$accesos): ?><li class="list-group-item text-muted"><?= et('Sin registros.') ?></li><?php endif; ?>
            <?php foreach ($accesos as $a):
                [$ic, $col, $txt] = ['login' => ['bi-box-arrow-in-right', 'success', t('Entraste')], 'logout' => ['bi-box-arrow-right', 'secondary', t('Cerraste sesión')],
                    'login_fallido' => ['bi-x-octagon', 'danger', t('Contraseña incorrecta')], '2fa_fallido' => ['bi-x-octagon', 'danger', t('Código 2FA incorrecto')],
                    'sesion_expirada' => ['bi-hourglass-bottom', 'secondary', t('Sesión cerrada por inactividad')]][$a['accion']] ?? ['bi-dot', 'secondary', $a['accion']]; ?>
            <li class="list-group-item d-flex gap-2"><i class="bi <?= $ic ?> text-<?= $col ?>"></i>
                <div><?= e($txt) ?> · <?= e(fmt_fecha($a['creado_en'])) ?> <?= e(date('H:i', strtotime($a['creado_en']))) ?>
                    <div class="text-muted text-truncate" style="max-width:300px" title="<?= e($a['user_agent'] ?? '') ?>"><?= e($a['ip'] ?? '') ?></div></div></li>
            <?php endforeach; ?>
        </ul>
        <div class="card-body small text-muted"><?= et('¿Ves un acceso que no reconoces? Cambia tu contraseña y activa la verificación en dos pasos.') ?></div>
    </div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
