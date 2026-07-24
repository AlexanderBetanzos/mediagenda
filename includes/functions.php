<?php
/**
 * Funciones de ayuda: sesión, autenticación, seguridad y utilidades.
 */
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* --------------------------------------------------------------------
 *  Multi-tenant: contexto del consultorio (tenant) activo
 * ------------------------------------------------------------------ */

/**
 * Fija el consultorio activo sin sesión. Lo usan las páginas públicas que sí
 * pertenecen a un tenant (el link de pago, el webhook de cobros), donde el
 * consultorio se deduce del token o de la URL, no de quién esté conectado.
 * Invalida las cachés que dependen del tenant.
 */
function tenant_forzar(int $id): void
{
    tenant_override($id);
    tenant(true);
    cfg_all(true);
    modulos_activos(true);
}

/** Getter/setter del override de tenant. Sin argumento, solo consulta. */
function tenant_override(?int $id = null, bool $limpiar = false): ?int
{
    static $override = null;
    if ($limpiar)         $override = null;
    elseif ($id !== null) $override = $id;
    return $override;
}

/** ID del consultorio activo: el forzado, el del usuario o paciente en sesión, o 1 (público). */
function tenant_id(): int
{
    if (($forzado = tenant_override()) !== null)         return $forzado;
    if (isset($_SESSION['usuario']['consultorio_id']))  return (int) $_SESSION['usuario']['consultorio_id'];
    if (isset($_SESSION['paciente']['consultorio_id'])) return (int) $_SESSION['paciente']['consultorio_id'];
    return 1;
}

/* --------------------------------------------------------------------
 *  Portal del paciente (sesión separada de la del personal)
 * ------------------------------------------------------------------ */

/** Paciente en sesión (portal), o null. */
function current_paciente(): ?array
{
    return $_SESSION['paciente'] ?? null;
}

/** Exige sesión de paciente; si no, manda al login del portal. */
function require_paciente(): void
{
    if (!isset($_SESSION['paciente'])) {
        redirect('/portal/login');
    }
    // El portal es un módulo de plan: si el consultorio deja de incluirlo (p. ej.
    // al terminar la prueba y quedarse en Básico), la sesión del paciente muere.
    if (!modulo_activo('portal')) {
        unset($_SESSION['paciente']);
        redirect('/portal/login?inactivo=1');
    }
}

/** ¿El usuario en sesión es súper-administrador (dueño del producto)? */
function es_superadmin(): bool
{
    return !empty($_SESSION['usuario']['es_superadmin']);
}

/** Fila del consultorio activo (cacheada). null si la tabla aún no existe. */
function tenant(bool $reset = false): ?array
{
    static $cache = [];
    $tid = tenant_id();
    if ($reset) { $cache = []; return null; }
    if (array_key_exists($tid, $cache)) return $cache[$tid] ?: null;
    $cache[$tid] = false;
    try {
        $st = db()->prepare('SELECT * FROM consultorios WHERE id = ?');
        $st->execute([$tid]);
        $cache[$tid] = $st->fetch() ?: false;
    } catch (Throwable $e) { /* tabla consultorios aún no creada */ }
    return $cache[$tid] ?: null;
}

/** Días restantes de prueba (negativo si ya venció). null si no aplica. */
function trial_dias_restantes(): ?int
{
    $t = tenant();
    if (!$t || empty($t['trial_fin'])) return null;
    return (int) floor((strtotime($t['trial_fin']) - strtotime('today')) / 86400);
}

/** ¿La fila $id de $tabla pertenece al consultorio activo? (anti cross-tenant) */
function pertenece_al_tenant(string $tabla, int $id): bool
{
    $permitidas = ['pacientes', 'usuarios', 'citas', 'consultas', 'recetas', 'facturas', 'archivos', 'productos',
                   'servicios', 'presupuestos'];
    if ($id <= 0 || !in_array($tabla, $permitidas, true)) return false;
    $st = db()->prepare("SELECT 1 FROM $tabla WHERE id = ? AND consultorio_id = ?");
    $st->execute([$id, tenant_id()]);
    return (bool) $st->fetchColumn();
}

/** ¿El consultorio está bloqueado (prueba vencida, suspendido o suscripción terminada)? */
function tenant_bloqueado(): bool
{
    $t = tenant();
    if (!$t) return false;
    // Membresía activa (incluye la activación manual desde la plataforma) manda:
    // nunca se bloquea, aunque haya restos de una suscripción MP cancelada.
    if (($t['estado'] ?? '') === 'activa') return false;
    if (in_array($t['estado'], ['suspendida', 'expirada'], true)) return true;
    if ($t['estado'] === 'trial' && (trial_dias_restantes() ?? 0) < 0) return true;
    // Suscripción cancelada/pausada: acceso hasta el fin del periodo ya pagado.
    if (in_array($t['mp_estado'] ?? '', ['cancelled', 'paused'], true)
        && !empty($t['proximo_cobro'])
        && strtotime($t['proximo_cobro']) < strtotime('today')) {
        return true;
    }
    return false;
}

/* --------------------------------------------------------------------
 *  Entitlements: módulos activos según el plan del consultorio
 * ------------------------------------------------------------------ */

/**
 * Módulos activos para el consultorio en sesión.
 * Devuelve ['*'] cuando aplica acceso total (fail-open): súper-admin, sin
 * tenant, en prueba vigente, plan sin mapeo, o tablas aún no creadas. Así
 * ninguna función desaparece por accidente; el gating real solo recorta
 * cuando el plan define explícitamente su lista de módulos.
 */
function modulos_activos(bool $reset = false): array
{
    static $cache = [];
    if ($reset) { $cache = []; return ['*']; }
    if (es_superadmin()) return ['*'];

    $tid = tenant_id();
    if (isset($cache[$tid])) return $cache[$tid];

    $t = tenant();
    // Sin tenant o en prueba vigente: acceso completo para evaluar.
    if (!$t || ($t['estado'] === 'trial' && (trial_dias_restantes() ?? 1) >= 0)) {
        return $cache[$tid] = ['*'];
    }

    try {
        $st = db()->prepare('SELECT modulo_clave FROM plan_modulos WHERE plan_clave = ?');
        $st->execute([$t['plan'] ?? '']);
        $mods = $st->fetchAll(PDO::FETCH_COLUMN);

        // Overrides por consultorio (add-ons activan, activo=0 desactiva).
        $ov = db()->prepare('SELECT modulo_clave, activo FROM consultorio_modulos WHERE consultorio_id = ?');
        $ov->execute([$tid]);
        $overrides = $ov->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($overrides as $clave => $on) {
            if ($on) { $mods[] = $clave; } else { $mods = array_diff($mods, [$clave]); }
        }

        // Plan sin mapeo y sin overrides: fail-open (no bloquear).
        if (!$mods && !$overrides) return $cache[$tid] = ['*'];
        return $cache[$tid] = array_values(array_unique($mods));
    } catch (Throwable $e) {
        return $cache[$tid] = ['*']; // tablas de planes aún no creadas
    }
}

/** ¿El consultorio activo tiene contratado el módulo $clave? */
function modulo_activo(string $clave): bool
{
    $m = modulos_activos();
    return in_array('*', $m, true) || in_array($clave, $m, true);
}

/**
 * ¿El consultorio $cid tiene contratado el módulo $clave?
 * Para contextos donde aún no hay sesión y por tanto tenant_id() no sirve:
 * el login del portal resuelve el consultorio a partir del paciente.
 */
function modulo_activo_en(int $cid, string $clave): bool
{
    $previo = tenant_override();
    tenant_override($cid);
    $ok = modulo_activo($clave);
    $previo === null ? tenant_override(null, true) : tenant_override($previo);
    return $ok;
}

/** Exige que el módulo esté activo; si no, redirige a la página de planes. */
function require_modulo(string $clave): void
{
    if (!modulo_activo($clave)) {
        flash('Esa función no está incluida en tu plan. Mejora tu plan para activarla.', 'warning');
        redirect('/pagos/index');
    }
}

/**
 * Igual que require_modulo(), pero para endpoints que responden JSON: un
 * redirect 302 llegaría al fetch como HTML y reventaría el parseo sin decir
 * por qué. Aquí el front recibe un 403 que sí puede mostrar.
 */
function require_modulo_json(string $clave): void
{
    if (!modulo_activo($clave)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => t('Esa función no está incluida en tu plan.')]);
        exit;
    }
}

/* --------------------------------------------------------------------
 *  Configuración del consultorio (clave-valor, POR consultorio)
 * ------------------------------------------------------------------ */

/** Devuelve los ajustes del consultorio activo (cacheado por tenant). */
function cfg_all(bool $reset = false): array
{
    static $cache = [];
    if ($reset) { $cache = []; return []; }
    $tid = tenant_id();
    if (isset($cache[$tid])) return $cache[$tid];
    $out = [];
    try {
        $st = db()->prepare('SELECT clave, valor FROM configuracion WHERE consultorio_id = ?');
        $st->execute([$tid]);
        foreach ($st as $row) { $out[$row['clave']] = $row['valor']; }
    } catch (Throwable $e) {
        $out = []; // la tabla aún no existe
    }
    $cache[$tid] = $out;
    return $out;
}

