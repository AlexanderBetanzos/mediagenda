<?php
/**
 * Configuración global. Este archivo SÍ se versiona y se despliega.
 *
 * Las CREDENCIALES no van aquí. Se cargan desde un archivo de secretos que
 * vive FUERA de public_html (a prueba de despliegues: ningún deploy lo borra
 * ni lo sobrescribe) o desde variables de entorno.
 *
 * En el servidor crea el archivo UN NIVEL ARRIBA de public_html, p. ej.:
 *     /home/USUARIO/mediagenda_secrets.php
 * con el contenido de config/secrets.sample.php.
 */

// 1) Cargar credenciales desde el archivo de secretos (el primero que exista).
//    Se busca en varios niveles por encima de public_html para tolerar la
//    estructura de carpetas de distintos hostings.
$__candidatos = [];
if ($__env = getenv('MEDIAGENDA_SECRETS')) {
    $__candidatos[] = $__env;                                              // ruta explícita por entorno
}
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $__dr = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    $__candidatos[] = dirname($__dr)     . '/mediagenda_secrets.php';      // carpeta que contiene public_html
    $__candidatos[] = dirname($__dr, 2)  . '/mediagenda_secrets.php';      // dos niveles arriba
    $__candidatos[] = dirname($__dr, 3)  . '/mediagenda_secrets.php';      // home en Hostinger (/home/USUARIO)
}
$__candidatos[] = __DIR__ . '/secrets.php';                               // local (en .gitignore) para desarrollo

$__secretLoaded = false;
foreach ($__candidatos as $__f) {
    if ($__f && is_file($__f)) { require $__f; $__secretLoaded = true; break; }
}
define('SECRETS_LOADED', $__secretLoaded);

// 2) Valores por defecto (desarrollo XAMPP) si el archivo de secretos no los definió.
defined('DB_HOST')    || define('DB_HOST',    getenv('DB_HOST') ?: '127.0.0.1');
defined('DB_NAME')    || define('DB_NAME',    getenv('DB_NAME') ?: 'consultorios_db');
defined('DB_USER')    || define('DB_USER',    getenv('DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('BASE_URL')   || define('BASE_URL',   getenv('BASE_URL') ?: '/consultorios');

// Credenciales de Mercado Pago (desde el archivo de secretos o variables de
// entorno). Vacías = pagos deshabilitados (el sistema funciona igual).
defined('MP_ACCESS_TOKEN') || define('MP_ACCESS_TOKEN', getenv('MP_ACCESS_TOKEN') ?: '');
defined('MP_PUBLIC_KEY')   || define('MP_PUBLIC_KEY',   getenv('MP_PUBLIC_KEY')   ?: '');

// Clave para ver los errores en producción con ?debug=CLAVE. Vacía = desactivado.
defined('APP_DEBUG_TOKEN') || define('APP_DEBUG_TOKEN', getenv('APP_DEBUG_TOKEN') ?: '');

// Remitente de los correos (debe ser del dominio del sitio para buena entrega).
defined('CORREO_FROM')      || define('CORREO_FROM',      getenv('CORREO_FROM') ?: 'no-reply@mediagenda.com.mx');
defined('CORREO_FROM_NAME') || define('CORREO_FROM_NAME', 'MediOS');

// Datos de soporte del proveedor (MediOS). Globales: NO dependen del
// consultorio. Se muestran en el panel para que los clientes nos contacten.
// El número de WhatsApp va en formato internacional sin signos (52 + número).
defined('SOPORTE_WHATSAPP') || define('SOPORTE_WHATSAPP', getenv('SOPORTE_WHATSAPP') ?: '525551568856');
defined('SOPORTE_TEL')      || define('SOPORTE_TEL',      getenv('SOPORTE_TEL')      ?: '55 5156 8856');
defined('SOPORTE_EMAIL')    || define('SOPORTE_EMAIL',    getenv('SOPORTE_EMAIL')    ?: 'contacto@betasyd.com.mx');
defined('SOPORTE_HORARIO')  || define('SOPORTE_HORARIO',  getenv('SOPORTE_HORARIO')  ?: 'Lunes a Viernes de 9:00 a 18:00 hrs.');

// 3) Configuración de la aplicación (no secreta).
define('APP_NAME', 'MediOS');
define('MONEDA', 'MXN');
date_default_timezone_set('America/Mexico_City');

/* 4) Errores: visibles en local/CLI, ocultos en producción.
 *
 * En producción se encienden de dos formas:
 *   · `?debug=CLAVE` en cualquier URL, con la clave de APP_DEBUG_TOKEN.
 *   · APP_DEBUG=1 como variable de entorno o `SetEnv APP_DEBUG 1` en .htaccess
 *     (Apache la entrega por $_SERVER; getenv() no la ve).
 *
 * El token va en el archivo de secretos, fuera de public_html. Sin token no hay
 * forma de activar el debug desde la URL: mostrar errores revela rutas del
 * servidor y fragmentos de consultas a cualquiera que sepa provocarlos.
 */
$__host  = $_SERVER['HTTP_HOST'] ?? '';
$__local = (PHP_SAPI === 'cli')
    || in_array($__host, ['localhost', '127.0.0.1'], true)
    || strncmp($__host, 'localhost:', 10) === 0
    || strncmp($__host, '127.0.0.1:', 10) === 0;

$__debug = (getenv('APP_DEBUG') === '1') || (($_SERVER['APP_DEBUG'] ?? '') === '1');
if (!$__debug && APP_DEBUG_TOKEN !== '' && isset($_GET['debug'])) {
    $__debug = hash_equals(APP_DEBUG_TOKEN, (string) $_GET['debug']);
}

error_reporting(E_ALL);
ini_set('display_errors', ($__debug || $__local) ? '1' : '0');

/**
 * Devuelve una única instancia de PDO (patrón singleton simple).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $hint = SECRETS_LOADED
                ? 'Se encontró el archivo de secretos, pero las credenciales MySQL no son válidas '
                  . '(revisa DB_USER / DB_PASS / DB_NAME).'
                : 'NO se encontró mediagenda_secrets.php fuera de public_html (revisa su ubicación).';
            die('Error de conexión a la base de datos. ' . $hint);
        }
    }
    return $pdo;
}
