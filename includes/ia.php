<?php
/**
 * IA clínica: convierte lo que el médico dictó o pegó en los campos ya
 * estructurados de la consulta (motivo, exploración, diagnóstico, tratamiento,
 * receta y notas).
 *
 * Cliente de la API de Claude vía HTTP/cURL. Sin Composer, igual que
 * mercadopago.php y whatsapp.php: se usa la API REST directamente.
 * Docs: https://docs.anthropic.com/en/api/messages
 *
 * LA LLAVE ES DE LA PLATAFORMA, no del consultorio. Vive en
 * `plataforma_config`, al lado del token de Mercado Pago con el que cobramos
 * las suscripciones. Al revés que WhatsApp, y a propósito: pedirle a un médico
 * que abra cuenta en Anthropic y administre una API key no va a pasar nunca.
 *
 * La contrapartida es que el consumo LO PAGA LA PLATAFORMA, así que cada uso
 * se mide por consultorio (`ia_uso`) y hay tope mensual. Sin tope, un solo
 * cliente entusiasta se lleva el margen del mes.
 */
require_once __DIR__ . '/functions.php';

class IaException extends RuntimeException {}

/** Modelo y endpoint. */
const IA_MODELO   = 'claude-opus-5';
const IA_ENDPOINT = 'https://api.anthropic.com/v1/messages';
const IA_VERSION  = '2023-06-01';

/* Precios públicos de Claude Opus 5, USD por millón de tokens. Solo se usan
   para estimar el gasto en la consola; no se cobra nada con esto. */
const IA_USD_ENTRADA = 5.00;
const IA_USD_SALIDA  = 25.00;

/* --------------------------------------------------------------------
 *  Credencial y estado
 * ------------------------------------------------------------------ */

function ia_api_key(): string
{
    return plataforma_cfg('anthropic_api_key', '');
}

/** ¿La plataforma tiene llave cargada? */
function ia_configurada(): bool
{
    return ia_api_key() !== '';
}

/** ¿Este consultorio puede usar la IA clínica ahora mismo? */
function ia_disponible(): bool
{
    return modulo_activo('ia') && ia_configurada() && ia_restantes() > 0;
}

/* --------------------------------------------------------------------
 *  Medidor y tope (por consultorio y mes)
 * ------------------------------------------------------------------ */

/** Tope mensual de consultas procesadas. El dueño puede subirlo por consultorio. */
function ia_limite(): int
{
    $porTenant = (int) cfg('ia_limite_mes', '0');
    if ($porTenant > 0) return $porTenant;
    return max(0, (int) plataforma_cfg('ia_limite_mes', '200'));
}

