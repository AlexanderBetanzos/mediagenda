<?php
/**
 * Núcleo de los recordatorios de cita por correo. Lo comparten:
 *   - cron/recordatorios.php          (ejecución diaria por cron)
 *   - platform/ajustes.php            (botón "Enviar recordatorios ahora")
 *
 * Envía un recordatorio a los pacientes con cita en $fecha (por omisión mañana)
 * que no estén canceladas y que aún no hayan recibido el suyo. Idempotente:
 * marca cada cita al procesarla, así "enviar ahora" no duplica lo que ya salió.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/correo.php';

/**
 * Corre los recordatorios de un día.
 *
 * @param string|null $fecha   Y-m-d; null = mañana.
 * @param callable|null $echo  Recibe cada línea de progreso (para el CLI). Opcional.
 * @return array{fecha:string,procesadas:int,correos:int,detalle:array}
 */
function recordatorios_enviar(?string $fecha = null, ?callable $echo = null): array
{
    $fecha = $fecha ?: date('Y-m-d', strtotime('+1 day'));
    $log   = $echo ?: function () {};

    // El loop fuerza el tenant vía $_SESSION['usuario']; respaldamos y
    // restauramos para no ensuciar la sesión de quien dispara el envío (p. ej.
    // un admin de plataforma en el navegador).
    $sesionPrevia = $_SESSION['usuario'] ?? null;

    $totProc = 0; $totMail = 0; $detalle = [];

    $consultorios = db()->query('SELECT id FROM consultorios')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($consultorios as $cid) {
        $cid = (int) $cid;
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
        $st->execute([$cid, $fecha]);
        $citas = $st->fetchAll();
        if (!$citas) continue;

        $marca = db()->prepare('UPDATE citas SET recordatorio_en = NOW() WHERE id = ? AND consultorio_id = ?');
        $proc = $mail = 0;
        foreach ($citas as $c) {
            $proc++;
            if (filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $enlace = cita_enlace((int) $c['id']);
                if (correo_recordatorio_cita($c['email'], $c['nombre'] . ' ' . $c['apellidos'],
                        fmt_fecha($c['fecha']), fmt_hora($c['hora']), $c['med_nombre'], $enlace)) {
                    $mail++;
                }
            }
            $marca->execute([$c['id'], $cid]);
        }
        if ($proc) { auditar('recordatorios', null, $cid, "$proc procesadas, $mail por correo", $cid); }
        $totProc += $proc; $totMail += $mail;
        $detalle[$cid] = ['procesadas' => $proc, 'correos' => $mail];
        $log("consultorio $cid: $proc citas, $mail correos");
    }

    // Restaura la sesión de quien disparó el envío.
    if ($sesionPrevia === null) unset($_SESSION['usuario']);
    else                        $_SESSION['usuario'] = $sesionPrevia;
    cfg_all(true);

    return ['fecha' => $fecha, 'procesadas' => $totProc, 'correos' => $totMail, 'detalle' => $detalle];
}
