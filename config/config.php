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

// Diagnóstico temporal: visita la web con ?diag=1 para ver dónde se busca el
// archivo de secretos. (Quitar este bloque cuando todo funcione.)
if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? '(vacío)') . "\n\n";
    echo "Se busca 'mediagenda_secrets.php' en estas rutas (la primera que exista gana):\n";
    foreach ($__candidatos as $__c) {
        echo (is_file($__c) ? "  [ENCONTRADO] " : "  [no existe]  ") . $__c . "\n";
    }
    echo "\nSECRETS_LOADED: " . ($__secretLoaded ? 'sí' : 'no') . "\n";
    exit;
}

// 2) Valores por defecto (desarrollo XAMPP) si el archivo de secretos no los definió.
defined('DB_HOST')    || define('DB_HOST',    getenv('DB_HOST') ?: '127.0.0.1');
defined('DB_NAME')    || define('DB_NAME',    getenv('DB_NAME') ?: 'consultorios_db');
defined('DB_USER')    || define('DB_USER',    getenv('DB_USER') ?: 'root');
defined('DB_PASS')    || define('DB_PASS',    getenv('DB_PASS') ?: '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('BASE_URL')   || define('BASE_URL',   getenv('BASE_URL') ?: '/consultorios');

// 3) Configuración de la aplicación (no secreta).
define('APP_NAME', 'MediAgenda');
define('MONEDA', 'MXN');
date_default_timezone_set('America/Mexico_City');

// 4) Errores: visibles en local/CLI, ocultos en producción (salvo APP_DEBUG=1).
$__host  = $_SERVER['HTTP_HOST'] ?? '';
$__local = (PHP_SAPI === 'cli')
    || in_array($__host, ['localhost', '127.0.0.1'], true)
    || strncmp($__host, 'localhost:', 10) === 0
    || strncmp($__host, '127.0.0.1:', 10) === 0;
error_reporting(E_ALL);
ini_set('display_errors', (getenv('APP_DEBUG') === '1' || $__local) ? '1' : '0');

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
