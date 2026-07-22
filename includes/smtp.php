<?php
/**
 * Cliente SMTP mínimo, sin dependencias (usa openssl/stream). Existe porque en
 * hosting compartido (Hostinger) la función mail() de PHP suele no entregar o
 * caer en spam; enviar por un buzón SMTP autenticado del propio dominio sí
 * llega. Si el SMTP no está configurado o falla, correo.php cae a mail().
 *
 * No implementa todo SMTP: solo EHLO → (STARTTLS) → AUTH LOGIN → MAIL/RCPT/DATA,
 * que es lo que necesita un correo transaccional.
 */

/** ¿Hay credenciales SMTP configuradas? */
function smtp_configurado(): bool
{
    return defined('SMTP_HOST') && SMTP_HOST !== '';
}

/**
 * Envía un correo por SMTP. Devuelve ['ok'=>bool, 'log'=>string] donde `log` es
 * la transcripción de la conversación (útil para diagnosticar en la plataforma).
 *
 * @param array $h  Headers ya armados como ['From'=>..., 'Reply-To'=>..., ...].
 */
function smtp_enviar(string $para, string $asuntoEnc, string $html, array $h): array
{
    $host   = SMTP_HOST;
    $port   = defined('SMTP_PORT') && SMTP_PORT ? (int) SMTP_PORT : 587;
    $user   = defined('SMTP_USER') ? SMTP_USER : '';
    $pass   = defined('SMTP_PASS') ? SMTP_PASS : '';
    // 'ssl' (implícito, 465), 'tls' (STARTTLS, 587) o '' (sin cifrado).
    $secure = defined('SMTP_SECURE') ? strtolower((string) SMTP_SECURE) : ($port === 465 ? 'ssl' : 'tls');
    $from   = CORREO_FROM;

    $trans = [];
    $fail  = function (string $msg) use (&$trans) {
        return ['ok' => false, 'log' => implode("\n", $trans) . "\n! " . $msg];
    };

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $dsn = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($dsn, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return $fail("No se pudo conectar a $host:$port ($errstr)");
    stream_set_timeout($fp, 15);

    // Lee una respuesta SMTP (soporta multilínea: "250-...", cierra con "250 ...").
    $leer = function () use ($fp, &$trans): array {
        $data = '';
        while (($linea = fgets($fp, 515)) !== false) {
            $data .= $linea;
            // El 4º carácter es '-' mientras haya más líneas; ' ' en la última.
            if (strlen($linea) < 4 || $linea[3] !== '-') break;
        }
        $trans[] = '< ' . rtrim($data);
        return [(int) substr($data, 0, 3), $data];
    };
    $escribir = function (string $cmd, bool $secreto = false) use ($fp, &$trans) {
        $trans[] = '> ' . ($secreto ? '****' : $cmd);
        fwrite($fp, $cmd . "\r\n");
    };

    [$code] = $leer();                                   // saludo 220
    if ($code !== 220) return $fail('El servidor no saludó (220)');

    $ehloHost = $_SERVER['SERVER_NAME'] ?? (substr(strrchr($from, '@'), 1) ?: 'localhost');
    $escribir('EHLO ' . $ehloHost);
    [$code] = $leer();
    if ($code !== 250) return $fail('EHLO rechazado');

    if ($secure === 'tls') {
        $escribir('STARTTLS');
        [$code] = $leer();
        if ($code !== 220) return $fail('STARTTLS rechazado');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('No se pudo negociar TLS');
        }
        $escribir('EHLO ' . $ehloHost);                  // re-EHLO tras cifrar
        [$code] = $leer();
        if ($code !== 250) return $fail('EHLO (tras TLS) rechazado');
    }

    if ($user !== '') {
        $escribir('AUTH LOGIN');
        [$code] = $leer();
        if ($code !== 334) return $fail('El servidor no aceptó AUTH LOGIN');
        $escribir(base64_encode($user), true);
        [$code] = $leer();
        if ($code !== 334) return $fail('Usuario rechazado');
        $escribir(base64_encode($pass), true);
        [$code] = $leer();
        if ($code !== 235) return $fail('Autenticación fallida (usuario o contraseña)');
    }

    $escribir('MAIL FROM:<' . $from . '>');
    [$code] = $leer();
    if ($code !== 250) return $fail('MAIL FROM rechazado');

    $escribir('RCPT TO:<' . $para . '>');
    [$code] = $leer();
    if ($code !== 250 && $code !== 251) return $fail('RCPT TO rechazado (destinatario)');

    $escribir('DATA');
    [$code] = $leer();
    if ($code !== 354) return $fail('DATA rechazado');

    // Cabecera + cuerpo. To/Subject van aquí (mail() los ponía por separado).
    $cab = $h;
    $cab['To']      = $para;
    $cab['Subject'] = $asuntoEnc;
    $lineas = [];
    foreach ($cab as $k => $v) $lineas[] = $k . ': ' . $v;
    $mensaje = implode("\r\n", $lineas) . "\r\n\r\n" . $html;

    // Normaliza a CRLF y aplica dot-stuffing (líneas que empiezan con '.').
    $mensaje = preg_replace('/\r\n|\r|\n/', "\r\n", $mensaje);
    $mensaje = preg_replace('/^\./m', '..', $mensaje);
    fwrite($fp, $mensaje . "\r\n.\r\n");
    $trans[] = '> [cuerpo del mensaje]';
    [$code] = $leer();
    if ($code !== 250) return $fail('El servidor no aceptó el mensaje');

    $escribir('QUIT');
    @fclose($fp);
    return ['ok' => true, 'log' => implode("\n", $trans)];
}
