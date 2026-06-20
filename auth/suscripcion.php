<?php
define('ALLOW_INACTIVE', true);   // esta página es accesible aunque la cuenta esté inactiva
require_once __DIR__ . '/../includes/functions.php';
require_login();

$t = tenant();
$estado = $t['estado'] ?? 'trial';
$mensaje = [
    'trial'      => 'Tu prueba gratuita terminó.',
    'expirada'   => 'Tu suscripción expiró.',
    'suspendida' => 'Tu cuenta está suspendida.',
][$estado] ?? 'Tu cuenta está inactiva.';

$soporte = cfg('email') ?: 'ventas@mediagenda.com.mx';

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

$planes = [
    ['Estándar', '$299', 'Consultorio en crecimiento', ['Hasta 5 médicos', 'Recordatorios', 'Reportes', 'Soporte por correo'], true],
    ['Premium',  '$599', 'Clínicas y equipos',         ['Médicos ilimitados', 'Roles avanzados', 'Respaldo diario', 'Soporte prioritario'], false],
];
?>
<!doctype html>
<html lang="es" class="app-light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activar plan · <?= e(marca_nombre()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<div class="container py-5" style="max-width:900px">
    <div class="text-center mb-4">
        <div class="display-5 text-brand"><i class="bi bi-lock-fill"></i></div>
        <h1 class="h3 mt-2"><?= e($mensaje) ?></h1>
        <p class="text-muted">Para seguir usando <strong><?= e(marca_nombre()) ?></strong>, elige un plan y reactiva tu cuenta.</p>
    </div>

    <?php if ($totalDatos > 0): ?>
    <div class="card border-success-subtle mb-4">
        <div class="card-body">
            <p class="text-center mb-3"><i class="bi bi-shield-check text-success"></i>
                <strong>Tus datos están guardados y a salvo.</strong> Reactiva tu cuenta para recuperar el acceso a:</p>
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
        <?php foreach ($planes as [$nombre, $precio, $desc, $items, $feat]): ?>
        <div class="col-md-5">
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
                    <a href="mailto:<?= e($soporte) ?>?subject=<?= rawurlencode('Activar plan ' . $nombre . ' — ' . marca_nombre()) ?>"
                       class="btn <?= $feat ? 'btn-primary' : 'btn-outline-primary' ?> w-100">
                        <i class="bi bi-envelope"></i> Activar <?= e($nombre) ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center text-muted small">
        ¿Dudas? Escríbenos a <a href="mailto:<?= e($soporte) ?>"><?= e($soporte) ?></a>.
        <br>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="text-muted">Cerrar sesión</a>
    </div>
</div>
</body>
</html>
