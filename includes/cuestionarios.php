<?php
/**
 * Cuestionarios previos a la consulta.
 *
 * El consultorio arma sus cuestionarios (historia clínica de primera vez,
 * seguimiento, lo que quiera) y el paciente los llena ANTES de llegar, desde
 * el mismo enlace con token de su cita (sin cuenta ni contraseña, igual que
 * confirmar). El médico ve las respuestas en la ficha y, con un clic, pasa a
 * su expediente las que van a un campo (alergias, antecedentes…). Lo que
 * escribe el paciente NUNCA se copia solo: el médico lo valida primero.
 *
 * A qué cita le toca cuál: el primer cuestionario activo que coincida, primero
 * los del médico de la cita y luego los de todos; "primera vez" solo aplica a
 * pacientes sin consultas registradas.
 *
 * Plan: Profesional y Clínica (va con la agenda en línea: módulo agenda_online).
 */
require_once __DIR__ . '/functions.php';

function cuest_tipos(): array
{
    return [
        'texto'    => 'Respuesta corta',
        'parrafo'  => 'Párrafo',
        'sino'     => 'Sí / No',
        'opcion'   => 'Una opción',
        'multiple' => 'Varias opciones',
        'numero'   => 'Número',
        'escala'   => 'Escala del 0 al 10',
        'fecha'    => 'Fecha',
    ];
}

/** Campos del expediente a los que puede ir una respuesta. 'motivo' es el de la cita. */
function cuest_campos(): array
{
    return [
        ''                        => '— No se copia —',
        'motivo'                  => 'Motivo de la cita',
        'alergias'                => 'Alergias',
        'enf_cronicas'            => 'Enfermedades crónicas',
        'antecedentes'            => 'Antecedentes personales',
        'antecedentes_familiares' => 'Antecedentes familiares',
        'cirugias'                => 'Cirugías',
        'habitos'                 => 'Hábitos',
        'vacunas'                 => 'Vacunas',
    ];
}

function cuest_disponible(): bool
{
    return modulo_activo('agenda_online');
}

