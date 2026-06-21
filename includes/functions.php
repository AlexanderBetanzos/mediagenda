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

/** ID del consultorio activo: el del usuario o paciente en sesión, o 1 (público). */
function tenant_id(): int
{
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
    $permitidas = ['pacientes', 'usuarios', 'citas', 'consultas', 'recetas', 'facturas', 'archivos', 'productos'];
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

/** Exige que el módulo esté activo; si no, redirige a la página de planes. */
function require_modulo(string $clave): void
{
    if (!modulo_activo($clave)) {
        flash('Esa función no está incluida en tu plan. Mejora tu plan para activarla.', 'warning');
        redirect('/pagos/index');
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
    return ['estado' => 'ok', 'mensaje' => 'Archivo agregado al expediente.'];
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
    return [
        'programada'  => 'Programada',
        'confirmada'  => 'Confirmada',
        'esperando'   => 'En espera',
        'en_consulta' => 'En consulta',
        'atendida'    => 'Atendida',
        'cancelada'   => 'Cancelada',
        'no_asistio'  => 'No asistió',
    ][$estado] ?? $estado;
}
