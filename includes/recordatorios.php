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
require_once __DIR__ . '/whatsapp.php';

/**
 * Corre los recordatorios de un día.
 *
 * @param string|null $fecha   Y-m-d; null = mañana.
 * @param callable|null $echo  Recibe cada línea de progreso (para el CLI). Opcional.
 * @return array{fecha:string,procesadas:int,correos:int,whatsapp:int,detalle:array}
 */
function recordatorios_enviar(?string $fecha = null, ?callable $echo = null): array
{
    $fecha = $fecha ?: date('Y-m-d', strtotime('+1 day'));
    $log   = $echo ?: function () {};

    // El loop fuerza el tenant vía $_SESSION['usuario']; respaldamos y
    // restauramos para no ensuciar la sesión de quien dispara el envío (p. ej.
    // un admin de plataforma en el navegador).
    $sesionPrevia = $_SESSION['usuario'] ?? null;

    $totProc = 0; $totMail = 0; $totWa = 0; $detalle = [];

    $consultorios = db()->query('SELECT id FROM consultorios')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($consultorios as $cid) {
        $cid = (int) $cid;
        $_SESSION['usuario'] = ['consultorio_id' => $cid];
        cfg_all(true);

        if (cfg('recordatorio_auto', '1') !== '1') continue;

        $st = db()->prepare(
            "SELECT c.id, c.fecha, c.hora, p.nombre, p.apellidos, p.email, p.telefono, u.nombre AS med_nombre
             FROM citas c
             JOIN pacientes p ON p.id = c.paciente_id
             JOIN usuarios  u ON u.id = c.medico_id
             WHERE c.consultorio_id = ? AND c.fecha = ?
               AND c.estado IN ('programada','confirmada') AND c.recordatorio_en IS NULL"
        );
        $st->execute([$cid, $fecha]);
        $citas = $st->fetchAll();

        // Vacunas que tocan en los próximos 3 días (corre aunque no haya citas).
        require_once __DIR__ . '/vacunas.php';
        $vac = vacunas_recordatorios($cid);
        if ($vac) { $log("consultorio $cid: $vac recordatorios de vacuna"); $totVac = ($totVac ?? 0) + $vac; }

        if (!$citas) continue;

        $marca = db()->prepare('UPDATE citas SET recordatorio_en = NOW() WHERE id = ? AND consultorio_id = ?');
        $proc = $mail = $wa = 0;
        // Se resuelve una vez por consultorio: depende de cfg(), que ya está
        // cargada para este tenant, y no cambia entre citas.
        $porWhats = wa_auto_activo();
        foreach ($citas as $c) {
            $proc++;
            if (filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $enlace = cita_enlace((int) $c['id']);
                if (correo_recordatorio_cita($c['email'], $c['nombre'] . ' ' . $c['apellidos'],
                        fmt_fecha($c['fecha']), fmt_hora($c['hora']), $c['med_nombre'], $enlace,
                        cita_folio((int) $c['id']))) {
                    $mail++;
                }
            }
            if ($porWhats && !empty($c['telefono'])) {
                $r = wa_recordatorio_cita($c['telefono'], $c['nombre'] . ' ' . $c['apellidos'],
                        fmt_fecha($c['fecha']), fmt_hora($c['hora']), $c['med_nombre']);
                if ($r['ok']) { $wa++; }
            }
            $marca->execute([$c['id'], $cid]);
        }
        if ($proc) { auditar('recordatorios', null, $cid, "$proc procesadas, $mail por correo, $wa por WhatsApp", $cid); }
        $totProc += $proc; $totMail += $mail; $totWa += $wa;
        $detalle[$cid] = ['procesadas' => $proc, 'correos' => $mail, 'whatsapp' => $wa];
        $log("consultorio $cid: $proc citas, $mail correos, $wa WhatsApp");
    }

    // Restaura la sesión de quien disparó el envío.
    if ($sesionPrevia === null) unset($_SESSION['usuario']);
    else                        $_SESSION['usuario'] = $sesionPrevia;
    cfg_all(true);

    return ['fecha' => $fecha, 'procesadas' => $totProc, 'correos' => $totMail,
            'whatsapp' => $totWa, 'vacunas' => $totVac ?? 0, 'detalle' => $detalle];
}

