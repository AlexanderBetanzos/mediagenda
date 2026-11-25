<?php
/**
 * Encuestas de satisfacción (sobre la tabla resenas):
 *
 *  - Se ENVÍAN SOLAS: X horas después de una cita atendida (cron horario), por
 *    correo y, con WhatsApp incluido, por WhatsApp (gasta 1 mensaje).
 *  - Además de las estrellas: 4 aspectos (puntualidad, trato, explicación,
 *    instalaciones) y la pregunta de recomendación 0–10 (NPS).
 *  - ALERTAS: una calificación de 3 estrellas o menos, o NPS de 6 o menos,
 *    abre una alerta para que el consultorio contacte al paciente antes de
 *    que lo cuente en otro lado. Se avisa por correo al consultorio.
 *  - A quien califica 4–5 se le invita a dejar su reseña también en Google.
 *
 * Ajustes del consultorio (cfg): encuesta_auto ('1'), encuesta_horas ('2'),
 * google_resenas_url ('').
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/whatsapp.php';

function encuesta_aspectos(): array
{
    return ['puntualidad' => 'Puntualidad', 'trato' => 'Trato del personal', 'explicacion' => 'Claridad del médico al explicarte', 'instalaciones' => 'Instalaciones y limpieza'];
}

function asegurar_encuestas(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM resenas')->fetchAll(), 'Field');
        $add = [];
        foreach (['puntualidad' => 'TINYINT NULL', 'trato' => 'TINYINT NULL', 'explicacion' => 'TINYINT NULL', 'instalaciones' => 'TINYINT NULL',
                  'nps' => 'TINYINT NULL', 'enviada_en' => 'DATETIME NULL', 'enviada_por' => 'VARCHAR(30) NULL',
                  'alerta_cerrada_en' => 'DATETIME NULL', 'alerta_nota' => 'VARCHAR(300) NULL'] as $c => $d) {
            if (!in_array($c, $cols, true)) $add[] = "ADD COLUMN $c $d";
        }
        if ($add) db()->exec('ALTER TABLE resenas ' . implode(', ', $add));
    } catch (Throwable $e) {}
}

/** ¿La respuesta merece alerta? */
function encuesta_es_alerta(array $r): bool
{
    return $r['estrellas'] !== null && ((int) $r['estrellas'] <= 3 || ($r['nps'] !== null && (int) $r['nps'] <= 6));
}

/**
 * Envía las encuestas pendientes de TODOS los consultorios (cron horario).
 * Ventana: citas atendidas entre hace 3 días y hace N horas.
 */
function encuestas_enviar(?callable $echo = null): array
{
    $log = $echo ?: function () {};
    asegurar_encuestas();
    $sesionPrevia = $_SESSION['usuario'] ?? null;
    $tot = ['enviadas' => 0, 'correos' => 0, 'whatsapp' => 0];
    foreach (db()->query('SELECT id FROM consultorios')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int) $cid;
        $_SESSION['usuario'] = ['consultorio_id' => $cid];
        cfg_all(true);
        if (cfg('encuesta_auto', '1') !== '1') continue;
        $horas = max(1, min(48, (int) cfg('encuesta_horas', '2')));
        $limite = date('Y-m-d H:i:s', time() - $horas * 3600);
        $st = db()->prepare(
            "SELECT c.id, p.nombre, p.apellidos, p.email, p.telefono, u.nombre med_nombre, r.id rid, r.enviada_en
             FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id
             LEFT JOIN resenas r ON r.cita_id = c.id
             WHERE c.consultorio_id = ? AND c.estado = 'atendida'
               AND TIMESTAMP(c.fecha, c.hora) <= ? AND c.fecha >= ?
               AND (r.id IS NULL OR (r.enviada_en IS NULL AND r.estrellas IS NULL))
             LIMIT 200");
        $st->execute([$cid, $limite, date('Y-m-d', strtotime('-3 days'))]);
        $n = 0;
        foreach ($st->fetchAll() as $c) {
            $tok = resena_invitar((int) $c['id']);
            if ($tok === '') continue;
            $enlace = resena_enlace($tok);
            $canales = [];
            if (filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL) && correo_encuesta($c['email'], $c['nombre'], (string) $c['med_nombre'], $enlace)) {
                $canales[] = 'correo'; $tot['correos']++;
            }
            if (!empty($c['telefono']) && wa_modo() === 'plataforma' && wa_plat_plantilla_encuesta() !== '' && modulo_activo('whatsapp')) {
                $r = wa_enviar_plataforma($c['telefono'], [$c['nombre'], marca_nombre(), $enlace], 'Encuesta a ' . $c['nombre'], wa_plat_plantilla_encuesta());
                if ($r['ok']) { $canales[] = 'whatsapp'; $tot['whatsapp']++; }
            }
            // Se marca aunque no haya canal: así aparece en "Pedir opinión" para mandarla a mano.
            db()->prepare('UPDATE resenas SET enviada_en = NOW(), enviada_por = ? WHERE token = ? AND enviada_en IS NULL')
                ->execute([$canales ? implode(',', $canales) : 'manual', $tok]);
            $n++;
        }
        if ($n) { $log("consultorio $cid: $n encuestas"); $tot['enviadas'] += $n; }
    }
    if ($sesionPrevia === null) unset($_SESSION['usuario']); else $_SESSION['usuario'] = $sesionPrevia;
    return $tot;
}

