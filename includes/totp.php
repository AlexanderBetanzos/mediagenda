<?php
/**
 * TOTP (RFC 6238) sin dependencias — compatible con Google Authenticator,
 * Authy, Microsoft Authenticator, etc. HMAC-SHA1, 6 dígitos, paso de 30s.
 */

/** Alfabeto Base32 (RFC 4648). */
const TOTP_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** Genera un secreto Base32 nuevo (por defecto 160 bits / 32 chars). */
function totp_secret(int $bytes = 20): string
{
    $raw = random_bytes($bytes);
    $out = '';
    $bits = 0; $val = 0;
    for ($i = 0; $i < strlen($raw); $i++) {
        $val = ($val << 8) | ord($raw[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= TOTP_B32[($val >> $bits) & 31];
        }
    }
    if ($bits > 0) { $out .= TOTP_B32[($val << (5 - $bits)) & 31]; }
    return $out;
}

/** Decodifica Base32 a binario. Ignora relleno/espacios y es case-insensitive. */
function totp_b32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    $out = '';
    $bits = 0; $val = 0;
    for ($i = 0; $i < strlen($b32); $i++) {
        $val = ($val << 5) | strpos(TOTP_B32, $b32[$i]);
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($val >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** Código de 6 dígitos para un secreto y un instante (por defecto, ahora). */
function totp_code(string $secret, ?int $time = null, int $step = 30, int $digits = 6): string
{
    $time = $time ?? time();
    $counter = intdiv($time, $step);
    // Contador como 8 bytes big-endian.
    $bin = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $bin, totp_b32_decode($secret), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
         (ord($hash[$offset + 3]) & 0xFF)
    );
    return str_pad((string) ($part % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código aceptando una ventana de ±$window pasos (tolerancia a
 * desfase de reloj). Comparación en tiempo constante.
 */
function totp_verify(string $secret, string $code, int $window = 1, int $step = 30): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + $i * $step, $step), $code)) {
            return true;
        }
    }
    return false;
}

/** URI otpauth:// para generar el QR de enrolamiento. */
function totp_uri(string $secret, string $cuenta, string $emisor): string
{
    $label = rawurlencode($emisor . ':' . $cuenta);
    $q = http_build_query([
        'secret' => $secret,
        'issuer' => $emisor,
        'digits' => 6,
        'period' => 30,
    ]);
    return 'otpauth://totp/' . $label . '?' . $q;
}