/**
 * Segundo recordatorio: citas de HOY que empiezan dentro de 1 a 3 horas y que
 * aún no lo recibieron. Pensado para un cron que corre cada hora; cada cita
 * se marca (recordatorio2_en) para no repetir. Solo consultorios con el
 * interruptor "2 horas antes" encendido.
 */
function recordatorios_2h(?callable $echo = null): array
{
    $log = $echo ?: function () {};
    if (!db()->query("SHOW COLUMNS FROM citas LIKE 'recordatorio2_en'")->fetch()) {
        db()->exec('ALTER TABLE citas ADD recordatorio2_en DATETIME NULL');
    }
    $sesionPrevia = $_SESSION['usuario'] ?? null;
    $desde = date('H:i:s', strtotime('+60 minutes'));
    $hasta = date('H:i:s', strtotime('+180 minutes'));
    $tot = ['procesadas' => 0, 'correos' => 0, 'whatsapp' => 0];
    if ($desde > $hasta) return $tot;   // cerca de medianoche: no hay ventana del mismo día

    foreach (db()->query('SELECT id FROM consultorios')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        $_SESSION['usuario'] = ['consultorio_id' => $cid];
        cfg_all(true);
        if (cfg('recordatorio_2h', '0') !== '1') continue;

        $st = db()->prepare(
            "SELECT c.id, c.fecha, c.hora, p.nombre, p.apellidos, p.email, p.telefono, u.nombre AS med_nombre
             FROM citas c
             JOIN pacientes p ON p.id = c.paciente_id
             JOIN usuarios  u ON u.id = c.medico_id
             WHERE c.consultorio_id = ? AND c.fecha = ? AND c.hora BETWEEN ? AND ?
               AND c.estado IN ('programada','confirmada') AND c.recordatorio2_en IS NULL"
        );
        $st->execute([$cid, date('Y-m-d'), $desde, $hasta]);
        $citas = $st->fetchAll();
        if (!$citas) continue;

        $marca = db()->prepare('UPDATE citas SET recordatorio2_en = NOW() WHERE id = ? AND consultorio_id = ?');
        $porWhats = wa_auto_activo();
        $proc = $mail = $wa = 0;
        foreach ($citas as $c) {
            $proc++;
            $nombre = $c['nombre'] . ' ' . $c['apellidos'];
            if (filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL)
                && correo_recordatorio_cita($c['email'], $nombre, t('hoy') . ', ' . fmt_fecha($c['fecha']), fmt_hora($c['hora']),
                        $c['med_nombre'], cita_enlace((int) $c['id']), cita_folio((int) $c['id']))) {
                $mail++;
            }
            if ($porWhats && !empty($c['telefono'])) {
                $r = wa_recordatorio_cita($c['telefono'], $nombre, t('hoy'), fmt_hora($c['hora']), $c['med_nombre']);
                if ($r['ok']) $wa++;
            }
            $marca->execute([$c['id'], $cid]);
        }
        auditar('recordatorios_2h', null, $cid, "$proc procesadas, $mail por correo, $wa por WhatsApp", $cid);
        $log("consultorio $cid (2 h antes): $proc citas, $mail correos, $wa WhatsApp");
        $tot['procesadas'] += $proc; $tot['correos'] += $mail; $tot['whatsapp'] += $wa;
    }
    if ($sesionPrevia === null) unset($_SESSION['usuario']);
    else                        $_SESSION['usuario'] = $sesionPrevia;
    cfg_all(true);
    return $tot;
}
