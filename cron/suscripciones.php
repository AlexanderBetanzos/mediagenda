<?php
/**
 * Sincronización diaria de suscripciones con Mercado Pago (red de seguridad).
 * El webhook mantiene todo al día, pero si un aviso se pierde (servidor caído,
 * timeout) el consultorio se quedaría con un estado o una fecha de próximo
 * cobro viejos. Una vez al día se consulta cada suscripción a la API, que es
 * la fuente de verdad, y se aplica con mp_sincronizar().
 *
 * Cómo programarlo (una vez al día):
 *   - Por CLI (recomendado):   php /ruta/cron/suscripciones.php
 *   - Por URL (cron de hosting): https://tu-sitio/cron/suscripciones.php?key=TOKEN
 *     (el mismo CRON_TOKEN que usan los recordatorios).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';

// --- Seguridad: solo CLI, o web con el token correcto ---
$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $esperado = getenv('CRON_TOKEN') ?: (defined('CRON_TOKEN') ? CRON_TOKEN : '');
    if ($esperado === '' || !hash_equals($esperado, $_GET['key'] ?? '')) {
        http_response_code(403);
        exit('403');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!mp_configurado()) { exit("Mercado Pago no está configurado.\n"); }

// Las canceladas cuyo periodo pagado ya terminó no van a cambiar: no se consultan.
$subs = db()->query(
    "SELECT id, nombre, mp_suscripcion_id FROM consultorios
     WHERE mp_suscripcion_id IS NOT NULL AND mp_suscripcion_id <> ''
       AND NOT (mp_estado = 'cancelled' AND (proximo_cobro IS NULL OR proximo_cobro < CURDATE()))"
)->fetchAll();

$ok = $err = 0;
foreach ($subs as $s) {
    try {
        $pre = mp_obtener_suscripcion($s['mp_suscripcion_id']);
        mp_sincronizar($pre);
        echo "#{$s['id']} {$s['nombre']}: " . ($pre['status'] ?? '?') . "\n";
        $ok++;
    } catch (Throwable $e) {
        echo "#{$s['id']} {$s['nombre']}: ERROR " . $e->getMessage() . "\n";
        $err++;
    }
    usleep(200000);   // sin ráfagas contra la API
}
echo "Total: $ok sincronizadas, $err con error.\n";
