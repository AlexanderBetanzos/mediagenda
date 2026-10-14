<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/correo.php';
require_once __DIR__ . '/../includes/auth_reset.php';

if (is_logged_in()) redirect('/dashboard');

$enviado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $enviado = true;   // la respuesta es la misma exista o no la cuenta
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            asegurar_resets();
            $st = db()->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 AND password_hash IS NOT NULL AND password_hash <> '' LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch();
            // Freno: máximo 3 enlaces por cuenta cada hora.
            $n = 0;
            if ($u) {
                $q = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE usuario_id = ? AND creado_en > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $q->execute([(int) $u['id']]);
                $n = (int) $q->fetchColumn();
            }
            if ($u && $n < 3) {
                $token = bin2hex(random_bytes(32));
                db()->prepare('INSERT INTO password_resets (usuario_id, token_hash, expira, creado_en, ip) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 1 HOUR),NOW(),?)')
                    ->execute([(int) $u['id'], hash('sha256', $token), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
                $url = correo_url('/auth/restablecer?t=' . $token);
                $cuerpo = 'Hola <strong>' . e($u['nombre']) . '</strong>,<br><br>'
                    . 'Recibimos una solicitud para cambiar la contraseña de tu cuenta. El enlace sirve <strong>una sola vez</strong> y vence en <strong>1 hora</strong>.<br><br>'
                    . 'Si no fuiste tú, ignora este correo: tu contraseña sigue igual.';
                enviar_correo($u['email'], 'Cambia tu contraseña', correo_layout('Cambia tu contraseña', $cuerpo, 'Elegir nueva contraseña', $url, 'Seguridad'));
                auditar('reset_solicitado', 'usuario', (int) $u['id'], null, (int) $u['consultorio_id'], ['id' => (int) $u['id'], 'nombre' => $u['nombre']]);
            }
        } catch (Throwable $e) { /* sin tabla o sin correo: misma respuesta */ }
    }
}

auth_pagina(t('Recuperar contraseña'), 'recuperar', function () use ($enviado) { ?>
    <div class="text-center mb-4">
        <span class="d-inline-grid rounded-circle mb-2" style="width:56px;height:56px;place-items:center;background:var(--brand-light);color:var(--brand)"><i class="bi bi-key fs-4"></i></span>
        <h1 class="h4 mb-1"><?= et('¿Olvidaste tu contraseña?') ?></h1>
        <p class="text-muted small mb-0"><?= et('Escribe el correo con el que entras y te mandamos un enlace para elegir una nueva.') ?></p>
    </div>
    <?php if ($enviado): ?>
        <div class="alert alert-success small"><i class="bi bi-envelope-check"></i> <?= et('Si ese correo tiene una cuenta, en unos minutos te llega el enlace. Revisa también la carpeta de spam.') ?></div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label class="form-label"><?= et('Correo electrónico') ?></label>
        <div class="input-group mb-3"><span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" name="email" class="form-control" required autofocus value="<?= e($_POST['email'] ?? '') ?>"></div>
        <button class="btn btn-primary w-100 py-2"><i class="bi bi-send"></i> <?= et('Enviar enlace') ?></button>
    </form>
    <div class="text-center mt-4"><a href="<?= BASE_URL ?>/auth/login" class="small text-decoration-none">&larr; <?= et('Volver a iniciar sesión') ?></a></div>
<?php });
