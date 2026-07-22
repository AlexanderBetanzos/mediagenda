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

$r = recordatorios_enviar(null, function ($linea) { echo $linea . "\n"; });

echo "Total: {$r['procesadas']} citas procesadas, {$r['correos']} correos enviados (mañana = {$r['fecha']})\n";
