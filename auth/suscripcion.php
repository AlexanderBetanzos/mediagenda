<?php
define('ALLOW_INACTIVE', true);   // esta página es accesible aunque la cuenta esté inactiva
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_once __DIR__ . '/../includes/mercadopago.php';

$t = tenant();
$estado = $t['estado'] ?? 'trial';
$mensaje = [
    'trial'      => 'Tu prueba gratuita terminó.',
    'expirada'   => 'Tu suscripción expiró.',
    'suspendida' => 'Tu cuenta está suspendida.',
][$estado] ?? 'Tu cuenta está inactiva.';

$soporte = cfg('email') ?: 'ventas@mediagenda.com.mx';

/* Si el consultorio YA tiene acceso (membresía activa o prueba vigente), esta
   página de reactivación no aplica: se le confirma su estado. */
$dias        = trial_dias_restantes();
$tieneAcceso = ($estado === 'activa') || ($estado === 'trial' && $dias !== null && $dias >= 0);
$planActual  = planes_mp()[$t['plan'] ?? '']['nombre'] ?? ($t['plan'] ?? '');

// Datos del consultorio (a salvo) — refuerza la conversión.
$tid = tenant_id();
$contar = function (string $tabla) use ($tid): int {
    $st = db()->prepare("SELECT COUNT(*) FROM $tabla WHERE consultorio_id = ?");
    $st->execute([$tid]);
    return (int) $st->fetchColumn();
};
$resumen = [
    ['bi-people',        $contar('pacientes'), 'pacientes'],
    ['bi-calendar-check',$contar('citas'),     'citas'],
    ['bi-file-medical',  $contar('consultas'), 'consultas'],
    ['bi-receipt',       $contar('facturas'),  'facturas'],
];
$totalDatos = array_sum(array_column($resumen, 1));

$precios = planes_mp();
$planes  = [];
foreach ($precios as $key => $pl) {
    $planes[] = [$key, $pl['nombre'], $pl['descripcion'], $pl['items'], $pl['destacado']];
}
$conPago = mp_configurado();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>" class="app-light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Activar plan') ?> · <?= e(marca_nombre()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<div class="container py-5" style="max-width:900px">
    <?php if ($tieneAcceso): ?>
    <!-- Ya cuenta con membresía / prueba vigente -->
    <div class="text-center py-5">
        <div class="display-4 text-success"><i class="bi bi-check-circle-fill"></i></div>
        <?php if ($estado === 'activa'): ?>
            <h1 class="h3 mt-3"><?= et('Tu plan ya está activo') ?></h1>
            <p class="text-muted"><?= et('Plan contratado') ?>: <strong><?= e($planActual) ?></strong></p>
        <?php else: ?>
            <h1 class="h3 mt-3"><?= et('Tu prueba sigue activa') ?></h1>
            <p class="text-muted"><strong><?= (int) $dias ?></strong> <?= et('día(s) restantes') ?></p>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary btn-lg mt-2"><i class="bi bi-speedometer2"></i> <?= et('Ir al panel') ?></a>
        <div class="mt-3"><a href="<?= BASE_URL ?>/auth/logout" class="text-muted small"><?= et('Cerrar sesión') ?></a></div>
    </div>
    <?php else: ?>
    <div class="text-center mb-4">
        <div class="display-5 text-brand"><i class="bi bi-lock-fill"></i></div>
        <h1 class="h3 mt-2"><?= e($mensaje) ?></h1>
        <p class="text-muted"><?= et('Para seguir usando') ?> <strong><?= e(marca_nombre()) ?></strong>, <?= et('elige un plan y reactiva tu cuenta.') ?></p>
    </div>

    <?php if ($totalDatos > 0): ?>
    <div class="card border-success-subtle mb-4">
        <div class="card-body">
            <p class="text-center mb-3"><i class="bi bi-shield-check text-success"></i>
                <strong><?= et('Tus datos están guardados y a salvo.') ?></strong> <?= et('Reactiva tu cuenta para recuperar el acceso a:') ?></p>
            <div class="row g-3 text-center">
                <?php foreach ($resumen as [$icono, $num, $etq]): if ($num > 0): ?>
                <div class="col">
                    <div class="display-6 fw-bold text-brand"><?= $num ?></div>
                    <div class="small text-muted"><i class="bi <?= $icono ?>"></i> <?= $etq ?></div>
                </div>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 justify-content-center mb-4">
        <?php foreach ($planes as [$key, $nombre, $desc, $items, $feat]):
            $precio = '$' . number_format($precios[$key]['precio'] ?? 0, 0); ?>
        <div class="col-md-4">
            <div class="card h-100 <?= $feat ? 'border-primary shadow' : '' ?>">
                <div class="card-body text-center p-4">
                    <?php if ($feat): ?><span class="badge bg-primary mb-2">Recomendado</span><?php endif; ?>
                    <h5 class="fw-bold mb-1"><?= e($nombre) ?></h5>
                    <div class="display-6 fw-bold text-brand"><?= e($precio) ?><span class="fs-6 text-muted fw-normal">/mes</span></div>
                    <p class="text-muted small"><?= e($desc) ?></p>
                    <ul class="list-unstyled text-start my-3">
                        <?php foreach ($items as $it): ?>
                            <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i><?= e($it) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($conPago): ?>
                    <a href="<?= BASE_URL ?>/pagos/checkout?plan=<?= e($key) ?>"
                       class="btn <?= $feat ? 'btn-primary' : 'btn-outline-primary' ?> w-100">
                        <i class="bi bi-credit-card"></i> <?= et('Suscribirme') ?>
                    </a>
                    <?php else: ?>
                    <a href="mailto:<?= e($soporte) ?>?subject=<?= rawurlencode('Activar plan ' . $nombre . ' — ' . marca_nombre()) ?>"
                       class="btn <?= $feat ? 'btn-primary' : 'btn-outline-primary' ?> w-100">
                        <i class="bi bi-envelope"></i> Activar <?= e($nombre) ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center text-muted small">
        ¿Dudas? Escríbenos a <a href="mailto:<?= e($soporte) ?>"><?= e($soporte) ?></a>.
        <br>
        <a href="<?= BASE_URL ?>/auth/logout" class="text-muted">Cerrar sesión</a>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
