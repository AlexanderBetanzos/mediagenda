<?php
define('ALLOW_INACTIVE', true);   // un consultorio bloqueado debe poder pagar
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_once __DIR__ . '/../includes/mercadopago.php';

$plan   = $_GET['plan'] ?? '';
$planes = planes_mp();

if (!mp_configurado()) {
    flash('Los pagos en línea aún no están disponibles. Contáctanos para activar tu plan.', 'warning');
    redirect('/auth/suscripcion');
}
if (!isset($planes[$plan])) {
    flash('Plan no válido.', 'warning');
    redirect('/auth/suscripcion');
}

try {
    $u   = current_user();
    $sus = mp_crear_suscripcion(tenant() ?? ['id' => tenant_id()], $plan, $u['email']);
    // Guarda el id de la suscripción (pendiente hasta que el webhook confirme).
    db()->prepare("UPDATE consultorios SET mp_suscripcion_id=?, mp_estado='pending' WHERE id=?")
        ->execute([$sus['id'], tenant_id()]);
    header('Location: ' . $sus['init_point']);   // redirige a Mercado Pago
    exit;
} catch (MpException $e) {
    flash('No se pudo iniciar el pago: ' . $e->getMessage(), 'danger');
    redirect('/auth/suscripcion');
}
