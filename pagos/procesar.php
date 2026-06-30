<?php
/**
 * Procesa el token del Card Payment Brick: crea la suscripción (preapproval)
 * ya autorizada en Mercado Pago y sincroniza el estado del consultorio.
 * Responde JSON. El cobro ocurre sin salir del sitio.
 */
define('ALLOW_INACTIVE', true);   // una cuenta bloqueada debe poder pagar
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_once __DIR__ . '/../includes/mercadopago.php';

header('Content-Type: application/json; charset=utf-8');

function reply(array $data): void { echo json_encode($data); exit; }

if (!mp_configurado()) {
    reply(['ok' => false, 'error' => 'El pago en línea no está disponible.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    reply(['ok' => false, 'error' => 'Solicitud inválida.']);
}

// CSRF: la petición es JSON, validamos manualmente.
if (!hash_equals(csrf_token(), (string) ($input['csrf'] ?? ''))) {
    http_response_code(419);
    reply(['ok' => false, 'error' => 'Sesión expirada. Recarga la página.']);
}

$plan   = preg_replace('/[^a-z]/', '', (string) ($input['plan'] ?? ''));
$token  = (string) ($input['token'] ?? '');
$planes = planes_mp();

if (!isset($planes[$plan])) {
    reply(['ok' => false, 'error' => 'Plan no válido.']);
}
if ($token === '') {
    reply(['ok' => false, 'error' => 'No se recibió la tarjeta. Inténtalo de nuevo.']);
}

try {
    $u   = current_user();
    $pre = mp_crear_suscripcion_token(tenant() ?? ['id' => tenant_id()], $plan, $u['email'], $token);

    // Aplica el estado al consultorio (authorized -> cuenta activa).
    mp_sincronizar($pre);
    tenant(true);   // refresca la caché del consultorio

    $estado = $pre['status'] ?? '';
    if ($estado === 'authorized') {
        reply(['ok' => true, 'redirect' => BASE_URL . '/pagos/retorno?preapproval_id=' . rawurlencode($pre['id'])]);
    }
    // Pendiente (revisión del medio de pago): guardamos referencia y avisamos.
    db()->prepare("UPDATE consultorios SET mp_suscripcion_id=?, mp_estado=? WHERE id=?")
        ->execute([$pre['id'], $estado ?: 'pending', tenant_id()]);
    reply(['ok' => true, 'redirect' => BASE_URL . '/pagos/retorno?preapproval_id=' . rawurlencode($pre['id'])]);
} catch (MpException $e) {
    reply(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    reply(['ok' => false, 'error' => 'No se pudo procesar el pago. Inténtalo de nuevo.']);
}
