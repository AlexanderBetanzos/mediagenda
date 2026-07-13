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

/** Crea la tabla de super usuarios de plataforma si aún no existe. */
function ensure_plataforma_admins_table(): void
{
    db()->exec(
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
function mensaje_recordatorio(string $paciente, string $fecha, string $hora): string
{
    $plantilla = cfg('recordatorio_plantilla',
        'Hola {paciente}, le recordamos su cita en {consultorio} el {fecha} a las {hora}. '
        . 'Por favor confirme su asistencia. ¡Gracias!');
    return strtr($plantilla, [
        '{paciente}'    => $paciente,
        '{consultorio}' => marca_nombre(),
        '{fecha}'       => $fecha,
        '{hora}'        => $hora,
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
