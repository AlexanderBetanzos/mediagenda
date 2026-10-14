<?php
/** Cartilla de vacunación del paciente (portal, solo lectura, imprimible). */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vacunas.php';
require_paciente();

$pid = (int) current_paciente()['id'];
$aplicadas = [];
try {
    asegurar_vacunas();
    $q = db()->prepare('SELECT * FROM vacunas_aplicadas WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC');
    $q->execute([$pid, tenant_id()]);
    $aplicadas = $q->fetchAll();
} catch (Throwable $e) {}

/* Próxima de cada vacuna: la de su aplicación más reciente. */
$pend = [];
foreach ($aplicadas as $a) { $k = mb_strtolower($a['nombre']); if (!isset($pend[$k])) $pend[$k] = $a; }
$pend = array_values(array_filter($pend, fn ($a) => !empty($a['proxima'])));
usort($pend, fn ($x, $y) => strcmp($x['proxima'], $y['proxima']));
$hoy = date('Y-m-d');

$titulo = t('Mis vacunas');
$portalActivo = 'vacunas';
include __DIR__ . '/../includes/portal_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-shield-plus text-brand"></i> <?= et('Cartilla de vacunación') ?></h1>
    <button type="button" class="btn btn-outline-secondary btn-sm d-print-none" onclick="window.print()"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
</div>

<?php if ($pend): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($pend as $a): $v = $a['proxima'] < $hoy; ?>
    <span class="badge rounded-pill <?= $v ? 'text-bg-danger' : 'text-bg-warning' ?> p-2 px-3" style="font-size:.85rem">
        <i class="bi <?= $v ? 'bi-exclamation-circle' : 'bi-calendar-event' ?>"></i>
        <?= e($a['nombre']) ?> · <?= $v ? et('pendiente desde') : et('te toca el') ?> <?= e(fmt_fecha($a['proxima'])) ?>
    </span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th><?= et('Fecha') ?></th><th><?= et('Vacuna') ?></th><th><?= et('Dosis') ?></th><th><?= et('Lote') ?></th><th><?= et('Próxima') ?></th></tr></thead>
            <tbody>
            <?php if (!$aplicadas): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Aún no hay vacunas registradas.') ?></td></tr>
            <?php else: foreach ($aplicadas as $a): ?>
                <tr>
                    <td><?= e(fmt_fecha($a['fecha'])) ?></td>
                    <td class="fw-semibold"><?= e($a['nombre']) ?></td>
                    <td><?= e($a['dosis'] ?: '—') ?></td>
                    <td class="small"><?= e($a['lote'] ?: '—') ?></td>
                    <td><?= $a['proxima'] ? e(fmt_fecha($a['proxima'])) : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted mt-3"><i class="bi bi-info-circle"></i> <?= et('Si ves algo que no coincide, avísanos en tu próxima visita.') ?></p>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
