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

$titulo = 'Mis citas';
include __DIR__ . '/../includes/portal_header.php';
?>
<h1 class="h3 mb-1">Hola, <?= e($pac['nombre']) ?> 👋</h1>
<p class="text-muted">Aquí están tus próximas citas y tu historial.</p>

<div class="card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-event text-brand"></i> Próximas citas</div>
    <div class="list-group list-group-flush">
        <?php if (!$proximas): ?>
            <div class="list-group-item text-muted text-center py-4">No tienes citas próximas. Contacta a tu consultorio para agendar.</div>
        <?php else: foreach ($proximas as $c): ?>
            <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="fw-semibold"><?= fmt_fecha($c['fecha']) ?> · <?= fmt_hora($c['hora']) ?></div>
                        <small class="text-muted"><?= e($c['med_nombre']) ?><?= $c['motivo'] ? ' · ' . e($c['motivo']) : '' ?></small>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#reag<?= $c['id'] ?>"><i class="bi bi-arrow-repeat"></i> Reagendar</button>
                        <form method="post" action="<?= BASE_URL ?>/portal/cita" class="m-0" onsubmit="return confirm('¿Cancelar esta cita?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="cancelar">
                            <input type="hidden" name="cita_id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </div>
                <div class="collapse mt-2" id="reag<?= $c['id'] ?>">
                    <form method="post" action="<?= BASE_URL ?>/portal/cita" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="reagendar">
                        <input type="hidden" name="cita_id" value="<?= $c['id'] ?>">
                        <div class="col-auto"><label class="form-label small mb-1">Nueva fecha</label><input type="date" name="fecha" class="form-control form-control-sm" required min="<?= date('Y-m-d') ?>"></div>
                        <div class="col-auto"><label class="form-label small mb-1">Hora</label><input type="time" name="hora" class="form-control form-control-sm" required value="09:00"></div>
                        <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Confirmar cambio</button></div>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history text-brand"></i> Historial de citas</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Motivo</th><th>Estado</th></tr></thead>
            <tbody>
            <?php if (!$pasadas): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Sin historial.</td></tr>
            <?php else: foreach ($pasadas as $c): ?>
                <tr>
                    <td><?= fmt_fecha($c['fecha']) ?></td>
                    <td><?= fmt_hora($c['hora']) ?></td>
                    <td class="small"><?= e($c['med_nombre']) ?></td>
                    <td><?= e($c['motivo'] ?: '—') ?></td>
                    <td><span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
