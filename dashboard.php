<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$u        = current_user();
$esMedico = $u['rol'] === 'medico';
$esAdmin  = has_role('admin');
$pdo      = db();

/* Aislamiento por consultorio + filtro por médico (ve solo lo suyo). */
$tid       = (int) tenant_id();
$medFiltro = $esMedico ? ' AND medico_id = ' . (int) $u['id'] : '';

/* Ocultar "Primeros pasos" (antes de imprimir nada). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['ocultar_pasos', 'ocultar_migrar'], true) && $esAdmin) {
    verify_csrf();
    guardar_cfg([$_POST['action'] === 'ocultar_pasos' ? 'onboarding_oculto' : 'migrar_oculto' => '1']);
    redirect('/dashboard');
}

/* Módulos activos según el plan (gatea secciones). */
$verFacturacion = modulo_activo('facturacion');
$verRecetas     = modulo_activo('recetas');
$verCitas       = modulo_activo('citas');
$verReportes    = modulo_activo('reportes');   // gráficas y BI: plan Profesional+

$MESES = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

/* ── KPIs: citas ─────────────────────────────────────────────────────── */
$citasHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha = CURDATE() AND estado <> 'cancelada' $medFiltro"
)->fetchColumn();
$citasConfHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha = CURDATE() AND estado IN ('confirmada','atendida') $medFiltro"
)->fetchColumn();
$citasAtendHoy = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha = CURDATE() AND estado = 'atendida' $medFiltro"
)->fetchColumn();
$citasPorConfirmar = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha >= CURDATE() AND estado = 'programada' $medFiltro"
)->fetchColumn();
$citasPend = (int) $pdo->query(
    "SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid AND fecha >= CURDATE() AND estado IN ('programada','confirmada') $medFiltro"
)->fetchColumn();

/* ── KPIs: pacientes / consultas / recetas ───────────────────────────── */
$totPacientes = (int) $pdo->query("SELECT COUNT(*) FROM pacientes WHERE consultorio_id = $tid")->fetchColumn();
$pacientesMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM pacientes WHERE consultorio_id = $tid AND YEAR(creado_en)=YEAR(CURDATE()) AND MONTH(creado_en)=MONTH(CURDATE())"
)->fetchColumn();
$consultasMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM consultas WHERE consultorio_id = $tid AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
)->fetchColumn();
$recetasMes = (int) $pdo->query(
    "SELECT COUNT(*) FROM recetas WHERE consultorio_id = $tid AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
)->fetchColumn();

/* ── KPIs: finanzas (facturas pagadas) ───────────────────────────────── */
$ingresosMes = $ingresosHoy = $ticketProm = $pendienteCobrar = 0.0;
if ($verFacturacion) {
    $ingresosMes = (float) $pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = $tid AND estado='pagada'
         AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
    )->fetchColumn();
    $ingresosHoy = (float) $pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = $tid AND estado='pagada' AND fecha=CURDATE() $medFiltro"
    )->fetchColumn();
    $ticketProm = (float) $pdo->query(
        "SELECT COALESCE(AVG(total),0) FROM facturas WHERE consultorio_id = $tid AND estado='pagada'
         AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro"
    )->fetchColumn();
    $pendienteCobrar = (float) $pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = $tid AND estado='pendiente' $medFiltro"
    )->fetchColumn();
}

/* ── Gráficas (solo si el plan incluye Reportes y BI) ─────────────────
 * Todo lo que sigue alimenta únicamente a Chart.js. Sin el módulo no se
 * renderiza ni una gráfica, así que tampoco se pagan las agregaciones.
 */
$revLabels = $revData = $newData = $consLabels = $consData = [];
$estLabels = $estData = $horasLabels = $metodoLabels = $metodoData = [];
$horas = $porTipo = $tipoLabels = [];
$topMedicos = [];

if ($verReportes):

/* ── Gráfica: ingresos + pacientes nuevos (últimos 12 meses) ─────────── */
$firstOfMonth = date('Y-m-01');
$revByMonth = [];
if ($verFacturacion) {
    $st = $pdo->prepare(
        "SELECT DATE_FORMAT(fecha,'%Y-%m') ym, COALESCE(SUM(total),0) t FROM facturas
         WHERE consultorio_id = ? AND estado='pagada' AND fecha >= DATE_SUB(?, INTERVAL 11 MONTH) $medFiltro
         GROUP BY ym"
    );
    $st->execute([$tid, $firstOfMonth]);
    $revByMonth = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$newByMonth = $pdo->prepare(
    "SELECT DATE_FORMAT(creado_en,'%Y-%m') ym, COUNT(*) n FROM pacientes
     WHERE consultorio_id = ? AND creado_en >= DATE_SUB(?, INTERVAL 11 MONTH) GROUP BY ym"
);
$newByMonth->execute([$tid, $firstOfMonth]);
$newByMonth = $newByMonth->fetchAll(PDO::FETCH_KEY_PAIR);

$revLabels = $revData = $newData = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("$firstOfMonth -$i month"));
    $m  = (int) substr($ym, 5, 2);
    $revLabels[] = $MESES[$m] . ' ' . substr($ym, 0, 4);
    $revData[]   = round((float) ($revByMonth[$ym] ?? 0), 2);
    $newData[]   = (int) ($newByMonth[$ym] ?? 0);
}

/* ── Gráfica: consultas por día (últimos 14 días) ────────────────────── */
$consByDay = $pdo->prepare(
    "SELECT DATE(fecha) d, COUNT(*) n FROM consultas
     WHERE consultorio_id = ? AND fecha >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) $medFiltro GROUP BY d"
);
$consByDay->execute([$tid]);
$consByDay = $consByDay->fetchAll(PDO::FETCH_KEY_PAIR);
$consLabels = $consData = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $consLabels[] = (int) date('d', strtotime($d)) . '/' . $MESES[(int) date('m', strtotime($d))];
    $consData[]   = (int) ($consByDay[$d] ?? 0);
}

/* ── Gráfica: citas por estado (este mes) ────────────────────────────── */
$citasByEstado = $pdo->prepare(
    "SELECT estado, COUNT(*) n FROM citas
     WHERE consultorio_id = ? AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro GROUP BY estado"
);
$citasByEstado->execute([$tid]);
$citasByEstado = $citasByEstado->fetchAll(PDO::FETCH_KEY_PAIR);
$estLabels = $estData = [];
foreach (['programada', 'confirmada', 'atendida', 'cancelada', 'no_asistio'] as $es) {
    if (!empty($citasByEstado[$es])) { $estLabels[] = estado_label($es); $estData[] = (int) $citasByEstado[$es]; }
}

endif; /* fin del primer bloque de gráficas */

/* ── Tasa de inasistencia (no-show) de los últimos 90 días ───────────── */
/* Es un KPI, no una gráfica: se calcula para todos los planes. */
$ns = $pdo->query(
    "SELECT SUM(estado='no_asistio') ns, COUNT(*) tot FROM citas
     WHERE consultorio_id = $tid AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND fecha < CURDATE() $medFiltro"
)->fetch();
$noShow = ($ns && $ns['tot'] > 0) ? round(100 * $ns['ns'] / $ns['tot'], 1) : 0.0;

if ($verReportes):

/* ── Gráfica: horas pico (citas por hora) ────────────────────────────── */
$horas = array_fill_keys(range(7, 20), 0);
foreach ($pdo->query("SELECT HOUR(hora) h, COUNT(*) c FROM citas WHERE consultorio_id = $tid $medFiltro GROUP BY h") as $r) {
    if (isset($horas[(int) $r['h']])) $horas[(int) $r['h']] = (int) $r['c'];
}
$horasLabels = array_map(fn($h) => sprintf('%02d:00', $h), array_keys($horas));

/* ── Gráfica: pacientes por tipo ─────────────────────────────────────── */
$porTipo = ['medico' => 0, 'dental' => 0];
$tipoLabels = [tipo_paciente_label('medico'), tipo_paciente_label('dental')];
if (es_veterinaria()) {
    /* Veterinaria: mascotas por especie (no tiene sentido médico/dental). */
    asegurar_campos_veterinaria();
    $porTipo = [];
    foreach ($pdo->query("SELECT COALESCE(NULLIF(especie,''),'Sin especie') e, COUNT(*) c FROM pacientes
                          WHERE consultorio_id = $tid GROUP BY e ORDER BY c DESC") as $r) {
        $porTipo[$r['e']] = (int) $r['c'];
    }
    $tipoLabels = array_map('t', array_keys($porTipo));
} else
foreach ($pdo->query("SELECT tipo, COUNT(*) c FROM pacientes WHERE consultorio_id = $tid GROUP BY tipo") as $r) {
    if (isset($porTipo[$r['tipo']])) $porTipo[$r['tipo']] = (int) $r['c'];
}

/* ── Médicos con más citas (solo admin: el médico ya ve solo lo suyo) ── */
$topMedicos = [];
if ($esAdmin) {
    $topMedicos = $pdo->query(
        "SELECT u.nombre, COUNT(*) c FROM citas ci JOIN usuarios u ON u.id = ci.medico_id
         WHERE ci.consultorio_id = $tid
         GROUP BY ci.medico_id ORDER BY c DESC LIMIT 5"
    )->fetchAll();
}

/* ── Gráfica: ingresos del mes por método de pago ────────────────────── */
$metodoLabels = $metodoData = [];
if ($verFacturacion) {
    $pm = $pdo->prepare(
        "SELECT COALESCE(NULLIF(metodo_pago,''),'Otro') m, COALESCE(SUM(total),0) t FROM facturas
         WHERE consultorio_id = ? AND estado='pagada' AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) $medFiltro
         GROUP BY m"
    );
    $pm->execute([$tid]);
    foreach ($pm->fetchAll(PDO::FETCH_KEY_PAIR) as $m => $t) { $metodoLabels[] = ucfirst($m); $metodoData[] = round((float) $t, 2); }
}

