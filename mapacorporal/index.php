<?php
/**
 * Mapa corporal interactivo: el médico hace CLIC sobre cualquier punto del
 * cuerpo y el marcador cae exactamente ahí (posición guardada). Cada hallazgo
 * lleva una etiqueta de zona, severidad y nota. Estilo de dashboard clínico.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die(t('Esta sección es solo para el personal clínico y el administrador.')); }
ensure_mapacorporal_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));
$regiones  = mapa_corporal_regiones();

/* Ilustración anatómica (CC0, Wikimedia) según el sexo del paciente. El SVG va
   de fondo y encima se captura el clic. Dimensiones intrínsecas de cada archivo
   para que las coordenadas del clic/marcador coincidan. */
$esF     = strncasecmp((string) ($pac['sexo'] ?? ''), 'F', 1) === 0;
$bodyImg = $esF ? 'assets/img/cuerpo_m.svg' : 'assets/img/cuerpo_h.svg';
$bw      = $esF ? 1387 : 1363;
$bh      = $esF ? 2598 : 1234;
$mr      = max(9, (int) round($bw * 0.016));   // radio del punto del marcador
$mh      = (int) round($mr * 1.7);             // radio del halo
$msw     = max(2, (int) round($bw * 0.004));   // grosor del borde del marcador

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'add') {
        $region = isset($regiones[$_POST['region'] ?? '']) ? $_POST['region'] : 'general';
        $titulo = trim($_POST['titulo'] ?? '');
        if ($titulo !== '') {
            $sev = in_array($_POST['severidad'] ?? '', ['leve','moderado','grave'], true) ? $_POST['severidad'] : 'moderado';
            $px = ($_POST['pos_x'] ?? '') !== '' ? max(0, min($bw, (int) $_POST['pos_x'])) : null;
            $py = ($_POST['pos_y'] ?? '') !== '' ? max(0, min($bh, (int) $_POST['pos_y'])) : null;
            $p3 = fn ($k, $min, $max) => ($_POST[$k] ?? '') !== '' && is_numeric($_POST[$k]) ? max($min, min($max, round((float) $_POST[$k], 3))) : null;
            $x3 = $p3('pos3_x', -1, 1); $y3 = $p3('pos3_y', 0, 2); $z3 = $p3('pos3_z', -1, 1);
            try {
                db()->prepare('INSERT INTO mapa_corporal_hallazgos (consultorio_id, paciente_id, region, titulo, nota, severidad, pos_x, pos_y, pos3_x, pos3_y, pos3_z, creado_por)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([tenant_id(), $pid, $region, mb_substr($titulo,0,160), trim($_POST['nota'] ?? '') ?: null, $sev, $px, $py, $x3, $y3, $z3, $u['id']]);
            } catch (Throwable $e) {   // sin columnas 3D todavía
                db()->prepare('INSERT INTO mapa_corporal_hallazgos (consultorio_id, paciente_id, region, titulo, nota, severidad, pos_x, pos_y, creado_por)
                               VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([tenant_id(), $pid, $region, mb_substr($titulo,0,160), trim($_POST['nota'] ?? '') ?: null, $sev, $px, $py, $u['id']]);
            }
            auditar('crear', 'mapa_corporal', (int) db()->lastInsertId(), $region . ' · Paciente #' . $pid);
            flash('Hallazgo agregado al mapa corporal.');
        }
        redirect('/mapacorporal/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('UPDATE mapa_corporal_hallazgos SET activo = 0 WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Hallazgo quitado.');
        redirect('/mapacorporal/index?paciente_id=' . $pid);
    }
}

try {
    $hall = db()->prepare('SELECT * FROM mapa_corporal_hallazgos WHERE paciente_id = ? AND consultorio_id = ? AND activo = 1 ORDER BY creado_en DESC');
    $hall->execute([$pid, tenant_id()]);
    $hall = $hall->fetchAll();
} catch (Throwable $e) { $hall = []; }

$sevColor = ['leve'=>'#22c55e','moderado'=>'#f59e0b','grave'=>'#ef4444'];

$titulo = t('Mapa corporal');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-person-bounding-box text-brand"></i> <?= et('Mapa corporal') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong> · <?= et('Haz clic sobre el cuerpo, en el punto exacto del hallazgo.') ?></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mapc-card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active" data-mv="3d"><i class="bi bi-badge-3d"></i> 3D</button>
                    <button type="button" class="btn btn-outline-secondary" data-mv="2d"><i class="bi bi-person"></i> <?= et('Ilustración') ?></button>
                </div>
                <div class="d-flex gap-1" id="c3dTools">
                    <select class="form-select form-select-sm w-auto" id="c3dModo" title="<?= e(t('Apariencia')) ?>">
                        <option value="piel"><?= et('Piel') ?></option><option value="anatomia"><?= et('Anatomía') ?></option><option value="holograma"><?= et('Holograma') ?></option>
                    </select>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-light" data-c3v="frente" title="<?= e(t('Frente')) ?>"><?= et('Frente') ?></button>
                        <button type="button" class="btn btn-light" data-c3v="espalda" title="<?= e(t('Espalda')) ?>"><?= et('Espalda') ?></button>
                        <button type="button" class="btn btn-light" data-c3v="derecha" title="<?= e(t('Lado')) ?>"><i class="bi bi-arrow-left-right"></i></button>
                        <button type="button" class="btn btn-light" data-c3v="reiniciar" title="<?= e(t('Girar')) ?>"><i class="bi bi-arrow-repeat"></i></button>
                    </div>
                </div>
            </div>
            <div id="c3d" class="c3d-stage">
                <div class="c3d-cargando"><div class="spinner-border text-primary"></div><div class="small mt-2"><?= et('Preparando el modelo 3D…') ?></div></div>
            </div>
            <div class="small text-muted text-center mt-1 c3d-hint"><i class="bi bi-hand-index"></i> <?= et('Arrastra para girar, rueda para acercar. Toca el cuerpo para marcar el hallazgo.') ?></div>
            <div class="mapc-stage" id="mapc2d" hidden>
                <svg viewBox="0 0 <?= $bw ?> <?= $bh ?>" class="mapc-svg" id="mapcSvg" role="img" aria-label="<?= e(t('Cuerpo humano — clic para marcar')) ?>">
                    <image class="mapc-img" href="<?= asset($bodyImg) ?>" x="0" y="0" width="<?= $bw ?>" height="<?= $bh ?>" preserveAspectRatio="xMidYMid meet"/>

                    <!-- Hallazgos existentes (en su posición exacta) -->
                    <?php foreach ($hall as $h): if ($h['pos_x'] === null || $h['pos_y'] === null) continue;
                        $col = $sevColor[$h['severidad']] ?? '#f59e0b'; ?>
                        <g class="mapc-pin" title="<?= e($h['titulo']) ?>">
                            <circle class="mapc-halo" cx="<?= (int)$h['pos_x'] ?>" cy="<?= (int)$h['pos_y'] ?>" r="<?= $mh ?>" fill="<?= $col ?>" opacity="0.20"/>
                            <circle cx="<?= (int)$h['pos_x'] ?>" cy="<?= (int)$h['pos_y'] ?>" r="<?= $mr ?>" fill="<?= $col ?>" stroke="#fff" stroke-width="<?= $msw ?>"/>
                        </g>
                    <?php endforeach; ?>

                    <!-- Marcador temporal (donde haces clic) -->
                    <g id="ghost" style="display:none">
                        <circle id="ghostHalo" cx="0" cy="0" r="<?= $mh ?>" fill="var(--brand,#2563eb)" opacity="0.18"/>
                        <circle id="ghostDot"  cx="0" cy="0" r="<?= $mr ?>" fill="var(--brand,#2563eb)" stroke="#fff" stroke-width="<?= $msw ?>"/>
                    </g>
                </svg>
            </div>
            <div class="d-flex justify-content-center gap-3 small text-muted mt-2">
                <span><span class="mapc-leg" style="background:#22c55e"></span> <?= et('Leve') ?></span>
                <span><span class="mapc-leg" style="background:#f59e0b"></span> <?= et('Moderado') ?></span>
                <span><span class="mapc-leg" style="background:#ef4444"></span> <?= et('Grave') ?></span>
            </div>
        </div></div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-4" id="formCard"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Agregar hallazgo') ?> <span id="posLbl" class="text-muted fw-normal small"></span></h2>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="accion" value="add"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
                <input type="hidden" name="pos_x" id="posX"><input type="hidden" name="pos_y" id="posY">
                <input type="hidden" name="pos3_x" id="pos3X"><input type="hidden" name="pos3_y" id="pos3Y"><input type="hidden" name="pos3_z" id="pos3Z">
                <div class="col-md-5"><label class="form-label small"><?= et('Zona (etiqueta)') ?></label>
                    <select name="region" id="selRegion" class="form-select">
                        <?php foreach ($regiones as $clave => $lbl): ?><option value="<?= e($clave) ?>"<?= $clave==='general'?' selected':'' ?>><?= e($lbl) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label small"><?= et('Severidad') ?></label>
                    <select name="severidad" class="form-select">
                        <option value="leve"><?= et('Leve') ?></option><option value="moderado" selected><?= et('Moderado') ?></option><option value="grave"><?= et('Grave') ?></option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= et('Agregar') ?></button></div>
                <div class="col-12"><label class="form-label small"><?= et('Hallazgo / diagnóstico') ?></label><input type="text" name="titulo" id="inpTitulo" class="form-control" required maxlength="160" placeholder="<?= e(t('Ej. Soplo cardiaco, dolor…')) ?>"></div>
                <div class="col-12"><label class="form-label small"><?= et('Nota') ?></label><input type="text" name="nota" class="form-control" maxlength="255"></div>
                <div class="col-12"><div class="small text-muted"><i class="bi bi-info-circle"></i> <?= et('Tip: primero haz clic en el cuerpo para fijar el punto; si no, se guarda sin ubicación.') ?></div></div>
            </form>
        </div></div>

        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Hallazgos') ?> (<?= count($hall) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$hall): ?>
                    <li class="list-group-item text-muted text-center py-4"><?= et('Sin hallazgos. Haz clic en el cuerpo para empezar.') ?></li>
                <?php else: foreach ($hall as $h): ?>
                <li class="list-group-item d-flex align-items-start gap-3 mapc-li" data-id="<?= (int) $h['id'] ?>">
                    <span class="mapc-leg mt-1" style="background:<?= $sevColor[$h['severidad']] ?? '#f59e0b' ?>"></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= e($h['titulo']) ?> <span class="badge bg-light text-dark border ms-1"><?= e($regiones[$h['region']] ?? $h['region']) ?></span></div>
                        <?php if ($h['nota']): ?><div class="small text-muted"><?= e($h['nota']) ?></div><?php endif; ?>
                        <div class="small text-muted"><?= e(fmt_fecha($h['creado_en'])) ?> · <?= et(ucfirst($h['severidad'])) ?></div>
                    </div>
                    <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Quitar este hallazgo?')) ?>');">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="del"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                    </form>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<style>
.mapc-stage { display:flex; justify-content:center; padding:.5rem; }
.mapc-stage[hidden] { display:none; }
.mapc-svg { width:100%; max-width:320px; max-height:560px; height:auto; cursor:crosshair;
            filter:drop-shadow(0 10px 18px rgba(15,23,42,.10)); }
.mapc-img { opacity:.9; }
html.app-dark .mapc-img { filter:invert(.9) hue-rotate(180deg); opacity:.85; }
.mapc-halo { transform-box:fill-box; transform-origin:center; animation:mapcPulse 1.6s ease-out infinite; }
@keyframes mapcPulse { 0%{opacity:.28} 70%{opacity:.05} 100%{opacity:.28} }
.mapc-leg { display:inline-block; width:11px; height:11px; border-radius:50%; }
.c3d-stage { position:relative; height:min(68vh,600px); border-radius:18px; overflow:hidden;
    background: radial-gradient(ellipse at 50% 40%, #ffffff 0%, #eef0ff 55%, #e4e2fb 100%); }
html.app-dark .c3d-stage { background: radial-gradient(ellipse at 50% 40%, #2a2f45 0%, #1c2033 60%, #151826 100%); }
.c3d-stage canvas { display:block; touch-action:none; }
.c3d-cargando { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#64748b; }
.c3d-capa { position:absolute; inset:0; pointer-events:none; overflow:hidden; }
.c3d-et { position:absolute; left:0; top:0; pointer-events:auto; border:0; background:rgba(255,255,255,.95); color:#1e293b; border-radius:99px;
    padding:.22rem .65rem .22rem .45rem; font-size:.74rem; font-weight:600; box-shadow:0 6px 18px rgba(30,27,75,.18);
    display:flex; gap:.4rem; align-items:center; white-space:nowrap; transition:opacity .2s; }
.c3d-et span { width:9px; height:9px; border-radius:50%; flex:none; }
.c3d-et.on { outline:2px solid #2563eb; }
.c3d-et.fant { background:#2563eb; color:#fff; }
.c3d-tip { position:absolute; pointer-events:none; background:#0f172a; color:#fff; font-size:.72rem; padding:.15rem .5rem; border-radius:6px; opacity:0; transition:opacity .15s; white-space:nowrap; }
.mapc-li.on { background: color-mix(in srgb, var(--brand,#2563eb) 8%, transparent); }
</style>
<script>
(function () {
    var svg = document.getElementById('mapcSvg');
    var ghost = document.getElementById('ghost');
    var gh = document.getElementById('ghostHalo'), gd = document.getElementById('ghostDot');
    var posLbl = document.getElementById('posLbl');
    if (!svg) return;
    svg.addEventListener('click', function (e) {
        var pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY;
        var m = svg.getScreenCTM(); if (!m) return;
        var loc = pt.matrixTransform(m.inverse());
        var x = Math.round(loc.x), y = Math.round(loc.y);
        document.getElementById('posX').value = x;
        document.getElementById('posY').value = y;
        [gh, gd].forEach(function (c) { c.setAttribute('cx', x); c.setAttribute('cy', y); });
        ghost.style.display = '';
        if (posLbl) posLbl.textContent = '· ' + '<?= e(t('punto fijado')) ?>';
        var t = document.getElementById('inpTitulo'); if (t) t.focus();
        document.getElementById('formCard').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
})();
</script>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"}}</script>
<script type="module" src="<?= asset('assets/js/cuerpo3d.js') ?>"></script>
<script>
(function () {
    var REG = <?= json_encode($regiones, JSON_UNESCAPED_UNICODE) ?>;
    var SEV = <?= json_encode($sevColor) ?>;
    var PINS = <?= json_encode(array_values(array_map(fn ($h) => ['id' => (int) $h['id'], 'x' => $h['pos3_x'] ?? null, 'y' => $h['pos3_y'] ?? null, 'z' => $h['pos3_z'] ?? null,
        'color' => $sevColor[$h['severidad']] ?? '#f59e0b', 'titulo' => ($regiones[$h['region']] ?? $h['region']) . ' · ' . $h['titulo']],
        array_filter($hall, fn ($h) => isset($h['pos3_x']) && $h['pos3_x'] !== null))), JSON_UNESCAPED_UNICODE) ?>;
    var cont = document.getElementById('c3d'), v2 = document.getElementById('mapc2d'), api = null;
    var gl = (function () { try { var c = document.createElement('canvas'); return !!(c.getContext('webgl2') || c.getContext('webgl')); } catch (e) { return false; } })();

    function vista(v) {
        document.querySelectorAll('[data-mv]').forEach(function (b) { b.classList.toggle('active', b.dataset.mv === v); });
        cont.hidden = v !== '3d'; document.querySelector('.c3d-hint').hidden = v !== '3d'; document.getElementById('c3dTools').style.setProperty('display', v === '3d' ? 'flex' : 'none', 'important');
        v2.hidden = v !== '2d';
        try { localStorage.setItem('mapcVista', v); } catch (e) {}
        if (v === '3d') arrancar();
    }
    function arrancar() {
        if (api || !window.Cuerpo3D) return;
        // Se construye en el siguiente cuadro para que se vea el indicador de carga.
        setTimeout(function () {
            api = window.Cuerpo3D.montar(cont, {
                sexo: <?= json_encode($esF ? 'F' : 'M') ?>, modo: (function () { try { return localStorage.getItem('mapcModo') || 'piel'; } catch (e) { return 'piel'; } })(),
                pins: PINS, regiones: REG,
                onPick: function (d) {
                    document.getElementById('pos3X').value = d.x; document.getElementById('pos3Y').value = d.y; document.getElementById('pos3Z').value = d.z;
                    var sel = document.getElementById('selRegion'); if (sel && REG[d.region]) sel.value = d.region;
                    var lbl = document.getElementById('posLbl'); if (lbl) lbl.textContent = '· ' + (REG[d.region] || d.region);
                    var t = document.getElementById('inpTitulo'); if (t) t.focus({ preventScroll: true });
                },
                onPin: function (id) {
                    document.querySelectorAll('.mapc-li').forEach(function (li) { li.classList.toggle('on', +li.dataset.id === id); });
                    var li = document.querySelector('.mapc-li[data-id="' + id + '"]'); if (li) li.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    api.destacar(id);
                }
            });
            var m = document.getElementById('c3dModo'); m.value = (function () { try { return localStorage.getItem('mapcModo') || 'piel'; } catch (e) { return 'piel'; } })();
        }, 30);
    }
    document.querySelectorAll('[data-mv]').forEach(function (b) { b.addEventListener('click', function () { vista(b.dataset.mv); }); });
    document.querySelectorAll('[data-c3v]').forEach(function (b) { b.addEventListener('click', function () { if (!api) return; if (b.dataset.c3v === 'reiniciar') api.reiniciar(); else api.vista(b.dataset.c3v); }); });
    document.getElementById('c3dModo').addEventListener('change', function () { if (api) api.modo(this.value); try { localStorage.setItem('mapcModo', this.value); } catch (e) {} });
    document.querySelectorAll('.mapc-li').forEach(function (li) { li.addEventListener('mouseenter', function () { if (api) api.destacar(+li.dataset.id); }); });

    var pref = 'mapcVista'; var inicial = '3d';
    try { inicial = localStorage.getItem(pref) || '3d'; } catch (e) {}
    if (!gl) { inicial = '2d'; document.querySelector('[data-mv="3d"]').disabled = true; }
    if (inicial === '3d') { if (window.Cuerpo3D) vista('3d'); else { vista('3d'); window.addEventListener('cuerpo3d:listo', arrancar, { once: true }); } }
    else vista('2d');
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
