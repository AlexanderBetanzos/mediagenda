<?php
/**
 * Cliente de WhatsApp Cloud API (Meta) vía HTTP/cURL. Sin Composer, igual que
 * mercadopago.php: se usa la API REST directamente.
 * Docs: https://developers.facebook.com/docs/whatsapp/cloud-api
 *
 * LAS CREDENCIALES SON DE CADA CONSULTORIO, no de la plataforma. Viven en
 * `configuracion` (vía cfg()) como las de Mercado Pago del tenant, y por la
 * misma razón: el mensaje sale DEL NÚMERO DEL DOCTOR. Si saliera de un número
 * nuestro, el paciente leería "MediOS" en vez del nombre del consultorio, que
 * es justo lo contrario de lo que vende este producto. Además Meta le cobra la
 * conversación al dueño de la cuenta.
 *
 * Meta NO deja mandar texto libre a alguien que no te escribió primero: hay que
 * usar una PLANTILLA aprobada por ellos. Por eso aquí solo se envían
 * plantillas, y el consultorio configura cuál usar.
 */
require_once __DIR__ . '/functions.php';

class WaException extends RuntimeException {}

/** Versión de la Graph API contra la que se habla. */
const WA_API_VERSION = 'v21.0';

/* --------------------------------------------------------------------
 *  Credenciales y estado (por consultorio)
 * ------------------------------------------------------------------ */

function wa_token():     string { return (string) cfg('wa_token', ''); }
function wa_phone_id():  string { return (string) cfg('wa_phone_id', ''); }
function wa_plantilla(): string { return (string) cfg('wa_plantilla', ''); }
function wa_idioma():    string { return (string) cfg('wa_idioma', 'es_MX'); }

/** ¿Este consultorio puede mandar WhatsApp automático? */
function wa_configurado(): bool
{
    return wa_token() !== '' && wa_phone_id() !== '' && wa_plantilla() !== '';
}

/**
 * ¿Los recordatorios deben salir por WhatsApp?
 * Requiere el módulo contratado, credenciales completas y el interruptor.
 */
function wa_auto_activo(): bool
{
    return modulo_activo('whatsapp') && wa_configurado() && cfg('wa_auto', '0') === '1';
}

/* --------------------------------------------------------------------
 *  Bitácora — mismo trato que los correos: si "no llegan", hay dónde ver
 * ------------------------------------------------------------------ */

function wa_log_path(): string
{
    return __DIR__ . '/../logs/whatsapp.log';
}

function wa_log(string $para, string $plantilla, bool $ok, string $extra = ''): void
{
    try {
        $dir = dirname(wa_log_path());
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $linea = sprintf("[%s] %s | tenant=%d | to=%s | plantilla=%s%s\n",
            date('Y-m-d H:i:s'),
            $ok ? 'OK ' : 'FALLO',
            tenant_id(),
            $para,
            $plantilla,
            $extra !== '' ? ' | ' . $extra : ''
        );
        @file_put_contents(wa_log_path(), $linea, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) { /* el log nunca debe romper un envío */ }
}

/* --------------------------------------------------------------------
 *  Transporte
 * ------------------------------------------------------------------ */

/**
 * Petición a la Graph API con las credenciales del consultorio activo.
 * @throws WaException si la red falla o Meta responde error.
 */
function wa_request(string $path, array $body): array
{
    $token = wa_token();
    if ($token === '') throw new WaException('Este consultorio no tiene WhatsApp configurado.');

    $ch = curl_init('https://graph.facebook.com/' . WA_API_VERSION . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new WaException('No se pudo conectar con WhatsApp: ' . $err);

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) throw new WaException('WhatsApp devolvió una respuesta ilegible.');

    if ($code >= 400) {
        // Meta anida el detalle útil; el genérico no le dice nada a nadie.
        $msg = $json['error']['error_user_msg']
            ?? $json['error']['message']
            ?? ('Error ' . $code);
        throw new WaException($msg);
    }
    return $json;
}

/**
 * Envía una plantilla aprobada.
 *
 * @param string   $telefono  Tal como está en el expediente; se normaliza a E.164.
 * @param string[] $params    Variables {{1}}, {{2}}… en orden.
 * @return array{ok:bool, mensaje:string, id:string}
 */
function wa_enviar_plantilla(string $telefono, array $params = [], ?string $plantilla = null): array
{
    $plantilla = $plantilla ?: wa_plantilla();
    $tel = tel_e164($telefono);

    if ($tel === '') {
        wa_log($telefono, $plantilla, false, 'teléfono inválido');
        return ['ok' => false, 'mensaje' => 'Teléfono inválido.', 'id' => ''];
    }
    if (!wa_configurado()) {
        wa_log($tel, $plantilla, false, 'sin credenciales');
        return ['ok' => false, 'mensaje' => 'WhatsApp no está configurado.', 'id' => ''];
    }

    $componentes = [];
    if ($params) {
        $componentes[] = [
            'type'       => 'body',
            'parameters' => array_map(
                fn($p) => ['type' => 'text', 'text' => mb_substr((string) $p, 0, 1024)],
                array_values($params)
            ),
        ];
    }

    try {
        $r = wa_request(wa_phone_id() . '/messages', [
            'messaging_product' => 'whatsapp',
            'to'                => $tel,
            'type'              => 'template',
            'template'          => [
                'name'       => $plantilla,
                'language'   => ['code' => wa_idioma()],
                'components' => $componentes,
            ],
        ]);
        $id = (string) ($r['messages'][0]['id'] ?? '');
        wa_log($tel, $plantilla, true, $id);
        return ['ok' => true, 'mensaje' => 'Mensaje enviado.', 'id' => $id];
    } catch (Throwable $e) {
        wa_log($tel, $plantilla, false, $e->getMessage());
        return ['ok' => false, 'mensaje' => $e->getMessage(), 'id' => ''];
    }
}

/**
 * Recordatorio de cita por WhatsApp.
 *
 * La plantilla del consultorio debe tener 4 variables, en este orden:
 *   {{1}} paciente   {{2}} fecha   {{3}} hora   {{4}} médico
 * Se documenta en la pantalla de Configuración junto al texto sugerido, porque
 * el orden lo fija Meta al aprobar la plantilla y aquí ya no se puede adivinar.
 */
function wa_recordatorio_cita(string $telefono, string $paciente, string $fecha,
                              string $hora, string $medico): array
{
    return wa_enviar_plantilla($telefono, [$paciente, $fecha, $hora, $medico]);
}
