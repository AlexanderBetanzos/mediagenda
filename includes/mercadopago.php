<?php
/**
 * Cliente mínimo de Mercado Pago (suscripciones recurrentes) vía HTTP/cURL.
 * Sin Composer: se usa la API REST directamente.
 * Docs: https://www.mercadopago.com.mx/developers/es/reference/subscriptions/_preapproval/post
 */
require_once __DIR__ . '/../config/config.php';

class MpException extends RuntimeException {}

/**
 * Planes de membresía (MXN/mes). La clave se usa en URLs y external_reference.
 * Fuente de verdad: tabla `planes` (sql/planes.sql). Si aún no existe, cae a
 * los 3 planes por defecto para no romper el registro ni los pagos.
 * Cada plan: nombre, precio, descripcion, items[], destacado, mp_plan_id.
 */
function planes_mp(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $rows = db()->query(
            'SELECT clave, nombre, precio, descripcion, items, destacado, mp_plan_id
             FROM planes WHERE activo = 1 ORDER BY orden, precio'
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['clave']] = [
                'nombre'      => $r['nombre'],
                'precio'      => (float) $r['precio'],
                'descripcion' => $r['descripcion'] ?? '',
                'items'       => $r['items'] ? (json_decode($r['items'], true) ?: []) : [],
                'destacado'   => (bool) $r['destacado'],
                'mp_plan_id'  => $r['mp_plan_id'] ?? null,
            ];
        }
        if ($out) return $cache = $out;
    } catch (Throwable $e) { /* tabla planes aún no creada */ }

    return $cache = [
        'basico'      => ['nombre' => 'Básico',      'precio' => 299.0,  'descripcion' => 'Un médico, todo bajo control',              'items' => [], 'destacado' => false, 'mp_plan_id' => null],
        'profesional' => ['nombre' => 'Profesional', 'precio' => 599.0,  'descripcion' => 'El que eligen los consultorios que crecen', 'items' => [], 'destacado' => true,  'mp_plan_id' => null],
        'clinica'     => ['nombre' => 'Clínica',     'precio' => 1199.0, 'descripcion' => 'Varias sucursales, un solo control',        'items' => [], 'destacado' => false, 'mp_plan_id' => null],
    ];
}

/**
 * Credenciales de Mercado Pago. Manda lo guardado en `plataforma_config` desde
 * la consola de plataforma; si ahí no hay nada, se cae al archivo de secretos.
 * Lee la base directamente (sin functions.php) para servir también al webhook
 * y a los procesos de consola, que no arrancan sesión.
 */
function mp_credencial(string $clave, string $fallback): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT clave, valor FROM plataforma_config
                                  WHERE clave IN ('mp_access_token','mp_public_key')") as $row) {
                $cache[$row['clave']] = (string) $row['valor'];
            }
        } catch (Throwable $e) { /* la tabla aún no existe */ }
    }
    $v = $cache[$clave] ?? '';
    return $v !== '' ? $v : $fallback;
}

function mp_access_token(): string { return mp_credencial('mp_access_token', MP_ACCESS_TOKEN); }
function mp_public_key():   string { return mp_credencial('mp_public_key',   MP_PUBLIC_KEY);   }

/** ¿Hay credenciales de Mercado Pago configuradas? */
function mp_configurado(): bool
{
    return mp_access_token() !== '';
}

/** ¿Las credenciales son de prueba (sandbox)? */
function mp_es_sandbox(): bool
{
    return strpos(mp_access_token(), 'TEST-') === 0;
}

/**
 * Valida las credenciales que llegan de un formulario. Sirve tanto para las de
 * la plataforma como para las de un consultorio: los valores actuales se pasan
 * como argumento en vez de leerlos aquí.
 *
 * Un campo vacío significa "no lo toques": así se puede cambiar la Public Key
 * sin volver a teclear el Access Token, y nada se borra por descuido.
 *
 * @return array{nuevos: array<string,string>, errores: string[]}
 */
function mp_credenciales_desde_post(array $post, string $tokenActual = '', string $publicActual = ''): array
{
    $etiquetas = ['mp_access_token' => 'Access Token', 'mp_public_key' => 'Public Key'];
    $nuevos = $errores = [];

    foreach ($etiquetas as $clave => $etiqueta) {
        $valor = trim((string) ($post[$clave] ?? ''));
        if ($valor === '') continue;
        if (!preg_match('/^(TEST|APP_USR)-[A-Za-z0-9._-]{10,}$/', $valor)) {
            $errores[] = "El $etiqueta no tiene el formato de Mercado Pago (TEST-… o APP_USR-…).";
            continue;
        }
        $nuevos[$clave] = $valor;
    }

    // Mezclar producción con pruebas no falla de forma ruidosa: los cobros
    // simplemente dejan de pasar. Se rechaza antes de guardar.
    $token  = $nuevos['mp_access_token'] ?? $tokenActual;
    $public = $nuevos['mp_public_key']   ?? $publicActual;
    if (!$errores && $token !== '' && $public !== ''
        && (strpos($token, 'TEST-') === 0) !== (strpos($public, 'TEST-') === 0)) {
        $errores[] = 'Las dos credenciales deben ser del mismo entorno: ambas de pruebas (TEST-) o ambas productivas (APP_USR-).';
    }

    return ['nuevos' => $nuevos, 'errores' => $errores];
}