function correo_encuesta(string $email, string $nombre, string $medico, string $enlace): bool
{
    $cuerpo = 'Hola <strong>' . e($nombre) . '</strong>,<br><br>Gracias por visitarnos en <strong>' . e(marca_nombre()) . '</strong>'
        . ($medico !== '' ? ' con ' . e($medico) : '') . '. ¿Nos regalas un minuto para contarnos cómo te fue?<br><br>'
        . 'Son 3 preguntas rápidas; tu opinión nos ayuda a atenderte mejor.';
    $html = correo_layout('¿Cómo te fue en tu consulta?', $cuerpo, 'Calificar mi consulta', $enlace);
    return enviar_correo($email, '¿Cómo te fue en tu consulta en ' . marca_nombre() . '?', $html);
}

/** Avisa al consultorio de una respuesta con alerta. */
function encuesta_avisar_alerta(array $r, string $paciente): void
{
    $dest = cfg('email');
    if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) return;
    $cuerpo = 'Un paciente calificó su consulta con <strong>' . (int) $r['estrellas'] . ' de 5 estrellas</strong>'
        . ($r['nps'] !== null ? ' y <strong>' . (int) $r['nps'] . ' de 10</strong> en recomendación' : '') . '.<br><br>'
        . '<strong>' . e($paciente) . '</strong>' . (!empty($r['comentario']) ? '<br><em>“' . e($r['comentario']) . '”</em>' : '')
        . '<br><br>Contactarlo hoy suele convertir una mala experiencia en un paciente fiel.';
    @enviar_correo($dest, 'Alerta de satisfacción · ' . $paciente, correo_layout('Un paciente no quedó satisfecho', $cuerpo, 'Ver encuesta', url_absoluta('/resenas/index#alertas')));
}

/** Métricas del consultorio en un periodo. */
function encuestas_metricas(int $dias = 90): array
{
    asegurar_encuestas();
    $tid = (int) tenant_id();
    $desde = date('Y-m-d H:i:s', strtotime("-$dias days"));
    $st = db()->prepare("SELECT COUNT(*) enviadas, SUM(estrellas IS NOT NULL) respondidas, AVG(estrellas) prom,
            AVG(puntualidad) puntualidad, AVG(trato) trato, AVG(explicacion) explicacion, AVG(instalaciones) instalaciones,
            SUM(nps >= 9) promotores, SUM(nps <= 6) detractores, SUM(nps IS NOT NULL) con_nps
        FROM resenas WHERE consultorio_id = ? AND COALESCE(invitada_en, creado_en) >= ?");
    $st->execute([$tid, $desde]);
    $m = $st->fetch();
    $m['nps_valor'] = $m['con_nps'] ? (int) round(100 * ($m['promotores'] - $m['detractores']) / $m['con_nps']) : null;
    $m['tasa'] = $m['enviadas'] ? (int) round(100 * $m['respondidas'] / $m['enviadas']) : null;
    $pm = db()->prepare("SELECT u.nombre, COUNT(r.estrellas) n, AVG(r.estrellas) prom, AVG(r.nps) nps
        FROM resenas r JOIN usuarios u ON u.id = r.medico_id
        WHERE r.consultorio_id = ? AND r.estrellas IS NOT NULL AND r.respondida_en >= ? GROUP BY u.id, u.nombre ORDER BY prom DESC");
    $pm->execute([$tid, $desde]);
    $m['medicos'] = $pm->fetchAll();
    return $m;
}
