<?php
/**
 * PLANTILLA de secretos (credenciales). NO se versiona el archivo real.
 *
 * EN EL SERVIDOR (recomendado): copia este archivo como `mediagenda_secrets.php`
 * en la carpeta que CONTIENE a public_html (un nivel arriba), por ejemplo:
 *     /home/USUARIO/mediagenda_secrets.php
 * Así ningún despliegue lo borra ni lo sobrescribe, y no es accesible por web.
 *
 * EN LOCAL (opcional): cópialo como `config/secrets.php` (está en .gitignore)
 * solo si tus credenciales de XAMPP no son las de por defecto.
 */

define('DB_HOST', 'localhost');                  // en Hostinger suele ser 'localhost'
define('DB_NAME', 'u840714983_mediagenda');      // nombre de tu BD
define('DB_USER', 'u840714983_mediagenda');      // usuario MySQL
define('DB_PASS', 'TU_CONTRASEÑA_DE_LA_BD');     // contraseña MySQL
define('BASE_URL', '');                          // '' si el sitio va en la raíz del dominio
