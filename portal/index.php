<?php
/** Inicio del portal: citas próximas y pasadas del paciente. */
require_once __DIR__ . '/../includes/functions.php';
require_paciente();

$pac = current_paciente();
$pid = $pac['id'];

$citas = db()->prepare(
    "SELECT c.*, u.nombre AS med_nombre
     FROM citas c JOIN usuarios u ON u.id = c.medico_id
     WHERE c.paciente_id = ? AND c.consultorio_id = ?
     ORDER BY c.fecha DESC, c.hora DESC"
);
$citas->execute([$pid, tenant_id()]);
$citas = $citas->fetchAll();

$hoy = date('Y-m-d');
$proximas = array_filter($citas, fn($c) => $c['fecha'] >= $hoy && !in_array($c['estado'], ['cancelada', 'no_asistio'], true));
$pasadas  = array_filter($citas, fn($c) => !in_array($c, $proximas, true));

$titulo = t('Mis citas');
$portalActivo = 'citas';
$proximas = array_reverse(array_values($proximas));   // la más cercana primero
$sig = $proximas[0] ?? null;
$resto = array_slice($proximas, 1);
$mesCorto = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$diaSem = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

$nRec = $nArch = 0;
try { $q = db()->prepare('SELECT COUNT(*) FROM recetas WHERE paciente_id = ? AND consultorio_id = ?'); $q->execute([$pid, tenant_id()]); $nRec = (int) $q->fetchColumn(); } catch (Throwable $e) {}
try { $q = db()->prepare('SELECT COUNT(*) FROM archivos WHERE paciente_id = ? AND consultorio_id = ?'); $q->execute([$pid, tenant_id()]); $nArch = (int) $q->fetchColumn(); } catch (Throwable $e) {}

/* La próxima vacuna (la más cercana entre las últimas de cada una). */
$prVac = null;
try {
    $q = db()->prepare("SELECT a.nombre, a.proxima FROM vacunas_aplicadas a WHERE a.paciente_id = ? AND a.consultorio_id = ? AND a.proxima IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM vacunas_aplicadas b WHERE b.consultorio_id = a.consultorio_id AND b.paciente_id = a.paciente_id AND b.nombre = a.nombre
                        AND (b.fecha > a.fecha OR (b.fecha = a.fecha AND b.id > a.id)))
        ORDER BY a.proxima LIMIT 1");
    $q->execute([$pid, tenant_id()]);
    $prVac = $q->fetch() ?: null;
} catch (Throwable $e) { /* sin módulo de vacunas todavía */ }
$__slug = (string) (tenant()['slug'] ?? '');
$agendar = $__slug !== '' && function_exists('agenda_online_activa') && agenda_online_activa() ? BASE_URL . '/agenda/reservar?c=' . rawurlencode($__slug) : '';

/** Formulario plegable para reagendar o cancelar una cita. */
$acciones = function (array $c, bool $claro = false) { ?>
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-sm <?= $claro ? 'btn-light' : 'btn-outline-primary' ?>" data-bs-toggle="collapse" data-bs-target="#reag<?= $c['id'] ?>"><i class="bi bi-arrow-repeat"></i> <?= et('Reagendar') ?></button>
        <form method="post" action="<?= BASE_URL ?>/portal/cita" class="m-0" onsubmit="return confirm(<?= e(json_encode(t('¿Cancelar esta cita?'))) ?>);">
            <?= csrf_field() ?><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="cita_id" value="<?= $c['id'] ?>">
            <button class="btn btn-sm <?= $claro ? 'btn-outline-light' : 'btn-outline-danger' ?>"><i class="bi bi-x-lg"></i> <?= et('Cancelar') ?></button>
        </form>
    </div>
    <div class="collapse mt-3" id="reag<?= $c['id'] ?>">
        <form method="post" action="<?= BASE_URL ?>/portal/cita" class="row g-2 align-items-end <?= $claro ? 'bg-white text-dark rounded-4 p-2 mx-0' : '' ?>">
            <?= csrf_field() ?><input type="hidden" name="accion" value="reagendar"><input type="hidden" name="cita_id" value="<?= $c['id'] ?>">
            <div class="col"><label class="form-label small mb-1"><?= et('Nueva fecha') ?></label><input type="date" name="fecha" class="form-control form-control-sm" required min="<?= date('Y-m-d') ?>"></div>
            <div class="col"><label class="form-label small mb-1"><?= et('Hora') ?></label><input type="time" name="hora" class="form-control form-control-sm" required value="09:00"></div>
            <div class="col-12 col-sm-auto"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-check-lg"></i> <?= et('Confirmar cambio') ?></button></div>
        </form>
    </div>
<?php };

include __DIR__ . '/../includes/portal_header.php';
?>
<h1 class="h3 pt-hola mb-1"><?= et('Hola') ?>, <?= e($pac['nombre']) ?></h1>
<p class="text-muted mb-4"><?= et('Tus citas, recetas y estudios en un solo lugar.') ?></p>

