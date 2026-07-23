<?php
/**
 * Registro de SOCIOS de la plataforma. Cualquiera puede crear una cuenta, pero
 * queda INACTIVA (activo=0) hasta que el dueño la aprueba y le asigna qué
 * consultorios puede ver. Sin aprobación no ve nada, así el auto-registro es
 * seguro. El dueño (súper) NO se registra aquí: se crea en el setup del login.
 */
require_once __DIR__ . '/../includes/functions.php';
ensure_plataforma_admins_table();

if (platform_admin()) redirect('/platform/index');

$pdo = db();
// Si la plataforma aún no tiene dueño, primero hay que crearlo (setup del login).
if ((int) $pdo->query("SELECT COUNT(*) FROM plataforma_admins")->fetchColumn() === 0) {
    redirect('/platform/login');
}

$error = '';
$nombre = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $nombre = trim($_POST['nombre'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $pass2  = $_POST['password2'] ?? '';

    if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t('Escribe tu nombre y un correo válido.');
    } elseif (strlen($pass) < 8) {
        $error = t('La contraseña debe tener al menos 8 caracteres.');
    } elseif ($pass !== $pass2) {
        $error = t('Las contraseñas no coinciden.');
    } else {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM plataforma_admins WHERE email = ?");
        $dup->execute([$email]);
        if ((int) $dup->fetchColumn() > 0) {
            $error = t('Ya existe una cuenta con ese correo.');
        } else {
            // rol socio, activo=0: pendiente de aprobación del dueño.
            $ins = $pdo->prepare(
                "INSERT INTO plataforma_admins (nombre, email, password_hash, rol, activo) VALUES (?,?,?, 'socio', 0)"
            );
            $ins->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            auditar('plataforma_socio_registro', 'plataforma_admin', (int) $pdo->lastInsertId(), $email);
            redirect('/platform/login?registrado=1');
        }
    }
}
?>
<!doctype html>
<html lang="es" class="app-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Registro de socio') ?> · <?= e(APP_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Mulish:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        html.app-dark { --brand:#2563eb; --brand-dark:#1e40af; }
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem; font-family:'Inter',sans-serif; }
        .plat-card { max-width:440px; width:100%; }
        .plat-badge { font-size:.6rem; letter-spacing:1.5px; font-weight:800; padding:.15rem .5rem; border-radius:6px; background:rgba(37,99,235,.18); color:#93c5fd; }
    </style>
</head>
<body>
<div class="card plat-card">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <div class="display-6" style="color:#2563eb"><i class="bi bi-person-badge-fill"></i></div>
            <h1 class="h4 mt-2 mb-1"><?= e(APP_NAME) ?> <span class="plat-badge">SOCIO</span></h1>
            <p class="text-muted small mb-0"><?= et('Crea tu cuenta de socio. El dueño la aprobará y te asignará qué clientes puedes ver.') ?></p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label"><?= et('Nombre') ?></label>
                <input type="text" name="nombre" class="form-control" required autofocus value="<?= e($nombre) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label"><?= et('Correo') ?></label>
                <input type="email" name="email" class="form-control" required value="<?= e($email) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label"><?= et('Contraseña') ?></label>
                <div class="input-group">
                    <input type="password" name="password" class="form-control" required minlength="8">
                    <button class="btn btn-outline-secondary toggle-pass" type="button" tabindex="-1" aria-label="<?= e(t('Mostrar u ocultar contraseña')) ?>"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label"><?= et('Repite la contraseña') ?></label>
                <div class="input-group">
                    <input type="password" name="password2" class="form-control" required minlength="8">
                    <button class="btn btn-outline-secondary toggle-pass" type="button" tabindex="-1" aria-label="<?= e(t('Mostrar u ocultar contraseña')) ?>"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button class="btn btn-primary w-100"><i class="bi bi-person-plus"></i> <?= et('Crear mi cuenta') ?></button>
        </form>

        <div class="text-center mt-4 small">
            <?= et('¿Ya tienes cuenta?') ?>
            <a href="<?= BASE_URL ?>/platform/login" class="text-decoration-none fw-semibold"><?= et('Inicia sesión') ?></a>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.toggle-pass').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var inp = btn.closest('.input-group').querySelector('input');
        var ic  = btn.querySelector('i');
        var oculto = inp.getAttribute('type') === 'password';
        inp.setAttribute('type', oculto ? 'text' : 'password');
        ic.className = oculto ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
});
</script>
</body>
</html>
