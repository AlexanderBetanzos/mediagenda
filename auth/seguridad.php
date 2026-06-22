<?php
/**
 * Seguridad de la cuenta del usuario en sesión: activar/desactivar 2FA (TOTP).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';
require_login();

$u = current_user();
// Estado fresco desde la BD (la sesión no guarda el secreto).
$row = db()->prepare('SELECT password_hash, twofa_activo FROM usuarios WHERE id = ?');
$row->execute([$u['id']]);
$row = $row->fetch();
$tiene2fa = !empty($row['twofa_activo']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'iniciar' && !$tiene2fa) {
        // Genera un secreto pendiente de confirmar.
        $_SESSION['2fa_setup'] = totp_secret();
        redirect('/auth/seguridad');
    }

    if ($accion === 'confirmar' && !$tiene2fa) {
        $secret = $_SESSION['2fa_setup'] ?? '';
        $code   = $_POST['code'] ?? '';
        if ($secret && totp_verify($secret, $code)) {
            db()->prepare('UPDATE usuarios SET twofa_secret = ?, twofa_activo = 1 WHERE id = ?')
                ->execute([$secret, $u['id']]);
            unset($_SESSION['2fa_setup']);
            auditar('2fa_activar');
            flash('Verificación en dos pasos activada.');
            redirect('/auth/seguridad');
        }
        flash('El código no coincide. Escanea el QR e inténtalo otra vez.', 'danger');
        redirect('/auth/seguridad');
    }

    if ($accion === 'desactivar' && $tiene2fa) {
        // Para desactivar, confirma la contraseña de la cuenta.
        if (password_verify($_POST['password'] ?? '', $row['password_hash'])) {
            db()->prepare('UPDATE usuarios SET twofa_secret = NULL, twofa_activo = 0 WHERE id = ?')
                ->execute([$u['id']]);
            auditar('2fa_desactivar');
            flash('Verificación en dos pasos desactivada.');
        } else {
            flash('Contraseña incorrecta. No se desactivó el 2FA.', 'danger');
        }
        redirect('/auth/seguridad');
    }
}

$setup = !$tiene2fa ? ($_SESSION['2fa_setup'] ?? '') : '';
$uri   = $setup ? totp_uri($setup, $u['email'], marca_nombre()) : '';

$titulo = t('Seguridad');
$activo = '';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-shield-lock text-brand"></i> <?= et('Seguridad de la cuenta') ?></h1>
<p class="text-muted"><?= et('Protege tu acceso con verificación en dos pasos (2FA).') ?></p>

<div class="row">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-phone"></i> <?= et('Verificación en dos pasos') ?></span>
        <?php if ($tiene2fa): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?= et('Activa') ?></span>
        <?php else: ?>
            <span class="badge bg-secondary"><?= et('Inactiva') ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
      <?php if ($tiene2fa): ?>
        <p><?= et('Tu cuenta pide un código de tu app de autenticación al iniciar sesión.') ?></p>
        <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('¿Desactivar la verificación en dos pasos?');">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="desactivar">
            <div class="col-sm-7">
                <label class="form-label"><?= et('Confirma tu contraseña para desactivar') ?></label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-sm-5">
                <button class="btn btn-outline-danger"><i class="bi bi-shield-slash"></i> <?= et('Desactivar 2FA') ?></button>
            </div>
        </form>

      <?php elseif (!$setup): ?>
        <p class="text-muted"><?= et('Usa Google Authenticator, Authy o Microsoft Authenticator. Es gratis y funciona sin internet.') ?></p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="iniciar">
            <button class="btn btn-primary"><i class="bi bi-shield-plus"></i> <?= et('Activar 2FA') ?></button>
        </form>

      <?php else: ?>
        <ol class="mb-3">
            <li><?= et('Abre tu app de autenticación y escanea este código QR.') ?></li>
            <li><?= et('Escribe el código de 6 dígitos que aparezca para confirmar.') ?></li>
        </ol>
        <div class="text-center mb-3">
            <div id="qr" class="d-inline-block p-2 bg-white border rounded"></div>
            <div class="small text-muted mt-2"><?= et('¿No puedes escanear? Clave manual:') ?>
                <code><?= e(chunk_split($setup, 4, ' ')) ?></code>
            </div>
        </div>
        <form method="post" class="row g-2 justify-content-center">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="confirmar">
            <div class="col-auto">
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required
                       class="form-control text-center" style="letter-spacing:.4em" placeholder="000000">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> <?= et('Confirmar y activar') ?></button>
            </div>
        </form>
        <script src="https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs/qrcode.min.js"></script>
        <script>new QRCode(document.getElementById('qr'), { text: <?= json_encode($uri) ?>, width: 180, height: 180 });</script>
      <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
