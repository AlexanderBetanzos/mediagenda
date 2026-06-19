<?php
/**
 * PLANTILLA de configuración. Copia este archivo como `config.php` en el
 * servidor y rellena tus credenciales reales:
 *
 *     cp config/config.sample.php config/config.php
 *
 * `config.php` está en .gitignore: NO se versiona ni se sube por el deploy,
 * así que cada entorno (local / producción) conserva sus propias credenciales.
 */

// --- Parámetros de la base de datos ---
define('DB_HOST', 'localhost');          // en hosting compartido suele ser 'localhost'
define('DB_NAME', 'TU_BASE_DE_DATOS');   // nombre de la BD en cPanel
define('DB_USER', 'TU_USUARIO');         // usuario de MySQL del hosting
define('DB_PASS', 'TU_PASSWORD');        // contraseña de MySQL
define('DB_CHARSET', 'utf8mb4');

// --- Ruta base del sitio ---
// Si el sistema vive en la raíz del dominio, usa '' ; si está en una subcarpeta,
// usa p. ej. '/consultorios'.
define('BASE_URL', '');
define('APP_NAME', 'MediAgenda');
define('MONEDA', 'MXN');

// --- Zona horaria ---
date_default_timezone_set('America/Mexico_City');

// --- Errores: en PRODUCCIÓN deben estar ocultos ---
error_reporting(E_ALL);
ini_set('display_errors', '0');          // '1' solo en desarrollo

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
            die('Error de conexión a la base de datos.');
        }
    }
    return $pdo;
}
