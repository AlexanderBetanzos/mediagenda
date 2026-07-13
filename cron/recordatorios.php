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
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/correo.php';

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

$manana = date('Y-m-d', strtotime('+1 day'));
$totProc = 0; $totMail = 0;

// Recorre todos los consultorios (cada uno decide si tiene recordatorios activos).
$consultorios = db()->query('SELECT id FROM consultorios')->fetchAll(PDO::FETCH_COLUMN);
foreach ($consultorios as $cid) {
    $cid = (int) $cid;
    // Fija el contexto del tenant para que cfg()/marca/zona resuelvan correcto.
    $_SESSION['usuario'] = ['consultorio_id' => $cid];
    cfg_all(true);

    if (cfg('recordatorio_auto', '1') !== '1') continue;

    $st = db()->prepare(
        "SELECT c.id, c.fecha, c.hora, p.nombre, p.apellidos, p.email, u.nombre AS med_nombre
         FROM citas c
         JOIN pacientes p ON p.id = c.paciente_id
         JOIN usuarios  u ON u.id = c.medico_id
         WHERE c.consultorio_id = ? AND c.fecha = ?
           AND c.estado IN ('programada','confirmada') AND c.recordatorio_en IS NULL"
    );
    $st->execute([$cid, $manana]);
    $citas = $st->fetchAll();
    if (!$citas) continue;

    $marca = db()->prepare('UPDATE citas SET recordatorio_en = NOW() WHERE id = ? AND consultorio_id = ?');
    $proc = $mail = 0;
    foreach ($citas as $c) {
        $proc++;
        if (filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            // El enlace es lo que convierte el recordatorio en una confirmación:
            // el paciente responde con un clic, sin llamar ni iniciar sesión.
            $enlace = cita_enlace((int) $c['id']);
            if (correo_recordatorio_cita($c['email'], $c['nombre'] . ' ' . $c['apellidos'],
                    fmt_fecha($c['fecha']), fmt_hora($c['hora']), $c['med_nombre'], $enlace)) {
                $mail++;
            }
        }
        $marca->execute([$c['id'], $cid]);  // marca como procesada (no reintentar)
    }
    if ($proc) { auditar('recordatorios', null, $cid, "$proc procesadas, $mail por correo", $cid); }
    $totProc += $proc; $totMail += $mail;
    echo "consultorio $cid: $proc citas, $mail correos\n";
}

echo "Total: $totProc citas procesadas, $totMail correos enviados (mañana = $manana)\n";
