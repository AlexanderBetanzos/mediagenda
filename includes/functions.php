<?php
/**
 * Funciones de ayuda: sesión, autenticación, seguridad y utilidades.
 */
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* --------------------------------------------------------------------
 *  Configuración del consultorio (tabla clave-valor)
 * ------------------------------------------------------------------ */

/** Devuelve todos los ajustes (cacheado por petición). $reset limpia la caché. */
function cfg_all(bool $reset = false): array
{
    static $cache = null;
    if ($reset)            { $cache = null; return []; }
    if ($cache !== null)   { return $cache; }
    $cache = [];
    try {
        foreach (db()->query('SELECT clave, valor FROM configuracion') as $row) {
            $cache[$row['clave']] = $row['valor'];
        }
    } catch (Throwable $e) {
        $cache = []; // la tabla aún no existe (antes de importar configuracion.sql)
    }
    return $cache;
}

/** Lee un ajuste; devuelve $default si está vacío o no existe. */
function cfg(string $clave, $default = '')
{
    $all = cfg_all();
    $v = $all[$clave] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

/** Guarda (upsert) un conjunto de ajustes clave => valor. */
function guardar_cfg(array $pares): void
{
    $stmt = db()->prepare(
        'INSERT INTO configuracion (clave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    foreach ($pares as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    cfg_all(true); // invalida la caché
}

/** Nombre comercial del consultorio (white-label). */
function marca_nombre(): string
{
    return cfg('marca_nombre', APP_NAME);
}

/** Código de moneda configurado. */
function moneda(): string
{
    return cfg('moneda', MONEDA);
}

/** Color de acento validado (#rrggbb); cae al de marca por defecto. */
function color_acento(): string
{
    $c = cfg('color_acento', '#0b6fb8');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#0b6fb8';
}

/** Tema activo: preferencia del usuario (cookie) o el default del consultorio. */
function tema_actual(): string
{
    $valid  = ['dark', 'light', 'auto'];
    $cookie = $_COOKIE['tema'] ?? '';
    if (in_array($cookie, $valid, true)) {
        return $cookie;
    }
    $def = cfg('tema_default', 'dark');
    return in_array($def, $valid, true) ? $def : 'dark';
}

// Aplica la zona horaria configurada (si la tabla ya existe).
date_default_timezone_set(cfg('zona_horaria', 'America/Mexico_City'));

/* --------------------------------------------------------------------
 *  Salida segura / utilidades
 * ------------------------------------------------------------------ */

/** Escapa texto para HTML. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Redirige a una ruta relativa a BASE_URL y termina. */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/* --------------------------------------------------------------------
 *  Mensajes flash
 * ------------------------------------------------------------------ */

function flash(string $mensaje, string $tipo = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $mensaje, 'tipo' => $tipo];
}

/** Devuelve y limpia los mensajes flash pendientes. */
function get_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* --------------------------------------------------------------------
 *  CSRF
 * ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto para formularios. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Verifica el token en peticiones POST; aborta si es inválido. */
function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            die('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
        }
    }
}

/* --------------------------------------------------------------------
 *  Autenticación y roles
 * ------------------------------------------------------------------ */

function current_user(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['usuario']);
}

/** Exige sesión iniciada; si no, manda al login. */
function require_login(): void
{
    if (!is_logged_in()) {
        flash('Debes iniciar sesión.', 'warning');
        redirect('/auth/login.php');
    }
}

/** Exige uno de los roles indicados. */
function require_role(string ...$roles): void
{
    require_login();
    $u = current_user();
    if (!in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        die('<h3 style="font-family:sans-serif;padding:2rem">403 — No tienes permiso para esta sección.</h3>'
            . '<p style="font-family:sans-serif;padding:0 2rem"><a href="' . BASE_URL . '/index.php">Volver al inicio</a></p>');
    }
}

function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u && in_array($u['rol'], $roles, true);
}

/** Etiqueta legible de un rol. */
function rol_label(string $rol): string
{
    return [
        'admin'     => 'Administrador',
        'medico'    => 'Médico / Dentista',
        'recepcion' => 'Recepción',
    ][$rol] ?? $rol;
}

/* --------------------------------------------------------------------
 *  Formato
 * ------------------------------------------------------------------ */

/** Formatea una fecha según el formato regional configurado (por defecto dd/mm/YYYY). */
function fmt_fecha(?string $f): string
{
    if (!$f) return '—';
    $t = strtotime($f);
    return $t ? date(cfg('formato_fecha', 'd/m/Y'), $t) : e($f);
}

/** Formatea una hora HH:MM:SS como HH:MM. */
function fmt_hora(?string $h): string
{
    if (!$h) return '—';
    $t = strtotime($h);
    return $t ? date('H:i', $t) : e($h);
}

/** Devuelve la fecha de hoy en español, ej: "jueves, 18 de junio de 2026". */
function fecha_hoy_larga(): string
{
    $dias  = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto',
              'septiembre','octubre','noviembre','diciembre'];
    return sprintf('%s, %d de %s de %s',
        $dias[(int) date('w')], (int) date('j'), $meses[(int) date('n')], date('Y'));
}

/** Calcula la edad a partir de la fecha de nacimiento. */
function edad(?string $fnac): string
{
    if (!$fnac) return '—';
    try {
        $d = new DateTime($fnac);
        return (new DateTime())->diff($d)->y . ' años';
    } catch (Exception $e) {
        return '—';
    }
}

/** Formatea un número como dinero, ej: $1,250.00. */
function fmt_money($n): string
{
    return '$' . number_format((float) $n, 2);
}

/** Devuelve color de badge Bootstrap según el estado de la cita. */
function estado_badge(string $estado): string
{
    return [
        'programada' => 'secondary',
        'confirmada' => 'info',
        'atendida'   => 'success',
        'cancelada'  => 'danger',
        'no_asistio' => 'warning',
    ][$estado] ?? 'secondary';
}

function estado_label(string $estado): string
{
    return [
        'programada' => 'Programada',
        'confirmada' => 'Confirmada',
        'atendida'   => 'Atendida',
        'cancelada'  => 'Cancelada',
        'no_asistio' => 'No asistió',
    ][$estado] ?? $estado;
}
