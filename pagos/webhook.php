<?php
/**
 * Webhook de Mercado Pago: recibe notificaciones de suscripciones/pagos.
 * No requiere login ni CSRF (lo llama Mercado Pago). La fuente de verdad es la
 * API: ante cualquier aviso, volvemos a CONSULTAR el recurso a Mercado Pago con
 * nuestro token y actualizamos el estado del consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';

http_response_code(200);   // confirmar recepción cuanto antes

if (!mp_configurado()) { echo 'sin-config'; exit; }

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$tipo = $_GET['type'] ?? $_GET['topic'] ?? ($body['type'] ?? '');
$id   = $_GET['data.id'] ?? $_GET['id'] ?? ($body['data']['id'] ?? ($body['id'] ?? ''));

try {
    if ($id !== '' && strpos((string) $tipo, 'preapproval') !== false) {
        $pre = mp_obtener_suscripcion((string) $id);
        mp_sincronizar($pre);
    }
    // (Los avisos 'payment' de cada cobro recurrente podrían registrarse aquí.)
} catch (Throwable $e) {
    // No exponer detalles; Mercado Pago reintenta si no recibe 200… ya lo dimos.
}
echo 'ok';
