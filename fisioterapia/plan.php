<?php
/**
 * Plan de ejercicios en casa: se arma con clics desde la biblioteca del
 * consultorio (o desde un plan anterior de cualquier paciente, como
 * protocolo), se ajusta la dosificación de cada ejercicio y sale como hoja
 * imprimible, por WhatsApp y en el portal del paciente.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
fisio_sembrar_biblioteca($tid);
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$plan = null;
$cargarPlan = function (int $planId) use ($tid) {
    $st = db()->prepare('SELECT * FROM fisio_planes WHERE id = ? AND consultorio_id = ?');
    $st->execute([$planId, $tid]);
    return $st->fetch() ?: null;
};
if ($id) {
    $plan = $cargarPlan($id);
    if (!$plan || (int) $plan['paciente_id'] !== $pid) { http_response_code(404); die('Plan no encontrado.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $txt = fn ($k, $max) => ($s = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($s, 0, $max);
    $nombre = $txt('nombre', 160) ?: t('Rutina en casa');
    $datos = [$nombre, $txt('objetivo', 255), ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
              ($_POST['semanas'] ?? '') !== '' ? max(0, min(52, (int) $_POST['semanas'])) : null,
              $txt('frecuencia', 80), $txt('indicaciones', 5000), isset($_POST['portal']) ? 1 : 0];
    $pdo = db();
    $pdo->beginTransaction();
    if ($plan) {
        $pdo->prepare('UPDATE fisio_planes SET nombre=?, objetivo=?, fecha=?, semanas=?, frecuencia=?, indicaciones=?, portal=? WHERE id = ? AND consultorio_id = ?')
            ->execute([...$datos, $id, $tid]);
        $pdo->prepare('DELETE FROM fisio_plan_items WHERE plan_id = ?')->execute([$id]);
    } else {
        // Un plan nuevo reemplaza al activo: el paciente sigue una sola rutina.
        if (isset($_POST['terminar_otros'])) {
            $pdo->prepare('UPDATE fisio_planes SET activo = 0 WHERE paciente_id = ? AND consultorio_id = ?')->execute([$pid, $tid]);
        }
        $pdo->prepare('INSERT INTO fisio_planes (consultorio_id, paciente_id, creado_por, nombre, objetivo, fecha, semanas, frecuencia, indicaciones, portal) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $pid, $u['id'], ...$datos]);
        $id = (int) $pdo->lastInsertId();
    }
    $it = $_POST['it'] ?? [];
    $ins = $pdo->prepare('INSERT INTO fisio_plan_items (plan_id, ejercicio_id, nombre, instrucciones, series, reps, segundos, carga, descanso, lado, notas, imagen_url, video_url, orden)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $g = fn ($c, $i, $max = 255) => mb_substr(trim((string) ($it[$c][$i] ?? '')), 0, $max);
    $url = fn ($s) => preg_match('~^https?://~i', $s) ? $s : null;
    $orden = 0;
    foreach ((array) ($it['nombre'] ?? []) as $i => $nom) {
        $nom = mb_substr(trim((string) $nom), 0, 160);
        if ($nom === '') continue;
        $ins->execute([$id, (int) ($it['ejercicio_id'][$i] ?? 0) ?: null, $nom, $g('instrucciones', $i, 5000) ?: null,
            $g('series', $i) !== '' ? max(0, min(99, (int) $g('series', $i))) : null, $g('reps', $i, 20) ?: null,
            $g('segundos', $i) !== '' ? max(0, min(7200, (int) $g('segundos', $i))) : null,
            $g('carga', $i, 60) ?: null, $g('descanso', $i, 30) ?: null, $g('lado', $i, 12) ?: null, $g('notas', $i) ?: null,
            $url($g('imagen_url', $i, 500)), $url($g('video_url', $i, 500)), $orden++]);
    }
    $pdo->commit();
    auditar($plan ? 'editar' : 'crear', 'fisio_plan', $id, 'Paciente #' . $pid);
    flash($plan ? 'Plan actualizado.' : 'Plan de ejercicios creado.');
    if (($_POST['despues'] ?? '') === 'imprimir') redirect('/fisioterapia/plan_imprimir?id=' . $id);
    redirect('/fisioterapia/index?paciente_id=' . $pid . '&tab=planes');
}

/* Partir de otro plan (duplicar / protocolo). */
$base  = $plan;
$items = $plan ? fisio_plan_items((int) $plan['id']) : [];
if (!$plan && ($cp = (int) ($_GET['copiar'] ?? 0)) && ($orig = $cargarPlan($cp))) {
    $base = $orig; unset($base['id']);
    $base['fecha'] = date('Y-m-d');
    $items = fisio_plan_items($cp);
}