/** Usos del consultorio en el mes en curso. */
function ia_usados(?int $cid = null, ?string $periodo = null): int
{
    $cid     = $cid ?: tenant_id();
    $periodo = $periodo ?: date('Y-m');
    try {
        $st = db()->prepare('SELECT usos FROM ia_uso WHERE consultorio_id = ? AND periodo = ?');
        $st->execute([$cid, $periodo]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;   // la tabla aún no existe: no bloquear
    }
}

/** Consultas que le quedan al consultorio este mes. */
function ia_restantes(): int
{
    return max(0, ia_limite() - ia_usados());
}

/** Suma un uso y sus tokens al medidor del mes. */
function ia_registrar_uso(int $entrada, int $salida): void
{
    try {
        db()->prepare(
            'INSERT INTO ia_uso (consultorio_id, periodo, usos, tokens_in, tokens_out)
             VALUES (?,?,1,?,?)
             ON DUPLICATE KEY UPDATE usos = usos + 1,
                                     tokens_in  = tokens_in  + VALUES(tokens_in),
                                     tokens_out = tokens_out + VALUES(tokens_out)'
        )->execute([tenant_id(), date('Y-m'), $entrada, $salida]);
    } catch (Throwable $e) { /* el medidor nunca debe romper una consulta */ }
}

/** Costo estimado en USD de un consumo de tokens. */
function ia_costo_usd(int $entrada, int $salida): float
{
    return ($entrada / 1e6) * IA_USD_ENTRADA + ($salida / 1e6) * IA_USD_SALIDA;
}

/* --------------------------------------------------------------------
 *  Bitácora
 * ------------------------------------------------------------------ */

function ia_log_path(): string
{
    return __DIR__ . '/../logs/ia.log';
}

function ia_log(bool $ok, string $extra = ''): void
{
    try {
        $dir = dirname(ia_log_path());
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents(ia_log_path(), sprintf("[%s] %s | tenant=%d | %s\n",
            date('Y-m-d H:i:s'), $ok ? 'OK ' : 'FALLO', tenant_id(), $extra),
            FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) { /* el log nunca debe romper una llamada */ }
}

/* --------------------------------------------------------------------
 *  Llamada
 * ------------------------------------------------------------------ */

/**
 * Petición a la API de Claude.
 * @throws IaException
 */
function ia_request(array $body): array
{
    $key = ia_api_key();
    if ($key === '') throw new IaException('La IA clínica no está configurada.');

    $ch = curl_init(IA_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,   // el modelo piensa antes de responder
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $key,
            'anthropic-version: ' . IA_VERSION,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new IaException('No se pudo conectar con el servicio de IA: ' . $err);

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) throw new IaException('El servicio de IA devolvió una respuesta ilegible.');

    if ($code >= 400) {
        throw new IaException($json['error']['message'] ?? ('Error ' . $code));
    }
    return $json;
}

/** Campos de la consulta que la IA puede rellenar (los mismos del formulario). */
function ia_campos_consulta(): array
{
    return [
        'motivo'      => 'Motivo de consulta y padecimiento actual, en una o dos frases.',
        'exploracion' => 'Exploración física y signos vitales mencionados.',
        'diagnostico' => 'Impresión diagnóstica.',
        'tratamiento' => 'Plan de tratamiento e indicaciones.',
        'receta'      => 'Medicamentos con dosis, vía y duración, uno por renglón.',
        'notas'       => 'Todo lo relevante que no encaje en los campos anteriores.',
    ];
}

/**
 * Convierte texto libre (dictado o pegado) en los campos de la consulta.
 *
 * Devuelve ['ok' => bool, 'campos' => array, 'mensaje' => string, 'uso' => array].
 * Nunca lanza: el formulario debe seguir usable aunque la IA falle.
 */
function ia_estructurar_consulta(string $texto, array $paciente = []): array
{
    $vacio = array_fill_keys(array_keys(ia_campos_consulta()), '');

    $texto = trim($texto);
    if (mb_strlen($texto) < 20) {
        return ['ok' => false, 'campos' => $vacio,
                'mensaje' => t('Escribe o dicta un poco más para que la IA tenga con qué trabajar.'), 'uso' => []];
    }
    if (!modulo_activo('ia')) {
        return ['ok' => false, 'campos' => $vacio,
                'mensaje' => t('La IA clínica no está incluida en tu plan.'), 'uso' => []];
    }
    if (!ia_configurada()) {
        return ['ok' => false, 'campos' => $vacio,
                'mensaje' => t('La IA clínica todavía no está disponible. Inténtalo más tarde.'), 'uso' => []];
    }
    if (ia_restantes() <= 0) {
        return ['ok' => false, 'campos' => $vacio,
                'mensaje' => t('Se agotaron tus consultas con IA de este mes.'), 'uso' => []];
    }

    // El esquema obliga a que vuelvan TODOS los campos: así el front puede
    // rellenar sin comprobar cuáles llegaron, y un campo sin datos vuelve vacío
    // en lugar de inventado.
    $props = [];
    foreach (ia_campos_consulta() as $clave => $desc) {
        $props[$clave] = ['type' => 'string', 'description' => $desc];
    }

    $contexto = '';
    if ($paciente) {
        $contexto = "\n\nDatos del paciente: "
            . trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apellidos'] ?? ''))
            . (!empty($paciente['edad'])    ? ', ' . $paciente['edad'] : '')
            . (!empty($paciente['sexo'])    ? ', sexo ' . $paciente['sexo'] : '')
            . (!empty($paciente['alergias']) ? ". Alergias: " . $paciente['alergias'] : '');
    }

    $sistema = "Eres asistente de un médico en México. Recibes la transcripción o las notas "
        . "sueltas de una consulta y las organizas en los campos del expediente.\n\n"
        . "Reglas:\n"
        . "- Usa ÚNICAMENTE lo que aparezca en el texto. No inventes signos vitales, "
        . "dosis, diagnósticos ni hallazgos que no se hayan dicho.\n"
        . "- Si un campo no tiene información, devuélvelo como cadena vacía.\n"
        . "- Escribe en español, en tercera persona y con terminología clínica, "
        . "conservando las palabras del médico cuando sean precisas.\n"
        . "- No agregues advertencias ni recomendaciones tuyas: esto lo revisa y firma el médico.";

    try {
        $r = ia_request([
            'model'      => IA_MODELO,
            'max_tokens' => 16000,
            'thinking'   => ['type' => 'adaptive'],
            'system'     => $sistema,
            'output_config' => [
                'format' => [
                    'type'   => 'json_schema',
                    'schema' => [
                        'type'                 => 'object',
                        'properties'           => $props,
                        'required'             => array_keys($props),
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'messages' => [[
                'role'    => 'user',
                'content' => "Organiza esta consulta en los campos del expediente:\n\n" . $texto . $contexto,
            ]],
        ]);
    } catch (Throwable $e) {
        ia_log(false, $e->getMessage());
        return ['ok' => false, 'campos' => $vacio, 'mensaje' => $e->getMessage(), 'uso' => []];
    }

    // Una negativa del modelo llega como HTTP 200: hay que mirar stop_reason.
    if (($r['stop_reason'] ?? '') === 'refusal') {
        ia_log(false, 'refusal');
        return ['ok' => false, 'campos' => $vacio,
                'mensaje' => t('La IA no pudo procesar este texto. Captura la consulta a mano.'), 'uso' => []];
    }

    // El contenido es una lista de bloques; con thinking activo el primero
    // puede ser un bloque de razonamiento, así que se busca el de texto.
    $json = '';
    foreach ($r['content'] ?? [] as $bloque) {
        if (($bloque['type'] ?? '') === 'text') { $json = (string) $bloque['text']; break; }
    }
    $datos = json_decode($json, true);
    if (!is_array($datos)) {
        ia_log(false, 'respuesta sin JSON válido');
        return ['ok' => false, 'campos' => $vacio,
                'mensaje' => t('La IA devolvió algo que no se pudo leer. Inténtalo otra vez.'), 'uso' => []];
    }

    $entrada = (int) ($r['usage']['input_tokens']  ?? 0);
    $salida  = (int) ($r['usage']['output_tokens'] ?? 0);
    ia_registrar_uso($entrada, $salida);
    ia_log(true, sprintf('in=%d out=%d usd=%.4f', $entrada, $salida, ia_costo_usd($entrada, $salida)));

    // Solo se devuelven los campos conocidos, saneados a texto plano.
    $campos = $vacio;
    foreach (array_keys($campos) as $clave) {
        $campos[$clave] = trim((string) ($datos[$clave] ?? ''));
    }

    return ['ok' => true, 'campos' => $campos, 'mensaje' => '',
            'uso' => ['entrada' => $entrada, 'salida' => $salida, 'restantes' => ia_restantes()]];
}
