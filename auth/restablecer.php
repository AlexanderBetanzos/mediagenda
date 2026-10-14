<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth_reset.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$u = reset_usuario($token);
$error = '';

if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p1 = (string) ($_POST['password'] ?? '');
    $p2 = (string) ($_POST['password2'] ?? '');
    if (mb_strlen($p1) < 8) $error = t('La contraseña debe tener al menos 8 caracteres.');
    elseif ($p1 !== $p2)   $error = t('Las contraseñas no coinciden.');
    else {
        $pdo = db();
        $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')->execute([password_hash($p1, PASSWORD_DEFAULT), (int) $u['id']]);
        // El enlace usado y cualquier otro pendiente de esa cuenta dejan de servir.
        $pdo->prepare('UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')->execute([(int) $u['id']]);
        auditar('reset_hecho', 'usuario', (int) $u['id'], null, (int) $u['consultorio_id'], ['id' => (int) $u['id'], 'nombre' => $u['nombre']]);
        flash(t('Listo: ya puedes entrar con tu nueva contraseña.'));
        redirect('/auth/login');
    }
}

auth_pagina(t('Nueva contraseña'), 'recuperar', function () use ($u, $token, $error) { ?>
    <?php if (!$u): ?>
        <div class="text-center">
            <i class="bi bi-link-45deg fs-1 text-muted"></i>
            <h1 class="h5 mt-2"><?= et('Este enlace ya no sirve') ?></h1>
            <p class="text-muted small"><?= et('Puede que haya vencido (dura 1 hora) o que ya se haya usado. Pide uno nuevo.') ?></p>
            <a href="<?= BASE_URL ?>/auth/recuperar" class="btn btn-primary"><?= et('Pedir otro enlace') ?></a>
        </div>
    <?php else: ?>
        <div class="text-center mb-4">
            <h1 class="h4 mb-1"><?= et('Elige tu nueva contraseña') ?></h1>
            <p class="text-muted small mb-0"><?= e($u['email']) ?></p>
        </div>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
            <label class="form-label"><?= et('Nueva contraseña') ?></label>
            <input type="password" name="password" class="form-control mb-1" minlength="8" required autofocus autocomplete="new-password">
            <div class="form-text mb-3"><?= et('Mínimo 8 caracteres.') ?></div>
            <label class="form-label"><?= et('Repítela') ?></label>
            <input type="password" name="password2" class="form-control mb-4" minlength="8" required autocomplete="new-password">
            <button class="btn btn-primary w-100 py-2"><i class="bi bi-check2"></i> <?= et('Guardar contraseña') ?></button>
        </form>
    <?php endif; ?>
<?php });
