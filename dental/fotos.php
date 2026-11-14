<?php
/**
 * Fotos clínicas dentales: serie fotográfica por fecha y etapa del
 * tratamiento (inicial, en tratamiento, final, control), con visor a
 * pantalla completa y comparador antes/después con deslizador.
 *
 * Las fotos se reducen en el navegador antes de subir (lado mayor 2400 px):
 * suben rápido desde el celular y no chocan con el límite del servidor.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$vistas = dental_vistas();
$todasVistas = array_merge(...array_values($vistas));
$etapas = dental_etapas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'subir') {
        $vista = in_array($_POST['vista'] ?? '', $todasVistas, true) ? $_POST['vista'] : 'Otra';
        $etapa = isset($etapas[$_POST['etapa'] ?? '']) ? $_POST['etapa'] : 'inicial';
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        $diente = mb_substr(preg_replace('/[^0-9, ]/', '', (string) ($_POST['diente'] ?? '')), 0, 20) ?: null;
        $notas = mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 255) ?: null;
        $ok = $err = 0; $msg = '';
        foreach (dental_files_lista('fotos') as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $mime = is_uploaded_file($f['tmp_name'] ?? '') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
            if (strpos($mime, 'image/') !== 0) { $err++; $msg = t('Solo se aceptan imágenes (JPG, PNG o WebP).'); continue; }
            $r = guardar_archivo_expediente($f, $pid, (int) $u['id'], 'Foto dental · ' . $vista, 0);
            if ($r['estado'] !== 'ok') { $err++; $msg = $r['mensaje']; continue; }
            db()->prepare('INSERT INTO dental_fotos (consultorio_id, paciente_id, archivo_id, fecha, vista, etapa, diente, notas, creado_por) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$tid, $pid, (int) $r['id'], $fecha, $vista, $etapa, $diente, $notas, $u['id']]);
            $ok++;
        }
        if ($ok) { auditar('crear', 'dental_foto', null, $ok . ' foto(s) · Paciente #' . $pid); flash(sprintf(t('%d foto(s) agregadas.'), $ok)); }
        if ($err) flash(sprintf(t('%d foto(s) no se pudieron subir: %s'), $err, $msg), 'warning');
        if (!$ok && !$err) flash(t('Elige al menos una foto.'), 'warning');
    } elseif ($accion === 'editar') {
        $vista = in_array($_POST['vista'] ?? '', $todasVistas, true) ? $_POST['vista'] : 'Otra';
        $etapa = isset($etapas[$_POST['etapa'] ?? '']) ? $_POST['etapa'] : 'inicial';
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        db()->prepare('UPDATE dental_fotos SET vista = ?, etapa = ?, fecha = ?, diente = ?, notas = ? WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([$vista, $etapa, $fecha, mb_substr(preg_replace('/[^0-9, ]/', '', (string) ($_POST['diente'] ?? '')), 0, 20) ?: null,
                       mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 255) ?: null, (int) ($_POST['id'] ?? 0), $pid, $tid]);
        flash(t('Foto actualizada.'));
    } elseif ($accion === 'borrar') {
        $st = db()->prepare('SELECT f.id, a.id AS aid, a.nombre_guardado FROM dental_fotos f JOIN archivos a ON a.id = f.archivo_id
                             WHERE f.id = ? AND f.paciente_id = ? AND f.consultorio_id = ? AND a.consultorio_id = ?');
        $st->execute([(int) ($_POST['id'] ?? 0), $pid, $tid, $tid]);
        if ($f = $st->fetch()) {
            $ruta = archivo_dir($pid) . '/' . basename($f['nombre_guardado']);
            if (is_file($ruta)) @unlink($ruta);
            db()->prepare('DELETE FROM archivos WHERE id = ? AND consultorio_id = ?')->execute([(int) $f['aid'], $tid]);
            db()->prepare('DELETE FROM dental_fotos WHERE id = ?')->execute([(int) $f['id']]);
            auditar('borrar', 'dental_foto', (int) $f['id'], 'Paciente #' . $pid);
            flash(t('Foto eliminada.'));
        }
    }
    redirect('/dental/fotos?paciente_id=' . $pid);
}

$fotos = dental_fotos($pid);
$porFecha = [];
foreach ($fotos as $f) $porFecha[$f['fecha']][] = $f;
$cuentaEtapa = array_count_values(array_column($fotos, 'etapa'));
$catDe = [];
foreach ($vistas as $cat => $vs) foreach ($vs as $v) $catDe[$v] = $cat;

$titulo = t('Fotos clínicas') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
dental_encabezado('Fotos clínicas', 'bi-images', $pac, $pid, 'fotos');
$etColor = ['inicial' => 'secondary', 'progreso' => 'info', 'final' => 'success', 'control' => 'primary'];
?>
<style>
.ph-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.65rem}
.ph{position:relative;border-radius:.6rem;overflow:hidden;background:#0b1220;aspect-ratio:4/3;cursor:zoom-in;border:2px solid transparent}
.ph img{width:100%;height:100%;object-fit:cover;transition:transform .25s}
.ph:hover img{transform:scale(1.04)}
.ph .cap{position:absolute;left:0;right:0;bottom:0;padding:.35rem .5rem;font-size:.72rem;color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.75))}
.ph .et{position:absolute;top:.35rem;left:.35rem}
.ph .ck{position:absolute;top:.35rem;right:.35rem;width:1.5rem;height:1.5rem;border-radius:50%;background:rgba(255,255,255,.85);display:none;align-items:center;justify-content:center;color:#2563eb}
.cmp-on .ph{cursor:pointer}.cmp-on .ph .ck{display:flex}
.ph.pick{border-color:#2563eb}.ph.pick .ck{background:#2563eb;color:#fff}
.ph-day{font-weight:600;font-size:.85rem;margin:1rem 0 .4rem;display:flex;align-items:center;gap:.5rem}
.drop{border:2px dashed var(--bs-border-color);border-radius:.75rem;padding:1rem;text-align:center;cursor:pointer;transition:.15s}
.drop.over{border-color:var(--bs-primary);background:color-mix(in srgb,var(--bs-primary) 6%,transparent)}
.prev{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.5rem}.prev img{width:64px;height:48px;object-fit:cover;border-radius:.35rem}
.vw{background:#05080f}
.vw img{max-width:100%;max-height:78vh;object-fit:contain;display:block;margin:auto}
.cmp{position:relative;max-width:100%;margin:auto;user-select:none;overflow:hidden;background:#05080f}
.cmp img{display:block;width:100%;max-height:76vh;object-fit:contain}
.cmp .top{position:absolute;inset:0;overflow:hidden}
.cmp .top img{position:absolute;inset:0;height:100%}
.cmp .bar{position:absolute;top:0;bottom:0;width:3px;background:#fff;box-shadow:0 0 6px rgba(0,0,0,.6);pointer-events:none}
.cmp .bar::after{content:'⟷';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#111;border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-weight:700}
.cmp .lb{position:absolute;top:.6rem;font-size:.75rem;color:#fff;background:rgba(0,0,0,.55);padding:.15rem .5rem;border-radius:99px}
</style>

<div class="row g-3">
<div class="col-12 col-xl-4">
    <form method="post" enctype="multipart/form-data" class="card" id="upForm"><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="accion" value="subir"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
        <h2 class="h6"><i class="bi bi-cloud-arrow-up text-brand"></i> <?= et('Subir fotos') ?></h2>
        <label class="drop d-block" id="drop">
            <i class="bi bi-camera fs-3 text-brand d-block"></i>
            <span class="small"><?= et('Toca para tomar o elegir fotos, o arrástralas aquí') ?></span>
            <input type="file" name="fotos[]" id="fotosIn" accept="image/*" multiple hidden>
            <div class="prev" id="prev"></div>
        </label>
        <div class="row g-2 mt-1">
            <div class="col-12"><label class="form-label small mb-0"><?= et('Vista') ?></label>
                <select name="vista" class="form-select form-select-sm"><?php foreach ($vistas as $cat => $vs): ?><optgroup label="<?= e(t($cat)) ?>"><?php foreach ($vs as $v): ?><option value="<?= e($v) ?>"><?= et($v) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small mb-0"><?= et('Etapa') ?></label>
                <select name="etapa" class="form-select form-select-sm"><?php foreach ($etapas as $k => $l): ?><option value="<?= $k ?>" <?= !$fotos && $k === 'inicial' || $fotos && $k === 'progreso' ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label small mb-0"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-5"><label class="form-label small mb-0"><?= et('Pieza(s)') ?></label><input type="text" name="diente" class="form-control form-control-sm" placeholder="11, 21" inputmode="numeric"></div>
            <div class="col-7"><label class="form-label small mb-0"><?= et('Nota') ?></label><input type="text" name="notas" class="form-control form-control-sm" maxlength="255"></div>
        </div>
        <button class="btn btn-primary w-100 mt-3" id="upBtn" disabled><i class="bi bi-upload"></i> <span><?= et('Subir') ?></span></button>
        <div class="form-text"><?= et('Se guardan en el expediente del paciente con el mismo resguardo que sus estudios.') ?></div>
    </div></form>

    <div class="card mt-3"><div class="card-body small">
        <div class="fw-semibold mb-1"><i class="bi bi-camera2 text-brand"></i> <?= et('Serie fotográfica recomendada') ?></div>
        <?php $tomadas = array_count_values(array_column($fotos, 'vista')); ?>
        <?php foreach (['Intraorales', 'Extraorales'] as $cat): ?>
        <div class="text-muted mt-2 mb-1"><?= et($cat) ?></div>
        <?php foreach ($vistas[$cat] as $v): if ($v === 'Detalle de pieza' || $v === '3/4') continue; ?>
            <div><i class="bi <?= !empty($tomadas[$v]) ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?>"></i> <?= et($v) ?><?php if (!empty($tomadas[$v])): ?> <span class="text-muted">(<?= $tomadas[$v] ?>)</span><?php endif; ?></div>
        <?php endforeach; endforeach; ?>
    </div></div>
</div>

<div class="col-12 col-xl-8">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <div class="btn-group btn-group-sm" id="fEt">
            <button type="button" class="btn btn-outline-secondary active" data-f=""><?= et('Todas') ?> (<?= count($fotos) ?>)</button>
            <?php foreach ($etapas as $k => $l): if (empty($cuentaEtapa[$k])) continue; ?><button type="button" class="btn btn-outline-secondary" data-f="<?= $k ?>"><?= et($l) ?> (<?= $cuentaEtapa[$k] ?>)</button><?php endforeach; ?>
        </div>
        <select class="form-select form-select-sm w-auto" id="fCat"><option value=""><?= et('Todas las vistas') ?></option><?php foreach (array_keys($vistas) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select>
        <?php if (count($fotos) >= 2): ?>
        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="cmpBtn"><i class="bi bi-layout-split"></i> <?= et('Comparar antes / después') ?></button> <?= ayuda_btn('dental.comparar') ?>
        <?php endif; ?>
    </div>
    <div class="alert alert-primary py-2 small" id="cmpHint" hidden><i class="bi bi-info-circle"></i> <?= et('Elige dos fotos: primero el "antes" y luego el "después".') ?></div>

    <?php if (!$fotos): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-images fs-1 d-block mb-2"></i><?= et('Aún no hay fotos clínicas. Sube la serie inicial para documentar el caso.') ?></div></div>
    <?php endif; ?>

    <div id="gal">
    <?php $idx = 0; foreach ($porFecha as $fecha => $lista): ?>
        <div class="ph-day" data-day><i class="bi bi-calendar3"></i> <?= e(fmt_fecha($fecha)) ?> <span class="text-muted fw-normal small"><?= count($lista) ?> <?= et('fotos') ?></span></div>
        <div class="ph-grid">
        <?php foreach ($lista as $f): $src = dental_foto_url((int) $f['archivo_id']); ?>
            <div class="ph" data-i="<?= $idx++ ?>" data-id="<?= (int) $f['id'] ?>" data-src="<?= e($src) ?>" data-et="<?= e($f['etapa']) ?>" data-cat="<?= e($catDe[$f['vista']] ?? 'Otras') ?>"
                 data-vista="<?= e(t($f['vista'])) ?>" data-fecha="<?= e(fmt_fecha($f['fecha'])) ?>" data-etl="<?= e(t($etapas[$f['etapa']] ?? $f['etapa'])) ?>"
                 data-diente="<?= e((string) $f['diente']) ?>" data-notas="<?= e((string) $f['notas']) ?>" data-fraw="<?= e($f['fecha']) ?>" data-vraw="<?= e($f['vista']) ?>">
                <img src="<?= e($src) ?>" alt="<?= e($f['vista']) ?>" loading="lazy">
                <span class="badge bg-<?= $etColor[$f['etapa']] ?? 'secondary' ?> et"><?= e(t($etapas[$f['etapa']] ?? $f['etapa'])) ?></span>
                <span class="ck"><i class="bi bi-check-lg"></i></span>
                <div class="cap"><?= e(t($f['vista'])) ?><?= $f['diente'] ? ' · ' . e($f['diente']) : '' ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
</div>

<!-- Visor -->
<div class="modal fade" id="vwModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content vw border-0">
    <div class="modal-header border-0 text-white py-2">
        <div><div class="fw-semibold" id="vwT"></div><div class="small opacity-75" id="vwS"></div></div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="position-relative">
        <img id="vwImg" alt="">
        <button type="button" class="btn btn-light btn-sm position-absolute top-50 start-0 translate-middle-y ms-2 rounded-circle" id="vwPrev"><i class="bi bi-chevron-left"></i></button>
        <button type="button" class="btn btn-light btn-sm position-absolute top-50 end-0 translate-middle-y me-2 rounded-circle" id="vwNext"><i class="bi bi-chevron-right"></i></button>
    </div>
    <form method="post" class="modal-footer border-0 justify-content-between flex-wrap gap-2 py-2" id="vwForm">
        <?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" id="vwId">
        <div class="d-flex flex-wrap gap-2 flex-grow-1">
            <select name="vista" id="vwVista" class="form-select form-select-sm w-auto"><?php foreach ($vistas as $cat => $vs): ?><optgroup label="<?= e(t($cat)) ?>"><?php foreach ($vs as $v): ?><option value="<?= e($v) ?>"><?= et($v) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
            <select name="etapa" id="vwEtapa" class="form-select form-select-sm w-auto"><?php foreach ($etapas as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select>
            <input type="date" name="fecha" id="vwFecha" class="form-control form-control-sm w-auto">
            <input type="text" name="diente" id="vwDiente" class="form-control form-control-sm" style="width:90px" placeholder="<?= e(t('Pieza')) ?>">
            <input type="text" name="notas" id="vwNotas" class="form-control form-control-sm" style="min-width:160px;flex:1" placeholder="<?= e(t('Nota')) ?>" maxlength="255">
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-light" id="vwDl" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>
            <button class="btn btn-sm btn-outline-danger" name="accion" value="borrar" onclick="return confirm('<?= e(t('¿Eliminar esta foto?')) ?>')"><i class="bi bi-trash"></i></button>
            <button class="btn btn-sm btn-primary" name="accion" value="editar"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
        </div>
    </form>
</div></div></div>

<!-- Comparador -->
<div class="modal fade" id="cmpModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content vw border-0">
    <div class="modal-header border-0 text-white py-2">
        <div class="fw-semibold"><i class="bi bi-layout-split"></i> <?= et('Antes / después') ?></div>
        <div class="btn-group btn-group-sm ms-3"><button type="button" class="btn btn-outline-light active" data-cm="slider"><?= et('Deslizador') ?></button><button type="button" class="btn btn-outline-light" data-cm="lado"><?= et('Lado a lado') ?></button></div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div id="cmpSlider" class="cmp">
        <img id="cmpA" alt="">
        <div class="top" id="cmpTop"><img id="cmpB" alt=""></div>
        <div class="bar" id="cmpBar"></div>
        <span class="lb" style="left:.6rem" id="cmpLA"></span><span class="lb" style="right:.6rem" id="cmpLB"></span>
    </div>
    <div id="cmpLado" class="row g-1 p-1" hidden><div class="col-6 text-center"><img id="cmpA2" class="img-fluid" alt=""><div class="small text-white-50 py-1" id="cmpLA2"></div></div><div class="col-6 text-center"><img id="cmpB2" class="img-fluid" alt=""><div class="small text-white-50 py-1" id="cmpLB2"></div></div></div>
</div></div></div>

<script>
(function () {
    /* ---- Subida: reducir en el navegador y previsualizar ---- */
    var input = document.getElementById('fotosIn'), drop = document.getElementById('drop'), prev = document.getElementById('prev'), btn = document.getElementById('upBtn');
    function reducir(file) {
        return new Promise(function (ok) {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 900 * 1024) return ok(file);
            var img = new Image(), url = URL.createObjectURL(file);
            img.onload = function () {
                var max = 2400, w = img.naturalWidth, h = img.naturalHeight, k = Math.min(1, max / Math.max(w, h));
                var c = document.createElement('canvas'); c.width = Math.round(w * k); c.height = Math.round(h * k);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) { ok(b && b.size < file.size ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', .88);
            };
            img.onerror = function () { ok(file); };
            img.src = url;
        });
    }
    function tomar(files) {
        var list = Array.prototype.filter.call(files, function (f) { return /^image\//.test(f.type); });
        if (!list.length) return;
        btn.disabled = true; btn.querySelector('span').textContent = <?= json_encode(t('Preparando…')) ?>;
        Promise.all(list.map(reducir)).then(function (out) {
            try { var dt = new DataTransfer(); out.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; } catch (e) {}
            prev.innerHTML = '';
            out.forEach(function (f) { var i = document.createElement('img'); i.src = URL.createObjectURL(f); prev.appendChild(i); });
            btn.disabled = false; btn.querySelector('span').textContent = <?= json_encode(t('Subir')) ?> + ' ' + out.length;
        });
    }
    input.addEventListener('change', function () { tomar(input.files); });
    ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (ev) { tomar(ev.dataTransfer.files); });
    document.getElementById('upForm').addEventListener('submit', function () { btn.disabled = true; btn.querySelector('span').textContent = <?= json_encode(t('Subiendo…')) ?>; });

    /* ---- Filtros ---- */
    var fEt = '', fCat = '';
    function filtrar() {
        document.querySelectorAll('#gal .ph').forEach(function (p) { p.hidden = (fEt && p.dataset.et !== fEt) || (fCat && p.dataset.cat !== fCat); });
        document.querySelectorAll('#gal [data-day]').forEach(function (h) {
            var g = h.nextElementSibling; h.hidden = g.hidden = !g.querySelector('.ph:not([hidden])');
        });
    }
    document.querySelectorAll('#fEt button').forEach(function (b) { b.addEventListener('click', function () {
        document.querySelectorAll('#fEt button').forEach(function (x) { x.classList.toggle('active', x === b); }); fEt = b.dataset.f; filtrar(); }); });
    document.getElementById('fCat').addEventListener('change', function () { fCat = this.value; filtrar(); });

    /* ---- Visor ---- */
    var fotos = Array.prototype.slice.call(document.querySelectorAll('#gal .ph')), cur = 0, cmp = false, picks = [];
    var vw = document.getElementById('vwModal'), vwM = vw && window.bootstrap ? new bootstrap.Modal(vw) : null;
    function visibles() { return fotos.filter(function (p) { return !p.hidden; }); }
    function mostrar(p) {
        var d = p.dataset; cur = visibles().indexOf(p);
        document.getElementById('vwImg').src = d.src; document.getElementById('vwDl').href = d.src;
        document.getElementById('vwT').textContent = d.vista + (d.diente ? ' · ' + d.diente : '');
        document.getElementById('vwS').textContent = d.fecha + ' · ' + d.etl + (d.notas ? ' · ' + d.notas : '');
        document.getElementById('vwId').value = d.id; document.getElementById('vwVista').value = d.vraw; document.getElementById('vwEtapa').value = d.et;
        document.getElementById('vwFecha').value = d.fraw; document.getElementById('vwDiente').value = d.diente; document.getElementById('vwNotas').value = d.notas;
    }
    function mover(k) { var v = visibles(); if (!v.length) return; mostrar(v[(cur + k + v.length) % v.length]); }
    document.getElementById('vwPrev').addEventListener('click', function () { mover(-1); });
    document.getElementById('vwNext').addEventListener('click', function () { mover(1); });
    vw.addEventListener('keydown', function (ev) { if (ev.target.matches('input,select')) return; if (ev.key === 'ArrowLeft') mover(-1); if (ev.key === 'ArrowRight') mover(1); });

    /* ---- Comparador ---- */
    var cmpBtn = document.getElementById('cmpBtn'), gal = document.getElementById('gal');
    var cm = document.getElementById('cmpModal'), cmM = cm && window.bootstrap ? new bootstrap.Modal(cm) : null;
    if (cmpBtn) cmpBtn.addEventListener('click', function () {
        cmp = !cmp; picks = []; gal.classList.toggle('cmp-on', cmp); cmpBtn.classList.toggle('active', cmp);
        document.getElementById('cmpHint').hidden = !cmp;
        fotos.forEach(function (p) { p.classList.remove('pick'); });
    });
    function abrirCmp(a, b) {
        var A = a.dataset, B = b.dataset;
        ['cmpA', 'cmpA2'].forEach(function (i) { document.getElementById(i).src = A.src; });
        ['cmpB', 'cmpB2'].forEach(function (i) { document.getElementById(i).src = B.src; });
        var la = A.fecha + ' · ' + A.etl, lb = B.fecha + ' · ' + B.etl;
        document.getElementById('cmpLA').textContent = document.getElementById('cmpLA2').textContent = la;
        document.getElementById('cmpLB').textContent = document.getElementById('cmpLB2').textContent = lb;
        pos(50); cmM && cmM.show();
    }
    var box = document.getElementById('cmpSlider'), top = document.getElementById('cmpTop'), bar = document.getElementById('cmpBar');
    function pos(pc) { pc = Math.max(0, Math.min(100, pc)); top.style.clipPath = 'inset(0 0 0 ' + pc + '%)'; bar.style.left = pc + '%'; }
    function mov(ev) { var r = box.getBoundingClientRect(), x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left; pos(x * 100 / r.width); }
    var arrastra = false;
    box.addEventListener('mousedown', function (ev) { arrastra = true; mov(ev); });
    window.addEventListener('mouseup', function () { arrastra = false; });
    box.addEventListener('mousemove', function (ev) { if (arrastra) mov(ev); });
    box.addEventListener('touchstart', mov, { passive: true }); box.addEventListener('touchmove', mov, { passive: true });
    document.querySelectorAll('[data-cm]').forEach(function (b) { b.addEventListener('click', function () {
        document.querySelectorAll('[data-cm]').forEach(function (x) { x.classList.toggle('active', x === b); });
        document.getElementById('cmpSlider').hidden = b.dataset.cm !== 'slider'; document.getElementById('cmpLado').hidden = b.dataset.cm !== 'lado';
    }); });

    gal.addEventListener('click', function (ev) {
        var p = ev.target.closest('.ph'); if (!p) return;
        if (!cmp) { mostrar(p); vwM && vwM.show(); return; }
        if (picks.indexOf(p) !== -1) { picks.splice(picks.indexOf(p), 1); p.classList.remove('pick'); return; }
        picks.push(p); p.classList.add('pick');
        if (picks.length === 2) { abrirCmp(picks[0], picks[1]); setTimeout(function () { picks.forEach(function (x) { x.classList.remove('pick'); }); picks = []; }, 300); }
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
