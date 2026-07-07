<?php
/**
 * Acceso a la PLATAFORMA (consola del dueño) — login independiente del panel
 * del consultorio. La primera vez permite crear el primer súper usuario, y ese
 * arranque solo lo puede hacer quien ya es súper-admin del sistema (seguridad).
 */
require_once __DIR__ . '/../includes/functions.php';
ensure_plataforma_admins_table();

if (platform_admin()) redirect('/platform/index');

$pdo      = db();
$hayAdmin = (int) $pdo->query("SELECT COUNT(*) FROM plataforma_admins")->fetchColumn() > 0;
$modo     = $hayAdmin ? 'login' : 'setup';
$error    = '';
$ok       = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($modo === 'setup') {
        if ($hayAdmin) redirect('/platform/login');
        if (!es_superadmin()) {
            $error = t('Para crear el primer súper usuario debes iniciar sesión como súper-admin del sistema.');
        } else {
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
                $ins = $pdo->prepare("INSERT INTO plataforma_admins (nombre, email, password_hash) VALUES (?,?,?)");
                $ins->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $_SESSION['plataforma_admin'] = ['id' => (int) $pdo->lastInsertId(), 'nombre' => $nombre, 'email' => $email];
                auditar('plataforma_setup', 'plataforma_admin', (int) $_SESSION['plataforma_admin']['id'], $email);
                redirect('/platform/index');
            }
        }
    } else { // login
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $st = $pdo->prepare("SELECT * FROM plataforma_admins WHERE email = ? AND activo = 1 LIMIT 1");
        $st->execute([$email]);
        $a = $st->fetch();
        if ($a && password_verify($pass, $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['plataforma_admin'] = ['id' => (int) $a['id'], 'nombre' => $a['nombre'], 'email' => $a['email']];
            $pdo->prepare("UPDATE plataforma_admins SET ultimo_acceso = NOW() WHERE id = ?")->execute([(int) $a['id']]);
            auditar('plataforma_login', 'plataforma_admin', (int) $a['id'], $email);
            redirect('/platform/index');
        }
        $error = t('Correo o contraseña incorrectos.');
    }
}
?>
<!doctype html>
<html lang="es" class="app-dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Acceso a plataforma') ?> · <?= e(APP_NAME) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Mulish:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        html.app-dark { --brand:#f66f14; --brand-dark:#d9600f; }
        body { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:1.5rem; font-family:'Inter',sans-serif; }
        .plat-card { max-width:440px; width:100%; }
        .plat-badge { font-size:.6rem; letter-spacing:1.5px; font-weight:800; padding:.15rem .5rem; border-radius:6px; background:rgba(246,111,20,.18); color:#ffb066; }
    </style>
</head>
<body>
<div class="card plat-card">
    <div class="card-body p-4 p-sm-5">
        <div class="text-center mb-4">
            <div class="display-6" style="color:#f66f14"><i class="bi bi-diagram-3-fill"></i></div>
            <h1 class="h4 mt-2 mb-1"><?= e(APP_NAME) ?> <span class="plat-badge">PLATAFORMA</span></h1>
            <p class="text-muted small mb-0"><?= $modo === 'setup' ? et('Crea el primer súper usuario de la plataforma.') : et('Acceso exclusivo del dueño del sistema.') ?></p>
        </div>

        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

        <?php if ($modo === 'setup' && !es_superadmin()): ?>
            <div class="alert alert-warning py-2 small">
                <?= et('La plataforma aún no tiene súper usuario. Inicia sesión en el sistema como súper-admin y vuelve a esta página para crearlo.') ?>
            </div>
            <a href="<?= BASE_URL ?>/auth/login" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> <?= et('Iniciar sesión en el sistema') ?></a>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <?php if ($modo === 'setup'): ?>
                <div class="mb-3">
                    <label class="form-label"><?= et('Nombre') ?></label>
                    <input type="text" name="nombre" class="form-control" required autofocus>
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label"><?= et('Correo') ?></label>
                <input type="email" name="email" class="form-control" required <?= $modo === 'login' ? 'autofocus' : '' ?>>
            </div>
            <div class="mb-3">
                <label class="form-label"><?= et('Contraseña') ?></label>
                <input type="password" name="password" class="form-control" required minlength="8">
            </div>
            <?php if ($modo === 'setup'): ?>
                <div class="mb-3">
                    <label class="form-label"><?= et('Repite la contraseña') ?></label>
                    <input type="password" name="password2" class="form-control" required minlength="8">
                </div>
            <?php endif; ?>
            <button class="btn btn-primary w-100">
                <?php if ($modo === 'setup'): ?><i class="bi bi-person-plus"></i> <?= et('Crear súper usuario') ?>
                <?php else: ?><i class="bi bi-box-arrow-in-right"></i> <?= et('Entrar') ?><?php endif; ?>
            </button>
        </form>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="<?= BASE_URL ?>/index" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> <?= et('Volver al sitio') ?></a>
        </div>
    </div>
</div>
</body>
</html>
