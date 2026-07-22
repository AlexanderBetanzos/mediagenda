<?php
/**
 * Auto-registro del paciente en el portal desde el correo de confirmación.
 *
 * Llega con ?t=<portal_token> (enlace de un solo uso que se generó al agendar
 * en línea). El paciente elige su contraseña, se activa su acceso, se limpia el
 * token y queda con sesión iniciada viendo sus citas. Sesión separada de la del
 * personal ($_SESSION['paciente']), igual que /portal/login.
 */
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['paciente'])) { redirect('/portal/index'); }

/* Localiza al paciente por su token. Sin token válido, no hay nada que activar. */
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$pac   = null;
if ($token !== '') {
    $st = db()->prepare('SELECT * FROM pacientes WHERE portal_token = ? LIMIT 1');
    $st->execute([$token]);
    $pac = $st->fetch() ?: null;
}

/* Marca/color de la clínica del paciente (white-label) en toda la página. */
$clin = null;
if ($pac) {
    tenant_forzar((int) $pac['consultorio_id']);
    $cst = db()->prepare('SELECT id, slug FROM consultorios WHERE id = ?');
    $cst->execute([(int) $pac['consultorio_id']]);
    $clin = $cst->fetch() ?: null;
}

$error = '';
$ok    = false;

/* El portal es un módulo de plan: si la clínica ya no lo incluye, no se activa. */
$portalDisponible = $pac && modulo_activo_en((int) $pac['consultorio_id'], 'portal');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pac) {
    verify_csrf();
    $pass  = (string) ($_POST['password'] ?? '');
    $pass2 = (string) ($_POST['password2'] ?? '');

    if (!$portalDisponible) {
        $error = 'El portal no está disponible por ahora. Pregunta en tu consultorio.';
    } elseif (mb_strlen($pass) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    } else {
        db()->prepare(
            'UPDATE pacientes SET portal_password_hash = ?, portal_activo = 1, portal_token = NULL
             WHERE id = ? AND consultorio_id = ?'
        )->execute([password_hash($pass, PASSWORD_DEFAULT), (int) $pac['id'], (int) $pac['consultorio_id']]);

        auditar('portal_autoregistro', 'paciente', (int) $pac['id'], null,
                (int) $pac['consultorio_id'], ['nombre' => $pac['nombre'] . ' ' . $pac['apellidos']]);

        // Queda con sesión iniciada: va directo a ver sus citas.
        session_regenerate_id(true);
        $_SESSION['paciente'] = [
            'id'             => (int) $pac['id'],
            'nombre'         => $pac['nombre'],
            'apellidos'      => $pac['apellidos'],
            'email'          => $pac['email'],
            'consultorio_id' => (int) $pac['consultorio_id'],
        ];
        redirect('/portal/index');
    }
}

$marca  = $pac ? marca_nombre() : 'Portal del paciente';
$acento = color_acento();
$logo   = $pac ? cfg('marca_logo') : '';
$slug   = $clin['slug'] ?? '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($marca) ?> · <?= et('Crear acceso al portal') ?></title>
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
                <p class="text-muted small mb-0"><?= et('Crea tu acceso para ver tus citas, recetas y estudios.') ?></p>
            </div>

            <?php if (!$pac): ?>
                <div class="alert alert-warning py-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= et('Este enlace no es válido o ya se usó. Si ya creaste tu acceso, inicia sesión.') ?>
                </div>
                <a href="<?= BASE_URL ?>/portal/login" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-box-arrow-in-right"></i> <?= et('Ir a iniciar sesión') ?>
                </a>
            <?php elseif (!$portalDisponible): ?>
                <div class="alert alert-warning py-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= et('El portal no está disponible por ahora. Pregunta en tu consultorio.') ?>
                </div>
            <?php else: ?>
                <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

                <div class="mb-3 small text-muted">
                    <?= et('Tu acceso quedará ligado a') ?> <strong><?= e($pac['email']) ?></strong>.
                </div>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="t" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= et('Elige una contraseña') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" class="form-control" required minlength="6" autofocus
                                   placeholder="<?= e(t('Mínimo 6 caracteres')) ?>">
                            <button class="btn btn-outline-secondary toggle-pass" type="button" tabindex="-1" aria-label="<?= e(t('Mostrar u ocultar contraseña')) ?>"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"><?= et('Repite la contraseña') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" name="password2" class="form-control" required minlength="6">
                        </div>
                    </div>
                    <button class="btn btn-primary w-100 py-2"><i class="bi bi-check-lg"></i> <?= et('Crear mi acceso') ?></button>
                </form>
                <div class="text-center mt-3 small">
                    <a href="<?= BASE_URL ?>/portal/login<?= $slug ? '?c=' . rawurlencode($slug) : '' ?>" class="text-muted text-decoration-none">
                        <?= et('¿Ya tienes acceso? Inicia sesión') ?>
                    </a>
                </div>
            <?php endif; ?>
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
