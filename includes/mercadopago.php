<?php
/**
 * Cliente mínimo de Mercado Pago (suscripciones recurrentes) vía HTTP/cURL.
 * Sin Composer: se usa la API REST directamente.
 * Docs: https://www.mercadopago.com.mx/developers/es/reference/subscriptions/_preapproval/post
 */
require_once __DIR__ . '/../config/config.php';

class MpException extends RuntimeException {}

/** Planes de membresía (MXN/mes). La clave se usa en URLs y external_reference. */
function planes_mp(): array
{
    return [
        'estandar' => ['nombre' => 'Estándar', 'precio' => 299.0],
        'premium'  => ['nombre' => 'Premium',  'precio' => 599.0],
    ];
}

/** ¿Hay credenciales de Mercado Pago configuradas? */
function mp_configurado(): bool
{
    return MP_ACCESS_TOKEN !== '';
}

/** ¿Las credenciales son de prueba (sandbox)? */
function mp_es_sandbox(): bool
{
    return strpos(MP_ACCESS_TOKEN, 'TEST-') === 0;
}

/** Petición genérica a la API de Mercado Pago. Devuelve el JSON decodificado. */
function mp_request(string $metodo, string $path, ?array $body = null): array
{
    if (!mp_configurado()) {
        throw new MpException('Mercado Pago no está configurado.');
    }
    $ch = curl_init('https://api.mercadopago.com' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . MP_ACCESS_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch); curl_close($ch);
        throw new MpException('Error de conexión con Mercado Pago: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true) ?: [];
    if ($code >= 300) {
        $msg = $data['message'] ?? ('HTTP ' . $code);
        throw new MpException('Mercado Pago respondió error: ' . $msg);
    }
    return $data;
}

/**
 * Crea una suscripción (preapproval) para un consultorio y un plan.
 * Devuelve ['id' => ..., 'init_point' => url a la que redirigir al usuario].
 */
function mp_crear_suscripcion(array $consultorio, string $planKey, string $payerEmail): array
{
    $planes = planes_mp();
    if (!isset($planes[$planKey])) {
        throw new MpException('Plan no válido.');
    }
    $plan = $planes[$planKey];

    $payload = [
        'reason'             => 'MediAgenda — Plan ' . $plan['nombre'],
        'external_reference' => 'consultorio:' . (int) $consultorio['id'] . '|plan:' . $planKey,
        'payer_email'        => $payerEmail,
        'back_url'           => rtrim(url_absoluta('/pagos/retorno.php'), '/'),
        'notification_url'   => url_absoluta('/pagos/webhook.php'),
        'status'             => 'pending',
        'auto_recurring'     => [
            'frequency'          => 1,
            'frequency_type'     => 'months',
            'transaction_amount' => $plan['precio'],
            'currency_id'        => 'MXN',
        ],
    ];

    $r = mp_request('POST', '/preapproval', $payload);
    $init = (mp_es_sandbox() && !empty($r['sandbox_init_point']))
        ? $r['sandbox_init_point']
        : ($r['init_point'] ?? '');
    if (empty($r['id']) || $init === '') {
        throw new MpException('Mercado Pago no devolvió el enlace de pago.');
    }
    return ['id' => $r['id'], 'init_point' => $init];
}

/** Consulta el estado de una suscripción (preapproval). */
function mp_obtener_suscripcion(string $id): array
{
    return mp_request('GET', '/preapproval/' . urlencode($id));
}

/**
 * Aplica el estado de una suscripción (preapproval) al consultorio:
 *  - authorized  -> cuenta activa con el plan contratado
 *  - paused/cancelled -> suspendida
 * Devuelve el id del consultorio afectado o null.
 */
function mp_sincronizar(array $pre): ?int
{
    $ref = $pre['external_reference'] ?? '';
    if (!preg_match('/consultorio:(\d+)\|plan:(\w+)/', $ref, $m)) return null;
    $cid  = (int) $m[1];
    $plan = $m[2];
    $estado = $pre['status'] ?? '';
    $pdo = db();

    if ($estado === 'authorized') {
        $prox = !empty($pre['next_payment_date']) ? date('Y-m-d', strtotime($pre['next_payment_date'])) : null;
        $pdo->prepare("UPDATE consultorios SET estado='activa', plan=?, mp_suscripcion_id=?, mp_estado=?, proximo_cobro=? WHERE id=?")
            ->execute([$plan, $pre['id'] ?? null, $estado, $prox, $cid]);
    } elseif (in_array($estado, ['paused', 'cancelled'], true)) {
        $pdo->prepare("UPDATE consultorios SET estado='suspendida', mp_estado=? WHERE id=?")
            ->execute([$estado, $cid]);
    } else {
        $pdo->prepare("UPDATE consultorios SET mp_suscripcion_id=?, mp_estado=? WHERE id=?")
            ->execute([$pre['id'] ?? null, $estado, $cid]);
    }

    // Bitácora (si la tabla existe).
    try {
        $pdo->prepare("INSERT INTO pagos_log (consultorio_id, tipo, referencia, estado, monto, payload) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, 'preapproval', $pre['id'] ?? null, $estado,
                       $pre['auto_recurring']['transaction_amount'] ?? null, json_encode($pre)]);
    } catch (Throwable $e) { /* tabla pagos_log aún no creada */ }

    return $cid;
}

/** Construye una URL absoluta del sitio (Mercado Pago exige back/notification URL completas). */
function url_absoluta(string $path): string
{
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https://' : 'http://') . $host . BASE_URL . $path;
}