/** Lee un ajuste; devuelve $default si está vacío o no existe. */
function cfg(string $clave, $default = '')
{
    $all = cfg_all();
    $v = $all[$clave] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

/** Guarda (upsert) ajustes del consultorio activo. */
function guardar_cfg(array $pares): void
{
    $tid  = tenant_id();
    $stmt = db()->prepare(
        'INSERT INTO configuracion (consultorio_id, clave, valor) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    foreach ($pares as $k => $v) {
        $stmt->execute([$tid, $k, $v]);
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
    $c = cfg('color_acento', '#2563eb');
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#2563eb';
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

/* --------------------------------------------------------------------
 *  Idioma / i18n
 * ------------------------------------------------------------------ */

/** Idioma activo: cookie del usuario, o el default del consultorio, o 'es'. */
function idioma_actual(): string
{
    $valid  = ['es', 'en'];
    $cookie = $_COOKIE['lang'] ?? '';
    if (in_array($cookie, $valid, true)) return $cookie;
    $def = cfg('idioma_default', 'es');
    return in_array($def, $valid, true) ? $def : 'es';
}

/**
 * Traduce un texto. El idioma base es español: la clave ES el texto en
 * español, así que en 'es' se devuelve tal cual. Para otros idiomas se busca
 * en lang/<idioma>.php (mapa español => traducción); si falta, cae al español.
 */
function t(string $texto): string
{
    $idi = idioma_actual();
    if ($idi === 'es') return $texto;
    static $cache = [];
    if (!isset($cache[$idi])) {
        $f = __DIR__ . '/../lang/' . $idi . '.php';
        $cache[$idi] = is_file($f) ? (require $f) : [];
    }
    return $cache[$idi][$texto] ?? $texto;
}

/** Escapa y traduce en un solo paso (para imprimir en HTML). */
function et(string $texto): string { return e(t($texto)); }

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

/** URL de un asset con cache-busting (?v=fecha de modificación). */
function asset(string $rel): string
{
    $rel = ltrim($rel, '/');
    $v   = @filemtime(__DIR__ . '/../' . $rel) ?: 1;
    return BASE_URL . '/' . $rel . '?v=' . $v;
}

/** Redirige a una ruta relativa a BASE_URL y termina. */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/**
 * Cierra la respuesta al navegador para seguir trabajando en segundo plano
 * (p. ej. enviar un correo por SMTP) sin que el usuario espere. En Hostinger
 * (PHP-FPM/LiteSpeed) el navegador recibe la página al instante y el correo se
 * envía después. Libera antes el lock de sesión para no bloquear la siguiente
 * petición del propio usuario.
 */
function cerrar_respuesta(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request'))      { @fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request'))    { @litespeed_finish_request(); return; }
    // Fallback (mod_php): al menos vacía los búferes de salida.
    while (ob_get_level() > 0) @ob_end_flush();
    @flush();
}

/* --------------------------------------------------------------------
 *  Auditoría (bitácora de actividad)
 * ------------------------------------------------------------------ */

/** IP del cliente (considera proxies comunes; valida el formato). */
function client_ip(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v) {
            $ip = trim(explode(',', $v)[0]); // primer salto en X-Forwarded-For
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}

/**
 * Registra un evento en la bitácora. Nunca interrumpe la aplicación: si la
 * tabla no existe o falla, lo ignora en silencio.
 *
 * @param int|null $tenant_id  Consultorio; por defecto el de la sesión.
 * @param array{id?:int,nombre?:string}|null $actor  Usuario; por defecto el de la sesión.
 */
function auditar(string $accion, ?string $entidad = null, ?int $entidad_id = null,
                 ?string $detalle = null, ?int $tenant_id = null, ?array $actor = null): void
{
    try {
        $u = $actor ?? current_user();
        db()->prepare(
            'INSERT INTO auditoria
             (consultorio_id, usuario_id, usuario_nombre, accion, entidad, entidad_id, detalle, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $tenant_id ?? tenant_id(),
            isset($u['id']) ? (int) $u['id'] : null,
            $u['nombre'] ?? null,
            $accion,
            $entidad,
            $entidad_id,
            $detalle !== null ? mb_substr($detalle, 0, 255) : null,
            client_ip(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null,
        ]);
    } catch (Throwable $e) { /* la tabla aún no existe o no se pudo registrar */ }
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
        redirect('/auth/login');
    }
    // Si el código llegó antes que la migración, el esquema se crea aquí en vez
    // de dejar el sitio caído (fotos) o el módulo invisible (agenda en línea).
    asegurar_esquema_fotos();
    asegurar_esquema_agenda();
    asegurar_esquema_medicos();
    // Gating de suscripción: si la prueba venció o el consultorio está suspendido,
    // se bloquea el acceso (salvo páginas que declaran ALLOW_INACTIVE, p. ej.
    // la pantalla de suscripción o el cierre de sesión).
    if (!defined('ALLOW_INACTIVE') && !es_superadmin() && tenant_bloqueado()) {
        redirect('/auth/suscripcion');
    }
}

/** Exige súper-administrador (panel de gestión de consultorios). */
function require_superadmin(): void
{
    require_login();
    if (!es_superadmin()) {
        http_response_code(403);
        die('<h3 style="font-family:sans-serif;padding:2rem">403 — Solo para súper-administradores.</h3>');
    }
}

/* --------------------------------------------------------------------
 *  Plataforma (consola del dueño) — sesión INDEPENDIENTE del consultorio.
 *  Vive en $_SESSION['plataforma_admin'] (tabla plataforma_admins), aparte
 *  de $_SESSION['usuario'] (personal de un consultorio).
 * ------------------------------------------------------------------ */

/** Súper usuario de plataforma en sesión, o null. */
function platform_admin(): ?array
{
    return $_SESSION['plataforma_admin'] ?? null;
}

/** Exige sesión de plataforma; si no, manda al login de plataforma. */
function require_platform(): void
{
    if (empty($_SESSION['plataforma_admin'])) {
        redirect('/platform/login');
    }
}

/** Crea/actualiza las tablas de admins de plataforma si aún no existen. */
function ensure_plataforma_admins_table(): void
{
    $db = db();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS plataforma_admins (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            nombre        VARCHAR(120) NOT NULL,
            email         VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            activo        TINYINT(1) NOT NULL DEFAULT 1,
            ultimo_acceso TIMESTAMP NULL DEFAULT NULL,
            creado_en     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Rol: 'super' (dueño, acceso total) o 'socio' (acceso a los consultorios
    // que el dueño le asigne). Los admins existentes quedan como 'super'.
    try { $db->exec("ALTER TABLE plataforma_admins ADD COLUMN IF NOT EXISTS rol ENUM('super','socio') NOT NULL DEFAULT 'super'"); }
    catch (Throwable $e) { /* MySQL viejo sin IF NOT EXISTS: se ignora */ }
    try { $db->exec("ALTER TABLE plataforma_admins ADD COLUMN IF NOT EXISTS telefono VARCHAR(30) DEFAULT NULL"); }
    catch (Throwable $e) { /* idem */ }
    // Qué consultorios ve/gestiona cada socio (el super los ve todos).
    $db->exec(
        "CREATE TABLE IF NOT EXISTS plataforma_admin_consultorios (
            admin_id       INT NOT NULL,
            consultorio_id INT NOT NULL,
            creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id, consultorio_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** ¿El admin de plataforma en sesión es el dueño (súper), no un socio? */
function platform_es_super(): bool
{
    $pa = platform_admin();
    // Sin rol en sesión (admins creados antes de esta feature) = súper.
    return $pa !== null && (($pa['rol'] ?? 'super') === 'super');
}

/** Exige sesión de plataforma Y que sea el dueño. Los socios no entran. */
function require_platform_super(): void
{
    require_platform();
    if (!platform_es_super()) {
        flash('Esa sección es solo del dueño del sistema.', 'warning');
        redirect('/platform/index');
    }
}

/**
 * IDs de consultorios que el admin de plataforma puede ver. null = todos
 * (cuando es súper). Para socios, los que el dueño les asignó.
 */
function platform_consultorios_visibles(): ?array
{
    if (platform_es_super()) return null;
    $pa = platform_admin();
    $st = db()->prepare('SELECT consultorio_id FROM plataforma_admin_consultorios WHERE admin_id = ?');
    $st->execute([(int) ($pa['id'] ?? 0)]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** ¿El admin actual puede ver/gestionar el consultorio $cid? */
function platform_puede_ver(int $cid): bool
{
    $vis = platform_consultorios_visibles();
    return $vis === null || in_array($cid, $vis, true);
}

/** Guard para páginas por-consultorio: 403 si el socio no lo tiene asignado. */
function require_platform_consultorio(int $cid): void
{
    require_platform();
    if (!platform_puede_ver($cid)) {
        flash('No tienes acceso a ese consultorio.', 'warning');
        redirect('/platform/index');
    }
}

/* --------------------------------------------------------------------
 *  Configuración GLOBAL de la plataforma (del dueño, no de un consultorio).
 *  Vive en `plataforma_config`; la edita solo la consola de plataforma.
 * ------------------------------------------------------------------ */

/** Crea la tabla de configuración de plataforma si aún no existe. */
function ensure_plataforma_config_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS plataforma_config (
            clave          VARCHAR(60) PRIMARY KEY,
            valor          TEXT DEFAULT NULL,
            actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Lee un ajuste global. Devuelve $default si está vacío, no existe, o la tabla
 * aún no se ha creado: así el sistema arranca sin tocar la base.
 */
function plataforma_cfg(string $clave, string $default = '', bool $reset = false): string
{
    static $cache = null;
    if ($reset) { $cache = null; return $default; }
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT clave, valor FROM plataforma_config') as $row) {
                $cache[$row['clave']] = (string) $row['valor'];
            }
        } catch (Throwable $e) { /* la tabla aún no existe */ }
    }
    $v = $cache[$clave] ?? '';
    return $v !== '' ? $v : $default;
}

/** Guarda (upsert) ajustes globales de plataforma. */
function guardar_plataforma_cfg(array $pares): void
{
    ensure_plataforma_config_table();
    $stmt = db()->prepare(
        'INSERT INTO plataforma_config (clave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
    );
    foreach ($pares as $k => $v) { $stmt->execute([$k, $v]); }
    plataforma_cfg('', '', true); // invalida la caché
}

/** Muestra solo los últimos 4 caracteres de un secreto: APP_USR-…3f2a. */
function secreto_enmascarado(string $valor): string
{
    if ($valor === '') return '';
    $cola = mb_substr($valor, -4);
    $cabeza = str_contains($valor, '-') ? explode('-', $valor)[0] . '-' : '';
    return $cabeza . str_repeat('•', 10) . $cola;
}

/* --------------------------------------------------------------------
 *  Analítica de tráfico (pageviews) — para las métricas de plataforma.
 * ------------------------------------------------------------------ */

/** Crea la tabla de visitas si no existe. */
function ensure_pageviews_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS pageviews (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(20) NOT NULL,
            path VARCHAR(120) NOT NULL,
            consultorio_id INT NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'guest',
            visitor CHAR(32) NULL,
            ip VARCHAR(45) NULL,
            ua VARCHAR(255) NULL,
            referer VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_area_created (area, created_at),
            INDEX idx_visitor (visitor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/**
 * Registra una visita (una vez por request GET). Áreas:
 * 'publico' | 'panel' | 'portal' | 'plataforma'. Nunca rompe la página.
 */
function track_pageview(string $area): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua !== '' && preg_match('~bot|crawl|spider|slurp|bingpreview|facebookexternalhit|preview|monitor|headless|curl|wget|python-requests~i', $ua)) return;

    try {
        ensure_pageviews_table();
        $vid = (string) ($_COOKIE['viz'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
            $vid = bin2hex(random_bytes(16));
            if (!headers_sent()) {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
                setcookie('viz', $vid, ['expires' => time() + 31536000, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
            }
        }
        $path = trim(str_replace(BASE_URL, '', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/') ?: 'index.php';
        $actor = platform_admin() ? 'platform' : (is_logged_in() ? 'admin' : (current_paciente() ? 'member' : 'guest'));
        $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        db()->prepare("INSERT INTO pageviews (area, path, consultorio_id, actor_type, visitor, ip, ua, referer) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$area, substr($path, 0, 120), tenant_id(), $actor, $vid, substr($ip, 0, 45), substr($ua, 0, 255), substr($ref, 0, 255)]);
    } catch (Throwable $e) { /* silencioso */ }
}

/** Nombre legible de un módulo a partir de su ruta, para reportes de tráfico. */
function pageview_module_label(string $area, string $path): string
{
    if ($path === 'index.php' || $path === '') return $area === 'publico' ? 'Inicio' : ucfirst($area);
    if ($path === 'dashboard.php') return 'Dashboard';
    $folders = [
        'citas' => 'Agenda', 'pacientes' => 'Pacientes', 'expediente' => 'Expediente', 'recetas' => 'Recetas',
        'facturacion' => 'Facturación', 'crm' => 'CRM', 'inventario' => 'Inventario', 'reportes' => 'Reportes',
        'corte' => 'Corte de caja', 'egresos' => 'Egresos', 'pos' => 'Punto de venta', 'reactivacion' => 'Reactivación',
        'usuarios' => 'Personal', 'configuracion' => 'Configuración', 'pagos' => 'Suscripción', 'plantillas' => 'Plantillas',
        'soporte' => 'Ayuda', 'feedback' => 'Comentarios', 'auth' => 'Acceso', 'portal' => 'Portal',
        'admin' => 'Súper-admin', 'platform' => 'Plataforma', 'odontograma' => 'Odontograma',
        'presupuestos' => 'Presupuestos', 'servicios' => 'Catálogo de servicios',
    ];
    if (str_contains($path, '/')) {
        $seg = explode('/', $path);
        return $folders[$seg[0]] ?? ucfirst($seg[0]);
    }
    return ucfirst(str_replace(['_', '.php'], [' ', ''], $path));
}

/** Etiqueta legible de un área de tráfico. */
function pageview_area_label(string $area): string
{
    return ['publico' => 'Sitio público', 'panel' => 'Panel del consultorio', 'portal' => 'Portal del paciente', 'plataforma' => 'Consola de plataforma'][$area] ?? ucfirst($area);
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

/** Etiqueta del tipo de paciente (evita el homónimo "Médico"=doctor vs tipo). */
function tipo_paciente_label(string $tipo): string
{
    if ($tipo === 'dental') return t('Dental');
    if ($tipo === 'optica') return t('Óptica');
    return idioma_actual() === 'en' ? 'Medical' : 'Médico';
}

/** Etiqueta legible de un rol. */
function rol_label(string $rol): string
{
    return t([
        'admin'     => 'Administrador',
        'medico'    => 'Médico / Dentista',
        'recepcion' => 'Recepción',
    ][$rol] ?? $rol);
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

/**
 * Índice de Masa Corporal a partir de peso (kg) y estatura (cm).
 * Devuelve null si faltan datos; o ['valor','categoria','color'(badge)].
 */
function imc($peso, $estatura_cm): ?array
{
    $peso = (float) $peso; $h = (float) $estatura_cm / 100;
    if ($peso <= 0 || $h <= 0) return null;
    $v = round($peso / ($h * $h), 1);
    if ($v < 18.5)      [$cat, $col] = ['Bajo peso', 'info'];
    elseif ($v < 25)    [$cat, $col] = ['Normal', 'success'];
    elseif ($v < 30)    [$cat, $col] = ['Sobrepeso', 'warning'];
    else                [$cat, $col] = ['Obesidad', 'danger'];
    return ['valor' => $v, 'categoria' => $cat, 'color' => $col];
}

/** Formatea un número como dinero, ej: $1,250.00. */
function fmt_money($n): string
{
    return '$' . number_format((float) $n, 2);
}

/** Tamaño legible de un archivo en bytes, ej: 1.2 MB. */
function fmt_bytes($bytes): string
{
    $bytes = (float) $bytes;
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? (int) $bytes : number_format($bytes, 1)) . ' ' . $u[$i];
}

/* --------------------------------------------------------------------
 *  Archivos del expediente
 * ------------------------------------------------------------------ */

/** Tipos permitidos en el expediente: extensión => MIME esperado. */
function archivo_tipos_permitidos(): array
{
    return [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt'  => 'text/plain',
    ];
}

/** Tamaño máximo permitido por archivo (bytes). */
function archivo_max_bytes(): int
{
    return 10 * 1024 * 1024; // 10 MB
}

/**
 * Permite médicos SIN login: email y contraseña dejan de ser obligatorios, y se
 * agrega la cédula profesional. Un médico sin correo no inicia sesión pero sí
 * recibe citas. Se ejecuta una vez; deja marca en `configuracion`.
 */
function asegurar_esquema_medicos(): void
{
    static $listo = false;
    if ($listo) return;
    $listo = true;

    try {
        if (cfg('esquema_medicos') === '1') return;

        // email y password: de NOT NULL a NULL (médicos sin acceso).
        $col = db()->query("SHOW COLUMNS FROM usuarios LIKE 'email'")->fetch();
        if ($col && stripos((string) ($col['Null'] ?? ''), 'NO') !== false) {
            db()->exec('ALTER TABLE usuarios MODIFY COLUMN email VARCHAR(150) NULL');
        }
        $col = db()->query("SHOW COLUMNS FROM usuarios LIKE 'password_hash'")->fetch();
        if ($col && stripos((string) ($col['Null'] ?? ''), 'NO') !== false) {
            db()->exec('ALTER TABLE usuarios MODIFY COLUMN password_hash VARCHAR(255) NULL');
        }
        // cédula profesional.
        if (!db()->query("SHOW COLUMNS FROM usuarios LIKE 'cedula'")->fetch()) {
            db()->exec('ALTER TABLE usuarios ADD COLUMN cedula VARCHAR(40) DEFAULT NULL AFTER especialidad');
        }

        guardar_cfg(['esquema_medicos' => '1']);
    } catch (Throwable $e) {
        // Sin permisos de DDL: queda la vía manual (sql/medicos.sql).
    }
}

/**
 * Crea, si faltan, la tabla y la columna donde vive la foto del paciente.
 *
 * Las vistas consultan pacientes.foto_mime; si el código se despliega antes de
 * correr sql/foto_paciente.sql, esa columna no existe y MySQL tumba TODAS las
 * páginas que listan pacientes. Un despliegue no debe poder tirar el sitio por
 * una migración pendiente, así que el esquema se asegura solo.
 *
 * Se ejecuta una vez por petición como mucho, y deja de comprobar en cuanto la
 * marca queda guardada en `configuracion`.
 */
function asegurar_esquema_fotos(): void
{
    static $listo = false;
    if ($listo) return;
    $listo = true;

    try {
        if (cfg('esquema_fotos') === '1') return;

        db()->exec(
            'CREATE TABLE IF NOT EXISTS paciente_fotos (
               paciente_id    INT PRIMARY KEY,
               consultorio_id INT NOT NULL DEFAULT 1,
               mime           VARCHAR(40) NOT NULL,
               bytes          LONGBLOB    NOT NULL,
               actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               CONSTRAINT fk_pfoto_paciente FOREIGN KEY (paciente_id)
                   REFERENCES pacientes(id) ON DELETE CASCADE,
               INDEX idx_pfoto_tenant (consultorio_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $col = db()->query("SHOW COLUMNS FROM pacientes LIKE 'foto_mime'")->fetch();
        if (!$col) {
            db()->exec('ALTER TABLE pacientes ADD COLUMN foto_mime VARCHAR(40) DEFAULT NULL');
        }

        guardar_cfg(['esquema_fotos' => '1']);
    } catch (Throwable $e) {
        // Sin permisos de DDL: queda la vía manual (sql/foto_paciente.sql).
    }
}

/**
 * Crea, si faltan, las columnas y el módulo de la agenda en línea.
 *
 * Sin esto, un despliegue sin migración deja dos daños: la tarjeta de "Agenda en
 * línea" no aparece en Configuración (el módulo no existe en el catálogo) y, peor,
 * la agenda truena al generar los enlaces de WhatsApp (citas.token no existe).
 * Una migración pendiente no debe romper el sistema.
 */
function asegurar_esquema_agenda(): void
{
    static $listo = false;
    if ($listo) return;
    $listo = true;

    try {
        if (cfg('esquema_agenda') === '2') return;

        $col = db()->query("SHOW COLUMNS FROM citas LIKE 'token'")->fetch();
        if (!$col) {
            db()->exec(
                "ALTER TABLE citas
                   ADD COLUMN token         VARCHAR(32) DEFAULT NULL,
                   ADD COLUMN confirmada_en DATETIME    DEFAULT NULL,
                   ADD COLUMN cancelada_en  DATETIME    DEFAULT NULL,
                   ADD COLUMN cancelada_por ENUM('paciente','consultorio') DEFAULT NULL,
                   ADD COLUMN origen        ENUM('mostrador','online') NOT NULL DEFAULT 'mostrador',
                   ADD UNIQUE INDEX uq_cita_token (token)"
            );
        }

        // Liga cobro→cita para el pago en línea de la reserva. Sin esto,
        // cobro_crear() con cita_id fallaría al cobrar una cita agendada.
        $c2 = db()->query("SHOW COLUMNS FROM cobros LIKE 'cita_id'")->fetch();
        if (!$c2) {
            db()->exec('ALTER TABLE cobros ADD COLUMN cita_id INT DEFAULT NULL AFTER presupuesto_id');
            // La FK va aparte: si falla (motor sin soporte), la columna basta.
            try {
                db()->exec('ALTER TABLE cobros ADD CONSTRAINT fk_cobro_cita
                            FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE SET NULL');
            } catch (Throwable $e) { /* la columna ya sirve sin la FK */ }
        }

        // El módulo y su plan: sin la fila en `modulos`, modulo_activo() lo niega
        // y la agenda en línea queda invisible aunque el código esté desplegado.
        db()->exec("INSERT INTO modulos (clave, nombre, fase, orden)
                    VALUES ('agenda_online', 'Agenda en línea', 2, 21)
                    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)");
        db()->exec("INSERT INTO plan_modulos (plan_clave, modulo_clave)
                    VALUES ('profesional','agenda_online'), ('clinica','agenda_online')
                    ON DUPLICATE KEY UPDATE plan_clave = VALUES(plan_clave)");

        guardar_cfg(['esquema_agenda' => '2']);
        modulos_activos(true);   // invalida la caché: el módulo ya existe
    } catch (Throwable $e) {
        // Sin permisos de DDL: queda la vía manual (sql/agenda_online.sql).
    }
}

/**
 * Reduce una imagen para guardarla como foto de perfil: recorta al cuadrado por
 * el centro y la deja en $lado px. Una foto de celular de 5 MB acaba pesando
 * unos 50 KB, que es lo que hace viable guardarla en la base de datos.
 * Devuelve [bytes, mime]; si GD no está disponible, devuelve el original.
 */
function foto_redimensionar(string $bytes, string $mime, int $lado = 400): array
{
    if (!function_exists('imagecreatefromstring')) return [$bytes, $mime];

    $img = @imagecreatefromstring($bytes);
    if (!$img) return [$bytes, $mime];

    $w = imagesx($img);
    $h = imagesy($img);
    $corte = min($w, $h);                 // lado del cuadrado a recortar
    $destino = min($lado, $corte);        // no agrandar una foto pequeña

    $out = imagecreatetruecolor($destino, $destino);
    imagecopyresampled(
        $out, $img,
        0, 0,
        (int) (($w - $corte) / 2), (int) (($h - $corte) / 2),   // centrado
        $destino, $destino, $corte, $corte
    );

    ob_start();
    imagejpeg($out, null, 82);            // JPEG: la foto de perfil no necesita alfa
    $nuevo = (string) ob_get_clean();

    imagedestroy($img);
    imagedestroy($out);

    return $nuevo !== '' ? [$nuevo, 'image/jpeg'] : [$bytes, $mime];
}

/**
 * Guarda la foto de un paciente EN LA BASE DE DATOS (tabla paciente_fotos).
 *
 * Antes se guardaba en uploads/, pero esa carpeta no estaba en .gitignore y el
 * despliegue (git clean) borraba las fotos en cada subida de cambios. En la base
 * entran en los respaldos y ya no dependen de cómo se comporte el deploy.
 *
 * Devuelve el mime guardado, o null si no vino archivo o no es una imagen válida.
 * Actualiza también pacientes.foto_mime, que es la marca barata que consultan los
 * listados para saber si hay foto sin cargar el blob.
 */
function guardar_foto_paciente(?array $f, int $paciente_id): ?string
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return null;
    if ($f['size'] > 6 * 1024 * 1024) return null; // 6 MB de entrada

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
    if (!isset(['image/jpeg' => 1, 'image/png' => 1, 'image/webp' => 1][$mime])) return null;

    $bytes = (string) file_get_contents($f['tmp_name']);
    if ($bytes === '') return null;
    [$bytes, $mime] = foto_redimensionar($bytes, $mime);

    return paciente_foto_escribir($paciente_id, $bytes, $mime) ? $mime : null;
}

/** Escribe (o reemplaza) los bytes de la foto de un paciente del consultorio activo. */
function paciente_foto_escribir(int $paciente_id, string $bytes, string $mime): bool
{
    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) return false;

    $st = db()->prepare(
        'INSERT INTO paciente_fotos (paciente_id, consultorio_id, mime, bytes)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE mime = VALUES(mime), bytes = VALUES(bytes)'
    );
    $st->bindValue(1, $paciente_id, PDO::PARAM_INT);
    $st->bindValue(2, tenant_id(), PDO::PARAM_INT);
    $st->bindValue(3, $mime);
    $st->bindValue(4, $bytes, PDO::PARAM_LOB);
    $st->execute();

    db()->prepare('UPDATE pacientes SET foto_mime = ? WHERE id = ? AND consultorio_id = ?')
        ->execute([$mime, $paciente_id, tenant_id()]);

    return true;
}

/** Borra la foto de un paciente (de la base y, si quedaba, del disco). */
function eliminar_foto_paciente(int $paciente_id, ?string $ruta_vieja = null): void
{
    db()->prepare('DELETE FROM paciente_fotos WHERE paciente_id = ? AND consultorio_id = ?')
        ->execute([$paciente_id, tenant_id()]);
    db()->prepare('UPDATE pacientes SET foto_mime = NULL, foto = NULL WHERE id = ? AND consultorio_id = ?')
        ->execute([$paciente_id, tenant_id()]);

    // Restos de la época en que la foto vivía en disco.
    if ($ruta_vieja) {
        $ruta = str_replace(['..', "\0"], '', $ruta_vieja);
        $abs  = __DIR__ . '/../' . ltrim($ruta, '/');
        if (is_file($abs)) { @unlink($abs); }
    }
}

/**
 * URL de la foto de un paciente (o '' si no tiene).
 *
 * NO se enlaza a /uploads directo: esa carpeta está bloqueada por .htaccess y
 * devolvía 403 (la imagen salía rota). Se sirve por pacientes/foto.php, que
 * verifica sesión y consultorio. El sufijo ?v= es para que el navegador note
 * el cambio cuando el paciente se cambia la foto.
 *
 * @param array|null $p Fila del paciente (necesita 'id' y 'foto').
 */
function foto_paciente_url(?array $p): string
{
    // 'foto_mime' es la marca actual (foto en la base); 'foto' es la ruta vieja
    // en disco, que sigue valiendo hasta que se migre sola al abrirla.
    $marca = $p['foto_mime'] ?? $p['foto'] ?? null;
    if (empty($p['id']) || empty($marca)) return '';
    return BASE_URL . '/pacientes/foto?id=' . (int) $p['id'];
}

/**
 * Avatar del paciente: su foto, o un círculo con sus iniciales si no tiene.
 * Devuelve el HTML listo para imprimir, para no repetir el mismo bloque en cada
 * listado. Los nombres de columna varían entre módulos (p.nombre vs pac_nombre),
 * por eso recibe los valores sueltos y no la fila entera.
 *
 * @param int $px Diámetro en píxeles (40 en listados, 72 en encabezados).
 */
function avatar_paciente(?int $id, ?string $nombre, ?string $apellidos, ?string $foto, int $px = 40): string
{
    $url = foto_paciente_url(['id' => $id, 'foto' => $foto]);
    $lado = (int) $px;

    if ($url) {
        return '<img src="' . e($url) . '" class="rounded-circle flex-shrink-0" alt=""'
             . ' style="width:' . $lado . 'px;height:' . $lado . 'px;object-fit:cover">';
    }

    $ini = strtoupper(mb_substr((string) $nombre, 0, 1) . mb_substr((string) $apellidos, 0, 1));
    return '<span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0"'
         . ' style="width:' . $lado . 'px;height:' . $lado . 'px;font-size:' . round($lado * 0.36) . 'px;'
         . 'background:color-mix(in srgb,var(--brand) 18%,transparent);color:var(--brand)">'
         . e($ini) . '</span>';
}

/** Carpeta física donde se guardan los archivos de un paciente (la crea si falta). */
function archivo_dir(int $paciente_id): string
{
    $dir = __DIR__ . '/../uploads/expedientes/' . tenant_id() . '/' . $paciente_id;
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

/** Icono de Bootstrap según la extensión del archivo. */
function archivo_icono(string $nombre): string
{
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'bi-file-earmark-image';
    if ($ext === 'pdf')                       return 'bi-file-earmark-pdf';
    if (in_array($ext, ['doc', 'docx'], true)) return 'bi-file-earmark-word';
    if (in_array($ext, ['xls', 'xlsx'], true)) return 'bi-file-earmark-excel';
    if ($ext === 'txt')                       return 'bi-file-earmark-text';
    return 'bi-file-earmark';
}

/**
 * Valida y guarda un archivo subido al expediente de un paciente.
 * No imprime nada: el llamador decide qué hacer con el resultado.
 *
 * @param array|null $f           Entrada de $_FILES (o null si no vino).
 * @param int        $consulta_id Liga opcional a una consulta (0 = ninguna).
 * @return array{estado:string,mensaje:string}  estado: 'ok' | 'vacio' | 'error'.
 */
function guardar_archivo_expediente(?array $f, int $paciente_id, int $usuario_id,
                                    ?string $descripcion = null, int $consulta_id = 0): array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['estado' => 'vacio', 'mensaje' => 'No se seleccionó ningún archivo.'];
    }
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return ['estado' => 'error', 'mensaje' => 'El archivo supera el tamaño máximo permitido.'];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return ['estado' => 'error', 'mensaje' => 'No se pudo subir el archivo. Inténtalo de nuevo.'];
    }
    if ($f['size'] > archivo_max_bytes()) {
        return ['estado' => 'error', 'mensaje' => 'El archivo supera el máximo de ' . fmt_bytes(archivo_max_bytes()) . '.'];
    }

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(archivo_tipos_permitidos()[$ext])) {
        return ['estado' => 'error', 'mensaje' => 'Tipo de archivo no permitido. Usa PDF, imagen, Word, Excel o texto.'];
    }
    // Comprueba el MIME real del contenido, no solo la extensión.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';

    $nombre_guardado = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], archivo_dir($paciente_id) . '/' . $nombre_guardado)) {
        return ['estado' => 'error', 'mensaje' => 'No se pudo guardar el archivo en el servidor.'];
    }

    db()->prepare(
        'INSERT INTO archivos
         (consultorio_id, paciente_id, consulta_id, subido_por, nombre_original, nombre_guardado, mime, tamano, descripcion)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        tenant_id(), $paciente_id, $consulta_id ?: null, $usuario_id,
        mb_substr($f['name'], 0, 255), $nombre_guardado, $mime, (int) $f['size'],
        ($descripcion = trim((string) $descripcion)) !== '' ? $descripcion : null,
    ]);
    // El id se devuelve para que el llamador pueda ligar el archivo a otra cosa
    // (p. ej. laboratorio marca el PDF del resultado con su orden).
    return ['estado' => 'ok', 'mensaje' => 'Archivo agregado al expediente.',
            'id' => (int) db()->lastInsertId()];
}

/**
 * Normaliza los campos editables de un paciente desde $_POST.
 * Devuelve un mapa columna => valor (listo para INSERT/UPDATE), compartido
 * por pacientes/create.php y edit.php para no duplicar la lista.
 */
function paciente_post_campos(array $post): array
{
    $t = fn(string $k) => (($v = trim((string) ($post[$k] ?? ''))) !== '' ? $v : null);
    $up = fn(string $k) => (($v = strtoupper(trim((string) ($post[$k] ?? '')))) !== '' ? $v : null);
    $sangres = ['O+','O-','A+','A-','B+','B-','AB+','AB-'];
    return [
        'nombre'                  => trim((string) ($post['nombre'] ?? '')),
        'apellidos'               => trim((string) ($post['apellidos'] ?? '')),
        'fecha_nacimiento'        => ($post['fecha_nacimiento'] ?? '') ?: null,
        'sexo'                    => in_array($post['sexo'] ?? '', ['M','F','O'], true) ? $post['sexo'] : null,
        'telefono'                => $t('telefono'),
        'email'                   => $t('email'),
        'direccion'               => $t('direccion'),
        'tipo'                    => in_array($post['tipo'] ?? '', ['medico','dental'], true) ? $post['tipo'] : 'medico',
        'curp'                    => $up('curp'),
        'rfc'                     => $up('rfc'),
        'ine'                     => $up('ine'),
        'tipo_sangre'             => in_array($post['tipo_sangre'] ?? '', $sangres, true) ? $post['tipo_sangre'] : null,
        'contacto_nombre'         => $t('contacto_nombre'),
        'contacto_telefono'       => $t('contacto_telefono'),
        'contacto_parentesco'     => $t('contacto_parentesco'),
        'alergias'                => $t('alergias'),
        'antecedentes'            => $t('antecedentes'),
        'antecedentes_familiares' => $t('antecedentes_familiares'),
        'cirugias'                => $t('cirugias'),
        'vacunas'                 => $t('vacunas'),
        'enf_cronicas'            => $t('enf_cronicas'),
        'habitos'                 => $t('habitos'),
        'notas'                   => $t('notas'),
    ];
}

/* --------------------------------------------------------------------
 *  Recordatorios / WhatsApp (click-to-chat)
 * ------------------------------------------------------------------ */

/** Normaliza un teléfono a solo dígitos en formato internacional (E.164 sin +). */
function tel_e164(?string $tel): string
{
    $d = preg_replace('/\D/', '', (string) $tel);
    if ($d === '') return '';
    $d = ltrim($d, '0');
    $lada = preg_replace('/\D/', '', cfg('pais_lada', '52')) ?: '52';
    // Si parece un número local (<= 10 dígitos), anteponemos la lada del país.
    if (strlen($d) <= 10) { $d = $lada . $d; }
    return $d;
}

/** Plantilla de recordatorio con marcadores reemplazados. */
function mensaje_recordatorio(string $paciente, string $fecha, string $hora, ?string $enlace = null): string
{
    // Con enlace, la plantilla por omisión pide confirmar con un clic. Es la
    // diferencia entre "por favor confirme" (que nadie hace) y un botón.
    $porOmision = $enlace
        ? 'Hola {paciente}, le recordamos su cita en {consultorio} el {fecha} a las {hora}. '
          . 'Confirme o cancele aquí: {enlace}'
        : 'Hola {paciente}, le recordamos su cita en {consultorio} el {fecha} a las {hora}. '
          . 'Por favor confirme su asistencia. ¡Gracias!';

    $plantilla = cfg('recordatorio_plantilla', $porOmision);

    // Si el consultorio personalizó su plantilla y no puso {enlace}, se agrega
    // al final: la confirmación no debe perderse por olvidar un marcador.
    if ($enlace && strpos($plantilla, '{enlace}') === false) {
        $plantilla .= ' ' . t('Confirme o cancele aquí') . ': {enlace}';
    }

    return strtr($plantilla, [
        '{paciente}'    => $paciente,
        '{consultorio}' => marca_nombre(),
        '{fecha}'       => $fecha,
        '{hora}'        => $hora,
        '{enlace}'      => $enlace ?? '',
    ]);
}

/** URL wa.me para abrir WhatsApp con un mensaje pre-cargado. '' si no hay tel. */
function wa_link(?string $telefono, string $mensaje): string
{
    $tel = tel_e164($telefono);
    if ($tel === '') return '';
    return 'https://wa.me/' . $tel . '?text=' . rawurlencode($mensaje);
}

/** Devuelve color de badge Bootstrap según el estado de la cita. */
function estado_badge(string $estado): string
{
    return [
        'programada'  => 'secondary',
        'confirmada'  => 'info',
        'esperando'   => 'warning',
        'en_consulta' => 'primary',
        'atendida'    => 'success',
        'cancelada'   => 'danger',
        'no_asistio'  => 'dark',
    ][$estado] ?? 'secondary';
}

function estado_label(string $estado): string
{
    return t([
        'programada'  => 'Programada',
        'confirmada'  => 'Confirmada',
        'esperando'   => 'En espera',
        'en_consulta' => 'En consulta',
        'atendida'    => 'Atendida',
        'cancelada'   => 'Cancelada',
        'no_asistio'  => 'No asistió',
    ][$estado] ?? $estado);
}

/* --------------------------------------------------------------------
 *  Presupuestos / planes de tratamiento
 * ------------------------------------------------------------------ */

/** Estados de un presupuesto: clave => [etiqueta, color de badge]. */
function presupuesto_estados(): array
{
    return [
        'borrador'  => ['Borrador',  'secondary'],
        'propuesto' => ['Propuesto', 'info'],
        'aceptado'  => ['Aceptado',  'primary'],
        'terminado' => ['Terminado', 'success'],
        'rechazado' => ['Rechazado', 'danger'],
        'cancelado' => ['Cancelado', 'dark'],
    ];
}

function presupuesto_estado_label(string $estado): string
{
    return t(presupuesto_estados()[$estado][0] ?? $estado);
}

function presupuesto_estado_badge(string $estado): string
{
    return presupuesto_estados()[$estado][1] ?? 'secondary';
}

/**
 * Un presupuesto cuenta como trabajo comprometido (y por tanto su saldo es
 * cobrable) solo cuando el paciente ya lo aceptó.
 */
function presupuesto_es_cobrable(string $estado): bool
{
    return in_array($estado, ['aceptado', 'terminado'], true);
}

/** Suma de abonos registrados a un presupuesto. */
function presupuesto_pagado(int $presupuesto_id): float
{
    $st = db()->prepare(
        'SELECT COALESCE(SUM(monto),0) FROM presupuesto_pagos
         WHERE presupuesto_id = ? AND consultorio_id = ?'
    );
    $st->execute([$presupuesto_id, tenant_id()]);
    return (float) $st->fetchColumn();
}

/**
 * Siguiente folio de presupuesto del consultorio, por año: PRE-2026-0007.
 * Se calcula sobre el consecutivo ya usado en el año, no sobre el id, para que
 * cada consultorio lleve su propia numeración corrida.
 */
function presupuesto_siguiente_folio(): string
{
    $prefijo = 'PRE-' . date('Y') . '-';
    $desde   = strlen($prefijo) + 1; // posición del consecutivo dentro del folio
    $st = db()->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(folio, $desde) AS UNSIGNED)), 0)
         FROM presupuestos WHERE consultorio_id = ? AND folio LIKE ?"
    );
    $st->execute([tenant_id(), $prefijo . '%']);
    $n = (int) $st->fetchColumn() + 1;
    return $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Construye una URL absoluta del sitio (Mercado Pago exige back/notification
 * URL completas, y rechaza las que no son https).
 *
 * Tras un proxy o balanceador (Hostinger, Cloudflare) la conexión al servidor
 * es HTTP y `$_SERVER['HTTPS']` viene vacío: el esquema real lo dice
 * X-Forwarded-Proto. Sin mirarlo, las URLs saldrían como http:// y Mercado
 * Pago rechazaría la preferencia.
 */
function url_absoluta(string $path): string
{
    // Por CLI (el cron de recordatorios) no hay HTTP_HOST: el enlace saldría
    // como http://localhost y llegaría roto al correo del paciente. SITIO_URL
    // (variable de entorno o constante en el archivo de secretos) da el dominio
    // real en ese caso.
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        $base = getenv('SITIO_URL') ?: (defined('SITIO_URL') ? SITIO_URL : '');
        $base = rtrim((string) $base, '/');
        if ($base !== '') return $base . BASE_URL . $path;
    }

    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https://' : 'http://') . $host . BASE_URL . $path;
}

/* --------------------------------------------------------------------
 *  Agenda en línea y confirmación de cita
 * ------------------------------------------------------------------ */

/**
 * Token del enlace que se le manda al paciente para confirmar o cancelar.
 * Se genera una vez y vive en la propia cita: muere con ella, así que el enlace
 * se invalida solo cuando la cita se borra.
 */
function cita_token(int $cita_id): string
{
    $st = db()->prepare('SELECT token FROM citas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$cita_id, tenant_id()]);
    $token = (string) ($st->fetchColumn() ?: '');

    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        db()->prepare('UPDATE citas SET token = ? WHERE id = ? AND consultorio_id = ?')
            ->execute([$token, $cita_id, tenant_id()]);
    }
    return $token;
}

/** URL pública para que el paciente confirme o cancele su cita. */
function cita_enlace(int $cita_id): string
{
    return url_absoluta('/agenda/confirmar?t=' . cita_token($cita_id));
}

/**
 * Folio legible de una cita a partir de su id: CITA-000123. No es una columna
 * nueva — se deriva del id, así que sirve de inmediato para citas ya creadas y
 * es estable y único. Da al paciente y al personal un código fácil de citar.
 */
function cita_folio(int $cita_id): string
{
    return 'CITA-' . str_pad((string) $cita_id, 6, '0', STR_PAD_LEFT);
}

/** URL pública de la página de reservas de un consultorio. */
function agenda_online_url(string $slug): string
{
    return url_absoluta('/agenda/reservar?c=' . rawurlencode($slug));
}

/**
 * Huecos libres de un médico en un día.
 *
 * Un hueco se ofrece solo si: cae dentro del horario que el médico configuró
 * para ese día de la semana, no choca con un bloqueo (vacaciones, comida), no
 * choca con una cita ya agendada, y no está en el pasado. Ofrecer un hueco que
 * no existe es peor que no ofrecer nada: el paciente se presenta y no hay lugar.
 *
 * @return string[] Horas 'HH:MM' disponibles, en orden.
 */
function agenda_huecos(int $medico_id, string $fecha, int $duracion = 30): array
{
    $tid = tenant_id();
    $dia = (int) date('w', strtotime($fecha));   // 0=domingo, como medico_horarios

    // 1) Horario del médico ese día. Sin horario configurado, no atiende.
    $st = db()->prepare(
        'SELECT hora_inicio, hora_fin FROM medico_horarios
         WHERE medico_id = ? AND consultorio_id = ? AND dia_semana = ?'
    );
    $st->execute([$medico_id, $tid, $dia]);
    $franjas = $st->fetchAll();
    if (!$franjas) return [];

    // 2) Citas ya agendadas ese día (las canceladas y las faltas no ocupan).
    $st = db()->prepare(
        "SELECT hora, duracion FROM citas
         WHERE medico_id = ? AND consultorio_id = ? AND fecha = ?
           AND estado NOT IN ('cancelada','no_asistio')"
    );
    $st->execute([$medico_id, $tid, $fecha]);
    $ocupadas = $st->fetchAll();

    // 3) Bloqueos que pisan ese día (del médico o de todo el consultorio).
    $st = db()->prepare(
        'SELECT inicio, fin FROM bloqueos
         WHERE consultorio_id = ? AND (medico_id = ? OR medico_id IS NULL)
           AND DATE(inicio) <= ? AND DATE(fin) >= ?'
    );
    $st->execute([$tid, $medico_id, $fecha, $fecha]);
    $bloqueos = $st->fetchAll();

    $ahora  = time();
    $huecos = [];
    $paso   = max(5, $duracion) * 60;

    foreach ($franjas as $f) {
        $ini = strtotime($fecha . ' ' . $f['hora_inicio']);
        $cierre = strtotime($fecha . ' ' . $f['hora_fin']);

        for ($t = $ini; $t + $duracion * 60 <= $cierre; $t += $paso) {
            if ($t <= $ahora) continue;                    // nadie reserva en el pasado
            if (hueco_ocupado($t, $t + $duracion * 60, $ocupadas, $bloqueos, $fecha)) continue;
            $huecos[] = date('H:i', $t);
        }
    }

    sort($huecos);
    return array_values(array_unique($huecos));
}

/**
 * ¿El hueco [$ini, $fin) pisa una cita ya agendada o un bloqueo?
 *
 * Dos intervalos se solapan si cada uno empieza antes de que termine el otro.
 * La comparación es con < y > (no <=): una cita que TERMINA a las 10:00 no
 * estorba a un hueco que EMPIEZA a las 10:00; si no, se perdería un hueco bueno
 * en cada frontera.
 *
 * Función aparte (y pura) porque es donde se agenda a dos pacientes en la misma
 * hora si uno se equivoca: se puede probar sola.
 */
function hueco_ocupado(int $ini, int $fin, array $ocupadas, array $bloqueos, string $fecha): bool
{
    foreach ($ocupadas as $c) {
        $ci = strtotime($fecha . ' ' . $c['hora']);
        $cf = $ci + (((int) $c['duracion']) ?: 30) * 60;
        if ($ini < $cf && $fin > $ci) return true;
    }
    foreach ($bloqueos as $b) {
        $bi = strtotime($b['inicio']);
        $bf = strtotime($b['fin']);
        if ($ini < $bf && $fin > $bi) return true;
    }
    return false;
}

/** ¿El consultorio tiene abierta su página pública de reservas? */
function agenda_online_activa(): bool
{
    return modulo_activo('agenda_online') && cfg('agenda_online', '0') === '1';
}

/**
 * ¿La hora exacta ($hora "HH:MM") sigue libre para ese médico ese día?
 *
 * Se usa al CONFIRMAR la reserva (no al mostrar los huecos). A diferencia de
 * agenda_huecos(), NO rechaza por "ya pasó la hora": si el paciente tardó unos
 * minutos en llenar el formulario, el hueco que eligió no debe invalidarse por
 * eso. Solo importa que no choque con otra cita ya agendada — que es el conflicto
 * real que evita agendar a dos personas a la misma hora.
 */
function agenda_hora_disponible(int $medico_id, string $fecha, string $hora, int $duracion = 30): bool
{
    if (!preg_match('/^\d{1,2}:\d{2}$/', $hora)) return false;

    $tid = tenant_id();
    $ini = strtotime($fecha . ' ' . $hora);
    if (!$ini) return false;
    $fin = $ini + $duracion * 60;

    // 1) Debe caer dentro de una franja del horario del médico ese día.
    $dia = (int) date('w', $ini);
    $st = db()->prepare('SELECT hora_inicio, hora_fin FROM medico_horarios
                         WHERE medico_id = ? AND consultorio_id = ? AND dia_semana = ?');
    $st->execute([$medico_id, $tid, $dia]);
    $dentro = false;
    foreach ($st->fetchAll() as $f) {
        if ($ini >= strtotime($fecha . ' ' . $f['hora_inicio'])
            && $fin <= strtotime($fecha . ' ' . $f['hora_fin'])) { $dentro = true; break; }
    }
    if (!$dentro) return false;

    // 2) No debe chocar con una cita ya agendada (canceladas/faltas no cuentan).
    $st = db()->prepare(
        "SELECT hora, duracion FROM citas
         WHERE medico_id = ? AND consultorio_id = ? AND fecha = ?
           AND estado NOT IN ('cancelada','no_asistio')"
    );
    $st->execute([$medico_id, $tid, $fecha]);
    foreach ($st->fetchAll() as $c) {
        $ci = strtotime($fecha . ' ' . $c['hora']);
        $cf = $ci + (((int) $c['duracion']) ?: 30) * 60;
        if ($ini < $cf && $fin > $ci) return false;   // se encima
    }
    return true;
}

/**
 * Resuelve un consultorio por su slug para mostrar su MICROSITIO público.
 * Solo consultorios vigentes (activa o en prueba): uno suspendido o expirado no
 * debe tener una página pública en pie. Devuelve la fila o null.
 */
function consultorio_publico(string $slug): ?array
{
    $slug = preg_replace('/[^a-z0-9\-_]/i', '', $slug);
    if ($slug === '') return null;

    try {
        $st = db()->prepare(
            "SELECT * FROM consultorios WHERE slug = ? AND estado IN ('activa','trial') LIMIT 1"
        );
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Horario de atención en texto, resumido a partir de medico_horarios.
 * Agrupa los días que comparten el mismo horario ("Lun a Vie: 9:00–14:00,
 * 16:00–19:00") porque nadie quiere leer siete renglones idénticos. Toma la
 * UNIÓN de las franjas de todos los médicos: es el horario en que el consultorio
 * abre, no el de una persona.
 */
function horario_atencion_texto(int $consultorio_id, ?int $medico_id = null): array
{
    try {
        // Con $medico_id se devuelve el calendario de ESE médico (cada uno tiene
        // el suyo). Sin él, la unión de todos (el horario general del consultorio).
        $sql = 'SELECT DISTINCT dia_semana, hora_inicio, hora_fin FROM medico_horarios
                WHERE consultorio_id = ?';
        $params = [$consultorio_id];
        if ($medico_id) { $sql .= ' AND medico_id = ?'; $params[] = $medico_id; }
        $sql .= ' ORDER BY dia_semana, hora_inicio';
        $st = db()->prepare($sql);
        $st->execute($params);
        $filas = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    if (!$filas) return [];

    $hm = fn($h) => date('G:i', strtotime($h));   // "9:00", "16:00"
    // Firma de cada día = sus franjas concatenadas, para saber cuáles son iguales.
    $porDia = [];
    foreach ($filas as $f) {
        $porDia[(int) $f['dia_semana']][] = $hm($f['hora_inicio']) . '–' . $hm($f['hora_fin']);
    }

    $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $orden = [1, 2, 3, 4, 5, 6, 0];   // arranca en lunes, domingo al final
    $out = [];
    $grupo = null;
    foreach ($orden as $d) {
        if (!isset($porDia[$d])) { if ($grupo) { $out[] = $grupo; $grupo = null; } continue; }
        $franjas = implode(', ', $porDia[$d]);
        if ($grupo && $grupo['franjas'] === $franjas) {
            $grupo['fin'] = $dias[$d];   // extiende el rango de días
        } else {
            if ($grupo) $out[] = $grupo;
            $grupo = ['ini' => $dias[$d], 'fin' => $dias[$d], 'franjas' => $franjas];
        }
    }
    if ($grupo) $out[] = $grupo;

    return array_map(function ($g) {
        $dias = $g['ini'] === $g['fin'] ? $g['ini'] : $g['ini'] . ' a ' . $g['fin'];
        return ['dias' => $dias, 'horas' => $g['franjas']];
    }, $out);
}

/* --------------------------------------------------------------------
 *  Documentos clínicos (constancias, incapacidades, resúmenes)
 * ------------------------------------------------------------------ */

/** Siguiente folio de documento del consultorio, por año: DOC-2026-0007. */
function documento_siguiente_folio(): string
{
    $prefijo = 'DOC-' . date('Y') . '-';
    $desde   = strlen($prefijo) + 1;
    $st = db()->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(folio, $desde) AS UNSIGNED)), 0)
         FROM documentos WHERE consultorio_id = ? AND folio LIKE ?"
    );
    $st->execute([tenant_id(), $prefijo . '%']);
    $n = (int) $st->fetchColumn() + 1;
    return $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** Marcadores disponibles en una plantilla: clave => para qué sirve. */
function documento_marcadores(): array
{
    return [
        '{paciente}'    => 'Nombre completo del paciente',
        '{edad}'        => 'Edad del paciente',
        '{sexo}'        => 'Sexo (masculino / femenino)',
        '{fecha}'       => 'Fecha de hoy, en letra',
        '{diagnostico}' => 'Diagnóstico de su última consulta',
        '{medico}'      => 'Nombre del médico que firma',
        '{especialidad}'=> 'Especialidad del médico',
        '{consultorio}' => 'Nombre del consultorio',
        '{dias}'        => 'Días de reposo (se pregunta al generar)',
    ];
}

/**
 * Resuelve los marcadores de una plantilla con los datos que el sistema YA
 * tiene. El médico corrige después lo que quiera: esto solo le ahorra teclear
 * lo que ya está capturado.
 */
function documento_resolver(string $cuerpo, array $paciente, ?array $medico = null,
                            ?string $diagnostico = null, array $extra = []): string
{
    $sexo = ['M' => 'masculino', 'F' => 'femenino'][$paciente['sexo'] ?? ''] ?? '';

    $mapa = [
        '{paciente}'     => trim(($paciente['nombre'] ?? '') . ' ' . ($paciente['apellidos'] ?? '')),
        '{edad}'         => edad($paciente['fecha_nacimiento'] ?? null),
        '{sexo}'         => $sexo,
        '{fecha}'        => fecha_larga(),
        '{diagnostico}'  => $diagnostico ?: '—',
        '{medico}'       => $medico['nombre'] ?? '',
        '{especialidad}' => $medico['especialidad'] ?? '',
        '{consultorio}'  => marca_nombre(),
    ];
    foreach ($extra as $k => $v) { $mapa['{' . $k . '}'] = (string) $v; }

    return strtr($cuerpo, $mapa);
}

/** Fecha de hoy en letra: "13 de julio de 2026" (así se escribe en un oficio). */
function fecha_larga(?string $f = null): string
{
    $t = $f ? strtotime($f) : time();
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
              'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return (int) date('j', $t) . ' de ' . $meses[(int) date('n', $t)] . ' de ' . date('Y', $t);
}

/**
 * Plantillas de arranque: los cuatro papeles que un consultorio extiende todas
 * las semanas. Se cargan con un botón y se editan como cualquier plantilla.
 */
function documento_plantillas_comunes(): array
{
    return [
        ['Constancia de buena salud',
         "A QUIEN CORRESPONDA:\n\n" .
         "Por medio de la presente hago constar que {paciente}, de {edad}, " .
         "fue valorado(a) clínicamente en este consultorio el día de hoy, encontrándose " .
         "en buen estado de salud general, sin datos de enfermedad infectocontagiosa " .
         "activa ni impedimento aparente para realizar sus actividades habituales.\n\n" .
         "Se extiende la presente a petición del interesado(a) para los fines legales " .
         "que a este convengan, en {consultorio}, a {fecha}.\n"],

        ['Justificante / incapacidad',
         "A QUIEN CORRESPONDA:\n\n" .
         "Hago constar que {paciente}, de {edad}, acudió a consulta médica " .
         "el día de hoy con diagnóstico de {diagnostico}, por lo cual se indica " .
         "reposo domiciliario por {dias} días a partir de esta fecha.\n\n" .
         "Se extiende la presente para los fines que al interesado(a) convengan, " .
         "en {consultorio}, a {fecha}.\n"],

        ['Referencia a especialista',
         "ESTIMADO(A) COLEGA:\n\n" .
         "Le envío a {paciente}, de {edad}, con diagnóstico de {diagnostico}, " .
         "para su valoración y manejo especializado.\n\n" .
         "Resumen del caso:\n\n\n" .
         "Agradezco de antemano su atención y quedo a sus órdenes para cualquier " .
         "información adicional.\n\n" .
         "Atentamente,\n{medico} · {especialidad}\n{consultorio}, a {fecha}.\n"],

        ['Resumen clínico',
         "RESUMEN CLÍNICO\n\n" .
         "Paciente: {paciente}\nEdad: {edad}\nFecha: {fecha}\n\n" .
         "Antecedentes de importancia:\n\n\n" .
         "Padecimiento actual:\n\n\n" .
         "Exploración física:\n\n\n" .
         "Diagnóstico: {diagnostico}\n\n" .
         "Plan de tratamiento:\n\n\n" .
         "Atentamente,\n{medico} · {especialidad}\n"],
    ];
}

/**
 * Resumen clínico del paciente: lo que un médico necesita saber ANTES de
 * entrar, sin leerse el expediente completo. Todo sale de datos que ya están
 * capturados; no hay nada que adivinar aquí.
 *
 * Lo primero son las alergias, y a propósito: es el dato que, si se pasa por
 * alto, hace daño de verdad.
 */
function paciente_resumen(int $paciente_id, array $p): array
{
    $tid = tenant_id();

    $q = db()->prepare(
        "SELECT COUNT(*) AS consultas,
                MAX(fecha) AS ultima
         FROM consultas WHERE paciente_id = ? AND consultorio_id = ?"
    );
    $q->execute([$paciente_id, $tid]);
    $c = $q->fetch() ?: [];

    // Próxima cita agendada (la que el paciente pregunta al llegar).
    $q = db()->prepare(
        "SELECT fecha, hora FROM citas
         WHERE paciente_id = ? AND consultorio_id = ? AND fecha >= CURDATE()
           AND estado IN ('programada','confirmada')
         ORDER BY fecha, hora LIMIT 1"
    );
    $q->execute([$paciente_id, $tid]);
    $prox = $q->fetch() ?: null;

    // Diagnósticos que más se repiten: el padecimiento crónico sale solo.
    $q = db()->prepare(
        "SELECT diagnostico, COUNT(*) n FROM consultas
         WHERE paciente_id = ? AND consultorio_id = ? AND diagnostico IS NOT NULL AND diagnostico <> ''
         GROUP BY diagnostico ORDER BY n DESC, MAX(fecha) DESC LIMIT 3"
    );
    $q->execute([$paciente_id, $tid]);
    $dx = $q->fetchAll();

    // Últimos signos vitales capturados (peso, estatura, presión).
    $q = db()->prepare(
        "SELECT peso, estatura, presion, temperatura, fecha FROM consultas
         WHERE paciente_id = ? AND consultorio_id = ?
           AND (peso IS NOT NULL OR presion IS NOT NULL)
         ORDER BY fecha DESC LIMIT 1"
    );
    $q->execute([$paciente_id, $tid]);
    $vitales = $q->fetch() ?: null;

    return [
        'alergias'     => trim((string) ($p['alergias'] ?? '')),
        'antecedentes' => trim((string) ($p['antecedentes'] ?? '')),
        'consultas'    => (int) ($c['consultas'] ?? 0),
        'ultima'       => $c['ultima'] ?? null,
        'proxima'      => $prox,
        'diagnosticos' => $dx,
        'vitales'      => $vitales,
        'imc'          => $vitales ? imc($vitales['peso'] ?? 0, $vitales['estatura'] ?? 0) : null,
    ];
}

/* --------------------------------------------------------------------
 *  Óptica (graduaciones, micas y órdenes de trabajo)
 * ------------------------------------------------------------------ */

/**
 * Formatea una dioptría como la escribe un optometrista: SIEMPRE con signo y
 * dos decimales (-0.75, +1.25, 0.00). El signo importa: +2.00 y -2.00 son
 * graduaciones opuestas, y un "2.00" a secas es ambiguo en una receta.
 */
function fmt_dioptria($v): string
{
    if ($v === null || $v === '') return '—';
    return sprintf('%+.2f', (float) $v);
}

/** Eje del cilindro: 0 a 180 grados, como "85°". */
function fmt_eje($v): string
{
    return ($v === null || $v === '') ? '—' : ((int) $v) . '°';
}

/** Tipos de lente que se pueden recetar. */
function optica_tipos_lente(): array
{
    return [
        'monofocal'   => 'Monofocal',
        'bifocal'     => 'Bifocal',
        'progresivo'  => 'Progresivo',
        'ocupacional' => 'Ocupacional',
    ];
}

/** Estados de una orden de trabajo: clave => [etiqueta, color de badge]. */
function optica_estados(): array
{
    return [
        'pedido'         => ['Pedido',          'secondary'],
        'en_laboratorio' => ['En laboratorio',  'info'],
        'recibido'       => ['Recibido',        'primary'],
        'entregado'      => ['Entregado',       'success'],
        'cancelado'      => ['Cancelado',       'dark'],
    ];
}

function optica_estado_label(string $estado): string
{
    return t(optica_estados()[$estado][0] ?? $estado);
}

function optica_estado_badge(string $estado): string
{
    return optica_estados()[$estado][1] ?? 'secondary';
}

/**
 * Un trabajo va tarde si ya pasó la fecha que se le prometió al cliente y
 * todavía no se entrega. Es LA métrica del mostrador: el cliente que viene por
 * sus lentes y no están es el que no vuelve.
 */
function optica_trabajo_atrasado(array $t): bool
{
    if (in_array($t['estado'], ['entregado', 'cancelado'], true)) return false;
    return !empty($t['fecha_promesa']) && $t['fecha_promesa'] < date('Y-m-d');
}

/** Siguiente folio de orden de trabajo del consultorio, por año: OPT-2026-0007. */
function optica_siguiente_folio(): string
{
    $prefijo = 'OPT-' . date('Y') . '-';
    $desde   = strlen($prefijo) + 1;
    $st = db()->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(folio, $desde) AS UNSIGNED)), 0)
         FROM optica_trabajos WHERE consultorio_id = ? AND folio LIKE ?"
    );
    $st->execute([tenant_id(), $prefijo . '%']);
    $n = (int) $st->fetchColumn() + 1;
    return $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Resumen de una graduación en una línea, como se dicta al laboratorio:
 *   OD -1.25 -0.50 x 90°  ·  OI -1.00 -0.75 x 85°  ·  ADD +2.00  ·  DIP 62
 */
function optica_graduacion_resumen(array $g): string
{
    $ojo = function (string $p) use ($g): string {
        $s = fmt_dioptria($g[$p . '_esfera'] ?? null);
        $c = $g[$p . '_cilindro'] ?? null;
        $txt = $s;
        if ($c !== null && $c !== '') {
            $txt .= ' ' . fmt_dioptria($c) . ' x ' . fmt_eje($g[$p . '_eje'] ?? null);
        }
        return $txt;
    };

    $partes = ['OD ' . $ojo('od'), 'OI ' . $ojo('oi')];

    $add = $g['od_adicion'] ?? $g['oi_adicion'] ?? null;
    if ($add !== null && $add !== '') $partes[] = 'ADD ' . fmt_dioptria($add);
    if (!empty($g['dip']))            $partes[] = 'DIP ' . rtrim(rtrim((string) $g['dip'], '0'), '.');

    return implode(' · ', $partes);
}

/**
 * Micas del catálogo que cubren una graduación.
 *
 * El precio de una mica NO es fijo: depende del rango de graduación (tallar un
 * -6.00 cuesta más que un -1.00). Aquí se filtra el catálogo por la esfera más
 * fuerte de los dos ojos y por el cilindro más alto, para no ofrecerle al
 * vendedor una mica que no se puede fabricar con esa receta.
 */
function optica_micas_para(array $g, ?string $tipo_lente = null): array
{
    // La esfera "que manda" es la de mayor valor absoluto entre ambos ojos.
    $esferas = array_filter([$g['od_esfera'] ?? null, $g['oi_esfera'] ?? null],
                            fn($v) => $v !== null && $v !== '');
    $cils    = array_filter([$g['od_cilindro'] ?? null, $g['oi_cilindro'] ?? null],
                            fn($v) => $v !== null && $v !== '');

    $esfera = 0.0;
    foreach ($esferas as $v) { if (abs((float) $v) > abs($esfera)) $esfera = (float) $v; }
    $cil = 0.0;
    foreach ($cils as $v) { if (abs((float) $v) > abs($cil)) $cil = (float) $v; }

    $sql = 'SELECT * FROM optica_micas
            WHERE consultorio_id = ? AND activo = 1
              AND (esfera_min   IS NULL OR ? >= esfera_min)
              AND (esfera_max   IS NULL OR ? <= esfera_max)
              AND (cilindro_max IS NULL OR ? <= cilindro_max)';
    $params = [tenant_id(), $esfera, $esfera, abs($cil)];

    $tipo = $tipo_lente ?: ($g['tipo_lente'] ?? null);
    if ($tipo && isset(optica_tipos_lente()[$tipo])) {
        $sql .= ' AND tipo_lente = ?';
        $params[] = $tipo;
    }
    $sql .= ' ORDER BY precio';

    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Micas de arranque, para que el catálogo no nazca vacío. Precios en 0 a
 * propósito: los pone la óptica según lo que le cobre su laboratorio.
 * [nombre, tipo, material, tratamientos, esfera_min, esfera_max, cil_max, días]
 */
function optica_micas_comunes(): array
{
    return [
        ['Monofocal CR-39',                    'monofocal',  'CR-39',            null,                        -4,  4, 2, 2],
        ['Monofocal CR-39 antirreflejante',    'monofocal',  'CR-39',            'Antirreflejante',           -4,  4, 2, 3],
        ['Monofocal policarbonato AR',         'monofocal',  'Policarbonato',    'Antirreflejante',           -8,  6, 4, 3],
        ['Monofocal alto índice 1.67 AR',      'monofocal',  'Alto índice 1.67', 'Antirreflejante',          -12,  8, 6, 5],
        ['Monofocal fotocromático AR',         'monofocal',  'Policarbonato',    'Fotocromático, AR',         -8,  6, 4, 5],
        ['Monofocal filtro azul AR',           'monofocal',  'CR-39',            'Filtro azul, AR',           -6,  6, 4, 4],
        ['Bifocal flat-top CR-39',             'bifocal',    'CR-39',            null,                        -6,  6, 4, 4],
        ['Progresivo estándar CR-39 AR',       'progresivo', 'CR-39',            'Antirreflejante',           -6,  6, 4, 5],
        ['Progresivo digital policarbonato AR','progresivo', 'Policarbonato',    'Antirreflejante',           -8,  6, 4, 7],
        ['Progresivo premium alto índice AR',  'progresivo', 'Alto índice 1.67', 'Antirreflejante, filtro azul', -12, 8, 6, 10],
        ['Ocupacional (oficina) AR',           'ocupacional','CR-39',            'Antirreflejante',           -6,  6, 4, 6],
    ];
}

/* --------------------------------------------------------------------
 *  Laboratorio (órdenes de estudio y resultados)
 * ------------------------------------------------------------------ */

/** Estados de una orden de laboratorio: clave => [etiqueta, color de badge]. */
function lab_estados(): array
{
    return [
        'solicitada' => ['Solicitada',  'secondary'],
        'en_proceso' => ['En proceso',  'info'],
        'lista'      => ['Lista',       'primary'],
        'entregada'  => ['Entregada',   'success'],
        'cancelada'  => ['Cancelada',   'dark'],
    ];
}

function lab_estado_label(string $estado): string
{
    return t(lab_estados()[$estado][0] ?? $estado);
}

function lab_estado_badge(string $estado): string
{
    return lab_estados()[$estado][1] ?? 'secondary';
}

/** Siguiente folio de orden de laboratorio del consultorio, por año: LAB-2026-0007. */
function lab_siguiente_folio(): string
{
    $prefijo = 'LAB-' . date('Y') . '-';
    $desde   = strlen($prefijo) + 1;
    $st = db()->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(folio, $desde) AS UNSIGNED)), 0)
         FROM lab_ordenes WHERE consultorio_id = ? AND folio LIKE ?"
    );
    $st->execute([tenant_id(), $prefijo . '%']);
    $n = (int) $st->fetchColumn() + 1;
    return $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/**
 * Estudios de laboratorio de arranque, para que el catálogo no nazca vacío.
 * El consultorio los carga con un botón y luego ajusta precios a su realidad:
 * los de aquí van en 0 a propósito, para que nadie cobre un precio inventado.
 */