function asegurar_cuestionarios(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuestionarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        descripcion VARCHAR(500) NULL,
        preguntas MEDIUMTEXT NOT NULL,
        aplica VARCHAR(12) NOT NULL DEFAULT 'todas',
        medico_id INT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en DATETIME NULL,
        KEY idx_c (consultorio_id, activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cuestionario_respuestas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        cuestionario_id INT NOT NULL,
        cita_id INT NOT NULL,
        paciente_id INT NOT NULL,
        nombre VARCHAR(150) NOT NULL,
        respuestas MEDIUMTEXT NOT NULL,
        respondido_en DATETIME NOT NULL,
        revisado_en DATETIME NULL,
        revisado_por INT NULL,
        copiado_en DATETIME NULL,
        UNIQUE KEY uq_cita (cita_id),
        KEY idx_pac (consultorio_id, paciente_id),
        KEY idx_rev (consultorio_id, revisado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Normaliza las preguntas que llegan del constructor (JSON) y descarta basura. */
function cuest_limpiar_preguntas($raw): array
{
    $arr = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($arr)) return [];
    $tipos = cuest_tipos(); $campos = cuest_campos();
    $out = []; $ids = [];
    foreach ($arr as $q) {
        if (!is_array($q)) continue;
        $texto = trim(mb_substr((string) ($q['texto'] ?? ''), 0, 300));
        if ($texto === '') continue;
        $tipo = isset($tipos[$q['tipo'] ?? '']) ? $q['tipo'] : 'texto';
        $ops = [];
        if (in_array($tipo, ['opcion', 'multiple'], true)) {
            foreach ((array) ($q['opciones'] ?? []) as $o) { $o = trim(mb_substr((string) $o, 0, 120)); if ($o !== '' && !in_array($o, $ops, true)) $ops[] = $o; }
            if (!$ops) $tipo = 'texto';
        }
        $id = preg_replace('/[^a-z0-9]/', '', (string) ($q['id'] ?? '')) ?: substr(bin2hex(random_bytes(4)), 0, 8);
        while (isset($ids[$id])) $id .= 'x';
        $ids[$id] = true;
        $out[] = ['id' => $id, 'texto' => $texto, 'tipo' => $tipo, 'opciones' => $ops,
                  'ayuda' => trim(mb_substr((string) ($q['ayuda'] ?? ''), 0, 200)),
                  'requerida' => !empty($q['requerida']),
                  'campo' => isset($campos[$q['campo'] ?? '']) ? (string) $q['campo'] : ''];
        if (count($out) >= 60) break;
    }
    return $out;
}

function cuest_obtener(int $id): ?array
{
    asegurar_cuestionarios();
    $st = db()->prepare('SELECT * FROM cuestionarios WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $c = $st->fetch();
    if (!$c) return null;
    $c['preguntas'] = json_decode((string) $c['preguntas'], true) ?: [];
    return $c;
}

/** ¿El paciente viene por primera vez? (sin consultas registradas) */
function cuest_primera_vez(int $pacienteId): bool
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM consultas WHERE paciente_id = ? AND consultorio_id = ?');
        $st->execute([$pacienteId, tenant_id()]);
        return (int) $st->fetchColumn() === 0;
    } catch (Throwable $e) { return false; }
}

/** El cuestionario que le toca a una cita (null si ninguno). */
function cuest_para_cita(array $cita): ?array
{
    if (!cuest_disponible()) return null;
    asegurar_cuestionarios();
    $st = db()->prepare('SELECT * FROM cuestionarios WHERE consultorio_id = ? AND activo = 1 AND (medico_id IS NULL OR medico_id = ?)
                         ORDER BY medico_id IS NULL, id');
    $st->execute([tenant_id(), (int) ($cita['medico_id'] ?? 0)]);
    $primera = null;
    foreach ($st->fetchAll() as $c) {
        if ($c['aplica'] === 'primera') {
            if ($primera === null) $primera = cuest_primera_vez((int) $cita['paciente_id']);
            if (!$primera) continue;
        } elseif ($c['aplica'] === 'subsecuente') {
            if ($primera === null) $primera = cuest_primera_vez((int) $cita['paciente_id']);
            if ($primera) continue;
        }
        $c['preguntas'] = json_decode((string) $c['preguntas'], true) ?: [];
        if ($c['preguntas']) return $c;
    }
    return null;
}

function cuest_respuesta_de_cita(int $citaId): ?array
{
    asegurar_cuestionarios();
    $st = db()->prepare('SELECT * FROM cuestionario_respuestas WHERE cita_id = ? AND consultorio_id = ?');
    $st->execute([$citaId, tenant_id()]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['respuestas'] = json_decode((string) $r['respuestas'], true) ?: [];
    return $r;
}

/** ¿Tiene sentido pedirle el cuestionario? (cita futura y viva, y aún sin responder) */
function cuest_pendiente(array $cita): ?array
{
    if (!in_array($cita['estado'] ?? '', ['programada', 'confirmada'], true)) return null;
    if (strtotime($cita['fecha'] . ' ' . $cita['hora']) < time()) return null;
    if (cuest_respuesta_de_cita((int) $cita['id'])) return null;
    return cuest_para_cita($cita);
}

function cuest_enlace(string $token): string
{
    return url_absoluta('/agenda/cuestionario?t=' . $token);
}

/** Tarjeta para las páginas públicas de la cita (agendada / confirmar). */
function cuest_cta(array $cita): string
{
    if (empty($cita['token'])) return '';
    $q = cuest_pendiente($cita);
    if (!$q) {
        $r = in_array($cita['estado'] ?? '', ['programada', 'confirmada'], true) ? cuest_respuesta_de_cita((int) $cita['id']) : null;
        return $r ? '<div class="alert alert-success small text-center py-2 mb-3"><i class="bi bi-clipboard-check"></i> '
            . et('Ya respondiste tu cuestionario. ¡Gracias!') . ' <a href="' . e(cuest_enlace($cita['token'])) . '">' . et('Revisar') . '</a></div>' : '';
    }
    $n = count($q['preguntas']);
    return '<div class="border rounded-3 p-3 mb-3 text-center" style="background:color-mix(in srgb, var(--brand) 7%, transparent)">'
        . '<div class="fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> ' . et('Ahorra tiempo en tu consulta') . '</div>'
        . '<div class="small text-muted mb-2">' . sprintf(et('Responde %d preguntas antes de llegar (unos %d minutos).'), $n, max(1, (int) ceil($n / 4))) . '</div>'
        . '<a href="' . e(cuest_enlace($cita['token'])) . '" class="btn btn-primary w-100 fw-semibold"><i class="bi bi-pencil-square"></i> ' . et('Llenar mi cuestionario') . '</a></div>';
}

/** Bloque para los correos de la cita, a partir del enlace con token. '' si no aplica. */
function cuest_bloque_correo(?string $enlace): string
{
    if (!$enlace || !preg_match('/[?&]t=([a-f0-9]{32})/', $enlace, $m)) return '';
    try {
        $st = db()->prepare('SELECT * FROM citas WHERE token = ? AND consultorio_id = ?');
        $st->execute([$m[1], tenant_id()]);
        $c = $st->fetch();
        if (!$c || !cuest_pendiente($c)) return '';
    } catch (Throwable $e) { return ''; }
    return '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" '
        . 'style="background:#f7f9fc;border:1px solid #e9eef5;border-radius:14px;margin:18px 0 4px">'
        . '<tr><td style="padding:18px 22px;text-align:center">'
        . '<div style="font-size:15px;font-weight:700;color:#1f2d3d;margin-bottom:4px">Ahorra tiempo en tu consulta</div>'
        . '<div style="font-size:14px;color:#5a6a80;line-height:1.55;margin-bottom:14px">Responde unas preguntas antes de llegar: tu médico las revisa antes de verte.</div>'
        . correo_boton('Llenar mi cuestionario', cuest_enlace($m[1]), color_acento())
        . '</td></tr></table>';
}

/** Valor legible de una respuesta. */
function cuest_valor_txt($v): string
{
    if (is_array($v)) return implode(', ', array_map('strval', $v));
    return trim((string) $v);
}

/**
 * Pasa al expediente las respuestas que tienen campo destino: se AGREGAN a lo
 * que ya hay (no se borra nada) con la fecha del cuestionario. Devuelve cuántos
 * campos se tocaron.
 */
function cuest_copiar_al_expediente(array $r): int
{
    $campos = cuest_campos();
    $porCampo = [];
    foreach ($r['respuestas'] as $a) {
        $campo = (string) ($a['campo'] ?? '');
        $val = cuest_valor_txt($a['valor'] ?? '');
        if ($campo === '' || !isset($campos[$campo]) || $val === '' || in_array(mb_strtolower($val), ['no', 'ninguna', 'ninguno', 'n/a'], true)) continue;
        $porCampo[$campo][] = [(string) ($a['texto'] ?? ''), $val];
    }
    if (!$porCampo) return 0;
    $tid = tenant_id();
    $fecha = date('d/m/Y', strtotime($r['respondido_en']));
    $n = 0;
    foreach ($porCampo as $campo => $pares) {
        // Una sola respuesta: solo el valor. Varias al mismo campo: "pregunta: valor" para no confundirlas.
        $txt = count($pares) === 1 ? $pares[0][1]
             : implode("\n", array_map(fn ($p) => ($p[0] !== '' ? $p[0] . ': ' : '') . $p[1], $pares));
        if ($campo === 'motivo') {
            $st = db()->prepare('SELECT motivo FROM citas WHERE id = ? AND consultorio_id = ?');
            $st->execute([(int) $r['cita_id'], $tid]);
            $act = trim((string) $st->fetchColumn());
            db()->prepare('UPDATE citas SET motivo = ? WHERE id = ? AND consultorio_id = ?')
                ->execute([mb_substr(($act !== '' ? $act . "\n" : '') . $txt, 0, 2000), (int) $r['cita_id'], $tid]);
        } else {
            $st = db()->prepare("SELECT `$campo` FROM pacientes WHERE id = ? AND consultorio_id = ?");
            $st->execute([(int) $r['paciente_id'], $tid]);
            $act = trim((string) $st->fetchColumn());
            $nuevo = ($act !== '' ? $act . "\n" : '') . "[Cuestionario $fecha] " . $txt;
            db()->prepare("UPDATE pacientes SET `$campo` = ? WHERE id = ? AND consultorio_id = ?")
                ->execute([$nuevo, (int) $r['paciente_id'], $tid]);
        }
        $n++;
    }
    db()->prepare('UPDATE cuestionario_respuestas SET copiado_en = NOW(), revisado_en = COALESCE(revisado_en, NOW()), revisado_por = COALESCE(revisado_por, ?) WHERE id = ? AND consultorio_id = ?')
        ->execute([(int) (current_user()['id'] ?? 0) ?: null, (int) $r['id'], $tid]);
    return $n;
}

/** Respuestas sin revisar de un paciente (para la ficha). */
function cuest_pendientes_paciente(int $pacienteId): array
{
    if (!cuest_disponible()) return [];
    asegurar_cuestionarios();
    $st = db()->prepare('SELECT r.id, r.nombre, r.respondido_en, r.revisado_en, c.fecha, c.hora FROM cuestionario_respuestas r
                         JOIN citas c ON c.id = r.cita_id
                         WHERE r.consultorio_id = ? AND r.paciente_id = ? ORDER BY r.respondido_en DESC LIMIT 5');
    $st->execute([tenant_id(), $pacienteId]);
    return $st->fetchAll();
}

/** Plantillas para empezar rápido. */
function cuest_plantillas(): array
{
    $q = function (string $texto, string $tipo, array $extra = []) { return ['texto' => $texto, 'tipo' => $tipo] + $extra; };
    return [
        'primera' => [
            'nombre' => 'Historia clínica — primera vez',
            'descripcion' => 'Para pacientes nuevos. Tu médico la revisa antes de la consulta.',
            'aplica' => 'primera',
            'preguntas' => [
                $q('¿Cuál es el motivo de tu consulta?', 'parrafo', ['requerida' => true, 'campo' => 'motivo']),
                $q('¿Desde cuándo tienes estas molestias?', 'texto'),
                $q('¿Eres alérgico(a) a algún medicamento o alimento? ¿A cuál?', 'parrafo', ['requerida' => true, 'campo' => 'alergias', 'ayuda' => 'Si no tienes alergias, escribe "Ninguna".']),
                $q('¿Tienes alguna de estas enfermedades?', 'multiple', ['opciones' => ['Diabetes', 'Hipertensión', 'Asma', 'Enfermedad de tiroides', 'Enfermedad del corazón', 'Ninguna'], 'campo' => 'enf_cronicas']),
                $q('¿Qué medicamentos tomas actualmente? (nombre y dosis)', 'parrafo', ['campo' => 'antecedentes']),
                $q('¿Te han operado? ¿De qué y cuándo?', 'parrafo', ['campo' => 'cirugias']),
                $q('¿Algún familiar directo tiene o tuvo estas enfermedades?', 'multiple', ['opciones' => ['Diabetes', 'Hipertensión', 'Cáncer', 'Enfermedad del corazón', 'Ninguna'], 'campo' => 'antecedentes_familiares']),
                $q('¿Fumas?', 'opcion', ['opciones' => ['No', 'Sí', 'Lo dejé'], 'campo' => 'habitos']),
                $q('¿Consumes bebidas alcohólicas?', 'opcion', ['opciones' => ['No', 'Ocasionalmente', 'Frecuentemente'], 'campo' => 'habitos']),
                $q('¿Hay algo más que quieras que tu médico sepa?', 'parrafo'),
            ],
        ],
        'seguimiento' => [
            'nombre' => 'Antes de tu consulta de seguimiento',
            'descripcion' => 'Para pacientes que ya te conocen: cómo han estado desde la última vez.',
            'aplica' => 'subsecuente',
            'preguntas' => [
                $q('¿Cómo te has sentido desde tu última consulta?', 'parrafo', ['requerida' => true, 'campo' => 'motivo']),
                $q('¿Has tomado tu tratamiento como se indicó?', 'opcion', ['opciones' => ['Sí, completo', 'A veces lo olvido', 'Lo suspendí']]),
                $q('¿Tienes síntomas nuevos? ¿Cuáles?', 'parrafo'),
                $q('Del 0 al 10, ¿cuánta molestia o dolor tienes hoy?', 'escala'),
                $q('¿Empezaste a tomar algún medicamento nuevo?', 'parrafo', ['campo' => 'antecedentes']),
            ],
        ],
    ];
}
