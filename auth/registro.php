<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_once __DIR__ . '/../includes/correo.php';

// Si ya hay sesión no se registra un consultorio nuevo: "Contratar ahora" es un
// upgrade, así que va al checkout del plan elegido. Sin plan válido, al panel.
if (is_logged_in()) {
    $planSel = preg_replace('/[^a-z]/', '', (string) ($_GET['plan'] ?? ''));
    redirect(isset(planes_mp()[$planSel]) ? '/pagos/checkout?plan=' . $planSel : '/dashboard');
}

// La regla de quién tiene prueba vive en functions.php (plan_con_prueba),
// porque la landing la necesita para rotular sus botones:
//  - Profesional (o registro sin plan)  -> prueba de 15 días, sin tarjeta.
//  - Básico / Clínica                   -> sin prueba, pago inmediato.
$plan  = preg_replace('/[^a-z]/', '', (string) ($_GET['plan'] ?? $_POST['plan'] ?? ''));
$planes = planes_mp();
if (!isset($planes[$plan])) $plan = 'profesional';   // sin plan o inválido = Profesional
$conPrueba = plan_con_prueba($plan);                   // misma regla que usa la landing
$pagar     = !$conPrueba;                              // Básico/Clínica = pago inmediato

$error = '';
$f = ['consultorio' => '', 'nombre' => '', 'email' => '', 'telefono' => ''];
// Interfaz (giro): ?giro=veterinaria preselecciona desde un anuncio o la landing.
$giro = (string) ($_POST['giro'] ?? $_GET['giro'] ?? 'medico');
if (!isset(giros_lista()[$giro])) $giro = 'medico';