/* --------------------------------------------------------------------
 *  Credenciales DEL CONSULTORIO (pago en línea de sus pacientes).
 *
 *  Distintas de las de la plataforma: estas viven en `configuracion`, son de
 *  cada tenant, y el dinero cae en la cuenta del consultorio. Las de arriba
 *  (`plataforma_config`) son con las que MediOS cobra las suscripciones.
 * ------------------------------------------------------------------ */

function mp_tenant_access_token(): string { return (string) cfg('mp_access_token', ''); }
function mp_tenant_public_key():   string { return (string) cfg('mp_public_key', ''); }

/** ¿El consultorio puede cobrar en línea a sus pacientes? */
function mp_tenant_habilitado(): bool
{
    return cfg('mp_pago_habilitado', '0') === '1'
        && mp_tenant_access_token() !== ''
        && mp_tenant_public_key()   !== '';
}

/** ¿Las credenciales del consultorio son de prueba (sandbox)? */
function mp_tenant_es_sandbox(): bool
{
    return strpos(mp_tenant_access_token(), 'TEST-') === 0;
}

/** Petición a la API con las credenciales del consultorio activo. */
function mp_tenant_request(string $metodo, string $path, ?array $body = null): array
{
    $token = mp_tenant_access_token();
    if ($token === '') {
        throw new MpException('Este consultorio no tiene configurado el pago en línea.');
    }
    return mp_request($metodo, $path, $body, $token);
}

/**
 * Crea la preferencia de Checkout Pro de un cobro y devuelve
 * ['id' => …, 'init_point' => url a la que mandar al paciente].
 *
 * `notification_url` lleva el consultorio en la query porque el webhook es
 * público: sin él no sabríamos con qué token consultar el pago.
 */
function mp_crear_preferencia_cobro(array $cobro, array $paciente): array
{
    $tid = (int) $cobro['consultorio_id'];

    $payload = [
        'items' => [[
            'title'       => mb_substr($cobro['concepto'], 0, 250),
            'quantity'    => 1,
            'unit_price'  => round((float) $cobro['monto'], 2),
            'currency_id' => moneda(),
        ]],
        'external_reference' => 'cobro:' . (int) $cobro['id'],
        'notification_url'   => url_absoluta('/pago/webhook?c=' . $tid),
        'back_urls' => [
            'success' => url_absoluta('/pago/retorno?t=' . $cobro['token']),
            'pending' => url_absoluta('/pago/retorno?t=' . $cobro['token']),
            'failure' => url_absoluta('/pago/retorno?t=' . $cobro['token']),
        ],
        'auto_return'  => 'approved',
        'statement_descriptor' => mb_substr(marca_nombre(), 0, 22),
    ];

    $nombre = trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apellidos'] ?? ''));
    if ($nombre !== '')            $payload['payer']['name']  = $nombre;
    if (!empty($paciente['email'])) $payload['payer']['email'] = $paciente['email'];

    $pref = mp_tenant_request('POST', '/checkout/preferences', $payload);

    // En sandbox, Mercado Pago sirve el checkout desde otra URL.
    $url = mp_tenant_es_sandbox()
        ? ($pref['sandbox_init_point'] ?? $pref['init_point'] ?? '')
        : ($pref['init_point'] ?? '');
    if ($url === '') {
        throw new MpException('Mercado Pago no devolvió una URL de pago.');
    }
    return ['id' => (string) ($pref['id'] ?? ''), 'init_point' => $url];
}

/**
 * Petición genérica a la API de Mercado Pago. Devuelve el JSON decodificado.
 * Sin $token usa el de la plataforma (suscripciones); con él, el del
 * consultorio (cobros a pacientes).
 */
