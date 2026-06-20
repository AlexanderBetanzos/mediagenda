<?php
define('ALLOW_INACTIVE', true);
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_once __DIR__ . '/../includes/mercadopago.php';

$preId  = $_GET['preapproval_id'] ?? '';
$estado = '';
if ($preId !== '' && mp_configurado()) {
    try {
        $pre = mp_obtener_suscripcion($preId);
        mp_sincronizar($pre);
        tenant(true);                 // refresca la caché del consultorio
        $estado = $pre['status'] ?? '';
    } catch (Throwable $e) { /* mostramos mensaje genérico */ }
}
$activa = ($estado === 'authorized');
?>
<!doctype html>
<html lang="es" class="app-light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suscripción · <?= e(marca_nombre()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<div class="container py-5 text-center" style="max-width:560px">
    <?php if ($activa): ?>
        <div class="display-4 text-success mb-3"><i class="bi bi-check-circle-fill"></i></div>
        <h1 class="h3">¡Pago confirmado!</h1>
        <p class="text-muted">Tu membresía está activa. Gracias por confiar en <strong><?= e(marca_nombre()) ?></strong>.</p>
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary mt-2"><i class="bi bi-speedometer2"></i> Ir a mi panel</a>
    <?php else: ?>
        <div class="display-4 text-warning mb-3"><i class="bi bi-hourglass-split"></i></div>
        <h1 class="h3">Estamos procesando tu pago</h1>
        <p class="text-muted">En cuanto Mercado Pago confirme la suscripción, tu cuenta se activará automáticamente. Puede tardar unos minutos.</p>
        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-primary mt-2">Volver al panel</a>
    <?php endif; ?>
</div>
</body>
</html>