/** Genera un slug único para el consultorio. */
function slug_unico(PDO $pdo, string $texto): string
{
    $base = mb_strtolower(trim($texto), 'UTF-8');
    $base = strtr($base, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-') ?: 'consultorio';
    $slug = $base;
    $i = 1;
    $st = $pdo->prepare('SELECT 1 FROM consultorios WHERE slug = ?');
    while (true) {
        $st->execute([$slug]);
        if (!$st->fetchColumn()) return $slug;
        $slug = $base . '-' . (++$i);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $f['consultorio'] = trim($_POST['consultorio'] ?? '');
    $f['nombre']      = trim($_POST['nombre'] ?? '');
    $f['email']       = trim($_POST['email'] ?? '');
    $f['telefono']    = trim($_POST['telefono'] ?? '');
    $pass             = $_POST['password'] ?? '';
    $pass2            = $_POST['password2'] ?? '';

    // Validaciones
    if ($f['consultorio'] === '' || $f['nombre'] === '' || $f['email'] === '' || $f['telefono'] === '') {
        $error = t('Completa todos los campos.');
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $error = t('El correo no es válido.');
    } elseif (strlen(preg_replace('/\D/', '', $f['telefono'])) < 7) {
        $error = t('El teléfono no es válido.');
    } elseif (strlen($pass) < 8) {
        $error = t('La contraseña debe tener al menos 8 caracteres.');
    } elseif ($pass !== $pass2) {
        $error = t('Las contraseñas no coinciden.');
    } else {
        $pdo = db();
        $dup = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $dup->execute([$f['email']]);
        if ($dup->fetchColumn()) {
            $error = 'Ese correo ya está registrado. ¿Quieres <a href="' . BASE_URL . '/auth/login.php">iniciar sesión</a>?';
        } else {
            try {
                $pdo->beginTransaction();
                $slug = slug_unico($pdo, $f['consultorio']);
                // Profesional: 15 días de prueba (sin tarjeta).
                // Básico/Clínica: sin prueba (trial_fin en el pasado → debe pagar).
                $trialFin = $conPrueba
                    ? date('Y-m-d', strtotime('+' . trial_dias_oferta() . ' days'))
                    : date('Y-m-d', strtotime('-1 day'));
                $pdo->prepare(
                    "INSERT INTO consultorios (nombre, slug, email, telefono, plan, estado, trial_inicio, trial_fin)
                     VALUES (?, ?, ?, ?, ?, 'trial', CURDATE(), ?)"
                )->execute([$f['consultorio'], $slug, $f['email'], $f['telefono'], $plan, $trialFin]);
                $cid = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO usuarios (consultorio_id, nombre, email, password_hash, rol, telefono, activo)
                     VALUES (?, ?, ?, ?, 'admin', ?, 1)"
                )->execute([$cid, $f['nombre'], $f['email'], password_hash($pass, PASSWORD_BCRYPT), $f['telefono']]);
                $uid = (int) $pdo->lastInsertId();

                $pdo->commit();

                // Iniciar sesión en el nuevo consultorio.
                session_regenerate_id(true);
                $_SESSION['usuario'] = [
                    'id'             => $uid,
                    'nombre'         => $f['nombre'],
                    'email'          => $f['email'],
                    'rol'            => 'admin',
                    'consultorio_id' => $cid,
                ];

                // Configuración inicial (white-label) del nuevo consultorio.
                guardar_cfg([
                    'marca_nombre'   => $f['consultorio'],
                    'tema_default'   => 'light',
                    'color_acento'   => '#2563eb',
                    'moneda'         => 'MXN',
                    'zona_horaria'   => 'America/Mexico_City',
                    'formato_fecha'  => 'd/m/Y',
                    'plan_preferido' => $plan,   // plan elegido (Profesional por defecto)
                    'giro'           => $giro,   // interfaz: médica, dental, veterinaria…
                ]);

                // Aviso al dueño de la plataforma: llegó un consultorio nuevo.
                require_once __DIR__ . '/../includes/correo.php';
                $planNom = planes_mp()[$plan]['nombre'] ?? $plan;
                @correo_aviso_plataforma(
                    'Nuevo registro: ' . $f['consultorio'] . ' (' . $planNom . ')',
                    '¡Se registró un consultorio!',
                    [
                        'Consultorio' => $f['consultorio'],
                        'Responsable' => $f['nombre'],
                        'Correo'      => $f['email'],
                        'Teléfono'    => $f['telefono'],
                        'Plan'        => $planNom . ($pagar ? ' · va a pagar' : ' · prueba de ' . trial_dias_oferta() . ' días'),
                        'Interfaz'    => giros_lista()[$giro]['nombre'],
                        'Página'      => url_absoluta('/c/' . $slug),
                        'Fecha'       => date('d/m/Y H:i'),
                    ],
                    'Nuevo registro'
                );

                // Meta Pixel: se disparan en la siguiente página (panel o checkout).
                meta_pixel_evento('CompleteRegistration');
                meta_pixel_evento($pagar ? 'InitiateCheckout' : 'StartTrial');

                if ($pagar) {
                    // Básico/Clínica: a pagar para activar (checkout embebido;
                    // cae a redirect si no hay Public Key de Mercado Pago).
                    redirect('/pagos/checkout?plan=' . $plan);
                }
                @correo_bienvenida_trial($f['email'], $f['nombre'], trial_dias_oferta());
                flash('¡Tu consultorio fue creado! Tienes ' . trial_dias_oferta() . ' días de prueba gratis.');
                redirect('/dashboard');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'No se pudo crear la cuenta. Inténtalo de nuevo.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Prueba gratis') ?> · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <?= meta_pixel_head() ?>
</head>
<body>
<div class="auth-split">
<?php $authModo = 'registro'; include __DIR__ . '/../includes/auth_lado.php'; ?>
<div class="auth-main">
    <div class="card shadow login-card" style="max-width:480px">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <?php if ($pagar): $p = $planes[$plan]; ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle mb-2">
                        <i class="bi bi-stars"></i> Plan <?= e($p['nombre']) ?> · $<?= number_format($p['precio'], 0) ?>/mes · IVA incluido
                    </span>
                    <h1 class="h4 mb-1">Crea tu cuenta y activa <?= e($p['nombre']) ?></h1>
                    <p class="text-muted small mb-0">Al continuar te llevamos al <strong>pago</strong> para activar tu suscripción mensual.</p>
                <?php else: ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle mb-2">
                        <i class="bi bi-gift"></i> <?= trial_dias_oferta() ?> días gratis · acceso completo · sin tarjeta
                    </span>
                    <h1 class="h4 mb-1"><?= et('Crea tu consultorio en') ?> <?= e(APP_NAME) ?></h1>
                    <p class="text-muted small mb-0"><?= trial_dias_oferta() ?> días de prueba del plan <strong>Profesional</strong>: pacientes, citas, expediente, recetas, facturación y reportes.</p>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= $error /* puede traer enlace */ ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="plan" value="<?= e($plan) ?>">
                <?php /* Interfaz: cada quien elige la que se adapta a su práctica; se
                         puede cambiar después en Configuración. */ ?>
                <div class="mb-3">
                    <label class="form-label"><?= et('¿Qué tipo de práctica tienes?') ?></label>
                    <div class="giro-grid">
                        <?php foreach (giros_lista() as $gk => $gd): ?>
                        <label class="giro-opt">
                            <input type="radio" name="giro" value="<?= e($gk) ?>" <?= $giro === $gk ? 'checked' : '' ?>>
                            <span><i class="bi <?= e($gd['icono']) ?>"></i> <?= e($gd['nombre']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text"><?= et('Adaptamos el panel a tu especialidad. Puedes cambiarlo cuando quieras.') ?></div>
                </div>
                <style>
                    .giro-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .45rem; }
                    .giro-opt { margin: 0; cursor: pointer; }
                    .giro-opt input { position: absolute; opacity: 0; pointer-events: none; }
                    .giro-opt span { display: flex; align-items: center; gap: .45rem; height: 100%; padding: .55rem .7rem; border-radius: 12px;
                                     border: 1px solid rgba(127,127,127,.28); font-size: .86rem; font-weight: 550; transition: all .15s ease; }
                    .giro-opt span .bi { color: var(--brand); }
                    .giro-opt input:checked + span { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 9%, transparent);
                                                     box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 25%, transparent); }
                    .giro-opt input:focus-visible + span { outline: 2px solid var(--brand); outline-offset: 2px; }
                </style>
                <div class="mb-3">
                    <label class="form-label"><?= et('Nombre del consultorio') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-hospital"></i></span>
                        <input type="text" name="consultorio" class="form-control" required autofocus maxlength="60"
                               value="<?= e($f['consultorio']) ?>" placeholder="Ej. Clínica Dental Sonrisas">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= et('Tu nombre') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="nombre" class="form-control" required maxlength="120"
                               value="<?= e($f['nombre']) ?>" placeholder="Dr(a). Nombre Apellido">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= et('Correo electrónico') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" class="form-control" required maxlength="150"
                               value="<?= e($f['email']) ?>" placeholder="correo@consultorio.com">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= et('Teléfono') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                        <input type="tel" name="telefono" class="form-control" required maxlength="40"
                               value="<?= e($f['telefono']) ?>" placeholder="Ej. 55 1234 5678">
                    </div>
                    <div class="form-text"><?= et('Lo usamos para contactarte sobre tu cuenta y tu plan.') ?></div>
                </div>
                <div class="row g-2 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label"><?= et('Contraseña') ?></label>
                        <div class="input-group">
                            <input type="password" name="password" id="pw1" class="form-control" required minlength="8" placeholder="<?= et('Mín. 8 caracteres') ?>">
                            <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="pw1" tabindex="-1" aria-label="<?= et('Mostrar u ocultar contraseña') ?>"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"><?= et('Confirmar') ?></label>
                        <div class="input-group">
                            <input type="password" name="password2" id="pw2" class="form-control" required minlength="8" placeholder="<?= et('Repite la contraseña') ?>">
                            <button type="button" class="btn btn-outline-secondary toggle-pw" data-target="pw2" tabindex="-1" aria-label="<?= et('Mostrar u ocultar contraseña') ?>"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                </div>
                <?php if ($pagar): ?>
                    <button class="btn btn-primary w-100 py-2"><i class="bi bi-credit-card"></i> <?= et('Continuar al pago') ?></button>
                <?php else: ?>
                    <button class="btn btn-primary w-100 py-2"><i class="bi bi-rocket-takeoff"></i> <?= et('Empezar mi prueba de') ?> <?= trial_dias_oferta() ?> <?= et('días') ?></button>
                <?php endif; ?>
            </form>

            <p class="text-center small text-muted mt-3 mb-0">
                <?= et('Al crear tu cuenta aceptas el') ?>
                <a href="<?= BASE_URL ?>/privacidad" target="_blank" rel="noopener"><?= et('Aviso de privacidad') ?></a>.
            </p>

            <div class="text-center mt-3 small text-muted">
                <?= et('¿Ya tienes cuenta?') ?> <a href="<?= BASE_URL ?>/auth/login"><?= et('Inicia sesión') ?></a>
                &middot; <a href="<?= BASE_URL ?>/index" class="text-muted"><?= et('Volver al sitio') ?></a>
            </div>
        </div>
    </div>
</div>
</div>
<script>
document.querySelectorAll('.toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.target);
        var icon  = btn.querySelector('i');
        var show  = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !show);
        icon.classList.toggle('bi-eye-slash', show);
    });
});
</script>
</body>
</html>