$bib = db()->prepare('SELECT * FROM fisio_ejercicios WHERE consultorio_id = ? AND activo = 1 ORDER BY region, nombre');
$bib->execute([$tid]);
$bib = $bib->fetchAll();

$otros = db()->prepare("SELECT pl.id, pl.nombre, pl.fecha, CONCAT(p.nombre, ' ', COALESCE(p.apellidos, '')) AS paciente,
                               (SELECT COUNT(*) FROM fisio_plan_items i WHERE i.plan_id = pl.id) AS n
                        FROM fisio_planes pl JOIN pacientes p ON p.id = pl.paciente_id
                        WHERE pl.consultorio_id = ?" . ($plan ? ' AND pl.id <> ' . (int) $plan['id'] : '') . '
                        ORDER BY pl.id DESC LIMIT 40');
$otros->execute([$tid]);
$otros = $otros->fetchAll();

$hayActivo = false;
if (!$plan) {
    $st = db()->prepare('SELECT COUNT(*) FROM fisio_planes WHERE paciente_id = ? AND consultorio_id = ? AND activo = 1');
    $st->execute([$pid, $tid]);
    $hayActivo = (int) $st->fetchColumn() > 0;
}

// Sugerencia de región: la de la última valoración.
$st = db()->prepare('SELECT region FROM fisio_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC LIMIT 1');
$st->execute([$pid, $tid]);
$regionSug = (string) ($st->fetchColumn() ?: '');

$titulo = t('Plan de ejercicios') . ' · ' . t('Fisioterapia');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
$val = fn ($k) => e((string) ($base[$k] ?? ''));
?>
<style>
.pl-item{border:1px solid var(--bs-border-color);border-radius:.6rem;padding:.6rem .7rem;margin-bottom:.5rem;background:var(--bs-body-bg)}
.pl-item .num{width:1.6rem;height:1.6rem;border-radius:50%;background:var(--bs-primary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex:none}
.pl-item input,.pl-item select,.pl-item textarea{font-size:.85rem}
.bib{max-height:70vh;overflow:auto}
.bib-it{display:flex;gap:.5rem;align-items:flex-start;padding:.45rem .5rem;border-bottom:1px solid var(--bs-border-color-translucent);cursor:pointer}
.bib-it:hover{background:var(--bs-tertiary-bg)}
.bib-it .t{font-weight:600;font-size:.85rem}
.bib-it .d{font-size:.72rem;color:var(--bs-secondary-color)}
@media(min-width:992px){.pl-side{position:sticky;top:76px}}
</style>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-list-check text-brand"></i> <?= $plan ? et('Editar plan de ejercicios') : et('Nuevo plan de ejercicios') ?></h1>
    <a href="<?= BASE_URL ?>/fisioterapia/index?paciente_id=<?= $pid ?>&tab=planes" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<form method="post" id="plForm">
<?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><?php if ($plan): ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><?php endif; ?>
<div class="row g-3">
<div class="col-12 col-lg-7">
    <div class="card mb-3"><div class="card-body row g-2">
        <div class="col-12 col-md-8"><label class="form-label small"><?= et('Nombre del plan') ?></label><input type="text" name="nombre" class="form-control" maxlength="160" required value="<?= $val('nombre') ?: e(t('Rutina en casa') . ($regionSug ? ' · ' . $regionSug : '')) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label small"><?= et('Inicio') ?></label><input type="date" name="fecha" class="form-control" value="<?= e($base['fecha'] ?? date('Y-m-d')) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label small"><?= et('Frecuencia') ?></label><input type="text" name="frecuencia" class="form-control" maxlength="80" list="dlFrec" value="<?= $val('frecuencia') ?: e(t('Diario')) ?>">
            <datalist id="dlFrec"><option value="Diario"><option value="Diario, 2 veces al día"><option value="3 veces por semana"><option value="Lunes, miércoles y viernes"><option value="Días sin terapia"></datalist></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Semanas') ?></label><input type="number" min="0" max="52" name="semanas" class="form-control" value="<?= $val('semanas') ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label small"><?= et('Objetivo') ?></label><input type="text" name="objetivo" class="form-control" maxlength="255" value="<?= $val('objetivo') ?>" placeholder="<?= e(t('p. ej. Recuperar la elevación del hombro sin dolor')) ?>"></div>
        <div class="col-12"><label class="form-label small"><?= et('Indicaciones generales') ?></label><textarea name="indicaciones" class="form-control" rows="2"><?= $val('indicaciones') ?: e(t('Haz los ejercicios despacio y sin aguantar la respiración. Una molestia leve (hasta 3 de 10) es normal; si el dolor aumenta o dura más de una hora después, baja las repeticiones y avísanos.')) ?></textarea></div>
        <div class="col-12 d-flex flex-wrap gap-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="portal" id="plPortal" <?= !$base || !empty($base['portal']) ? 'checked' : '' ?>><label class="form-check-label small" for="plPortal"><?= et('Mostrar en el portal del paciente (puede marcar los días que lo hace)') ?></label></div>
            <?php if ($hayActivo): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="terminar_otros" id="plTerm" checked><label class="form-check-label small" for="plTerm"><?= et('Terminar el plan activo anterior') ?></label></div><?php endif; ?>
        </div>
    </div></div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0"><?= et('Ejercicios') ?> <span class="badge bg-secondary-subtle text-body" id="plCount">0</span></h2>
        <button type="button" class="btn btn-sm btn-light" id="plVacio"><i class="bi bi-plus"></i> <?= et('Ejercicio libre') ?></button>
    </div>
    <div id="plItems"></div>
    <div id="plEmpty" class="text-center text-muted border rounded py-4 small"><?= et('Agrega ejercicios desde la biblioteca de la derecha.') ?></div>

    <div class="d-flex justify-content-end gap-2 my-3">
        <button class="btn btn-outline-primary" name="despues" value="imprimir"><i class="bi bi-printer"></i> <?= et('Guardar e imprimir') ?></button>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar plan') ?></button>
    </div>
</div>

<div class="col-12 col-lg-5"><div class="pl-side">
    <?php if ($otros && !$plan): ?>
    <div class="card mb-3"><div class="card-body py-2">
        <label class="form-label small mb-1"><i class="bi bi-copy"></i> <?= et('Partir de un plan anterior (protocolo)') ?></label>
        <select class="form-select form-select-sm" onchange="if(this.value)location.href='<?= BASE_URL ?>/fisioterapia/plan?paciente_id=<?= $pid ?>&copiar='+this.value">
            <option value=""><?= et('Elegir…') ?></option>
            <?php foreach ($otros as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) ($_GET['copiar'] ?? 0) === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['nombre']) ?> · <?= e(trim($o['paciente'])) ?> (<?= (int) $o['n'] ?>)</option><?php endforeach; ?>
        </select>
    </div></div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-collection-play text-brand"></i> <?= et('Biblioteca') ?></span>
            <a href="<?= BASE_URL ?>/fisioterapia/ejercicios" class="small" target="_blank"><?= et('Administrar') ?></a>
        </div>
        <div class="p-2 border-bottom d-flex gap-2">
            <input type="search" id="bibQ" class="form-control form-control-sm" placeholder="<?= e(t('Buscar ejercicio…')) ?>">
            <select id="bibR" class="form-select form-select-sm" style="max-width:150px"><option value=""><?= et('Todas') ?></option>
                <?php foreach (array_unique(array_column($bib, 'region')) as $r): ?><option <?= $r === $regionSug ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
        </div>
        <div class="bib" id="bibList">
            <?php foreach ($bib as $b): ?>
            <div class="bib-it" data-id="<?= (int) $b['id'] ?>" data-r="<?= e($b['region']) ?>" data-q="<?= e(mb_strtolower($b['nombre'] . ' ' . $b['tipo'] . ' ' . $b['region'])) ?>">
                <i class="bi bi-plus-circle text-brand mt-1"></i>
                <div class="flex-grow-1"><div class="t"><?= e($b['nombre']) ?></div><div class="d"><?= e($b['region']) ?> · <?= e($b['tipo']) ?> · <?= e(fisio_dosis($b)) ?></div></div>
                <?php if ($b['video_url']): ?><i class="bi bi-play-btn text-muted"></i><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div></div>
</div>
</form>

<template id="plTpl">
<div class="pl-item">
    <input type="hidden" data-f="ejercicio_id"><input type="hidden" data-f="imagen_url"><input type="hidden" data-f="video_url">
    <div class="d-flex gap-2 align-items-center mb-2">
        <span class="num"></span>
        <input type="text" data-f="nombre" class="form-control form-control-sm fw-semibold" placeholder="<?= e(t('Nombre del ejercicio')) ?>" required>
        <div class="btn-group btn-group-sm flex-none">
            <button type="button" class="btn btn-light" data-mv="-1" title="<?= e(t('Subir')) ?>"><i class="bi bi-arrow-up"></i></button>
            <button type="button" class="btn btn-light" data-mv="1" title="<?= e(t('Bajar')) ?>"><i class="bi bi-arrow-down"></i></button>
            <button type="button" class="btn btn-light text-danger" data-rm title="<?= e(t('Quitar')) ?>"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <div class="row g-1 mb-1">
        <div class="col-3 col-md-2"><input type="number" min="0" data-f="series" class="form-control form-control-sm" placeholder="<?= e(t('Series')) ?>" title="<?= e(t('Series')) ?>"></div>
        <div class="col-3 col-md-2"><input type="text" data-f="reps" class="form-control form-control-sm" placeholder="<?= e(t('Reps')) ?>" title="<?= e(t('Repeticiones')) ?>"></div>
        <div class="col-3 col-md-2"><input type="number" min="0" data-f="segundos" class="form-control form-control-sm" placeholder="<?= e(t('Seg.')) ?>" title="<?= e(t('Segundos (sostener / duración)')) ?>"></div>
        <div class="col-3 col-md-2"><input type="text" data-f="carga" class="form-control form-control-sm" placeholder="<?= e(t('Carga')) ?>" title="<?= e(t('Peso o banda')) ?>"></div>
        <div class="col-6 col-md-2"><input type="text" data-f="descanso" class="form-control form-control-sm" placeholder="<?= e(t('Descanso')) ?>"></div>
        <div class="col-6 col-md-2"><select data-f="lado" class="form-select form-select-sm"><option value=""><?= et('Lado') ?></option><option><?= et('Derecho') ?></option><option><?= et('Izquierdo') ?></option><option><?= et('Ambos') ?></option></select></div>
    </div>
    <textarea data-f="instrucciones" class="form-control form-control-sm mb-1" rows="2" placeholder="<?= e(t('Instrucciones para el paciente')) ?>"></textarea>
    <input type="text" data-f="notas" class="form-control form-control-sm" placeholder="<?= e(t('Nota / progresión (opcional)')) ?>">
</div>
</template>

<script>
(function () {
    var BIB = <?= json_encode(array_column(array_map(fn ($b) => ['id' => (int) $b['id'], 'nombre' => $b['nombre'], 'instrucciones' => $b['instrucciones'],
        'series' => $b['series'], 'reps' => $b['reps'], 'segundos' => $b['segundos'], 'imagen_url' => $b['imagen_url'], 'video_url' => $b['video_url'],
        'ejercicio_id' => (int) $b['id']], $bib), null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
    var INI = <?= json_encode(array_map(fn ($i) => array_intersect_key($i, array_flip(['ejercicio_id', 'nombre', 'instrucciones', 'series', 'reps', 'segundos', 'carga', 'descanso', 'lado', 'notas', 'imagen_url', 'video_url'])), $items), JSON_UNESCAPED_UNICODE) ?>;
    var box = document.getElementById('plItems'), tpl = document.getElementById('plTpl');

    function renum() {
        var its = box.querySelectorAll('.pl-item');
        its.forEach(function (el, i) {
            el.querySelector('.num').textContent = i + 1;
            el.querySelectorAll('[data-f]').forEach(function (f) { f.name = 'it[' + f.dataset.f + '][]'; });
        });
        document.getElementById('plCount').textContent = its.length;
        document.getElementById('plEmpty').hidden = its.length > 0;
        var ids = Array.prototype.map.call(box.querySelectorAll('[data-f=ejercicio_id]'), function (i) { return i.value; });
        document.querySelectorAll('.bib-it').forEach(function (b) { b.style.opacity = ids.indexOf(b.dataset.id) !== -1 ? .45 : 1; });
    }
    function add(d, focus) {
        var el = tpl.content.firstElementChild.cloneNode(true);
        el.querySelectorAll('[data-f]').forEach(function (f) { var v = d[f.dataset.f]; if (v !== null && v !== undefined) f.value = v; });
        box.appendChild(el); renum();
        if (focus) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.querySelector('[data-f=series]').focus(); }
    }
    INI.forEach(function (d) { add(d); }); renum();

    box.addEventListener('click', function (ev) {
        var it = ev.target.closest('.pl-item'); if (!it) return;
        if (ev.target.closest('[data-rm]')) { it.remove(); renum(); }
        var mv = ev.target.closest('[data-mv]');
        if (mv) {
            if (mv.dataset.mv === '-1' && it.previousElementSibling) box.insertBefore(it, it.previousElementSibling);
            if (mv.dataset.mv === '1' && it.nextElementSibling) box.insertBefore(it.nextElementSibling, it);
            renum();
        }
    });
    document.getElementById('bibList').addEventListener('click', function (ev) {
        var b = ev.target.closest('.bib-it'); if (!b) return;
        add(BIB[b.dataset.id], true);
    });
    document.getElementById('plVacio').addEventListener('click', function () { add({}, false); box.lastElementChild.querySelector('[data-f=nombre]').focus(); });

    var q = document.getElementById('bibQ'), r = document.getElementById('bibR');
    function filtrar() {
        var s = q.value.trim().toLowerCase(), rg = r.value;
        document.querySelectorAll('.bib-it').forEach(function (b) { b.hidden = (rg && b.dataset.r !== rg) || (s && b.dataset.q.indexOf(s) === -1); });
    }
    q.addEventListener('input', filtrar); r.addEventListener('change', filtrar);
    if (r.value && !document.querySelector('.bib-it[data-r="' + r.value.replace(/"/g, '') + '"]')) r.value = '';
    filtrar();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
