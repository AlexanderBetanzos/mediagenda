<?php
/**
 * Cartilla de vacunación de un paciente (o mascota): registrar aplicaciones,
 * ver el historial con su próxima dosis e imprimir la cartilla.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vacunas.php';
require_login();
require_modulo('expediente');
asegurar_vacunas();

$pdo = db();
$tid = (int) tenant_id();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
if (!$pid || !pertenece_al_tenant('pacientes', $pid)) { flash(t('Paciente no encontrado.'), 'warning'); redirect('/vacunacion/index'); }
$st = $pdo->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$st->execute([$pid, $tid]);
$p = $st->fetch();
$vet = es_veterinaria();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? 'aplicar';
    if ($acc === 'aplicar') {
        $vid   = (int) ($_POST['vacuna_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        if ($vid) {
            $q = $pdo->prepare('SELECT nombre FROM vacunas_catalogo WHERE id = ? AND consultorio_id = ?');
            $q->execute([$vid, $tid]);
            $nombre = (string) ($q->fetchColumn() ?: $nombre);
        }
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        $prox  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['proxima'] ?? '') ? $_POST['proxima'] : null;
        if ($nombre === '') {
            flash(t('Elige la vacuna aplicada.'), 'warning');
        } else {
            $pdo->prepare('INSERT INTO vacunas_aplicadas (consultorio_id, paciente_id, vacuna_id, nombre, dosis, lote, fecha, proxima, aplicada_por, notas)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid, $pid, $vid ?: null, mb_substr($nombre, 0, 120), trim((string) ($_POST['dosis'] ?? '')) ?: null,
                           trim((string) ($_POST['lote'] ?? '')) ?: null, $fecha, $prox, (int) current_user()['id'],
                           mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 255) ?: null]);
            auditar('vacuna_aplicar', 'paciente', $pid, $nombre . ' · ' . $fecha);
            flash(sprintf(t('Vacuna %s registrada.'), $nombre));
        }
    } elseif ($acc === 'borrar' && has_role('admin', 'medico')) {
        $aid = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM vacunas_aplicadas WHERE id = ? AND consultorio_id = ? AND paciente_id = ?')->execute([$aid, $tid, $pid]);
        auditar('vacuna_borrar', 'paciente', $pid, '#' . $aid);
        flash(t('Registro eliminado.'));
    }
    redirect('/vacunacion/paciente?paciente_id=' . $pid);
}

/* Catálogo que aplica a este paciente (en veterinaria, por su especie). */
$catalogo = array_values(array_filter(vacunas_catalogo(), function ($v) use ($vet, $p) {
    if (!$vet || $v['especie'] === '' || $v['especie'] === 'Todas') return true;
    return empty($p['especie']) || strcasecmp($v['especie'], (string) $p['especie']) === 0;
}));
$q = $pdo->prepare('SELECT a.*, u.nombre quien FROM vacunas_aplicadas a LEFT JOIN usuarios u ON u.id = a.aplicada_por
                    WHERE a.consultorio_id = ? AND a.paciente_id = ? ORDER BY a.fecha DESC, a.id DESC');
$q->execute([$tid, $pid]);
$aplicadas = $q->fetchAll();

/* Pendientes: la próxima de cada vacuna (la de su aplicación más reciente). */
$pendientes = [];
foreach ($aplicadas as $a) {
    $k = mb_strtolower($a['nombre']);
    if (isset($pendientes[$k])) continue;
    $pendientes[$k] = $a;
}
$pendientes = array_filter($pendientes, fn ($a) => !empty($a['proxima']));
usort($pendientes, fn ($x, $y) => strcmp($x['proxima'], $y['proxima']));
$hoy = date('Y-m-d');

$titulo = t('Cartilla de vacunación');
$activo = 'vacunacion';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb" class="d-print-none"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($p['nombre'] . ($vet ? '' : ' ' . $p['apellidos'])) ?></a></li>
    <li class="breadcrumb-item active"><?= et('Vacunas') ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-shield-plus"></i> <?= et('Cartilla de vacunación') ?></h1>
    <div class="d-flex gap-2 d-print-none">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> <?= et('Imprimir cartilla') ?></button>
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-light"><i class="bi bi-arrow-left"></i> <?= et('Expediente') ?></a>
    </div>
</div>

<div class="pf-card mb-3 cartilla-head">
    <div class="fw-bold fs-5"><?= e($p['nombre'] . ($vet ? '' : ' ' . $p['apellidos'])) ?></div>
    <div class="text-muted small">
        <?= e(implode(' · ', array_filter([$vet ? mascota_resumen($p) : '', edad($p['fecha_nacimiento'] ?? null),
            $vet && mascota_propietario($p) ? t('Propietario') . ': ' . mascota_propietario($p) : '', marca_nombre()]))) ?>
    </div>
</div>

<?php if ($pendientes): ?>
<div class="vac-prox mb-3">
    <?php foreach ($pendientes as $a): $venc = $a['proxima'] < $hoy; $wa = wa_link($p['telefono'] ?? '', vacuna_wa_mensaje($p, $a['nombre'], $a['proxima'])); ?>
    <div class="vac-chip <?= $venc ? 'venc' : '' ?>">
        <i class="bi <?= $venc ? 'bi-exclamation-circle-fill' : 'bi-calendar-event' ?>"></i>
        <span><b><?= e($a['nombre']) ?></b> · <?= $venc ? et('vencida desde') : et('toca el') ?> <?= e(fmt_fecha($a['proxima'])) ?></span>
        <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="d-print-none" title="<?= e(t('Recordar por WhatsApp')) ?>"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="cobro-grid">
    <section class="pf-sec">
        <div class="pf-sec-h"><i class="bi bi-journal-medical"></i> <?= et('Vacunas aplicadas') ?></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Vacuna') ?></th><th><?= et('Dosis') ?></th><th><?= et('Lote') ?></th><th><?= et('Próxima') ?></th><th class="d-print-none"></th></tr></thead>
                <tbody>
                <?php if (!$aplicadas): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Aún no hay vacunas registradas.') ?></td></tr>
                <?php else: foreach ($aplicadas as $a): ?>
                    <tr>
                        <td class="text-nowrap"><?= e(fmt_fecha($a['fecha'])) ?></td>
                        <td class="fw-semibold"><?= e($a['nombre']) ?><?php if ($a['quien']): ?><div class="small text-muted fw-normal"><?= e($a['quien']) ?></div><?php endif; ?></td>
                        <td><?= e($a['dosis'] ?: '—') ?></td>
                        <td class="small"><?= e($a['lote'] ?: '—') ?></td>
                        <td class="text-nowrap"><?php if ($a['proxima']): ?><span class="badge rounded-pill <?= $a['proxima'] < $hoy ? 'text-bg-danger' : 'text-bg-light border' ?>"><?= e(fmt_fecha($a['proxima'])) ?></span><?php else: ?>—<?php endif; ?></td>
                        <td class="text-end d-print-none">
                            <?php if (has_role('admin', 'medico')): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('<?= e(t('¿Eliminar este registro de vacuna?')) ?>');">
                                <?= csrf_field() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" aria-label="<?= e(t('Eliminar')) ?>"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="cobro-side d-print-none">
        <form method="post" class="pf-card" id="vacForm">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="aplicar"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
            <div class="fw-semibold mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Registrar vacuna') ?></div>
            <label class="form-label"><?= et('Vacuna') ?> *</label>
            <select name="vacuna_id" id="vacSel" class="form-select mb-2" required>
                <option value=""><?= et('— Selecciona —') ?></option>
                <?php foreach ($catalogo as $v): ?>
                <option value="<?= (int) $v['id'] ?>" data-ref="<?= (int) $v['refuerzo_dias'] ?>"><?= e($v['nombre']) ?><?= $vet && $v['especie'] && $v['especie'] !== 'Todas' ? ' · ' . e($v['especie']) : '' ?></option>
                <?php endforeach; ?>
            </select>
            <div class="row g-2">
                <div class="col-6"><label class="form-label"><?= et('Fecha') ?></label><input type="date" name="fecha" id="vacFecha" class="form-control" value="<?= $hoy ?>"></div>
                <div class="col-6"><label class="form-label"><?= et('Dosis') ?></label><input type="text" name="dosis" class="form-control" placeholder="<?= e(t('Ej. 1a dosis')) ?>"></div>
                <div class="col-6"><label class="form-label"><?= et('Lote') ?></label><input type="text" name="lote" class="form-control"></div>
                <div class="col-6"><label class="form-label"><?= et('Próxima dosis') ?></label><input type="date" name="proxima" id="vacProx" class="form-control"></div>
            </div>
            <div class="form-text" id="vacRef"></div>
            <label class="form-label mt-2"><?= et('Notas') ?></label>
            <input type="text" name="notas" class="form-control" maxlength="255">
            <button class="btn btn-primary w-100 mt-3"><i class="bi bi-check-lg"></i> <?= et('Registrar') ?></button>
            <?php if (has_role('admin')): ?><a href="<?= BASE_URL ?>/vacunacion/index?tab=catalogo" class="btn btn-link btn-sm w-100 mt-1"><?= et('Editar catálogo de vacunas') ?></a><?php endif; ?>
        </form>
    </aside>
</div>

<style>
.cobro-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1.25rem; align-items: start; }
@media (max-width: 991.98px) { .cobro-grid { grid-template-columns: 1fr; } }
.cobro-side { position: sticky; top: 90px; }
.pf-card, .pf-sec { background: var(--bs-body-bg); border-radius: 18px; padding: 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 10px 28px rgba(15,30,60,.06); }
body.ui-glass .pf-card, body.ui-glass .pf-sec { background: var(--g-glass-2, #fff); box-shadow: var(--g-shadow-sm); }
.pf-sec-h { display: flex; align-items: center; gap: .55rem; font-weight: 650; margin-bottom: 1rem; }
.pf-sec-h .bi { width: 34px; height: 34px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, transparent); }
.vac-prox { display: flex; flex-wrap: wrap; gap: .5rem; }
.vac-chip { display: inline-flex; align-items: center; gap: .5rem; padding: .5rem .85rem; border-radius: 999px; font-size: .88rem;
            background: color-mix(in srgb, var(--brand) 9%, transparent); border: 1px solid color-mix(in srgb, var(--brand) 22%, transparent); }
.vac-chip .bi { color: var(--brand); }
.vac-chip.venc { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.3); } .vac-chip.venc > .bi { color: #dc2626; }
.vac-chip a .bi { color: #16a34a; font-size: 1.05rem; }
@media print {
    .app-navbar, .sidebar, footer, .ayuda-fab, .d-print-none { display: none !important; }
    .cobro-grid { display: block; } .pf-card, .pf-sec { box-shadow: none !important; border: 1px solid #ddd; }
}
</style>
<script>
/* Próxima dosis: fecha de aplicación + refuerzo de la vacuna (editable). */
(function () {
    var sel = document.getElementById('vacSel'), fe = document.getElementById('vacFecha'), pr = document.getElementById('vacProx'), info = document.getElementById('vacRef');
    function calc() {
        var o = sel.options[sel.selectedIndex], ref = o ? parseInt(o.dataset.ref || '0', 10) : 0;
        if (!ref || !fe.value) { info.textContent = sel.value ? <?= json_encode(t('Esta vacuna no tiene refuerzo en el catálogo: pon la próxima fecha si aplica.'), JSON_UNESCAPED_UNICODE) ?> : ''; return; }
        var d = new Date(fe.value + 'T12:00'); d.setDate(d.getDate() + ref);
        pr.value = d.toISOString().slice(0, 10);
        info.textContent = <?= json_encode(t('Calculada con el refuerzo del catálogo; puedes cambiarla.'), JSON_UNESCAPED_UNICODE) ?>;
    }
    sel.addEventListener('change', calc); fe.addEventListener('change', calc);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
