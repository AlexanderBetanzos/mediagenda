<?php
/**
 * Nota de evolución SOAP de una sesión de fisioterapia: EVA al llegar y al
 * salir, intervenciones aplicadas (con sus parámetros), respuesta y plan.
 * "Repetir la sesión anterior" copia intervenciones y parámetros, que en la
 * práctica cambian poco de una sesión a otra.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$n   = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM fisio_notas WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
    $st->execute([$id, $pid, $tid]);
    $n = $st->fetch();
    if (!$n) { http_response_code(404); die('Nota no encontrada.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $txt = fn ($k) => ($s = trim((string) ($_POST[$k] ?? ''))) === '' ? null : $s;
    $eva = fn ($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? max(0, min(10, (int) $_POST[$k])) : null;
    $ints = array_values(array_filter(array_map(fn ($x) => mb_substr(trim(str_replace('|', '/', (string) $x)), 0, 60), (array) ($_POST['int'] ?? []))));
    $otra = trim(str_replace('|', '/', (string) ($_POST['int_otra'] ?? '')));
    if ($otra !== '') $ints[] = mb_substr($otra, 0, 60);
    $d = [
        ($_POST['fecha'] ?? '') ?: date('Y-m-d'), $eva('eva_pre'), $eva('eva_post'),
        $txt('subjetivo'), $txt('objetivo'), $ints ? implode('|', array_unique($ints)) : null, $txt('parametros'),
        $txt('analisis'), $txt('plan'),
        in_array($_POST['tolerancia'] ?? '', ['buena', 'regular', 'mala'], true) ? $_POST['tolerancia'] : null,
    ];
    if ($n) {
        db()->prepare('UPDATE fisio_notas SET fecha=?, eva_pre=?, eva_post=?, subjetivo=?, objetivo=?, intervenciones=?, parametros=?, analisis=?, plan=?, tolerancia=?
                       WHERE id = ? AND consultorio_id = ?')->execute([...$d, $id, $tid]);
        auditar('editar', 'fisio_nota', $id, 'Paciente #' . $pid);
        flash('Nota actualizada.');
    } else {
        db()->prepare('INSERT INTO fisio_notas (consultorio_id, paciente_id, creado_por, fecha, eva_pre, eva_post, subjetivo, objetivo, intervenciones, parametros, analisis, plan, tolerancia)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$tid, $pid, $u['id'], ...$d]);
        auditar('crear', 'fisio_nota', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Sesión registrada.');
    }
    redirect('/fisioterapia/index?paciente_id=' . $pid . '&tab=notas');
}

// Sesión anterior (para "repetir") y número de esta sesión.
$st = db()->prepare('SELECT * FROM fisio_notas WHERE paciente_id = ? AND consultorio_id = ?' . ($n ? ' AND id <> ' . (int) $n['id'] : '') . ' ORDER BY fecha DESC, id DESC LIMIT 1');
$st->execute([$pid, $tid]);
$ant = $st->fetch() ?: null;
$st = db()->prepare('SELECT COUNT(*) FROM fisio_notas WHERE paciente_id = ? AND consultorio_id = ?' . ($n ? ' AND (fecha < ? OR (fecha = ? AND id <= ?))' : ''));
$st->execute($n ? [$pid, $tid, $n['fecha'], $n['fecha'], $n['id']] : [$pid, $tid]);
$num = (int) $st->fetchColumn() + ($n ? 0 : 1);

// Plan de tratamiento vigente, a la vista mientras se escribe.
$st = db()->prepare("SELECT dx_fisio, plan, obj_corto FROM fisio_valoraciones WHERE paciente_id = ? AND consultorio_id = ? AND tipo <> 'alta' ORDER BY fecha DESC, id DESC LIMIT 1");
$st->execute([$pid, $tid]);
$vig = $st->fetch() ?: null;

$selInts = array_filter(explode('|', (string) ($n['intervenciones'] ?? '')));
$catalogo = fisio_intervenciones();
$extra = array_diff($selInts, $catalogo);
$val = fn ($k) => e((string) ($n[$k] ?? ''));

$titulo = t('Nota de evolución') . ' · ' . t('Fisioterapia');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<style>
.fv-eva{display:flex;gap:.2rem;flex-wrap:wrap}
.fv-eva label{width:2rem;height:2rem;border-radius:.45rem;display:flex;align-items:center;justify-content:center;border:1px solid var(--bs-border-color);cursor:pointer;font-weight:600;font-size:.85rem}
.fv-eva input{display:none}
.fv-eva input:checked+label{color:#fff;border-color:transparent}
.soap-l{font-weight:800;color:var(--bs-primary);margin-right:.35rem}
</style>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-journal-plus text-brand"></i> <?= et('Nota de evolución') ?> · <?= et('Sesión') ?> #<?= $num ?></h1>
    <a href="<?= BASE_URL ?>/fisioterapia/index?paciente_id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php if ($vig && ($vig['dx_fisio'] || $vig['plan'])): ?>
<div class="alert alert-light border small py-2">
    <?php if ($vig['dx_fisio']): ?><div><b><?= et('Diagnóstico:') ?></b> <?= e($vig['dx_fisio']) ?></div><?php endif; ?>
    <?php if ($vig['obj_corto']): ?><div><b><?= et('Objetivos a corto plazo:') ?></b> <?= e($vig['obj_corto']) ?></div><?php endif; ?>
    <?php if ($vig['plan']): ?><div><b><?= et('Plan:') ?></b> <?= e($vig['plan']) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="card"><div class="card-body row g-3">
    <?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><?php if ($n): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <div class="col-12 col-md-3"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= e($n['fecha'] ?? date('Y-m-d')) ?>"></div>
    <?php foreach (['eva_pre' => 'EVA al llegar', 'eva_post' => 'EVA al salir'] as $k => $lbl): $cur = $n[$k] ?? null; ?>
    <div class="col-12 col-md-auto"><label class="form-label small"><?= et($lbl) ?></label>
        <div class="fv-eva"><?php for ($i = 0; $i <= 10; $i++): $c = $i <= 3 ? '#10b981' : ($i <= 6 ? '#f59e0b' : '#ef4444'); ?>
            <input type="radio" name="<?= $k ?>" value="<?= $i ?>" id="<?= $k . $i ?>" <?= $cur !== null && (int) $cur === $i ? 'checked' : '' ?>><label for="<?= $k . $i ?>" data-c="<?= $c ?>"><?= $i ?></label>
        <?php endfor; ?></div></div>
    <?php endforeach; ?>

    <div class="col-12"><label class="form-label small"><span class="soap-l">S</span><?= et('Subjetivo: cómo llega, qué refiere desde la última sesión') ?></label>
        <textarea name="subjetivo" class="form-control" rows="2"><?= $val('subjetivo') ?></textarea></div>
    <div class="col-12"><label class="form-label small"><span class="soap-l">O</span><?= et('Objetivo: mediciones y hallazgos de hoy') ?></label>
        <textarea name="objetivo" class="form-control" rows="2" placeholder="<?= e(t('ROM, fuerza, pruebas, edema, marcha…')) ?>"><?= $val('objetivo') ?></textarea></div>

    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
            <label class="form-label small mb-0"><?= et('Intervenciones aplicadas') ?></label>
            <?php if ($ant && !$n && ($ant['intervenciones'] || $ant['parametros'])): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="repetir"
                data-ints="<?= e((string) $ant['intervenciones']) ?>" data-par="<?= e((string) $ant['parametros']) ?>" data-plan="<?= e((string) $ant['plan']) ?>">
                <i class="bi bi-arrow-repeat"></i> <?= et('Repetir la sesión anterior') ?> (<?= e(fmt_fecha($ant['fecha'])) ?>)</button>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <?php foreach (array_merge($catalogo, array_values($extra)) as $i => $x): ?>
            <input type="checkbox" class="btn-check" name="int[]" value="<?= e($x) ?>" id="int<?= $i ?>" <?= in_array($x, $selInts, true) ? 'checked' : '' ?>>
            <label class="btn btn-sm btn-outline-primary" for="int<?= $i ?>"><?= e($x) ?></label>
            <?php endforeach; ?>
            <input type="text" name="int_otra" class="form-control form-control-sm" style="max-width:200px" placeholder="<?= e(t('Otra…')) ?>">
        </div>
    </div>
    <div class="col-12"><label class="form-label small"><?= et('Parámetros y detalle (zona, tiempo, intensidad, dosis)') ?></label>
        <textarea name="parametros" id="parametros" class="form-control" rows="2" placeholder="<?= e(t('p. ej. TENS 100 Hz 15 min en trapecio; US 1 MHz 1 W/cm² 5 min; 3 × 12 rotación externa con banda roja')) ?>"><?= $val('parametros') ?></textarea></div>

    <div class="col-12"><label class="form-label small"><span class="soap-l">A</span><?= et('Análisis: respuesta al tratamiento y avance hacia los objetivos') ?></label>
        <textarea name="analisis" class="form-control" rows="2"><?= $val('analisis') ?></textarea></div>
    <div class="col-12 col-md-9"><label class="form-label small"><span class="soap-l">P</span><?= et('Plan: próxima sesión, progresión, tarea en casa') ?></label>
        <textarea name="plan" id="plan" class="form-control" rows="2"><?= $val('plan') ?></textarea></div>
    <div class="col-12 col-md-3"><label class="form-label small"><?= et('Tolerancia') ?></label>
        <select name="tolerancia" class="form-select"><option value="">—</option><?php foreach (['buena' => 'Buena', 'regular' => 'Regular', 'mala' => 'Mala'] as $k => $l): ?><option value="<?= $k ?>" <?= ($n['tolerancia'] ?? '') === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar nota') ?></button></div>
</div></form>

<script>
(function () {
    function paint() { document.querySelectorAll('.fv-eva label').forEach(function (l) { var i = document.getElementById(l.htmlFor); l.style.background = i.checked ? l.dataset.c : ''; }); }
    document.querySelectorAll('.fv-eva input').forEach(function (i) { i.addEventListener('change', paint); }); paint();
    var b = document.getElementById('repetir');
    if (b) b.addEventListener('click', function () {
        var ints = (b.dataset.ints || '').split('|');
        document.querySelectorAll('input[name="int[]"]').forEach(function (c) { if (ints.indexOf(c.value) !== -1) c.checked = true; });
        var p = document.getElementById('parametros'); if (!p.value) p.value = b.dataset.par || '';
        var pl = document.getElementById('plan'); if (!pl.value) pl.value = b.dataset.plan || '';
        b.disabled = true;
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
