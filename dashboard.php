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
$horas = $porTipo = [];
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
    $revLabels[] = $MESES[$m] . ' ' . substr($ym, 2, 2);
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
    "SELECT c.*, p.nombre, p.apellidos FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     WHERE c.consultorio_id = ? AND c.fecha = CURDATE() AND c.estado <> 'cancelada' $medFiltro
     ORDER BY c.hora LIMIT 8"
);
$ag->execute([$tid]);
$agendaHoy = $ag->fetchAll();

/* ── Próximas citas (próximos 7 días) ────────────────────────────────── */
$px = $pdo->prepare(
    "SELECT c.fecha, c.hora, c.estado, p.nombre, p.apellidos FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     WHERE c.consultorio_id = ? AND c.fecha BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
       AND c.estado IN ('programada','confirmada') $medFiltro
     ORDER BY c.fecha, c.hora LIMIT 8"
);
$px->execute([$tid]);
$proximas = $px->fetchAll();

/* ── Últimos expedientes (últimas consultas) ─────────────────────────── */
$ue = $pdo->prepare(
    "SELECT co.fecha, co.diagnostico, p.id pid, p.nombre, p.apellidos,
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
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="h4 mb-1 fw-bold"><?= e($saludo) ?>, <?= e($firstName) ?> 👋</h1>
            <p class="text-muted mb-0 small text-capitalize"><?= e(fecha_hoy_larga()) ?> · <?= e(marca_nombre()) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($verCitas): ?><a href="<?= BASE_URL ?>/citas/create" class="btn btn-primary btn-sm"><i class="bi bi-calendar-plus"></i> <?= et('Nueva cita') ?></a><?php endif; ?>
            <a href="<?= BASE_URL ?>/pacientes/create" class="btn btn-light btn-sm"><i class="bi bi-person-plus"></i> <?= et('Nuevo paciente') ?></a>
            <?php if ($verCitas): ?><a href="<?= BASE_URL ?>/citas/calendario" class="btn btn-light btn-sm"><i class="bi bi-calendar3"></i> <?= et('Agenda') ?></a><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($verFacturacion && $esAdmin): ?>
<!-- ── Finanzas del mes ─────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 fw-bold mb-0"><i class="bi bi-wallet2 text-brand"></i> <?= et('Finanzas del mes') ?></h2>
            <a href="<?= BASE_URL ?>/facturacion/index" class="btn btn-sm btn-outline-secondary"><?= et('Ver facturación') ?></a>
        </div>
        <div class="row g-3 text-center">
            <div class="col-md-4"><div class="p-2"><div class="fw-bold" style="font-size:1.5rem;color:#22c55e"><?= fmt_money($ingresosMes) ?></div><div class="text-muted small"><?= et('Cobrado') ?></div></div></div>
            <div class="col-md-4"><div class="p-2"><div class="fw-bold" style="font-size:1.5rem;color:#f59e0b"><?= fmt_money($pendienteCobrar) ?></div><div class="text-muted small"><?= et('Pendiente por cobrar') ?></div></div></div>
            <div class="col-md-4"><div class="p-2"><div class="fw-bold" style="font-size:1.5rem;color:var(--brand)"><?= fmt_money($ticketProm) ?></div><div class="text-muted small"><?= et('Ticket promedio') ?></div></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── KPIs (fila 1) ────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <?php
    $row1 = [];
    if ($verCitas) $row1[] = [et('Citas hoy'), $citasHoy, 'bi-calendar-check', 'var(--brand)', BASE_URL.'/citas/index', $citasConfHoy.' '.et('confirmadas')];
    $row1[] = [et('Pacientes'), number_format($totPacientes), 'bi-people', '#0d9488', BASE_URL.'/pacientes/index', '+'.$pacientesMes.' '.et('este mes')];
    $row1[] = [et('Consultas (mes)'), number_format($consultasMes), 'bi-clipboard2-pulse', '#6366f1', BASE_URL.'/expediente/index', null];
    if ($verFacturacion) $row1[] = [et('Ingresos del mes'), fmt_money($ingresosMes), 'bi-cash-coin', '#22c55e', BASE_URL.'/facturacion/index', et('Hoy').': '.fmt_money($ingresosHoy)];
    elseif ($verRecetas) $row1[] = [et('Recetas (mes)'), number_format($recetasMes), 'bi-capsule', '#a78bfa', BASE_URL.'/recetas/index', null];
    foreach ($row1 as [$label, $value, $icon, $color, $link, $sub]): ?>
    <div class="col-6 col-xl-3">
        <a href="<?= $link ?>" class="text-decoration-none">
        <div class="card stat-card kpi-card h-100"><div class="card-body d-flex align-items-center gap-3">
            <div class="stat-icon" style="background:color-mix(in srgb,<?= $color ?> 16%,transparent);color:<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div class="min-w-0">
                <div class="stat-num"><?= e($value) ?></div>
                <div class="stat-label"><?= e($label) ?></div>
                <?php if ($sub): ?><div class="kpi-sub"><?= e($sub) ?></div><?php endif; ?>
            </div>
        </div></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── KPIs (fila 2) ────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $row2 = [];
    if ($verCitas) {
        $row2[] = [et('Atendidas hoy'), number_format($citasAtendHoy), 'bi-check2-circle', '#22c55e', BASE_URL.'/citas/index', false];
        $row2[] = [et('Por confirmar'), number_format($citasPorConfirmar), 'bi-hourglass-split', '#f59e0b', BASE_URL.'/citas/index', false];
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
            <div class="stat-icon" style="background:color-mix(in srgb,<?= $color ?> 16%,transparent);color:<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div class="min-w-0">
                <div class="stat-num<?= $alerta ? ' text-danger' : '' ?>"><?= e($value) ?></div>
                <div class="stat-label"><?= e($label) ?></div>
            </div>
        </div></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

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
            <div class="card-header fw-semibold"><i class="bi bi-pie-chart text-brand"></i> <?= et('Pacientes por tipo') ?></div>
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

<!-- ── Agenda de hoy + Próximas citas ───────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-calendar-day text-brand"></i> <?= et('Agenda de Hoy') ?></span>
                <a href="<?= BASE_URL ?>/citas/index" class="small"><?= et('Ver completa →') ?></a>
            </div>
            <div class="card-body p-0">
                <?php if (!$agendaHoy): ?>
                    <p class="text-muted text-center py-4 mb-0"><?= et('Sin citas para hoy.') ?></p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                    <?php foreach ($agendaHoy as $c): ?>
                        <li class="list-group-item d-flex align-items-center justify-content-between px-3">
                            <span><span class="text-brand fw-semibold me-2"><?= fmt_hora($c['hora']) ?></span><?= e($c['apellidos'] . ', ' . $c['nombre']) ?></span>
                            <span class="dot-estado dot-<?= e($c['estado']) ?>" title="<?= estado_label($c['estado']) ?>"></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Próximas citas (7 días)') ?></div>
            <div class="card-body py-2">
                <?php if (!$proximas): ?>
                    <p class="text-muted small mb-0 py-2"><?= et('Nada agendado esta semana.') ?></p>
                <?php else: foreach ($proximas as $c): ?>
                    <div class="d-flex justify-content-between align-items-center py-1 border-bottom border-opacity-10">
                        <span class="small"><?= e($c['apellidos'] . ', ' . $c['nombre']) ?></span>
                        <span class="small text-muted text-capitalize"><?= e(fmt_fecha($c['fecha'])) ?> · <?= fmt_hora($c['hora']) ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
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
                    <td><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $r['pid'] ?>" class="fw-semibold text-decoration-none"><?= e($r['nombre'] . ' ' . $r['apellidos']) ?></a></td>
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

<style>
/* Banner de bienvenida (glass cálido, estilo GymOS) */
.welcome-banner { border-radius: 16px; padding: 1.25rem 1.5rem; }
html.app-dark .welcome-banner {
    background: linear-gradient(135deg, rgba(246,111,20,.16), rgba(255,214,10,.05));
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
</style>

<?php if ($verReportes): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var css     = getComputedStyle(document.documentElement);
    var brand   = (css.getPropertyValue('--brand').trim() || '#f66f14');
    var isLight = document.documentElement.classList.contains('app-light');
    var tick    = isLight ? '#6b7c93' : '#9aa0aa';
    var grid    = isLight ? 'rgba(15,39,71,.07)' : 'rgba(255,255,255,.07)';
    var tipBg   = isLight ? '#ffffff' : '#14161d';
    var PALETTE = [brand, '#ff9a4d', '#ffd60a', '#38bdf8', '#22c55e', '#a78bfa', '#ef4444', '#f59e0b', '#14b8a6', '#ec4899'];
    var moneyFmt = function (v) { return '$' + Number(v).toLocaleString('es-MX'); };
    Chart.defaults.color = tick;
    Chart.defaults.font.family = "'Inter', sans-serif";

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
        if (hasFact) ds.push({ type: 'bar', label: 'Ingresos', data: <?= json_encode($revData) ?>, backgroundColor: brand, borderRadius: 6, yAxisID: 'y', order: 2 });
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
        data: { labels: <?= json_encode($horasLabels) ?>, datasets: [{ label: 'Citas', data: <?= json_encode(array_values($horas)) ?>, backgroundColor: '#6366f1', borderRadius: 5 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: tipBg, cornerRadius: 8 } },
            scales: { x: { grid: { color: grid }, border: { display: false } }, y: { grid: { color: grid }, border: { display: false }, beginAtZero: true, ticks: { precision: 0 } } } }
    });

    // Pacientes por tipo (doughnut)
    var elT = document.getElementById('chartTipo');
    if (elT) new Chart(elT, {
        type: 'doughnut',
        data: { labels: <?= json_encode([tipo_paciente_label('medico'), tipo_paciente_label('dental')]) ?>,
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

<?php include __DIR__ . '/includes/footer.php'; ?>
