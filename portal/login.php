<?php
/**
 * Login del portal del paciente. Sesión separada de la del personal
 * ($_SESSION['paciente']). El correo puede no ser único entre consultorios:
 * se valida la contraseña contra cada coincidencia con portal activo.
 */
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['paciente'])) { redirect('/portal/index'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    $st = db()->prepare('SELECT * FROM pacientes WHERE email = ? AND portal_activo = 1');
    $st->execute([$email]);
    $encontrado = null;
    foreach ($st->fetchAll() as $p) {
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
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal del paciente</title>
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
                <div class="display-5 text-brand"><i class="bi bi-person-heart"></i></div>
                <h1 class="h4 mt-2 mb-0">Portal del paciente</h1>
                <p class="text-muted small">Consulta tus citas, recetas y estudios.</p>
            </div>

            <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
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
                    </div>
                </div>
                <button class="btn btn-primary w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
            </form>
            <div class="text-center mt-4 small text-muted">
                ¿No tienes acceso? Pídelo en tu consultorio.
            </div>
        </div>
    </div>
</div>
</body>
</html>
