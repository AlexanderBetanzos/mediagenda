<?php
/**
 * Recuperación de contraseña del personal: enlace de un solo uso que vence
 * en 1 hora. Se guarda solo el hash del token (si alguien lee la base, no
 * puede usar los enlaces vigentes).
 */
function asegurar_resets(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expira DATETIME NOT NULL,
        usado_en DATETIME NULL,
        creado_en DATETIME NOT NULL,
        ip VARCHAR(45) NULL,
        UNIQUE KEY uq_pr (token_hash),
        INDEX idx_pr_u (usuario_id, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Usuario vigente de un token (o null si no existe, ya se usó o venció). */
function reset_usuario(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    asegurar_resets();
    $st = db()->prepare('SELECT r.id reset_id, u.* FROM password_resets r JOIN usuarios u ON u.id = r.usuario_id
                         WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira > NOW() AND u.activo = 1');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

/** Página de acceso con el panel de marca (comparte estilos con el login). */
function auth_pagina(string $titulo, string $modo, callable $cuerpo): void
{ ?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($titulo) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth-split">
<?php $authModo = $modo; include __DIR__ . '/auth_lado.php'; ?>
<div class="auth-main">
    <div class="card shadow login-card"><div class="card-body p-4 p-sm-5"><?php $cuerpo(); ?></div></div>
</div>
</div>
</body>
</html>
<?php }