function mp_request(string $metodo, string $path, ?array $body = null, ?string $token = null): array
{
    $token = $token ?? mp_access_token();
    if ($token === '') {
        throw new MpException('Mercado Pago no está configurado.');
    }
    $ch = curl_init('https://api.mercadopago.com' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
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
        'reason'             => 'MediOS — Plan ' . $plan['nombre'],
        'external_reference' => 'consultorio:' . (int) $consultorio['id'] . '|plan:' . $planKey,
        'payer_email'        => $payerEmail,
        'back_url'           => rtrim(url_absoluta('/pagos/retorno'), '/'),
        'notification_url'   => url_absoluta('/pagos/webhook.php'),  // webhook queda con .php (excluido del redirect)
        'status'             => 'pending',
        'auto_recurring'     => [
            'frequency'          => 1,
            'frequency_type'     => 'months',
            'transaction_amount' => $plan['precio'],
            'currency_id'        => 'MXN',
        ],
    ];

    $r = mp_request('POST', '/preapproval', $payload);
    // El init_point ya corresponde al entorno del token (prueba o producción).
    $init = $r['init_point'] ?? ($r['sandbox_init_point'] ?? '');
    if (empty($r['id']) || $init === '') {
        throw new MpException('Mercado Pago no devolvió el enlace de pago.');
    }
    return ['id' => $r['id'], 'init_point' => $init];
}

/**
 * Crea una suscripción (preapproval) YA AUTORIZADA con un token de tarjeta
 * generado por el Card Payment Brick. No hay redirección: Mercado Pago cobra
 * el primer mes al instante y deja la suscripción activa, renovándose sola.
 * Devuelve el preapproval completo (incluye 'id' y 'status').
 */
function mp_crear_suscripcion_token(array $consultorio, string $planKey, string $payerEmail, string $cardToken): array
{
    $planes = planes_mp();
    if (!isset($planes[$planKey])) {
        throw new MpException('Plan no válido.');
    }
    if ($cardToken === '') {
        throw new MpException('Falta el token de la tarjeta.');
    }
    $plan = $planes[$planKey];

    $payload = [
        'reason'             => 'MediOS — Plan ' . $plan['nombre'],
        'external_reference' => 'consultorio:' . (int) $consultorio['id'] . '|plan:' . $planKey,
        'payer_email'        => $payerEmail,
        'card_token_id'      => $cardToken,
        'back_url'           => rtrim(url_absoluta('/pagos/retorno'), '/'),
        'notification_url'   => url_absoluta('/pagos/webhook.php'),
        'status'             => 'authorized',   // cobra y activa de inmediato
        'auto_recurring'     => [
            'frequency'          => 1,
            'frequency_type'     => 'months',
            'transaction_amount' => $plan['precio'],
            'currency_id'        => 'MXN',
        ],
    ];

    $r = mp_request('POST', '/preapproval', $payload);
    if (empty($r['id'])) {
        throw new MpException('Mercado Pago no devolvió la suscripción.');
    }
    return $r;
}

/** Consulta el estado de una suscripción (preapproval). */
function mp_obtener_suscripcion(string $id): array
{
    return mp_request('GET', '/preapproval/' . urlencode($id));
}

/** Cancela una suscripción (no se vuelve a cobrar). */
function mp_cancelar_suscripcion(string $id): array
{
    return mp_request('PUT', '/preapproval/' . urlencode($id), ['status' => 'cancelled']);
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

    // Estado anterior (para detectar una activación NUEVA y no duplicar correos).
    $prev = $pdo->prepare("SELECT estado FROM consultorios WHERE id=?");
    $prev->execute([$cid]);
    $estadoPrevio = $prev->fetchColumn();

    if ($estado === 'authorized') {
        $prox = !empty($pre['next_payment_date']) ? date('Y-m-d', strtotime($pre['next_payment_date'])) : null;
        $pdo->prepare("UPDATE consultorios SET estado='activa', plan=?, mp_suscripcion_id=?, mp_estado=?, proximo_cobro=? WHERE id=?")
            ->execute([$plan, $pre['id'] ?? null, $estado, $prox, $cid]);
        if ($estadoPrevio !== 'activa') {
            mp_notificar_activacion($cid, $plan, $prox);
        }
    } elseif (in_array($estado, ['paused', 'cancelled'], true)) {
        // No bloquea de inmediato: el acceso sigue hasta proximo_cobro (fin del periodo pagado).
        $pdo->prepare("UPDATE consultorios SET mp_estado=? WHERE id=?")->execute([$estado, $cid]);
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

/** Envía el correo de confirmación al admin del consultorio recién activado. */
function mp_notificar_activacion(int $cid, string $plan, ?string $proximo): void
{
    require_once __DIR__ . '/correo.php';
    $st = db()->prepare("SELECT nombre, email FROM usuarios WHERE consultorio_id=? AND rol='admin' ORDER BY id LIMIT 1");
    $st->execute([$cid]);
    if ($a = $st->fetch()) {
        $planNombre = planes_mp()[$plan]['nombre'] ?? $plan;
        @correo_suscripcion_activa($a['email'], $a['nombre'], $planNombre, $proximo);
    }
}

/* url_absoluta() vive en functions.php: la usan también los cobros, los
   recordatorios de cita y la agenda en línea, no solo Mercado Pago. */

