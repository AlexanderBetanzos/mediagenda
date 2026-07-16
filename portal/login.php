<?php
/**
 * Login del portal del paciente. Sesión separada de la del personal
 * ($_SESSION['paciente']).
 *
 * Va dividido por consultorio con ?c=<slug> (igual que el micrositio y GymOS
 * con ?t=): cuando llega el slug, la página toma la MARCA de esa clínica y el
 * login se limita a sus pacientes. Sin slug, es el portal genérico del producto
 * y el correo se valida contra cada consultorio donde exista (puede repetirse).
 */
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['paciente'])) { redirect('/portal/index'); }

/* Slug de la clínica: por GET al entrar, por POST al enviar el formulario. */
$slug = (string) ($_GET['c'] ?? $_GET['t'] ?? $_POST['c'] ?? '');
$clin = $slug !== '' ? consultorio_publico($slug) : null;
if ($clin) {
    // Marca/color/logo de ESA clínica (white-label) en toda la página.
    tenant_forzar((int) $clin['id']);
    $slug = $clin['slug'];
}

$error = '';
$aviso = isset($_GET['inactivo'])
    ? 'El portal no está disponible por ahora. Pregunta en tu consultorio.'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    // Con clínica fija, el login se limita a sus pacientes; sin ella, a todos.
    $sql = 'SELECT * FROM pacientes WHERE email = ? AND portal_activo = 1';
    $par = [$email];
    if ($clin) { $sql .= ' AND consultorio_id = ?'; $par[] = (int) $clin['id']; }

    $st = db()->prepare($sql);
    $st->execute($par);
    $encontrado = null;
    foreach ($st->fetchAll() as $p) {
        // El portal es un módulo de plan: si el consultorio ya no lo incluye,
        // ese paciente no entra aunque su acceso siga marcado como activo.
        if (!modulo_activo_en((int) $p['consultorio_id'], 'portal')) continue;
        if ($p['portal_password_hash'] && password_verify($pass, $p['portal_password_hash'])) {
            $encontrado = $p; break;
        }
    }

    if ($encontrado) {
        session_regenerate_id(true);
        $_SESSION['paciente'] = [
            'id'             => (int) $encontrado['id'],
            'nombre'         => $encontrado['nombre'],
            'apellidos'      => $encontrado['apellidos'],
            'email'          => $encontrado['email'],
            'consultorio_id' => (int) $encontrado['consultorio_id'],
        ];
        auditar('portal_login', 'paciente', (int) $encontrado['id'], null,
                (int) $encontrado['consultorio_id'], ['nombre' => $encontrado['nombre'] . ' ' . $encontrado['apellidos']]);
        redirect('/portal/index');
    }
    $error = 'Correo o contraseña incorrectos.';
}

/* Marca a mostrar: la de la clínica si vino por slug, si no la del producto. */
$marca  = $clin ? marca_nombre() : 'Portal del paciente';
$acento = color_acento();
$logo   = $clin ? cfg('marca_logo') : '';
$volver = $clin ? BASE_URL . '/c/' . rawurlencode($slug) : BASE_URL . '/';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($marca) ?> · Portal del paciente</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>:root { --brand: <?= $acento ?>; --brand-dark: color-mix(in srgb, <?= $acento ?> 78%, #000); }</style>
</head>
<body>
<div class="login-wrap">
    <div class="card shadow login-card">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <?php if ($logo): ?>
                    <img src="<?= e($logo) ?>" alt="<?= e($marca) ?>" style="max-height:56px;width:auto">
                <?php else: ?>
                    <div class="display-5 text-brand"><i class="bi bi-person-heart"></i></div>
                <?php endif; ?>
                <h1 class="h4 mt-3 mb-0"><?= e($marca) ?></h1>
                <p class="text-muted small mb-0"><?= $clin ? 'Portal del paciente · consulta tus citas, recetas y estudios.' : 'Consulta tus citas, recetas y estudios.' ?></p>
            </div>

            <?php if ($aviso): ?><div class="alert alert-warning py-2"><?= e($aviso) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <?php if ($clin): ?><input type="hidden" name="c" value="<?= e($slug) ?>"><?php endif; ?>
                <div class="mb-3">
                    <label class="form-label">Correo electrónico</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required>
                        <button class="btn btn-outline-secondary toggle-pass" type="button" tabindex="-1" aria-label="Mostrar u ocultar contraseña"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button class="btn btn-primary w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
            </form>
            <div class="text-center mt-4 small">
                <a href="<?= e($volver) ?>" class="text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> <?= $clin ? 'Volver al sitio' : 'Volver al inicio' ?></a>
            </div>
            <div class="text-center mt-2 small text-muted">
                ¿No tienes acceso? Pídelo en tu consultorio.
            </div>
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