endif; /* fin de las gráficas */

/* ── Agenda de hoy ───────────────────────────────────────────────────── */
$ag = $pdo->prepare(
    "SELECT c.*, p.id AS pac_id, p.nombre, p.apellidos, COALESCE(p.foto_mime, p.foto) AS foto FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     WHERE c.consultorio_id = ? AND c.fecha = CURDATE() AND c.estado <> 'cancelada' $medFiltro
     ORDER BY c.hora LIMIT 8"
);
$ag->execute([$tid]);
$agendaHoy = $ag->fetchAll();

/* ── Mini gráficas (sparklines) de los KPI: últimos 14 días ──────────────
   Decoran el número con su tendencia y la variación contra la semana
   anterior. Una consulta agrupada por serie; los días sin datos van en 0. */
$dias14 = [];
for ($i = 13; $i >= 0; $i--) $dias14[] = date('Y-m-d', strtotime("-$i day"));
$serie = function (string $sql) use ($pdo, $tid, $dias14): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$tid]);
        $m = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) { $m = []; }
    return array_map(fn ($d) => (float) ($m[$d] ?? 0), $dias14);
};
$medC = str_replace('medico_id', 'c.medico_id', $medFiltro);
$spark = [
    'citas'     => $serie("SELECT c.fecha d, COUNT(*) FROM citas c WHERE c.consultorio_id = ? AND c.estado <> 'cancelada'
                           AND c.fecha >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND c.fecha <= CURDATE() $medC GROUP BY d"),
    'pacientes' => $serie("SELECT DATE(creado_en) d, COUNT(*) FROM pacientes WHERE consultorio_id = ?
                           AND creado_en >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d"),
    'consultas' => $serie("SELECT DATE(fecha) d, COUNT(*) FROM consultas WHERE consultorio_id = ?
                           AND fecha >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) $medFiltro GROUP BY d"),
    'ingresos'  => $verFacturacion ? $serie("SELECT fecha d, SUM(total) FROM facturas WHERE consultorio_id = ? AND estado = 'pagada'
                           AND fecha >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) $medFiltro GROUP BY d") : array_fill(0, 14, 0),
    'recetas'   => $verRecetas ? $serie("SELECT DATE(fecha) d, COUNT(*) FROM recetas WHERE consultorio_id = ?
                           AND fecha >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) $medFiltro GROUP BY d") : array_fill(0, 14, 0),
];
/* Variación: últimos 7 días contra los 7 anteriores. null si no hay base. */
$delta = function (array $s): ?int {
    $prev = array_sum(array_slice($s, 0, 7)); $act = array_sum(array_slice($s, 7));
    if ($prev <= 0) return $act > 0 ? null : 0;
    return (int) round(($act - $prev) / $prev * 100);
};

/** Sparkline SVG suave (curva + área degradada), sin librerías. */
function spark_svg(array $v, string $color, string $id): string
{
    $w = 120; $h = 38; $n = count($v);
    if ($n < 2) return '';
    $max = max($v) ?: 1;
    $pts = [];
    foreach ($v as $i => $y) $pts[] = [$i * $w / ($n - 1), $h - 4 - ($y / $max) * ($h - 10)];
    $d = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
    for ($i = 1; $i < $n; $i++) {                 // curva cardinal → bézier
        $p0 = $pts[max(0, $i - 2)]; $p1 = $pts[$i - 1]; $p2 = $pts[$i]; $p3 = $pts[min($n - 1, $i + 1)];
        $c1 = [$p1[0] + ($p2[0] - $p0[0]) / 6, $p1[1] + ($p2[1] - $p0[1]) / 6];
        $c2 = [$p2[0] - ($p3[0] - $p1[0]) / 6, $p2[1] - ($p3[1] - $p1[1]) / 6];
        $d .= ' C' . round($c1[0], 1) . ',' . round($c1[1], 1) . ' ' . round($c2[0], 1) . ',' . round($c2[1], 1) . ' ' . round($p2[0], 1) . ',' . round($p2[1], 1);
    }
    $last = end($pts);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".28"/>'
        . '<stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
        . '<path d="' . $d . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z" fill="url(#' . $id . ')"/>'
        . '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
        . '<circle cx="' . round($last[0], 1) . '" cy="' . round($last[1], 1) . '" r="2.6" fill="' . $color . '"/></svg>';
}

/* ── Agenda de la semana: hoy y los 6 días siguientes (línea de tiempo) ── */
$semana = [];
for ($i = 0; $i < 7; $i++) $semana[date('Y-m-d', strtotime("+$i day"))] = [];
if ($verCitas) {
    $sq = $pdo->prepare(
        "SELECT c.*, p.id pac_id, p.nombre, p.apellidos,
                COALESCE(p.foto_mime, p.foto) AS foto, u.nombre medico
         FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id
         WHERE c.consultorio_id = ? AND c.estado <> 'cancelada' AND c.fecha BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY) $medC
         ORDER BY c.fecha, c.hora LIMIT 300"
    );
    try { $sq->execute([$tid]); foreach ($sq->fetchAll() as $r) $semana[$r['fecha']][] = $r; } catch (Throwable $e) {}
}

/* ── Actividad de la semana: atendidas / canceladas / no asistió por día ── */
$actLabels = $actAt = $actCa = $actNo = [];
if ($verReportes && $verCitas) {
    $aq = $pdo->prepare("SELECT c.fecha d, SUM(c.estado='atendida') a, SUM(c.estado='cancelada') ca, SUM(c.estado='no_asistio') no
                         FROM citas c WHERE c.consultorio_id = ? AND c.fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE() $medC
                         GROUP BY c.fecha");
    $aq->execute([$tid]);
    $act = [];
    foreach ($aq->fetchAll() as $r) $act[$r['d']] = $r;
    $diasCortos = [t('Dom'), t('Lun'), t('Mar'), t('Mié'), t('Jue'), t('Vie'), t('Sáb')];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $actLabels[] = $diasCortos[(int) date('w', strtotime($d))] . ' ' . (int) date('j', strtotime($d));
        $actAt[] = (int) ($act[$d]['a'] ?? 0); $actCa[] = (int) ($act[$d]['ca'] ?? 0); $actNo[] = (int) ($act[$d]['no'] ?? 0);
    }
}

/* ── Recetas recientes ───────────────────────────────────────────────── */
$recetasRec = [];
if ($verRecetas) {
    $rq = $pdo->prepare("SELECT r.id, r.fecha, r.diagnostico, p.id pac_id, p.nombre, p.apellidos, COALESCE(p.foto_mime, p.foto) AS foto
                         FROM recetas r JOIN pacientes p ON p.id = r.paciente_id
                         WHERE r.consultorio_id = ? " . str_replace('medico_id', 'r.medico_id', $medFiltro) . "
                         ORDER BY r.fecha DESC, r.id DESC LIMIT 5");
    try { $rq->execute([$tid]); $recetasRec = $rq->fetchAll(); } catch (Throwable $e) {}
}

/* ── Tu equipo hoy (admin): cada médico con sus citas de hoy ─────────── */
$equipoHoy = [];
if ($esAdmin && $verCitas) {
    $eq = $pdo->prepare("SELECT u.id, u.nombre, u.especialidad,
                                SUM(c.id IS NOT NULL) n,
                                MIN(CASE WHEN c.hora >= CURTIME() THEN c.hora END) prox
                         FROM usuarios u
                         LEFT JOIN citas c ON c.medico_id = u.id AND c.consultorio_id = u.consultorio_id
                              AND c.fecha = CURDATE() AND c.estado IN ('programada','confirmada','atendida')
                         WHERE u.consultorio_id = ? AND u.rol = 'medico' AND u.activo = 1
                         GROUP BY u.id ORDER BY n DESC, u.nombre LIMIT 6");
    try { $eq->execute([$tid]); $equipoHoy = $eq->fetchAll(); } catch (Throwable $e) {}
}

/* ── Próxima cita: la tarjeta protagonista del banner ───────────────── */
$proxima = null;
if ($verCitas) {
    $pq = $pdo->prepare(
        "SELECT c.*, p.id AS pac_id, p.nombre, p.apellidos, p.telefono, COALESCE(p.foto_mime, p.foto) AS foto, u.nombre AS medico
         FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id
         WHERE c.consultorio_id = ? AND c.estado IN ('programada','confirmada')
           AND TIMESTAMP(c.fecha, c.hora) >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) " . str_replace('medico_id', 'c.medico_id', $medFiltro) . "
         ORDER BY c.fecha, c.hora LIMIT 1"
    );
    $pq->execute([$tid]);
    $proxima = $pq->fetch() ?: null;
}

/* ── Próximas citas (próximos 7 días) ────────────────────────────────── */
$px = $pdo->prepare(
    "SELECT c.fecha, c.hora, c.estado, p.id AS pac_id, p.nombre, p.apellidos, COALESCE(p.foto_mime, p.foto) AS foto FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     WHERE c.consultorio_id = ? AND c.fecha BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       AND c.estado IN ('programada','confirmada') $medFiltro
     ORDER BY c.fecha, c.hora LIMIT 8"
);
$px->execute([$tid]);
$proximas = $px->fetchAll();

/* ── Últimos expedientes (últimas consultas) ─────────────────────────── */
$ue = $pdo->prepare(
    "SELECT co.fecha, co.diagnostico, p.id pid, p.nombre, p.apellidos, COALESCE(p.foto_mime, p.foto) AS foto,
            (SELECT COUNT(*) FROM citas ci WHERE ci.paciente_id=p.id
             AND ci.fecha>=CURDATE() AND ci.estado IN('programada','confirmada')) futuras
     FROM consultas co JOIN pacientes p ON p.id = co.paciente_id
     WHERE co.consultorio_id = ? $medFiltro
     ORDER BY co.fecha DESC LIMIT 6"
);
$ue->execute([$tid]);
$ultimos = $ue->fetchAll();

/* ── Últimas facturas ────────────────────────────────────────────────── */
$ultimasFacturas = [];
if ($verFacturacion) {
    $uf = $pdo->prepare(
        "SELECT f.folio, f.total, f.estado, f.metodo_pago, f.fecha, p.nombre, p.apellidos
         FROM facturas f JOIN pacientes p ON p.id = f.paciente_id
         WHERE f.consultorio_id = ? $medFiltro
         ORDER BY f.creado_en DESC LIMIT 6"
    );
    $uf->execute([$tid]);
    $ultimasFacturas = $uf->fetchAll();
}

/* ── Funciones "pro" traídas del dashboard de Khronos ─────────────────── */

/* Egresos del mes y balance (utilidad): ingresos − egresos. La tabla la crea
   el módulo de egresos la primera vez que se abre; si aún no existe, cero. */
$egresosMes = 0.0;
if ($verFacturacion && $esAdmin) {
    try {
        $egresosMes = (float) $pdo->query(
            "SELECT COALESCE(SUM(monto),0) FROM egresos WHERE consultorio_id = $tid
             AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())"
        )->fetchColumn();
    } catch (Throwable $e) {}
}
$balanceMes = $ingresosMes - $egresosMes;

/* Por cobrar: facturas pendientes, la más vieja primero (en Khronos son las
   "membresías vencidas": lo que se le escapa al negocio si nadie lo mira). */
$porCobrar = [];
$porCobrarTotal = 0;
if ($verFacturacion && !$esMedico) {
    $pc = $pdo->prepare(
        "SELECT f.id, f.folio, f.total, f.fecha, DATEDIFF(CURDATE(), f.fecha) dias,
                p.id pac_id, p.nombre, p.apellidos, COALESCE(p.foto_mime, p.foto) AS foto
         FROM facturas f JOIN pacientes p ON p.id = f.paciente_id
         WHERE f.consultorio_id = ? AND f.estado = 'pendiente'
         ORDER BY f.fecha ASC, f.id ASC LIMIT 6"
    );
    $pc->execute([$tid]);
    $porCobrar = $pc->fetchAll();
    $porCobrarTotal = (int) $pdo->query("SELECT COUNT(*) FROM facturas WHERE consultorio_id = $tid AND estado = 'pendiente'")->fetchColumn();
}

/* Por confirmar en las próximas 48 h, con recordatorio por WhatsApp en un
   clic (en Khronos: "por vencer en 7 días" + recordatorio wa.me). */
$porConfirmar = [];
if ($verCitas) {
    $pf = $pdo->prepare(
        "SELECT c.id, c.fecha, c.hora, p.id pac_id, p.nombre, p.apellidos, p.telefono, COALESCE(p.foto_mime, p.foto) AS foto
         FROM citas c JOIN pacientes p ON p.id = c.paciente_id
         WHERE c.consultorio_id = ? AND c.estado = 'programada'
           AND TIMESTAMP(c.fecha, c.hora) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) $medFiltro
         ORDER BY c.fecha, c.hora LIMIT 6"
    );
    $pf->execute([$tid]);
    $porConfirmar = $pf->fetchAll();
}
$verWhatsapp = modulo_activo('whatsapp');

/* Primeros pasos (solo admin, hasta completarlos u ocultarlos). Cada paso se
   comprueba contra los datos reales, no contra una casilla que alguien marca. */
$pasos = [];
if ($esAdmin && cfg('onboarding_oculto') !== '1') {
    $cuenta = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $pasos[] = [cfg('marca_logo') !== '' || cfg('marca_nombre') !== '', t('Personaliza tu consultorio (nombre y logo)'), BASE_URL . '/configuracion/index', t('Ir a Configuración')];
    $pasos[] = [$cuenta("SELECT COUNT(*) FROM usuarios WHERE consultorio_id = $tid AND rol = 'medico'") > 0, t('Da de alta a tus médicos'), BASE_URL . '/medicos/edit', t('Nuevo médico')];
    try { $nServ = $cuenta("SELECT COUNT(*) FROM servicios WHERE consultorio_id = $tid"); } catch (Throwable $e) { $nServ = 0; }
    $pasos[] = [$nServ > 0, t('Agrega tus servicios y precios'), BASE_URL . '/servicios/index', t('Agregar servicios')];
    $pasos[] = [$totPacientes > 0, t('Registra tu primer paciente'), BASE_URL . '/pacientes/create', t('Nuevo paciente')];
    if ($verCitas) $pasos[] = [$cuenta("SELECT COUNT(*) FROM citas WHERE consultorio_id = $tid") > 0, t('Agenda tu primera cita'), BASE_URL . '/citas/create', t('Nueva cita')];
    if ($verFacturacion) $pasos[] = [$cuenta("SELECT COUNT(*) FROM facturas WHERE consultorio_id = $tid") > 0, t('Registra tu primer cobro'), BASE_URL . '/facturacion/create', t('Nuevo cobro')];
    if (count(array_filter($pasos, fn ($p) => $p[0])) === count($pasos)) $pasos = [];   // todo listo: no estorba
}

/* Tus cortes de hoy (de Khronos): cada quien cierra lo que cobró; el admin
   además ve el corte general. */
$cortesHoy = [];
if ($verFacturacion) {
    require_once __DIR__ . '/includes/cortes.php';
    try {
        $cortesHoy['propio'] = [t('Mi corte'), cierre_estado_hoy('propio', (int) $u['id'])];
        if ($esAdmin) $cortesHoy['general'] = [t('General'), cierre_estado_hoy('general')];
    } catch (Throwable $e) { $cortesHoy = []; }
}

/* ── Exportar a CSV por módulo (antes de imprimir el layout) ─────────── */
/* Cada módulo se descarga en su propio archivo, con metadatos al inicio.
 * La lista respeta plan y rol: solo se ofrece lo que el usuario puede ver. */
$modulosCsv = [];
if ($verCitas)                  $modulosCsv['citas']     = 'Citas';
$modulosCsv['pacientes'] = 'Pacientes';
$modulosCsv['consultas'] = 'Consultas';
if ($verRecetas)                $modulosCsv['recetas']   = 'Recetas';
if ($verFacturacion && $esAdmin) $modulosCsv['finanzas'] = 'Finanzas';

if (($_GET['export'] ?? '') === 'csv') {
    $mod = $_GET['mod'] ?? '';
    if (!isset($modulosCsv[$mod])) $mod = array_key_first($modulosCsv);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $mod . '_' . date('Y-m-d_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    /* Metadatos del export: quién, de dónde, con qué alcance y cuándo. */
    require_once __DIR__ . '/includes/mercadopago.php'; // planes_mp(): nombre del plan
    $cons       = tenant();
    $planClave  = $cons['plan'] ?? '';
    $planNombre = planes_mp()[$planClave]['nombre'] ?? ucfirst($planClave);
    fputcsv($out, ['Metadato', 'Valor']);
    fputcsv($out, ['Sistema', marca_nombre()]);
    fputcsv($out, ['Consultorio', $cons['nombre'] ?? '']);
    fputcsv($out, ['Plan', $planNombre]);
    fputcsv($out, ['Módulo', $modulosCsv[$mod]]);
    fputcsv($out, ['Exportado por', $u['nombre'] . ' (' . $u['rol'] . ')']);
    fputcsv($out, ['Alcance', $esMedico ? 'Solo mis registros' : 'Todo el consultorio']);
    fputcsv($out, ['Fecha de exportación', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Zona horaria', date_default_timezone_get()]);
    fputcsv($out, []);

    switch ($mod) {
        case 'citas':
            fputcsv($out, ['Indicador', 'Valor']);
            fputcsv($out, ['Citas hoy', $citasHoy]);
            fputcsv($out, ['Confirmadas hoy', $citasConfHoy]);
            fputcsv($out, ['Atendidas hoy', $citasAtendHoy]);
            fputcsv($out, ['Por confirmar', $citasPorConfirmar]);
            fputcsv($out, ['Próximas (programadas/confirmadas)', $citasPend]);
            fputcsv($out, ['Tasa de inasistencia 90 días (%)', $noShow]);
            if ($verReportes && $estLabels) {
                fputcsv($out, []);
                fputcsv($out, ['Estado de cita (mes)', 'Citas']);
                foreach ($estLabels as $i => $lbl) fputcsv($out, [$lbl, $estData[$i] ?? 0]);
            }
            if ($verReportes && $horas) {
                fputcsv($out, []);
                fputcsv($out, ['Hora', 'Citas']);
                foreach (array_values($horas) as $i => $n) fputcsv($out, [$horasLabels[$i], $n]);
            }
            if ($verReportes && $topMedicos) {
                fputcsv($out, []);
                fputcsv($out, ['Médico', 'Citas']);
                foreach ($topMedicos as $m) fputcsv($out, [$m['nombre'], $m['c']]);
            }
            break;

        case 'pacientes':
            fputcsv($out, ['Indicador', 'Valor']);
            fputcsv($out, ['Pacientes totales', $totPacientes]);
            fputcsv($out, ['Nuevos este mes', $pacientesMes]);
            if ($verReportes && array_sum($porTipo) > 0) {
                fputcsv($out, []);
                fputcsv($out, ['Tipo', 'Pacientes']);
                foreach ($porTipo as $tipo => $n) fputcsv($out, [ucfirst($tipo), $n]);
            }
            if ($verReportes && $revLabels) {
                fputcsv($out, []);
                fputcsv($out, ['Mes', 'Pacientes nuevos']);
                foreach ($revLabels as $i => $lbl) fputcsv($out, [$lbl, $newData[$i] ?? 0]);
            }
            break;

        case 'consultas':
            fputcsv($out, ['Indicador', 'Valor']);
            fputcsv($out, ['Consultas este mes', $consultasMes]);
            if ($verReportes && $consLabels) {
                fputcsv($out, []);
                fputcsv($out, ['Día', 'Consultas']);
                foreach ($consLabels as $i => $lbl) fputcsv($out, [$lbl, $consData[$i] ?? 0]);
            }
            break;

        case 'recetas':
            fputcsv($out, ['Indicador', 'Valor']);
            fputcsv($out, ['Recetas este mes', $recetasMes]);
            break;

        case 'finanzas':
            fputcsv($out, ['Indicador', 'Valor']);
            fputcsv($out, ['Cobrado este mes', number_format($ingresosMes, 2, '.', '')]);
            fputcsv($out, ['Cobrado hoy', number_format($ingresosHoy, 2, '.', '')]);
            fputcsv($out, ['Ticket promedio', number_format($ticketProm, 2, '.', '')]);
            fputcsv($out, ['Pendiente por cobrar', number_format($pendienteCobrar, 2, '.', '')]);
            if ($verReportes && $revLabels) {
                fputcsv($out, []);
                fputcsv($out, ['Mes', 'Ingresos']);
                foreach ($revLabels as $i => $lbl) fputcsv($out, [$lbl, number_format((float) ($revData[$i] ?? 0), 2, '.', '')]);
            }
            if ($verReportes && $metodoLabels) {
                fputcsv($out, []);
                fputcsv($out, ['Método de pago (mes)', 'Ingresos']);
                foreach ($metodoLabels as $i => $lbl) fputcsv($out, [$lbl, number_format((float) ($metodoData[$i] ?? 0), 2, '.', '')]);
            }
            break;
    }
    exit;
}

/* ── Saludo según la hora ────────────────────────────────────────────── */
$hora     = (int) date('H');
$saludo   = $hora < 12 ? t('Buenos días') : ($hora < 19 ? t('Buenas tardes') : t('Buenas noches'));
$firstName = explode(' ', trim($u['nombre']))[0];

$titulo = t('Dashboard');
$activo = 'dashboard';
include __DIR__ . '/includes/header.php';
?>

<!-- ── Banner de bienvenida ─────────────────────────────────────────── -->
<div class="welcome-banner mb-4">
    <div class="row g-3 align-items-center">
    <div class="col-lg-7">
        <div class="mb-3">
            <h1 class="h3 mb-1 fw-bold"><?= e($saludo) ?>, <?= e($firstName) ?> 👋</h1>
            <p class="text-muted mb-0 small text-capitalize"><?= e(fecha_hoy_larga()) ?> · <?= e(marca_nombre()) ?></p>
            <?php if ($verCitas): ?>
            <p class="mb-0 mt-2"><?= $citasHoy
                ? sprintf(et('Hoy tienes %s, %s ya confirmadas.'), '<strong>' . $citasHoy . ' ' . ($citasHoy === 1 ? et('cita') : et('citas')) . '</strong>', '<strong>' . $citasConfHoy . '</strong>')
                : et('Hoy no tienes citas agendadas.') ?></p>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($verCitas): ?><a href="<?= BASE_URL ?>/citas/create" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus"></i> <?= et('Nueva cita') ?></a><?php endif; ?>
            <a href="<?= BASE_URL ?>/pacientes/create" class="btn btn-light btn-sm"><i class="bi bi-person-plus"></i> <?= et('Nuevo paciente') ?></a>
            <?php if ($verCitas): ?><a href="<?= BASE_URL ?>/citas/calendario" class="btn btn-light btn-sm"><i class="bi bi-calendar3"></i> <?= et('Agenda') ?></a><?php endif; ?>
            <div class="dropdown">
                <button type="button" class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-filetype-csv"></i> <?= et('Exportar CSV') ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach ($modulosCsv as $modClave => $modNombre): ?>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/dashboard?export=csv&mod=<?= e($modClave) ?>"><?= et($modNombre) ?></a></li>
                    <?php endforeach; ?>
                    <?php if ($esAdmin): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/configuracion/exportar">
                            <i class="bi bi-file-earmark-zip"></i> <?= et('Todos mis datos (Excel, ZIP)') ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php if ($verCitas): ?>
    <?php /* Tarjeta protagonista: la próxima cita, con lo que se hace con ella. */ ?>
    <div class="col-lg-5">
        <div class="spot">
            <?php if ($proxima):
                $esHoyP = $proxima['fecha'] === date('Y-m-d');
                $waP = modulo_activo('whatsapp') && $proxima['estado'] === 'programada'
                    ? wa_link($proxima['telefono'], mensaje_recordatorio($proxima['nombre'] . ' ' . $proxima['apellidos'],
                        fmt_fecha($proxima['fecha']), fmt_hora($proxima['hora']), cita_enlace((int) $proxima['id']))) : ''; ?>
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="spot-lbl"><i class="bi bi-calendar-heart"></i> <?= et('Próxima cita') ?></span>
                <span class="badge rounded-pill" style="background:rgba(255,255,255,.22)"><?= e(estado_label($proxima['estado'])) ?></span>
            </div>
            <div class="d-flex align-items-center gap-3 mb-3">
                <?= avatar_paciente((int) $proxima['pac_id'], $proxima['nombre'], $proxima['apellidos'], $proxima['foto'] ?? null, 54) ?>
                <div class="min-w-0">
                    <div class="fw-bold text-truncate"><?= e($proxima['nombre'] . ' ' . $proxima['apellidos']) ?></div>
                    <div class="small" style="opacity:.85"><?= $proxima['medico'] ? e($proxima['medico']) . ' · ' : '' ?><?= $esHoyP ? et('Hoy') : e(fmt_fecha($proxima['fecha'])) ?></div>
                </div>
                <div class="ms-auto text-end"><div class="spot-hora"><?= fmt_hora($proxima['hora']) ?></div></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if (cita_es_en_linea($proxima)): ?>
                    <a href="<?= BASE_URL ?>/telemedicina/sala?id=<?= (int) $proxima['id'] ?>" target="_blank" class="btn btn-sm spot-btn"><i class="bi bi-camera-video"></i> <?= et('Entrar a la videoconsulta') ?></a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $proxima['pac_id'] ?>" class="btn btn-sm spot-btn"><i class="bi bi-folder2-open"></i> <?= et('Abrir expediente') ?></a>
                <?php endif; ?>
                <?php if ($waP): ?><a href="<?= e($waP) ?>" target="_blank" rel="noopener" class="btn btn-sm spot-btn-2"><i class="bi bi-whatsapp"></i> <?= et('Recordar') ?></a><?php endif; ?>
                <a href="<?= BASE_URL ?>/citas/edit?id=<?= (int) $proxima['id'] ?>" class="btn btn-sm spot-btn-2"><?= et('Ver cita') ?></a>
            </div>
            <?php else: ?>
            <span class="spot-lbl"><i class="bi bi-calendar-heart"></i> <?= et('Próxima cita') ?></span>
            <div class="fw-bold fs-5 mt-2 mb-1"><?= et('Tu agenda está libre') ?></div>
            <div class="small mb-3" style="opacity:.85"><?= et('No hay citas por venir. Agenda una o comparte tu página para que te encuentren.') ?></div>
            <a href="<?= BASE_URL ?>/citas/create" class="btn btn-sm spot-btn"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>

<?php if ($cortesHoy): ?>
<!-- ── Tus cortes de hoy (de Khronos) ───────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2 py-2">
        <span class="fw-semibold small"><i class="bi bi-safe2 text-brand"></i> <?= et('Tus cortes de hoy') ?>:</span>
        <?php foreach ($cortesHoy as $alc => [$lbl, $est]):
            $cer = $est['cerrado_hoy'];
            $cls = ($est['num'] > 0) ? 'text-bg-warning' : ($cer ? 'text-bg-success' : 'bg-light text-secondary border');
            $url = ($est['num'] > 0 || !$cer) ? BASE_URL . '/corte/cerrar?alcance=' . $alc : BASE_URL . '/corte/cierre?id=' . (int) $cer['id']; ?>
            <a href="<?= e($url) ?>" class="badge rounded-pill text-decoration-none <?= $cls ?>" style="font-size:.85rem;padding:.5em .95em">
                <i class="bi <?= $est['num'] > 0 ? 'bi-exclamation-circle-fill' : ($cer ? 'bi-check-circle-fill' : 'bi-circle') ?>"></i>
                <?= e($lbl) ?>:
                <?php if ($cer): ?><?= et('cerrado') ?> <?= e(date('H:i', strtotime($cer['cerrado_en']))) ?><?php endif; ?>
                <?php if ($est['num'] > 0): ?><?= $cer ? '· ' : '' ?><?= et('pendiente') ?> · <?= (int) $est['num'] ?> <?= et('cobros') ?> · <?= fmt_money($est['total']) ?>
                <?php elseif (!$cer): ?><?= et('sin cobros') ?><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <span class="small text-muted"><?= et('Al terminar tu turno, cierra tu corte.') ?></span>
        <a href="<?= BASE_URL ?>/corte/cierres" class="small ms-auto"><?= et('Historial →') ?></a>
    </div>
</div>
<?php endif; ?>

<?php if ($pasos):
    $hechos = count(array_filter($pasos, fn ($x) => $x[0]));
    $pct    = (int) round($hechos / count($pasos) * 100); ?>
<!-- ── Primeros pasos (de Khronos) ──────────────────────────────────── -->
<div class="card mb-4 pasos-card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-rocket-takeoff text-brand"></i> <?= et('Primeros pasos') ?>
                <span class="text-muted small fw-normal">· <?= $hechos ?>/<?= count($pasos) ?></span></h2>
            <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="ocultar_pasos">
                <button class="btn btn-sm btn-link text-muted p-0 text-decoration-none"><?= et('Ocultar') ?></button></form>
        </div>
        <div class="progress mb-3" style="height:6px"><div class="progress-bar" style="width:<?= $pct ?>%;background:var(--brand)"></div></div>
        <div class="row g-2">
            <?php foreach ($pasos as [$ok, $lbl, $url, $cta]): ?>
            <div class="col-md-6">
                <div class="paso d-flex align-items-center justify-content-between gap-2<?= $ok ? ' ok' : '' ?>">
                    <span class="small"><i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'circle text-muted' ?>"></i> <?= e($lbl) ?></span>
                    <?php if (!$ok): ?><a href="<?= e($url) ?>" class="btn btn-sm btn-outline-secondary text-nowrap"><?= e($cta) ?></a><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── KPIs con mini gráfica y variación (como las referencias) ──────── -->
<?php
$acentoHex = color_acento();
$kpis = [];
if ($verCitas) $kpis[] = [t('Citas hoy'), number_format($citasHoy), 'bi-calendar-check', $acentoHex, BASE_URL . '/citas/index', $citasConfHoy . ' ' . t('confirmadas'), 'citas'];
$kpis[] = [t('Pacientes'), number_format($totPacientes), 'bi-people', '#0d9488', BASE_URL . '/pacientes/index', '+' . $pacientesMes . ' ' . t('este mes'), 'pacientes'];
if ($verFacturacion) $kpis[] = [t('Ingresos del mes'), fmt_money($ingresosMes), 'bi-cash-coin', '#16a34a', BASE_URL . '/facturacion/index', t('Hoy') . ': ' . fmt_money($ingresosHoy), 'ingresos'];
elseif ($verRecetas) $kpis[] = [t('Recetas (mes)'), number_format($recetasMes), 'bi-capsule', '#8b5cf6', BASE_URL . '/recetas/index', null, 'recetas'];
$kpis[] = [t('Consultas (mes)'), number_format($consultasMes), 'bi-clipboard2-pulse', '#6366f1', BASE_URL . '/expediente/index', null, 'consultas'];
?>
<div class="row g-3 mb-4">
    <?php foreach ($kpis as $ki => [$label, $value, $icon, $color, $link, $sub, $serieK]):
        $dl = $delta($spark[$serieK]); ?>
    <div class="col-6 col-xl-3">
        <a href="<?= $link ?>" class="text-decoration-none">
        <div class="card kpi2 h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <span class="kpi2-lbl"><?= e($label) ?></span>
                <span class="kpi2-ic" style="--kpi:<?= e($color) ?>"><i class="bi <?= $icon ?>"></i></span>
            </div>
            <div class="kpi2-n mt-1" data-count><?= e($value) ?></div>
            <div class="d-flex align-items-end justify-content-between gap-2">
                <div class="min-w-0">
                    <?php if ($dl === null): ?><span class="kpi2-d up"><?= et('Nuevo') ?></span>
                    <?php elseif ($dl === 0): ?><span class="kpi2-d"><?= et('= semana pasada') ?></span>
                    <?php else: ?><span class="kpi2-d <?= $dl > 0 ? 'up' : 'down' ?>"><i class="bi bi-arrow-<?= $dl > 0 ? 'up' : 'down' ?>-short"></i><?= abs($dl) ?>%</span><?php endif; ?>
                    <?php if ($sub): ?><div class="kpi2-sub text-truncate"><?= e($sub) ?></div><?php endif; ?>
                </div>
                <?= spark_svg($spark[$serieK], $color, 'sp' . $ki) ?>
            </div>
        </div></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($verCitas):
    $diasSem = [t('Dom'), t('Lun'), t('Mar'), t('Mié'), t('Jue'), t('Vie'), t('Sáb')];
    $hoyF = date('Y-m-d');
    $lateral = $equipoHoy ? 'equipo' : ($recetasRec ? 'recetas' : ''); ?>
<!-- ── Agenda de la semana: tira de días + línea de tiempo ──────────────── -->
<div class="row g-3 mb-3">
    <div class="col-xl-<?= $lateral ? '8' : '12' ?>">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-calendar-week text-brand"></i> <?= et('Agenda de la semana') ?></span>
                <a href="<?= BASE_URL ?>/citas/calendario" class="small"><?= et('Ver calendario →') ?></a>
            </div>
            <div class="card-body">
                <div class="tl-dias" role="tablist">
                    <?php foreach ($semana as $f => $cs): $ts = strtotime($f); ?>
                    <button type="button" class="tl-dia<?= $f === $hoyF ? ' on' : '' ?>" data-dia="<?= $f ?>">
                        <span class="tl-dia-n"><?= (int) date('j', $ts) ?></span>
                        <span class="tl-dia-s"><?= $f === $hoyF ? et('Hoy') : e($diasSem[(int) date('w', $ts)]) ?></span>
                        <?php if ($cs): ?><span class="tl-dia-c"><?= count($cs) ?></span><?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($semana as $f => $cs): ?>
                <div class="tl-dia-body" data-dia="<?= $f ?>"<?= $f === $hoyF ? '' : ' hidden' ?>>
                    <?php if (!$cs): ?>
                        <div class="tl-vacio"><i class="bi bi-cup-hot"></i>
                            <div class="fw-semibold"><?= et('Día libre') ?></div>
                            <a href="<?= BASE_URL ?>/citas/create" class="small"><?= et('Agendar una cita') ?></a></div>
                    <?php else: foreach ($cs as $c): ?>
                        <a href="<?= BASE_URL ?>/citas/edit?id=<?= (int) $c['id'] ?>" class="tl-row text-decoration-none">
                            <span class="tl-hora"><?= fmt_hora($c['hora']) ?></span>
                            <span class="tl-item est-<?= e($c['estado']) ?>">
                                <?= avatar_paciente((int) $c['pac_id'], $c['nombre'], $c['apellidos'], $c['foto'] ?? null, 34) ?>
                                <span class="min-w-0 flex-grow-1">
                                    <span class="d-block fw-semibold text-truncate"><?= e($c['nombre'] . ' ' . $c['apellidos']) ?></span>
                                    <span class="d-block small text-muted text-truncate">
                                        <?= e(implode(' · ', array_filter([$c['motivo'] ?? '', $c['medico'] ?? '', (int) ($c['duracion'] ?? 0) ? (int) $c['duracion'] . ' min' : '']))) ?>
                                    </span>
                                </span>
                                <?php if (($c['modalidad'] ?? '') === 'en_linea'): ?><i class="bi bi-camera-video text-brand" title="<?= e(t('Videoconsulta')) ?>"></i><?php endif; ?>
                                <span class="tl-est"><?= e(estado_label($c['estado'])) ?></span>
                            </span>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php if ($lateral === 'equipo'): ?>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-people text-brand"></i> <?= et('Tu equipo hoy') ?></span>
                <a href="<?= BASE_URL ?>/medicos/index" class="small"><?= et('Ver todos') ?></a>
            </div>
            <div class="card-body py-2">
                <?php foreach ($equipoHoy as $m): $ini = mb_strtoupper(mb_substr(preg_replace('/^(dra?|lic|mtr[oa]|psic)\.?\s+/iu', '', $m['nombre']), 0, 1)); ?>
                <div class="eq-row">
                    <span class="eq-av"><?= e($ini) ?></span>
                    <span class="min-w-0 flex-grow-1">
                        <span class="d-block fw-semibold text-truncate"><?= e($m['nombre']) ?></span>
                        <span class="d-block small text-muted text-truncate"><?= e($m['especialidad'] ?: t('Médico')) ?></span>
                    </span>
                    <span class="text-end flex-shrink-0">
                        <span class="d-block fw-bold"><?= (int) $m['n'] ?></span>
                        <span class="d-block small text-muted"><?= $m['prox'] ? et('próx.') . ' ' . fmt_hora($m['prox']) : ((int) $m['n'] ? et('terminó') : et('sin citas')) ?></span>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php elseif ($lateral === 'recetas'): ?>
    <div class="col-xl-4"><?php $recetasMostradas = true; include __DIR__ . '/includes/dashboard_recetas.php'; ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($porConfirmar || $porCobrar): ?>
<!-- ── Para atender hoy (de Khronos: vencidas / por vencer) ─────────── -->
<div class="row g-3 mb-3">
    <?php if ($verCitas): ?>
    <div class="col-lg-<?= ($verFacturacion && !$esMedico) ? '6' : '12' ?>">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-hourglass-split text-warning"></i> <?= et('Por confirmar · próximas 48 h') ?></span>
                <a href="<?= BASE_URL ?>/citas/index" class="small"><?= et('Ver citas →') ?></a>
            </div>
            <div class="card-body py-2">
                <?php if (!$porConfirmar): ?>
                    <p class="text-muted text-center small py-3 mb-0"><i class="bi bi-check-circle text-success fs-5 d-block mb-1"></i><?= et('Todas las citas próximas están confirmadas.') ?></p>
                <?php else: foreach ($porConfirmar as $c):
                    $wa = $verWhatsapp ? wa_link($c['telefono'], mensaje_recordatorio(
                        $c['nombre'] . ' ' . $c['apellidos'], fmt_fecha($c['fecha']), fmt_hora($c['hora']), cita_enlace((int) $c['id']))) : '';
                    $esHoy = $c['fecha'] === date('Y-m-d'); ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom border-opacity-10">
                        <span class="small d-flex align-items-center gap-2 min-w-0">
                            <?= avatar_paciente((int) $c['pac_id'], $c['nombre'], $c['apellidos'], $c['foto'] ?? null, 28) ?>
                            <span class="text-truncate"><?= e($c['apellidos'] . ', ' . $c['nombre']) ?></span>
                        </span>
                        <span class="d-flex align-items-center gap-2 flex-shrink-0">
                            <span class="small <?= $esHoy ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $esHoy ? et('Hoy') : ($c['fecha'] === date('Y-m-d', strtotime('+1 day')) ? et('Mañana') : e(fmt_fecha($c['fecha']))) ?> · <?= fmt_hora($c['hora']) ?></span>
                            <?php if ($wa): ?>
                            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0" title="<?= e(t('Recordatorio por WhatsApp')) ?>"><i class="bi bi-whatsapp"></i></a>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>/citas/edit?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"><?= et('Ver') ?></a>
                        </span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($verFacturacion && !$esMedico): ?>
    <div class="col-lg-<?= $verCitas ? '6' : '12' ?>">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-exclamation-triangle-fill text-danger"></i> <?= et('Por cobrar') ?></span>
                <?php if ($porCobrarTotal > count($porCobrar)): ?><span class="badge rounded-pill text-bg-danger"><?= number_format($porCobrarTotal) ?> <?= et('en total') ?></span><?php endif; ?>
            </div>
            <div class="card-body py-2">
                <?php if (!$porCobrar): ?>
                    <p class="text-muted text-center small py-3 mb-0"><i class="bi bi-check-circle text-success fs-5 d-block mb-1"></i><?= et('Nada pendiente por cobrar.') ?></p>
                <?php else: foreach ($porCobrar as $f): $d = (int) $f['dias']; ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 py-2 border-bottom border-opacity-10">
                        <span class="small d-flex align-items-center gap-2 min-w-0">
                            <?= avatar_paciente((int) $f['pac_id'], $f['nombre'], $f['apellidos'], $f['foto'] ?? null, 28) ?>
                            <span class="text-truncate"><?= e($f['nombre'] . ' ' . $f['apellidos']) ?> <span class="text-muted">· <?= e($f['folio']) ?></span></span>
                        </span>
                        <span class="d-flex align-items-center gap-2 flex-shrink-0">
                            <span class="badge rounded-pill <?= $d > 30 ? 'text-bg-danger' : ($d > 7 ? 'text-bg-warning' : 'text-bg-light border') ?>"><?= $d <= 0 ? et('hoy') : sprintf(t('hace %dd'), $d) ?></span>
                            <span class="fw-semibold small"><?= fmt_money($f['total']) ?></span>
                            <a href="<?= BASE_URL ?>/facturacion/ver?id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"><?= et('Ver') ?></a>
                        </span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($actLabels || ($recetasRec && empty($recetasMostradas))): ?>
<!-- ── Actividad de la semana + recetas recientes ───────────────────────── -->
<div class="row g-3 mb-4">
    <?php if ($actLabels): ?>
    <div class="col-lg-<?= ($recetasRec && empty($recetasMostradas)) ? '8' : '12' ?>">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-activity text-brand"></i> <?= et('Actividad de la semana') ?></div>
            <div class="card-body"><div style="height:250px"><canvas id="chartActividad"></canvas></div></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($recetasRec && empty($recetasMostradas)): ?>
    <div class="col-lg-<?= $actLabels ? '4' : '12' ?>"><?php $recetasMostradas = true; include __DIR__ . '/includes/dashboard_recetas.php'; ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($verFacturacion && $esAdmin): ?>
<!-- ── Finanzas del mes ─────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-wallet2 text-brand"></i> <?= et('Finanzas del mes') ?></h2>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>/corte/index" class="btn btn-sm btn-outline-secondary"><i class="bi bi-cash-stack"></i> <?= et('Corte de hoy') ?></a>
                <a href="<?= BASE_URL ?>/egresos/index" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-lg"></i> <?= et('Gestionar egresos') ?></a>
            </div>
        </div>
        <?php /* Como en Khronos: ingresos − egresos = utilidad. Antes solo se veía
                 lo cobrado, y un mes "bueno" podía ser de pérdida. */ ?>
        <div class="row g-3 text-center fin-row">
            <div class="col-6 col-md-3"><div class="p-2"><div class="fin-n" style="color:#22c55e"><?= fmt_money($ingresosMes) ?></div><div class="text-muted small"><?= et('Cobrado') ?></div></div></div>
            <div class="col-6 col-md-3"><div class="p-2"><div class="fin-n" style="color:#ef4444"><?= fmt_money($egresosMes) ?></div><div class="text-muted small"><?= et('Egresos') ?></div></div></div>
            <div class="col-6 col-md-3"><div class="p-2"><div class="fin-n" style="color:<?= $balanceMes >= 0 ? '#22c55e' : '#ef4444' ?>"><?= fmt_money($balanceMes) ?></div><div class="text-muted small"><?= et('Balance (utilidad)') ?></div></div></div>
            <div class="col-6 col-md-3"><div class="p-2"><div class="fin-n" style="color:#3b82f6"><?= fmt_money($pendienteCobrar) ?></div><div class="text-muted small"><?= et('Pendiente por cobrar') ?></div></div></div>
        </div>
        <div class="text-center text-muted small mt-1"><?= et('Ticket promedio') ?>: <strong><?= fmt_money($ticketProm) ?></strong> · <?= et('Hoy') ?>: <strong><?= fmt_money($ingresosHoy) ?></strong></div>
    </div>
</div>
<?php endif; ?>

<?php if ($verReportes): ?>
<!-- ── Gráficas (fila 1): ingresos+altas + citas por estado ─────────── -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-bar-chart-fill text-brand"></i>
                <?= $verFacturacion ? et('Ingresos y pacientes nuevos · 12 meses') : et('Pacientes nuevos · 12 meses') ?>
            </div>
            <div class="card-body"><div style="height:300px"><canvas id="chartRevenue"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-pie-chart-fill text-brand"></i> <?= et('Citas por estado (mes)') ?></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if ($estData): ?><div style="height:300px;width:100%"><canvas id="chartEstados"></canvas></div>
                <?php else: ?><p class="text-muted small mb-0"><?= et('Sin citas este mes.') ?></p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Gráficas (fila 2): consultas 14d + métodos de pago ───────────── -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-graph-up text-brand"></i> <?= et('Consultas · últimos 14 días') ?></div>
            <div class="card-body"><div style="height:260px"><canvas id="chartConsultas"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-credit-card-2-front text-brand"></i> <?= et('Ingresos por método (mes)') ?></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if ($metodoData): ?><div style="height:260px;width:100%"><canvas id="chartMetodos"></canvas></div>
                <?php else: ?><p class="text-muted small mb-0"><?= $verFacturacion ? et('Sin pagos este mes.') : et('Facturación no disponible en tu plan.') ?></p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Gráficas (fila 3): horas pico + tipo de paciente + top médicos ─ -->
<div class="row g-3 mb-4">
    <div class="col-lg-<?= $topMedicos ? '6' : '8' ?>">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-clock text-brand"></i> <?= et('Horas pico (citas por hora)') ?></div>
            <div class="card-body"><div style="height:260px"><canvas id="chartHoras"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-<?= $topMedicos ? '3' : '4' ?>">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-pie-chart text-brand"></i> <?= es_veterinaria() ? et('Mascotas por especie') : et('Pacientes por tipo') ?></div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if ($totPacientes): ?><div style="height:260px;width:100%"><canvas id="chartTipo"></canvas></div>
                <?php else: ?><p class="text-muted small mb-0"><?= et('Sin pacientes registrados.') ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($topMedicos): ?>
    <div class="col-lg-3">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-award text-brand"></i> <?= et('Médicos con más citas') ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($topMedicos as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="small"><?= e($m['nombre']) ?></span>
                        <span class="badge rounded-pill text-bg-info"><?= number_format((int) $m['c']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- ── Reportes y BI: no incluidos en el plan ───────────────────────── -->
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <div class="fw-semibold"><i class="bi bi-bar-chart-fill text-brand"></i> <?= et('Reportes y BI') ?></div>
            <p class="text-muted small mb-0"><?= et('Ingresos por mes, horas pico, citas por estado y más. Disponible desde el plan Profesional.') ?></p>
        </div>
        <?php if ($esAdmin): ?>
        <a href="<?= BASE_URL ?>/pagos/index" class="btn btn-primary btn-sm"><i class="bi bi-stars"></i> <?= et('Mejorar plan') ?></a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── KPIs (fila 2) ────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $row2 = [];
    if ($verCitas) {
        $row2[] = [et('Atendidas hoy'), number_format($citasAtendHoy), 'bi-check2-circle', '#22c55e', BASE_URL.'/citas/index', false];
        $row2[] = [et('Por confirmar'), number_format($citasPorConfirmar), 'bi-hourglass-split', '#3b82f6', BASE_URL.'/citas/index', false];
        $row2[] = [et('Inasistencia (90 días)'), $noShow . '%', 'bi-person-x', '#ef4444', BASE_URL.'/citas/index', $noShow > 15];
    }
    if ($verRecetas) $row2[] = [et('Recetas (mes)'), number_format($recetasMes), 'bi-capsule', '#a78bfa', BASE_URL.'/recetas/index', false];
    $row2[] = [et('Nuevos este mes'), number_format($pacientesMes), 'bi-person-plus', '#38bdf8', BASE_URL.'/pacientes/index', false];
    if ($verCitas) $row2[] = [et('Próximas citas'), number_format($citasPend), 'bi-calendar-week', '#6366f1', BASE_URL.'/citas/index', false];
    $row2 = array_slice($row2, 0, 4);
    foreach ($row2 as [$label, $value, $icon, $color, $link, $alerta]): ?>
    <div class="col-6 col-xl-3">
        <a href="<?= $link ?>" class="text-decoration-none">
        <div class="card stat-card kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon" style="--kpi:<?= $color ?>;background:color-mix(in srgb,<?= $color ?> 16%,transparent);color:<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div class="min-w-0">
                <div class="stat-num<?= $alerta ? ' text-danger' : '' ?>"><?= e($value) ?></div>
                <div class="stat-label"><?= e($label) ?></div>
            </div>
        </div></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Últimos expedientes ──────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-folder2-open text-brand"></i> <?= et('Últimos Expedientes') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Paciente') ?></th><th><?= et('Última visita') ?></th><th><?= et('Diagnóstico') ?></th><th class="text-end"><?= et('Estado') ?></th></tr></thead>
            <tbody>
            <?php if (!$ultimos): ?>
                <tr><td colspan="4" class="text-center text-muted py-4"><?= et('Aún no hay consultas registradas.') ?></td></tr>
            <?php else: foreach ($ultimos as $r): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $r['pid'], $r['nombre'], $r['apellidos'], $r['foto'] ?? null, 32) ?>
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $r['pid'] ?>" class="fw-semibold text-decoration-none"><?= e($r['nombre'] . ' ' . $r['apellidos']) ?></a>
                        </div>
                    </td>
                    <td><?= fmt_fecha($r['fecha']) ?></td>
                    <td><?= e($r['diagnostico'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ($r['futuras'] > 0): ?><span class="badge rounded-pill text-bg-info"><?= et('Seguimiento') ?></span>
                        <?php else: ?><span class="badge rounded-pill text-bg-success"><?= et('Activo') ?></span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($verFacturacion): ?>
<!-- ── Últimas facturas ─────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-receipt text-brand"></i> <?= et('Últimas facturas') ?></span>
        <a href="<?= BASE_URL ?>/facturacion/index" class="btn btn-sm btn-outline-secondary"><?= et('Ver todas') ?></a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Folio') ?></th><th><?= et('Paciente') ?></th><th><?= et('Método') ?></th><th><?= et('Fecha') ?></th><th class="text-end"><?= et('Total') ?></th></tr></thead>
            <tbody>
            <?php if (!$ultimasFacturas): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin facturas registradas.') ?></td></tr>
            <?php else: foreach ($ultimasFacturas as $f):
                $badge  = ['pagada' => 'success', 'pendiente' => 'warning', 'cancelada' => 'secondary'][$f['estado']] ?? 'secondary';
                $estLbl = ['pagada' => t('Pagada'), 'pendiente' => t('Pendiente'), 'cancelada' => t('Cancelada')][$f['estado']] ?? ucfirst($f['estado']); ?>
                <tr>
                    <td class="small text-muted"><?= e($f['folio']) ?></td>
                    <td class="small fw-semibold"><?= e($f['nombre'] . ' ' . $f['apellidos']) ?></td>
                    <td><span class="badge bg-secondary bg-opacity-50"><?= e($f['metodo_pago'] ?: '—') ?></span></td>
                    <td class="small text-muted"><?= fmt_fecha($f['fecha']) ?></td>
                    <td class="text-end fw-bold"><?= fmt_money($f['total']) ?> <span class="badge rounded-pill text-bg-<?= $badge ?> ms-1"><?= e($estLbl) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (micrositio_visible()):
    $urlPub  = micrositio_url();
    $pendWeb = $esAdmin ? micrositio_pendientes() : [];   // solo el admin puede arreglarlo
    $avance  = $esAdmin ? micrositio_avance() : 100;
?>
<?php /* La página pública sirve sobre todo para MANDARLA: al paciente que
         pregunta por WhatsApp, en la firma del correo, en el perfil de redes.
         Por eso la tarjeta no es solo un enlace: copia y comparte en un clic.
         Y si está a medio llenar lo dice, porque una página vacía no vende y
         el dueño no tiene cómo enterarse salvo abriéndola. */ ?>
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold mb-1"><i class="bi bi-globe2 text-brand"></i> <?= et('Tu página pública') ?></div>
            <a href="<?= e($urlPub) ?>" target="_blank" rel="noopener"
               class="small text-decoration-none d-inline-block text-truncate mw-100 font-monospace"><?= e($urlPub) ?></a>
            <div class="form-text mb-0"><?= et('Compártela con tus pacientes: ahí ven tus servicios, tu equipo y pueden agendar.') ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="copiarWeb" data-url="<?= e($urlPub) ?>">
                <i class="bi bi-clipboard"></i> <?= et('Copiar') ?>
            </button>
            <a href="<?= e(wa_compartir(t('Conoce nuestros servicios y agenda tu cita aquí') . ': ' . $urlPub)) ?>"
               target="_blank" rel="noopener" class="btn btn-sm btn-outline-success">
                <i class="bi bi-whatsapp"></i> <?= et('Compartir') ?>
            </a>
            <a href="<?= e($urlPub) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">
                <i class="bi bi-box-arrow-up-right"></i> <?= et('Abrir') ?>
            </a>
        </div>
    </div>

    <?php if ($pendWeb): ?>
    <div class="card-body border-top pt-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small fw-semibold">
                <i class="bi bi-clipboard-check text-brand"></i>
                <?= et('Completa tu página') ?>
            </span>
            <span class="small text-muted"><?= $avance ?>%</span>
        </div>
        <div class="progress mb-3" style="height:6px">
            <div class="progress-bar bg-success" style="width:<?= $avance ?>%"></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($pendWeb as [$clave, $etiqueta, $url]): ?>
                <a href="<?= e($url) ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-plus-lg"></i> <?= et($etiqueta) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="form-text mt-2">
            <?= et('Cada punto que llenes aparece solo en tu página. Nadie contrata a un consultorio cuya página está vacía.') ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
document.getElementById('copiarWeb').addEventListener('click', function (ev) {
    var b = ev.currentTarget;
    navigator.clipboard.writeText(b.dataset.url).then(function () {
        b.innerHTML = '<i class="bi bi-check-lg"></i> <?= e(t('Copiado')) ?>';
        setTimeout(function () { b.innerHTML = '<i class="bi bi-clipboard"></i> <?= e(t('Copiar')) ?>'; }, 2000);
    });
});
</script>
<?php endif; ?>

<?php if ($esAdmin && $totPacientes < 50 && cfg('migrar_oculto') !== '1'): ?>
<!-- ── Migración: el dueño casi siempre trae sus pacientes en Excel u otro sistema ── -->
<div class="card mb-4 migrar-card">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="migrar-ic"><i class="bi bi-file-earmark-arrow-up"></i></span>
        <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold"><?= et('¿Tienes tus pacientes en Excel o en otro sistema?') ?></div>
            <div class="small text-muted"><?= et('No los captures uno por uno: nosotros te ayudamos a pasar tu información a tu panel. Escríbenos y te ayudamos de forma personalizada.') ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>?text=<?= rawurlencode(t('Hola, quiero migrar mis pacientes a') . ' ' . marca_nombre() . '. ' . t('Los tengo en:') . ' ') ?>"
               target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp"></i> <?= et('Migrar mis datos') ?></a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ayuda="migrar"><i class="bi bi-question-circle"></i> <?= et('¿Cómo funciona?') ?></button>
            <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="ocultar_migrar">
                <button class="btn btn-sm btn-link text-muted text-decoration-none" title="<?= e(t('Ocultar')) ?>"><i class="bi bi-x-lg"></i></button></form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* Banner de bienvenida (glass azul, estilo GymOS) */
.welcome-banner { border-radius: 16px; padding: 1.25rem 1.5rem; }
html.app-dark .welcome-banner {
    background: linear-gradient(135deg, rgba(37,99,235,.16), rgba(255,214,10,.05));
    border: 1px solid var(--d-border);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
}
html.app-light .welcome-banner {
    background: linear-gradient(135deg, #fff, #fdf3ea);
    border: 1px solid rgba(0,0,0,.06);
}
/* Tarjetas KPI horizontales */
.kpi-card { transition: transform .15s ease, box-shadow .15s ease; }
.kpi-card:hover { transform: translateY(-3px); }
.kpi-card .stat-num { font-size: 1.5rem; }
.kpi-card .kpi-sub { font-size: .7rem; opacity: .8; }
.min-w-0 { min-width: 0; }

/* KPIs con mini gráfica */
.kpi2 { transition: transform .25s cubic-bezier(.22,1,.36,1), box-shadow .25s ease; }
.kpi2:hover { transform: translateY(-3px); }
.kpi2-lbl { font-size: .8rem; font-weight: 600; opacity: .7; }
.kpi2-ic { width: 38px; height: 38px; border-radius: 12px; flex: none; display: flex; align-items: center; justify-content: center; color: #fff;
           background: linear-gradient(160deg, color-mix(in srgb, var(--kpi) 80%, #fff), color-mix(in srgb, var(--kpi) 82%, #000));
           box-shadow: 0 6px 14px color-mix(in srgb, var(--kpi) 30%, transparent); }
.kpi2-n { font-size: 1.75rem; font-weight: 750; letter-spacing: -.035em; line-height: 1.1; font-variant-numeric: tabular-nums; }
.kpi2-d { display: inline-flex; align-items: center; font-size: .72rem; font-weight: 700; padding: .12rem .45rem; border-radius: 999px; background: rgba(127,127,127,.14); }
.kpi2-d.up { background: rgba(34,197,94,.15); color: #15803d; }
.kpi2-d.down { background: rgba(239,68,68,.14); color: #b91c1c; }
.kpi2-sub { font-size: .72rem; opacity: .7; margin-top: .25rem; }
.kpi2 .spark { width: 46%; max-width: 130px; height: 40px; flex: none; overflow: visible; }

/* Agenda de la semana */
.tl-dias { display: flex; gap: .45rem; overflow-x: auto; padding-bottom: .4rem; margin-bottom: .9rem; scrollbar-width: none; }
.tl-dia { position: relative; flex: 1 0 58px; border: 1px solid rgba(127,127,127,.2); background: transparent; border-radius: 14px; padding: .45rem .3rem;
          display: flex; flex-direction: column; align-items: center; color: inherit; transition: all .2s ease; }
.tl-dia:hover { border-color: var(--brand); }
.tl-dia-n { font-size: 1.15rem; font-weight: 750; line-height: 1.1; }
.tl-dia-s { font-size: .7rem; opacity: .65; text-transform: capitalize; }
.tl-dia-c { position: absolute; top: -6px; right: -4px; min-width: 18px; height: 18px; border-radius: 999px; font-size: .65rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center; background: var(--brand); color: #fff; padding: 0 .3rem; }
.tl-dia.on { background: linear-gradient(180deg, color-mix(in srgb, var(--brand) 78%, #fff), var(--brand)); color: #fff; border-color: transparent;
             box-shadow: 0 8px 18px color-mix(in srgb, var(--brand) 32%, transparent); }
.tl-dia.on .tl-dia-s { opacity: .9; }
.tl-dia.on .tl-dia-c { background: #fff; color: var(--brand); }
.tl-row { display: flex; gap: .8rem; align-items: stretch; color: inherit; padding: .25rem 0; }
.tl-hora { width: 54px; flex: none; font-size: .8rem; font-weight: 700; opacity: .6; padding-top: .7rem; text-align: right; font-variant-numeric: tabular-nums; }
.tl-item { flex: 1; min-width: 0; display: flex; align-items: center; gap: .7rem; padding: .55rem .8rem; border-radius: 14px;
           border-left: 4px solid var(--est, var(--brand)); background: color-mix(in srgb, var(--est, var(--brand)) 8%, transparent);
           transition: transform .2s ease, background .2s ease; }
.tl-row:hover .tl-item { transform: translateX(3px); background: color-mix(in srgb, var(--est, var(--brand)) 13%, transparent); }
.tl-est { font-size: .68rem; font-weight: 700; padding: .15rem .5rem; border-radius: 999px; flex: none;
          background: color-mix(in srgb, var(--est, var(--brand)) 18%, transparent); color: var(--est, var(--brand)); }
.est-programada { --est: #3b82f6; } .est-confirmada { --est: #16a34a; } .est-atendida { --est: #8b5cf6; } .est-no_asistio { --est: #ef4444; }
.tl-vacio { text-align: center; padding: 2rem 1rem; opacity: .8; } .tl-vacio .bi { font-size: 1.8rem; color: var(--brand); }

/* Tu equipo hoy / recetas recientes */
.eq-row, .rx-row { display: flex; align-items: center; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(127,127,127,.12); }
.eq-row:last-child, .rx-row:last-child { border-bottom: 0; }
.eq-av { width: 40px; height: 40px; border-radius: 50%; flex: none; display: flex; align-items: center; justify-content: center; font-weight: 700;
         color: #fff; background: linear-gradient(160deg, color-mix(in srgb, var(--brand) 75%, #fff), var(--brand)); }
.rx-ic { width: 38px; height: 38px; border-radius: 12px; flex: none; display: flex; align-items: center; justify-content: center;
         color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, transparent); }
.rx-go { width: 34px; height: 34px; border-radius: 50%; flex: none; display: flex; align-items: center; justify-content: center;
         color: var(--brand); background: color-mix(in srgb, var(--brand) 9%, transparent); text-decoration: none; }
.rx-go:hover { background: var(--brand); color: #fff; }
.fin-row .fin-n { font-weight: 700; font-size: 1.45rem; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.pasos-card { border-left: 4px solid var(--brand) !important; }
.migrar-card { border: 1px dashed color-mix(in srgb, var(--brand) 40%, transparent) !important; background: color-mix(in srgb, var(--brand) 4%, transparent); }
.migrar-ic { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
             color: var(--brand); background: color-mix(in srgb, var(--brand) 12%, transparent); flex: none; }
.pasos-card .paso { padding: .55rem .75rem; border-radius: 12px; border: 1px solid rgba(127,127,127,.18); }
.pasos-card .paso.ok { opacity: .7; }
</style>

<?php if ($verReportes): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var css     = getComputedStyle(document.documentElement);
    var brand   = (css.getPropertyValue('--brand').trim() || '#2563eb');
    var isLight = document.documentElement.classList.contains('app-light');
    var tick    = isLight ? '#6b7c93' : '#9aa0aa';
    var grid    = isLight ? 'rgba(15,39,71,.07)' : 'rgba(255,255,255,.07)';
    var tipBg   = isLight ? '#ffffff' : '#14161d';
    var PALETTE = [brand, '#60a5fa', '#ffd60a', '#38bdf8', '#22c55e', '#a78bfa', '#ef4444', '#3b82f6', '#14b8a6', '#ec4899'];
    var moneyFmt = function (v) { return '$' + Number(v).toLocaleString('es-MX'); };
    Chart.defaults.color = tick;
    Chart.defaults.font.family = getComputedStyle(document.documentElement).getPropertyValue('--font-ui').trim();

    function fade(c, a) {
        c = c.trim();
        if (c[0] === '#') { var h = c.slice(1); if (h.length === 3) h = h.split('').map(function (x) { return x + x; }).join('');
            var n = parseInt(h, 16); return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')'; }
        return c;
    }

    var hasFact = <?= $verFacturacion ? 'true' : 'false' ?>;

    // Ingresos (barras) + pacientes nuevos (línea)
    var elRev = document.getElementById('chartRevenue');
    if (elRev) {
        var ds = [];
        if (hasFact) ds.push({ type: 'bar', label: 'Ingresos', data: <?= json_encode($revData) ?>, backgroundColor: brand, borderRadius: 999, borderSkipped: false, barPercentage: .5, categoryPercentage: .7, yAxisID: 'y', order: 2 });
        ds.push({ type: 'line', label: 'Pacientes nuevos', data: <?= json_encode($newData) ?>, borderColor: '#38bdf8', backgroundColor: '#38bdf8', tension: .35, yAxisID: hasFact ? 'y1' : 'y', order: 1, pointRadius: 3 });
        new Chart(elRev, {
            data: { labels: <?= json_encode($revLabels) ?>, datasets: ds },
            options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { usePointStyle: true } },
                    tooltip: { backgroundColor: tipBg, padding: 10, cornerRadius: 8,
                        callbacks: { label: function (c) { return c.dataset.label + ': ' + (c.dataset.type === 'bar' ? moneyFmt(c.parsed.y) : c.parsed.y); } } } },
                scales: {
                    x: { grid: { color: grid }, border: { display: false } },
                    y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { callback: function (v) { return hasFact ? moneyFmt(v) : v; } } }
                    <?php if ($verFacturacion): ?>, y1: { position: 'right', grid: { drawOnChartArea: false }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } }<?php endif; ?>
                }
            }
        });
    }

    // Actividad de la semana: barras agrupadas delgadas (como la referencia)
    var elA = document.getElementById('chartActividad');
    if (elA) new Chart(elA, {
        type: 'bar',
        data: { labels: <?= json_encode($actLabels, JSON_UNESCAPED_UNICODE) ?>, datasets: [
            { label: <?= json_encode(t('Atendidas'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($actAt) ?>, backgroundColor: brand },
            { label: <?= json_encode(t('Canceladas'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($actCa) ?>, backgroundColor: '#f59e0b' },
            { label: <?= json_encode(t('No asistió'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($actNo) ?>, backgroundColor: '#ef4444' }
        ].map(function (d) { d.borderRadius = 999; d.borderSkipped = false; d.barPercentage = .55; d.categoryPercentage = .6; return d; }) },
        options: { responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { backgroundColor: tipBg, cornerRadius: 8 } },
            scales: { x: { grid: { display: false }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } }
    });

    // Citas por estado (doughnut)
    var elEst = document.getElementById('chartEstados');
    if (elEst) new Chart(elEst, {
        type: 'doughnut',
        data: { labels: <?= json_encode($estLabels) ?>, datasets: [{ data: <?= json_encode($estData) ?>, backgroundColor: PALETTE, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12 } } } }
    });

    // Consultas 14 días (línea de área)
    var elC = document.getElementById('chartConsultas');
    if (elC) {
        var g = elC.getContext('2d').createLinearGradient(0, 0, 0, 240);
        g.addColorStop(0, fade(brand, .35)); g.addColorStop(1, fade(brand, .02));
        new Chart(elC, {
            type: 'line',
            data: { labels: <?= json_encode($consLabels) ?>, datasets: [{ label: 'Consultas', data: <?= json_encode($consData) ?>, borderColor: brand, backgroundColor: g, fill: true, tension: .35, pointRadius: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: tipBg, cornerRadius: 8 } },
                scales: { x: { grid: { color: grid }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }

    // Horas pico (barras)
    var elH = document.getElementById('chartHoras');
    if (elH) new Chart(elH, {
        type: 'bar',
        data: { labels: <?= json_encode($horasLabels) ?>, datasets: [{ label: 'Citas', data: <?= json_encode(array_values($horas)) ?>, backgroundColor: fade(brand, .85), borderRadius: 999, borderSkipped: false, barPercentage: .5, categoryPercentage: .7 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: tipBg, cornerRadius: 8 } },
            scales: { x: { grid: { color: grid }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } }
    });

    // Pacientes por tipo (doughnut)
    var elT = document.getElementById('chartTipo');
    if (elT) new Chart(elT, {
        type: 'doughnut',
        data: { labels: <?= json_encode($tipoLabels) ?>,
            datasets: [{ data: <?= json_encode(array_values($porTipo)) ?>, backgroundColor: PALETTE, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12 } } } }
    });

    // Métodos de pago (doughnut)
    var elM = document.getElementById('chartMetodos');
    if (elM) new Chart(elM, {
        type: 'doughnut',
        data: { labels: <?= json_encode($metodoLabels) ?>, datasets: [{ data: <?= json_encode($metodoData) ?>, backgroundColor: PALETTE, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12 } },
                tooltip: { backgroundColor: tipBg, cornerRadius: 8, callbacks: { label: function (c) { return c.label + ': ' + moneyFmt(c.parsed); } } } } }
    });
})();
</script>
<?php endif; ?>

<script>
/* Agenda de la semana: al tocar un día se muestra su línea de tiempo. */
(function () {
    var dias = document.querySelectorAll('.tl-dia');
    dias.forEach(function (b) {
        b.addEventListener('click', function () {
            dias.forEach(function (x) { x.classList.toggle('on', x === b); });
            document.querySelectorAll('.tl-dia-body').forEach(function (d) { d.hidden = d.getAttribute('data-dia') !== b.getAttribute('data-dia'); });
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
