<?php
/**
 * Webhook de compras de créditos (timbres CFDI y mensajes de WhatsApp). Lo llama Mercado Pago (cuenta de la
 * PLATAFORMA): sin login ni CSRF. El aviso solo dice "mira el pago N"; el
 * importe, el estado y a qué compra pertenece se consultan a la API.
 * Siempre 200, para que Mercado Pago no reintente en bucle.
 */
require_once __DIR__ . '/../includes/creditos.php';

http_response_code(200);

$cuerpo = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$tipo   = (string) ($cuerpo['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$pagoId = (string) ($cuerpo['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');
if ($pagoId === '' && isset($_GET['data.id'])) $pagoId = (string) $_GET['data.id'];

if ($tipo !== 'payment' || !preg_match('/^\d+$/', $pagoId)) exit;

try {
    credito_confirmar_pago($pagoId);
} catch (Throwable $e) {
    auditar('timbres_webhook_error', 'timbres', null, mb_substr($e->getMessage(), 0, 200), null, ['nombre' => 'Mercado Pago']);
}
