<?php
/**
 * Recordatorios automáticos de cita (correo).
 * Pensado para correr UNA VEZ AL DÍA por cron. Envía un recordatorio por
 * correo a los pacientes con cita MAÑANA (que no estén canceladas y que aún
 * no hayan recibido el recordatorio). Idempotente: marca cada cita al procesarla.
 *
 * Cómo programarlo:
 *   - Por CLI (recomendado):   php /ruta/cron/recordatorios.php
 *   - Por URL (cron de hosting): https://tu-sitio/cron/recordatorios.php?key=TOKEN
 *
 * Cada hora (para el aviso "2 horas antes"):  agrega  --cada-hora  (CLI) o
 * &cada_hora=1 (URL). Así el recordatorio del día anterior solo sale entre
 * 9:00 y 20:59 —no a medianoche— y en cada corrida salen los de 2 horas antes.
 *     (define CRON_TOKEN en el archivo de secretos / variables de entorno).
 */
require_once __DIR__ . '/../includes/recordatorios.php';

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

$eco = function ($linea) { echo $linea . "\n"; };
$cadaHora = $cli ? in_array('--cada-hora', $argv ?? [], true) : !empty($_GET['cada_hora']);
$hora = (int) date('G');

if (!$cadaHora || ($hora >= 9 && $hora < 21)) {
    $r = recordatorios_enviar(null, $eco);
    echo "Total: {$r['procesadas']} citas procesadas, {$r['correos']} correos, "
       . "{$r['whatsapp']} WhatsApp (mañana = {$r['fecha']})\n";
}
if ($cadaHora) {
    $r2 = recordatorios_2h($eco);
    echo "2 horas antes: {$r2['procesadas']} citas, {$r2['correos']} correos, {$r2['whatsapp']} WhatsApp\n";
}
