<?php
/**
 * Reto de doble factor (TOTP). Se llega aquí tras validar la contraseña
 * cuando el usuario tiene 2FA activo; la sesión queda "a medias" en pre_2fa.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';

if (is_logged_in()) { redirect('/dashboard'); }

$pre = $_SESSION['pre_2fa'] ?? null;
if (!$pre) { redirect('/auth/login'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['cancelar'] ?? '') === '1') {
        unset($_SESSION['pre_2fa']);
        redirect('/auth/login');
    }
    $code = preg_replace('/\D/', '', $_POST['code'] ?? '');
    if (totp_verify($pre['twofa_secret'], $code)) {
        $datos = $pre; unset($datos['twofa_secret']);
        session_regenerate_id(true);
        $_SESSION['usuario'] = $datos;
        unset($_SESSION['pre_2fa']);
        auditar('login', null, null, '2FA', $datos['consultorio_id'], $datos);
        flash('¡Bienvenido(a), ' . $datos['nombre'] . '!');
        redirect('/dashboard');
    }
    auditar('2fa_fallido', null, null, null, (int) $pre['consultorio_id'],
            ['id' => (int) $pre['id'], 'nombre' => $pre['nombre']]);
    $error = 'Código incorrecto. Revisa tu app de autenticación e inténtalo de nuevo.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación en dos pasos · <?= e(marca_nombre()) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="login-wrap">
    <div class="card shadow login-card">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <div class="display-5 text-brand"><i class="bi bi-shield-lock-fill"></i></div>
                <h1 class="h4 mt-2 mb-0">Verificación en dos pasos</h1>
                <p class="text-muted small">Ingresa el código de 6 dígitos de tu app de autenticación.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="mb-4">
                    <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                           pattern="[0-9]*" maxlength="6" required autofocus
                           class="form-control form-control-lg text-center"
                           style="letter-spacing:.5em;font-size:1.6rem" placeholder="000000">
                </div>
                <button class="btn btn-primary w-100 py-2"><i class="bi bi-check2-circle"></i> Verificar</button>
            </form>

            <form method="post" class="text-center mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="cancelar" value="1">
                <button class="btn btn-link btn-sm text-muted">Cancelar e iniciar sesión con otra cuenta</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