function lab_estudios_comunes(): array
{
    return [
        ['Biometría hemática completa', 'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  '', ''],
        ['Química sanguínea (6 elementos)', 'Sangre', 'Sangre venosa', 'Ayuno de 8 h', '', ''],
        ['Glucosa en ayuno',            'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  'mg/dL', '70 - 100'],
        ['Hemoglobina glucosilada (HbA1c)', 'Sangre', 'Sangre venosa', '',           '%',     '< 5.7'],
        ['Perfil de lípidos',           'Sangre', 'Sangre venosa', 'Ayuno de 12 h', '', ''],
        ['Colesterol total',            'Sangre', 'Sangre venosa', 'Ayuno de 12 h', 'mg/dL', '< 200'],
        ['Triglicéridos',               'Sangre', 'Sangre venosa', 'Ayuno de 12 h', 'mg/dL', '< 150'],
        ['Ácido úrico',                 'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  'mg/dL', '3.4 - 7.0'],
        ['Creatinina',                  'Sangre', 'Sangre venosa', '',              'mg/dL', '0.6 - 1.2'],
        ['Urea / BUN',                  'Sangre', 'Sangre venosa', '',              'mg/dL', '7 - 20'],
        ['Pruebas de función hepática', 'Sangre', 'Sangre venosa', 'Ayuno de 8 h',  '', ''],
        ['Perfil tiroideo (TSH, T3, T4)', 'Sangre', 'Sangre venosa', '',            '', ''],
        ['Examen general de orina',     'Orina',  'Orina',         'Primera micción del día', '', ''],
        ['Coprológico',                 'Heces',  'Heces',         '',              '', ''],
        ['Prueba de embarazo (BhCG)',   'Sangre', 'Sangre venosa', '',              '', ''],
        ['Radiografía de tórax',        'Imagen', '—',             '',              '', ''],
        ['Ultrasonido abdominal',       'Imagen', '—',             'Ayuno de 6 h',  '', ''],
        ['Electrocardiograma',          'Imagen', '—',             '',              '', ''],
    ];
}

/**
 * Catálogo de especialidades médicas (para etiquetar plantillas, médicos, etc.).
 * Lista abierta: el usuario puede escribir cualquier otra. Orden alfabético.
 */
function especialidades_catalogo(): array
{
    return [
        'Medicina General', 'Medicina Familiar', 'Medicina Interna', 'Pediatría',
        'Ginecología y Obstetricia', 'Traumatología y Ortopedia', 'Dermatología',
        'Cardiología', 'Nutrición', 'Endocrinología', 'Gastroenterología',
        'Neumología', 'Nefrología', 'Neurología', 'Urología', 'Oftalmología',
        'Otorrinolaringología', 'Psicología', 'Psiquiatría', 'Oncología',
        'Alergología e Inmunología', 'Hematología', 'Reumatología', 'Geriatría',
        'Odontología', 'Cirugía General', 'Rehabilitación y Fisioterapia',
    ];
}

/**
 * Plantillas de consulta iniciales por especialidad. Formato de cada fila:
 * [nombre, especialidad, tipo, motivo, exploracion, diagnostico, tratamiento, receta, notas].
 * Son esqueletos editables: el médico las ajusta a su estilo. Cubren muchas
 * áreas de la medicina para que el sistema se sienta integral desde el día 1.
 */
function plantillas_semilla(): array
{
    $g = 'general'; // tipo del expediente (general/medico/dental)
    return [
        ['Consulta de primera vez', 'Medicina General', $g,
            'Motivo de consulta:', "Signos vitales:\nExploración física por aparatos y sistemas:", 'Impresión diagnóstica:', 'Plan de manejo:', '', 'Antecedentes revisados. Se explica diagnóstico y plan al paciente.'],
        ['Control de hipertensión', 'Medicina Interna', $g,
            'Control de presión arterial', "TA: ___/___ mmHg  FC: ___  Peso: ___\nEdema, ruidos cardiacos, campos pulmonares:", 'Hipertensión arterial sistémica en control / descontrol', 'Ajuste de antihipertensivo. Dieta hiposódica. Ejercicio.', '', 'Meta TA <130/80. Cita de control en 1 mes.'],
        ['Control de diabetes', 'Medicina Interna', $g,
            'Control de diabetes mellitus', "Glucosa capilar: ___ mg/dL\nPeso/IMC. Revisión de pies (sensibilidad, pulsos, lesiones):", 'Diabetes mellitus tipo 2 en control / descontrol', 'Ajuste de hipoglucemiante. Dieta y ejercicio. Educación en autocuidado.', '', 'Solicitar HbA1c y perfil de lípidos. Meta HbA1c <7%.'],
        ['Control del niño sano', 'Pediatría', $g,
            'Control de niño sano', "Peso: ___ kg  Talla: ___ cm  PC: ___ cm\nPercentiles. Desarrollo psicomotor. Esquema de vacunación:", 'Niño sano / desarrollo adecuado para la edad', 'Continuar lactancia/alimentación. Vacunas según cartilla.', '', 'Curva de crecimiento actualizada. Próximo control según edad.'],
        ['Control prenatal', 'Ginecología y Obstetricia', $g,
            'Control prenatal', "FUM: ___  SDG: ___  FPP: ___\nTA, peso, FCF, altura uterina, movimientos fetales:", 'Embarazo de ___ semanas de curso normal / con factores de riesgo', 'Ácido fólico/hierro. Signos de alarma explicados. USG y laboratorios según trimestre.', '', 'Próxima cita prenatal en ___ semanas.'],
        ['Valoración ortopédica', 'Traumatología y Ortopedia', $g,
            'Dolor / lesión musculoesquelética', "Inspección, palpación, arcos de movilidad, fuerza, pruebas especiales:\nRadiografías:", 'Impresión diagnóstica ortopédica', 'Inmovilización / analgesia / rehabilitación / referencia a cirugía según el caso.', '', 'Reposo relativo. Datos de alarma. Control con estudios de imagen.'],
        ['Valoración dermatológica', 'Dermatología', $g,
            'Lesión / padecimiento en piel', "Descripción de la lesión (tipo, color, tamaño, distribución, tiempo de evolución):\nDermatoscopía:", 'Diagnóstico dermatológico', 'Tratamiento tópico/sistémico. Fotoprotección. Cuidados de la piel.', '', 'Fotografía clínica para seguimiento comparativo.'],
        ['Valoración cardiológica', 'Cardiología', $g,
            'Valoración cardiovascular', "TA, FC, ruidos cardiacos, soplos, pulsos, edema.\nECG: ___   Riesgo cardiovascular:", 'Impresión diagnóstica cardiológica', 'Manejo farmacológico. Control de factores de riesgo (TA, lípidos, glucosa, tabaquismo).', '', 'Solicitar ECG/ecocardiograma/prueba de esfuerzo según el caso.'],
        ['Consulta de nutrición', 'Nutrición', $g,
            'Valoración nutricional', "Peso: ___  Talla: ___  IMC: ___  % grasa: ___  Cintura: ___ cm\nHábitos alimentarios y actividad física:", 'Diagnóstico nutricional (sobrepeso/obesidad/desnutrición/adecuado)', 'Plan de alimentación individualizado (___ kcal). Metas de peso. Actividad física.', '', 'Próxima medición y ajuste del plan en ___ semanas.'],
        ['Consulta otorrinolaringología', 'Otorrinolaringología', $g,
            'Padecimiento de oído / nariz / garganta', "Otoscopía, rinoscopía, orofaringe, cuello:", 'Diagnóstico ORL', 'Tratamiento médico. Lavados/aseos. Referencia a estudios (audiometría) si aplica.', '', 'Datos de alarma explicados.'],
        ['Consulta psicológica', 'Psicología', $g,
            'Motivo de consulta / valoración emocional', "Estado mental, afecto, discurso, ideación. Escalas aplicadas (ansiedad/depresión):", 'Impresión clínica', 'Plan terapéutico. Número y frecuencia de sesiones.', '', 'Acuerdos de la sesión y tareas.'],
        ['Valoración oftalmológica', 'Oftalmología', $g,
            'Padecimiento ocular / revisión de la vista', "Agudeza visual OD/OI, presión intraocular, segmento anterior, fondo de ojo:", 'Diagnóstico oftalmológico', 'Tratamiento / corrección óptica / referencia según el caso.', '', 'Control y estudios complementarios.'],
        ['Consulta de gastroenterología', 'Gastroenterología', $g,
            'Síntomas digestivos', "Abdomen: inspección, palpación, peristalsis, dolor, visceromegalias:", 'Impresión diagnóstica gastrointestinal', 'Medidas dietéticas y farmacológicas. Estudios (endoscopía/USG) según el caso.', '', 'Datos de alarma digestivos explicados.'],
        ['Valoración de urgencia', 'Medicina General', $g,
            'Urgencia', "Triage. Signos vitales. Exploración dirigida al motivo:", 'Diagnóstico de urgencia', 'Manejo inmediato. Referencia/traslado si amerita.', '', 'Hora de atención y evolución.'],
    ];
}

/** Crea la tabla de exámenes de oftalmología si no existe (self-healing). */
function ensure_oftalmo_table(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS oftalmo_examenes (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, av_od VARCHAR(20), av_oi VARCHAR(20), pio_od DECIMAL(4,1), pio_oi DECIMAL(4,1),
            segmento_ant TEXT, fondo_ojo TEXT, diagnostico VARCHAR(255), plan TEXT, notas TEXT, creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_oft (consultorio_id, paciente_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existe */ }
}

/** Crea la tabla de sesiones de psicología si no existe (self-healing). */
function ensure_psico_table(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS psico_sesiones (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, enfoque VARCHAR(160), notas TEXT, tareas TEXT, phq9 TINYINT, gad7 TINYINT,
            riesgo ENUM('ninguno','bajo','moderado','alto'), creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_psico (consultorio_id, paciente_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existe */ }
}

/** Interpreta el PHQ-9 (depresión, 0-27) → [etiqueta, color]. */
function phq9_nivel(?int $s): array
{
    if ($s === null) return ['—','secondary'];
    if ($s < 5)   return ['Mínima', 'success'];
    if ($s < 10)  return ['Leve', 'info'];
    if ($s < 15)  return ['Moderada', 'warning'];
    if ($s < 20)  return ['Moderada-grave', 'danger'];
    return ['Grave', 'danger'];
}

/** Interpreta el GAD-7 (ansiedad, 0-21) → [etiqueta, color]. */
function gad7_nivel(?int $s): array
{
    if ($s === null) return ['—','secondary'];
    if ($s < 5)   return ['Mínima', 'success'];
    if ($s < 10)  return ['Leve', 'info'];
    if ($s < 15)  return ['Moderada', 'warning'];
    return ['Grave', 'danger'];
}

/** Crea las tablas de dermatología si no existen (self-healing). */
function ensure_derma_tables(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS derma_lesiones (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            region VARCHAR(120), tipo VARCHAR(120), descripcion TEXT, diagnostico VARCHAR(255),
            activo TINYINT(1) NOT NULL DEFAULT 1, creado_por INT, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_derma (consultorio_id, paciente_id, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS derma_fotos (
            id INT AUTO_INCREMENT PRIMARY KEY, lesion_id INT NOT NULL, archivo_id INT NOT NULL, fecha DATE NOT NULL,
            notas VARCHAR(255), creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_dfoto (lesion_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existen */ }
}

/** Crea la tabla de valoraciones de cardiología si no existe (self-healing). */
function ensure_cardio_table(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS cardio_valoraciones (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, presion VARCHAR(20), fc SMALLINT, colesterol_total DECIMAL(5,1), hdl DECIMAL(5,1),
            ldl DECIMAL(5,1), trigliceridos DECIMAL(6,1), glucosa DECIMAL(5,1), tabaquismo TINYINT(1) NOT NULL DEFAULT 0,
            diabetes TINYINT(1) NOT NULL DEFAULT 0, nyha ENUM('I','II','III','IV'),
            riesgo ENUM('bajo','moderado','alto','muy_alto'), ecg_hallazgos TEXT, notas TEXT, creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_cardio (consultorio_id, paciente_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existe */ }
}

/** Crea la tabla de valoraciones de nutrición si no existe (self-healing). */
function ensure_nutricion_table(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS nutricion_valoraciones (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, peso DECIMAL(5,2), estatura DECIMAL(5,2), grasa_pct DECIMAL(4,1), musculo_pct DECIMAL(4,1),
            cintura DECIMAL(5,1), cadera DECIMAL(5,1), meta_peso DECIMAL(5,2), kcal_plan SMALLINT, plan TEXT, notas TEXT,
            creado_por INT, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_nut (consultorio_id, paciente_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existe */ }
}

/** Clasificación de IMC (OMS). Devuelve [etiqueta, color-bootstrap]. */
function imc_clasificacion(float $imc): array
{
    if ($imc <= 0)     return ['—', 'secondary'];
    if ($imc < 18.5)   return ['Bajo peso', 'info'];
    if ($imc < 25)     return ['Normal', 'success'];
    if ($imc < 30)     return ['Sobrepeso', 'warning'];
    if ($imc < 35)     return ['Obesidad I', 'danger'];
    if ($imc < 40)     return ['Obesidad II', 'danger'];
    return ['Obesidad III', 'danger'];
}

/** Crea las tablas de control prenatal si no existen (self-healing). */
function ensure_prenatal_tables(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS embarazos (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fum DATE, fpp DATE, grupo_sanguineo VARCHAR(6), gestas TINYINT, partos TINYINT, cesareas TINYINT,
            abortos TINYINT, riesgo ENUM('bajo','alto') NOT NULL DEFAULT 'bajo', activo TINYINT(1) NOT NULL DEFAULT 1,
            desenlace VARCHAR(120), cerrado_en DATE, notas TEXT, creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_emb (consultorio_id, paciente_id, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS prenatal_visitas (
            id INT AUTO_INCREMENT PRIMARY KEY, embarazo_id INT NOT NULL, fecha DATE NOT NULL, sdg DECIMAL(4,1),
            peso DECIMAL(5,2), presion VARCHAR(20), fcf SMALLINT, altura_uterina DECIMAL(4,1), movimientos TINYINT(1),
            edema VARCHAR(40), notas TEXT, creado_por INT, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pv (embarazo_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existen */ }
}

/** Semanas de gestación entre la FUM y una fecha (por omisión hoy). "" si no hay FUM. */
function sdg_desde_fum(?string $fum, ?string $hasta = null): string
{
    if (!$fum) return '';
    try {
        $ini = new DateTime($fum);
        $fin = new DateTime($hasta ?: 'today');
        $dias = (int) $ini->diff($fin)->days;
        if ($fin < $ini) return '';
        $sem = intdiv($dias, 7); $d = $dias % 7;
        return $sem . '.' . $d . ' SDG'; // p. ej. 24.3 SDG (24 semanas 3 días)
    } catch (Throwable $e) { return ''; }
}

/** Fecha probable de parto: FUM + 280 días (regla de Naegele). null si no hay FUM. */
function fpp_desde_fum(?string $fum): ?string
{
    if (!$fum) return null;
    try { return (new DateTime($fum))->modify('+280 days')->format('Y-m-d'); }
    catch (Throwable $e) { return null; }
}

/** Crea la tabla de consentimientos si no existe (self-healing). */
function ensure_consentimientos_table(): void
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS consentimientos (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1,
            paciente_id INT NOT NULL, medico_id INT DEFAULT NULL,
            titulo VARCHAR(180) NOT NULL, contenido MEDIUMTEXT DEFAULT NULL,
            firma_paciente MEDIUMTEXT DEFAULT NULL, firma_medico MEDIUMTEXT DEFAULT NULL,
            firmante VARCHAR(160) DEFAULT NULL, creado_por INT DEFAULT NULL,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_consent (consultorio_id, paciente_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existe */ }
}

/**
 * Plantillas de consentimiento informado (título => cuerpo). {paciente} y
 * {consultorio} se sustituyen al usarlas. Son editables antes de firmar.
 */
function consentimientos_plantillas(): array
{
    $base = 'Yo, {paciente}, declaro que se me ha explicado en lenguaje claro ';
    return [
        'Consentimiento general de atención médica' =>
            $base . 'la naturaleza de mi padecimiento y el plan de atención propuesto en {consultorio}. '
            . "He podido hacer preguntas y se me han respondido. Autorizo la atención médica y los "
            . "procedimientos diagnósticos y terapéuticos que mi médico considere necesarios.",
        'Consentimiento para procedimiento / cirugía' =>
            $base . 'en qué consiste el procedimiento propuesto, sus beneficios, riesgos, posibles '
            . "complicaciones y alternativas, incluida la opción de no realizarlo. Comprendo que la "
            . "medicina no es una ciencia exacta y no se me han garantizado resultados. Autorizo su realización.",
        'Consentimiento para anestesia / sedación' =>
            $base . 'el tipo de anestesia o sedación a emplear, sus riesgos y cuidados. Informé mis '
            . "antecedentes, alergias y medicamentos. Autorizo su administración.",
        'Aviso de privacidad y tratamiento de datos (LFPDPPP)' =>
            'Autorizo a {consultorio} el tratamiento de mis datos personales y de salud para fines de '
            . "atención médica, expediente clínico, facturación y contacto, conforme a la Ley Federal de "
            . "Protección de Datos Personales en Posesión de los Particulares. Conozco mis derechos ARCO.",
        'Consentimiento para fotografía clínica' =>
            $base . 'que se tomarán fotografías clínicas con fines de seguimiento y expediente. Autorizo '
            . "su captura y resguardo confidencial; no se usarán con fines distintos sin mi autorización.",
    ];
}

/** Caras de un diente: clave => etiqueta. Se guardan como "O,M,V". */
function caras_dentales(): array
{
    return [
        'O' => 'Oclusal',
        'M' => 'Mesial',
        'D' => 'Distal',
        'V' => 'Vestibular',
        'L' => 'Lingual/Palatina',
    ];
}

/** Normaliza la lista de caras que llega de un formulario a "O,M,V" (o null). */
function caras_normalizar($caras): ?string
{
    $validas = array_keys(caras_dentales());
    $entrada = is_array($caras) ? $caras : explode(',', (string) $caras);
    $limpias = array_values(array_intersect($validas, array_map('trim', $entrada)));
    return $limpias ? implode(',', $limpias) : null;
}

/** Dientes válidos en notación FDI (permanentes), en orden de arcada. */
function dientes_fdi(): array
{
    return array_merge(
        [18,17,16,15,14,13,12,11], [21,22,23,24,25,26,27,28],
        [48,47,46,45,44,43,42,41], [31,32,33,34,35,36,37,38]
    );
}
