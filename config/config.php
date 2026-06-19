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
$__candidatos = array_filter([
    getenv('MEDIAGENDA_SECRETS') ?: null,                                  // ruta explícita por entorno
    !empty($_SERVER['DOCUMENT_ROOT'])
        ? dirname($_SERVER['DOCUMENT_ROOT']) . '/mediagenda_secrets.php'    // un nivel arriba de public_html
        : null,
    __DIR__ . '/secrets.php',                                              // local (en .gitignore) para desarrollo
]);
foreach ($__candidatos as $__f) {
    if (is_file($__f)) { require $__f; break; }
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
            die('Error de conexión a la base de datos. Revisa el archivo de secretos '
                . '(credenciales MySQL) fuera de public_html.');
        }
    }
    return $pdo;
}
