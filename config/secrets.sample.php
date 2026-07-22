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

// --- Diagnóstico en producción (opcional; vacío = desactivado) ---
// Con esto puedes ver el error real de una página añadiendo ?debug=LA_CLAVE a
// su URL. Usa una cadena larga y aleatoria, y trátala como una contraseña:
// quien la tenga puede leer rutas del servidor y fragmentos de consultas.
define('APP_DEBUG_TOKEN', '');                   // p. ej. 'k7Fq2xR9vT4mZ8pL'

// --- Mercado Pago (opcional; vacío = pagos deshabilitados) ---
// Pruebas: usa las credenciales de PRUEBA (Access Token que empieza con TEST-).
// Producción: las credenciales productivas. Panel: https://www.mercadopago.com.mx/developers
define('MP_ACCESS_TOKEN', '');                   // TEST-xxxx (sandbox) o APP_USR-xxxx (prod)
define('MP_PUBLIC_KEY',   '');                   // TEST-xxxx o APP_USR-xxxx

// --- Correo (remitente de los correos transaccionales) ---
// Debe ser una dirección DEL DOMINIO del sitio para que SPF/DKIM validen y no
// caigan en spam. Si lo dejas sin definir, usa no-reply@mediagenda.com.mx.
// define('CORREO_FROM',      'no-reply@tu-dominio.com');
// define('CORREO_FROM_NAME', 'Tu Clínica');

// --- SMTP (RECOMENDADO) ---
// En hosting compartido la función mail() de PHP suele no entregar los correos
// o mandarlos a spam. Con un buzón SMTP del propio dominio, los correos llegan.
// Crea un buzón en tu hosting (p. ej. no-reply@tu-dominio.com) y pon aquí sus
// datos. En Hostinger: SMTP_HOST='smtp.hostinger.com', puerto 465 (ssl) o 587
// (tls). SMTP_USER es normalmente el correo completo. Deja SMTP_HOST vacío para
// seguir usando mail(). CORREO_FROM debería ser el MISMO buzón que SMTP_USER.
// define('SMTP_HOST',   'smtp.hostinger.com');
// define('SMTP_PORT',   465);            // 465 = ssl, 587 = tls
// define('SMTP_SECURE', 'ssl');          // 'ssl' | 'tls' | ''
// define('SMTP_USER',   'no-reply@tu-dominio.com');
// define('SMTP_PASS',   'LA_CONTRASEÑA_DEL_BUZON');

// --- Cron de recordatorios de cita ---
// SITIO_URL: dominio real del sitio (con https://, sin barra final). SOLO se usa
// cuando el cron corre por CLI (php cron/recordatorios.php); sin él, los enlaces
// de los correos saldrían como http://localhost y llegarían rotos.
define('SITIO_URL', 'https://mediagenda.com.mx');
// CRON_TOKEN: obligatorio SOLO si programas el cron por URL
// (https://tu-sitio/cron/recordatorios.php?key=EL_TOKEN). Usa una cadena larga
// y aleatoria. Si corres el cron por CLI no hace falta.
define('CRON_TOKEN', '');                        // p. ej. 'a9F3k...'