<div class="row g-4">
<div class="col-lg-7">
    <?php if ($sig): $ts = strtotime($sig['fecha']); $dias = (int) round(($ts - strtotime(date('Y-m-d'))) / 86400); ?>
    <div class="pt-proxima mb-4">
        <div class="small fw-semibold opacity-75 mb-2"><i class="bi bi-calendar-heart"></i> <?= et('Tu próxima cita') ?> · <?= $dias === 0 ? et('hoy') : ($dias === 1 ? et('mañana') : sprintf(et('en %d días'), $dias)) ?></div>
        <div class="d-flex align-items-center gap-3 position-relative" style="z-index:1">
            <div class="cal"><small><?= $mesCorto[(int) date('n', $ts)] ?></small><b><?= date('j', $ts) ?></b></div>
            <div class="min-w-0">
                <div class="fs-4 fw-bold"><?= et($diaSem[(int) date('w', $ts)]) ?> · <?= fmt_hora($sig['hora']) ?></div>
                <div class="opacity-75"><?= e($sig['med_nombre']) ?><?= $sig['motivo'] ? ' · ' . e($sig['motivo']) : '' ?></div>
                <div class="mt-1"><span class="badge bg-white text-dark"><?= estado_label($sig['estado']) ?></span> <span class="small opacity-75 font-monospace ms-1"><?= e(cita_folio((int) $sig['id'])) ?></span></div>
            </div>
        </div>
        <div class="mt-3 position-relative" style="z-index:1"><?php $acciones($sig, true); ?></div>
    </div>
    <?php else: ?>
    <div class="card mb-4"><div class="card-body text-center py-4">
        <i class="bi bi-calendar-plus fs-1 text-brand d-block mb-2"></i>
        <div class="fw-semibold"><?= et('No tienes citas próximas') ?></div>
        <p class="text-muted small mb-3"><?= et('Agenda en línea o llama a la clínica.') ?></p>
        <?php if ($agendar): ?><a href="<?= e($agendar) ?>" class="btn btn-primary"><i class="bi bi-calendar-plus"></i> <?= et('Agendar una cita') ?></a><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php if ($resto): ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-calendar-event text-brand"></i> <?= et('Otras citas próximas') ?></div>
        <?php foreach ($resto as $c): $ts = strtotime($c['fecha']); ?>
        <div class="pt-item flex-wrap">
            <div class="pt-fecha"><small><?= $mesCorto[(int) date('n', $ts)] ?></small><b><?= date('j', $ts) ?></b></div>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold"><?= fmt_hora($c['hora']) ?> · <?= e($c['med_nombre']) ?></div>
                <small class="text-muted"><?= e($c['motivo'] ?: cita_folio((int) $c['id'])) ?></small></div>
            <span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span>
            <div class="w-100"><?php $acciones($c); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial de citas') ?></div>
        <?php if (!$pasadas): ?><p class="text-muted text-center small py-4 mb-0"><?= et('Sin historial.') ?></p><?php endif; ?>
        <?php foreach (array_slice($pasadas, 0, 12) as $c): $ts = strtotime($c['fecha']); ?>
        <div class="pt-item">
            <div class="pt-fecha"><small><?= $mesCorto[(int) date('n', $ts)] ?></small><b><?= date('j', $ts) ?></b></div>
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold small"><?= date('Y', $ts) ?> · <?= fmt_hora($c['hora']) ?> · <?= e($c['med_nombre']) ?></div>
                <small class="text-muted text-truncate d-block"><?= e($c['motivo'] ?: '—') ?></small></div>
            <span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="col-lg-5">
    <?php if ($prVac): $venc = $prVac['proxima'] < date('Y-m-d'); ?>
    <a href="<?= BASE_URL ?>/portal/vacunas" class="alert <?= $venc ? 'alert-danger' : 'alert-info' ?> d-flex align-items-center gap-2 text-decoration-none rounded-4 border-0">
        <i class="bi bi-shield-plus fs-4"></i>
        <span><strong><?= et('Vacuna') ?>: <?= e($prVac['nombre']) ?></strong><br><small><?= $venc ? et('pendiente desde') : et('te toca el') ?> <?= e(fmt_fecha($prVac['proxima'])) ?> · <?= et('Ver mi cartilla →') ?></small></span>
    </a>
    <?php endif; ?>
    <div class="pt-accesos" style="grid-template-columns:repeat(2,1fr)">
        <a class="pt-acc" href="<?= BASE_URL ?>/portal/recetas"><i class="bi bi-capsule"></i><b><?= et('Recetas') ?></b><small><?= $nRec ? sprintf(et('%d guardadas'), $nRec) : et('Aún no hay') ?></small></a>
        <a class="pt-acc" href="<?= BASE_URL ?>/portal/vacunas"><i class="bi bi-shield-plus"></i><b><?= et('Vacunas') ?></b><small><?= et('Tu cartilla') ?></small></a>
        <a class="pt-acc" href="<?= BASE_URL ?>/portal/archivos"><i class="bi bi-paperclip"></i><b><?= et('Estudios') ?></b><small><?= $nArch ? sprintf(et('%d archivos'), $nArch) : et('Aún no hay') ?></small></a>
        <?php if ($agendar): ?><a class="pt-acc" href="<?= e($agendar) ?>"><i class="bi bi-calendar-plus"></i><b><?= et('Agendar') ?></b><small><?= et('Nueva cita en línea') ?></small></a>
        <?php elseif (cfg('telefono')): ?><a class="pt-acc" href="tel:<?= e(preg_replace('/[^0-9+]/', '', cfg('telefono'))) ?>"><i class="bi bi-telephone"></i><b><?= et('Llamar') ?></b><small><?= e(cfg('telefono')) ?></small></a><?php endif; ?>
    </div>
    <?php if (cfg('direccion')): ?>
    <div class="card mt-3"><div class="card-body small">
        <div class="fw-semibold mb-1"><i class="bi bi-geo-alt text-brand"></i> <?= e(marca_nombre()) ?></div>
        <div class="text-muted"><?= e(cfg('direccion')) ?></div>
        <a class="btn btn-sm btn-outline-primary mt-2" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode(cfg('direccion')) ?>"><i class="bi bi-map"></i> <?= et('Cómo llegar') ?></a>
    </div></div>
    <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
