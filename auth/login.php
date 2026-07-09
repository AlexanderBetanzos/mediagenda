<?php
require_once __DIR__ . '/../includes/functions.php';

// Si ya hay sesión, al panel.
if (is_logged_in()) {
    redirect('/dashboard');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $stmt = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        $datos = [
            'id'             => (int) $user['id'],
            'nombre'         => $user['nombre'],
            'email'          => $user['email'],
            'rol'            => $user['rol'],
            'consultorio_id' => (int) $user['consultorio_id'],
            'es_superadmin'  => (int) ($user['es_superadmin'] ?? 0),
        ];

        // Regenerar id de sesión para evitar fijación.
        session_regenerate_id(true);
        $_SESSION['usuario'] = $datos;
        auditar('login', null, null, null, $datos['consultorio_id'], $datos);
        flash('¡Bienvenido(a), ' . $user['nombre'] . '!');
        redirect('/dashboard');
    }
    auditar('login_fallido', null, null, 'email: ' . mb_substr($email, 0, 120),
            $user ? (int) $user['consultorio_id'] : null,
            $user ? ['id' => (int) $user['id'], 'nombre' => $user['nombre']] : ['nombre' => $email]);
    $error = t('Correo o contraseña incorrectos.');
}
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Iniciar sesión') ?> · <?= e(marca_nombre()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
                <div class="display-5 text-brand"><i class="bi bi-heart-pulse-fill"></i></div>
                <h1 class="h4 mt-2 mb-0"><?= e(marca_nombre()) ?></h1>
                <p class="text-muted small"><?= e(cfg('marca_lema', 'Sistema de gestión médica y dental')) ?></p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label"><?= et('Correo electrónico') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" required autofocus
                               value="<?= e($_POST['email'] ?? '') ?>" placeholder="correo@consultorio.com">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label"><?= et('Contraseña') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required placeholder="••••••••">
                        <button class="btn btn-outline-secondary toggle-pass" type="button" tabindex="-1" aria-label="Mostrar u ocultar contraseña"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button class="btn btn-primary w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> <?= et('Entrar') ?></button>
            </form>

            <div class="text-center mt-4 pt-3 border-top">
                <p class="mb-2 small text-muted"><?= et('¿Aún no tienes cuenta?') ?></p>
                <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-outline-primary w-100">
                    <i class="bi bi-rocket-takeoff"></i> <?= et('Crear consultorio — 15 días gratis') ?>
                </a>
            </div>
            <div class="text-center mt-3">
                <a href="<?= BASE_URL ?>/portal/login" class="small text-decoration-none"><i class="bi bi-person-heart"></i> <?= et('¿Eres paciente? Entra al portal') ?></a>
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>/index" class="small text-muted">&larr; <?= et('Volver al sitio') ?></a>
                    <span class="text-muted small mx-1">·</span>
                    <a href="#" class="small text-muted text-decoration-none" onclick="setLang('<?= idioma_actual() === 'en' ? 'es' : 'en' ?>');return false"><?= idioma_actual() === 'en' ? 'Español' : 'English' ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function setLang(l){ document.cookie = 'lang=' + l + ';path=<?= BASE_URL ?>/;max-age=31536000;samesite=Lax'; location.reload(); }
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
