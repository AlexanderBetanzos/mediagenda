<?php
/**
 * Verificación en dos pasos (2FA) con app autenticadora (TOTP, RFC 6238):
 * Google Authenticator, Microsoft Authenticator, Authy… El secreto se guarda
 * cifrado; además hay 8 códigos de respaldo de un solo uso (guardados con hash).
 * También aquí: límite de intentos fallidos de inicio de sesión por IP.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cfdi.php';   // cfdi_cifrar / cfdi_descifrar

function asegurar_totp(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM usuarios')->fetchAll(), 'Field');
        if (!in_array('totp_secret', $cols, true)) {
            db()->exec('ALTER TABLE usuarios ADD COLUMN totp_secret TEXT NULL, ADD COLUMN totp_activo TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN totp_respaldo TEXT NULL');
        }
    } catch (Throwable $e) {}
}

function b32_encode(string $bin): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $ch) $out .= $a[bindec(str_pad($ch, 5, '0'))];
    return $out;
}

function b32_decode(string $s): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $by) if (strlen($by) === 8) $out .= chr(bindec($by));
    return $out;
}

function totp_codigo(string $secretB32, int $paso): string
{
    $h = hash_hmac('sha1', pack('N*', 0, $paso), b32_decode($secretB32), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Acepta el código actual y el de ±30 s (relojes desfasados). */
function totp_valido(string $secretB32, string $codigo): bool
{
    $codigo = preg_replace('/\D/', '', $codigo);
    if (strlen($codigo) !== 6) return false;
    $t = intdiv(time(), 30);
    foreach ([-1, 0, 1] as $d) if (hash_equals(totp_codigo($secretB32, $t + $d), $codigo)) return true;
    return false;
}

function totp_uri(string $secretB32, string $cuenta): string
{
    $emisor = APP_NAME;
    return 'otpauth://totp/' . rawurlencode($emisor . ':' . $cuenta) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($emisor) . '&digits=6&period=30';
}

/** Fila del usuario con sus datos de 2FA. */
function totp_usuario(int $uid): ?array
{
    asegurar_totp();
    $st = db()->prepare('SELECT id, nombre, email, totp_secret, totp_activo, totp_respaldo FROM usuarios WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** Comprueba un código de la app o uno de respaldo (que se gasta). */
function totp_verificar_usuario(int $uid, string $codigo): bool
{
    $u = totp_usuario($uid);
    if (!$u || !$u['totp_activo']) return false;
    $sec = cfdi_descifrar((string) $u['totp_secret']);
    if ($sec && totp_valido($sec, $codigo)) return true;
    // Código de respaldo (8 caracteres)
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    if (strlen($c) !== 8) return false;
    $lista = json_decode((string) $u['totp_respaldo'], true) ?: [];
    foreach ($lista as $i => $h) {
        if (password_verify($c, $h)) {
            unset($lista[$i]);
            db()->prepare('UPDATE usuarios SET totp_respaldo = ? WHERE id = ?')->execute([json_encode(array_values($lista)), $uid]);
            return true;
        }
    }
    return false;
}

/** Genera 8 códigos de respaldo; devuelve los visibles (solo se muestran una vez). */
function totp_generar_respaldo(int $uid): array
{
    $vis = []; $hash = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtoupper(substr(b32_encode(random_bytes(5)), 0, 8));
        $vis[] = substr($c, 0, 4) . '-' . substr($c, 4);
        $hash[] = password_hash($c, PASSWORD_DEFAULT);
    }
    db()->prepare('UPDATE usuarios SET totp_respaldo = ? WHERE id = ?')->execute([json_encode($hash), $uid]);
    return $vis;
}

/**
 * Límite de intentos: 8 fallos desde la misma IP en 15 minutos bloquean el
 * inicio de sesión desde esa IP por el resto de la ventana.
 */
function login_bloqueado(): bool
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM auditoria WHERE ip = ? AND accion IN ('login_fallido','2fa_fallido','plataforma_login_fallido') AND creado_en > NOW() - INTERVAL 15 MINUTE");
        $st->execute([client_ip()]);
        return (int) $st->fetchColumn() >= 8;
    } catch (Throwable $e) { return false; }
}

/* ------------------------------------------------------------------ *
 *  2FA de la consola de plataforma (tabla plataforma_admins)
 * ------------------------------------------------------------------ */
function asegurar_totp_plataforma(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        if (!db()->query("SHOW COLUMNS FROM plataforma_admins LIKE 'totp_secret'")->fetch()) {
            db()->exec('ALTER TABLE plataforma_admins ADD COLUMN totp_secret TEXT NULL, ADD COLUMN totp_activo TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN totp_respaldo TEXT NULL');
        }
    } catch (Throwable $e) {}
}

function totp_plataforma(int $id): ?array
{
    asegurar_totp_plataforma();
    $st = db()->prepare('SELECT id, nombre, email, totp_secret, totp_activo, totp_respaldo FROM plataforma_admins WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function totp_verificar_plataforma(int $id, string $codigo): bool
{
    $u = totp_plataforma($id);
    if (!$u || !$u['totp_activo']) return false;
    $sec = cfdi_descifrar((string) $u['totp_secret']);
    if ($sec && totp_valido($sec, $codigo)) return true;
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    if (strlen($c) !== 8) return false;
    $lista = json_decode((string) $u['totp_respaldo'], true) ?: [];
    foreach ($lista as $i => $h) {
        if (password_verify($c, $h)) {
            unset($lista[$i]);
            db()->prepare('UPDATE plataforma_admins SET totp_respaldo = ? WHERE id = ?')->execute([json_encode(array_values($lista)), $id]);
            return true;
        }
    }
    return false;
}

function totp_respaldo_plataforma(int $id): array
{
    $vis = []; $hash = [];
    for ($i = 0; $i < 8; $i++) {
        $c = strtoupper(substr(b32_encode(random_bytes(5)), 0, 8));
        $vis[] = substr($c, 0, 4) . '-' . substr($c, 4);
        $hash[] = password_hash($c, PASSWORD_DEFAULT);
    }
    db()->prepare('UPDATE plataforma_admins SET totp_respaldo = ? WHERE id = ?')->execute([json_encode($hash), $id]);
    return $vis;
}
