<?php
/**
 * Webhook de cobros a pacientes. Lo llama Mercado Pago: sin login ni CSRF.
 *
 * El aviso solo dice "mira el pago N". Nunca se confía en su contenido: se
 * consulta el pago a la API con el token del consultorio y de ahí sale el
 * importe, el estado y a qué cobro pertenece. El consultorio viene en la query
 * (`?c=`) porque sin él no sabríamos con qué token preguntar.
 *
 * Se responde 200 siempre: un error nuestro no debe hacer que Mercado Pago
 * reintente en bucle. Los fallos quedan en la bitácora.
 */
require_once __DIR__ . '/../includes/cobros.php';

http_response_code(200);

$tenant = (int) ($_GET['c'] ?? 0);
if ($tenant > 0) {
    tenant_forzar($tenant);
}

/* El id del pago llega de varias formas según la versión del aviso. */
$cuerpo = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$tipo   = (string) ($cuerpo['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$pagoId = (string) ($cuerpo['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');

// `data.id` en la query no es un nombre válido de variable en PHP.
if ($pagoId === '' && isset($_GET['data.id'])) {
    $pagoId = (string) $_GET['data.id'];
}

if ($tenant <= 0 || $tipo !== 'payment' || !preg_match('/^\d+$/', $pagoId)) {
    exit;   // aviso de otro tipo (merchant_order, plan…) o incompleto
}

try {
    $resultado = cobro_confirmar_pago($pagoId);
    if ($resultado === 'pagado') {
        auditar('cobro_pagado', 'cobro', null, 'pago ' . $pagoId, $tenant, ['nombre' => 'Mercado Pago']);
    }
} catch (Throwable $e) {
    auditar('cobro_error', 'cobro', null, mb_substr($e->getMessage(), 0, 200), $tenant, ['nombre' => 'Mercado Pago']);
}
