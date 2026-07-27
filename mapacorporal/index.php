<?php
/**
 * Mapa corporal interactivo: el médico hace CLIC sobre cualquier punto del
 * cuerpo y el marcador cae exactamente ahí (posición guardada). Cada hallazgo
 * lleva una etiqueta de zona, severidad y nota. Estilo de dashboard clínico.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_mapacorporal_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));
$regiones  = mapa_corporal_regiones();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'add') {
        $region = isset($regiones[$_POST['region'] ?? '']) ? $_POST['region'] : 'general';
        $titulo = trim($_POST['titulo'] ?? '');
        if ($titulo !== '') {
            $sev = in_array($_POST['severidad'] ?? '', ['leve','moderado','grave'], true) ? $_POST['severidad'] : 'moderado';
            $px = ($_POST['pos_x'] ?? '') !== '' ? max(0, min(200, (int) $_POST['pos_x'])) : null;
            $py = ($_POST['pos_y'] ?? '') !== '' ? max(0, min(470, (int) $_POST['pos_y'])) : null;
            db()->prepare('INSERT INTO mapa_corporal_hallazgos (consultorio_id, paciente_id, region, titulo, nota, severidad, pos_x, pos_y, creado_por)
                           VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([tenant_id(), $pid, $region, mb_substr($titulo,0,160), trim($_POST['nota'] ?? '') ?: null, $sev, $px, $py, $u['id']]);
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
        <div class="card"><div class="card-body">
            <div class="mapc-stage">
                <svg viewBox="0 0 200 470" class="mapc-svg" id="mapcSvg" role="img" aria-label="<?= e(t('Cuerpo humano — clic para marcar')) ?>">
                    <defs>
                        <linearGradient id="mapcGrad" x1="0" y1="0" x2="0.4" y2="1">
                            <stop offset="0" class="mapc-g1"/>
                            <stop offset="1" class="mapc-g2"/>
                        </linearGradient>
                    </defs>
                    <g class="mapc-body" fill="url(#mapcGrad)">
                        <circle cx="100" cy="40" r="23"/>
                        <path d="M90 62 C90 74 86 78 78 82 C64 88 56 100 54 116 C52 132 50 152 48 174 C47 188 46 202 46 214 C46 224 50 228 55 226 C59 222 61 210 62 198 C64 178 65 158 67 142 C68 134 70 130 74 128 C75 152 75 182 74 208 C73 228 75 248 81 264 C79 302 77 352 75 400 C75 414 73 424 75 428 C77 434 88 434 90 428 C92 416 92 404 92 392 C93 344 93 304 94 276 L100 272 L106 276 C107 304 107 344 108 392 C108 404 108 416 110 428 C112 434 123 434 125 428 C127 424 125 414 125 400 C123 352 121 302 119 264 C125 248 127 228 126 208 C125 182 125 152 126 128 C130 130 132 134 133 142 C135 158 136 178 138 198 C139 210 141 222 145 226 C150 228 154 224 154 214 C154 202 153 188 152 174 C150 152 148 132 146 116 C144 100 136 88 122 82 C114 78 110 74 110 62 Z"/>
                    </g>

                    <!-- Hallazgos existentes (en su posición exacta) -->
                    <?php foreach ($hall as $h): if ($h['pos_x'] === null || $h['pos_y'] === null) continue;
                        $col = $sevColor[$h['severidad']] ?? '#f59e0b'; ?>
                        <g class="mapc-pin" title="<?= e($h['titulo']) ?>">
                            <circle class="mapc-halo" cx="<?= (int)$h['pos_x'] ?>" cy="<?= (int)$h['pos_y'] ?>" r="10" fill="<?= $col ?>" opacity="0.20"/>
                            <circle cx="<?= (int)$h['pos_x'] ?>" cy="<?= (int)$h['pos_y'] ?>" r="6" fill="<?= $col ?>" stroke="#fff" stroke-width="1.5"/>
                        </g>
                    <?php endforeach; ?>

                    <!-- Marcador temporal (donde haces clic) -->
                    <g id="ghost" style="display:none">
                        <circle id="ghostHalo" cx="0" cy="0" r="11" fill="var(--brand,#2563eb)" opacity="0.18"/>
                        <circle id="ghostDot"  cx="0" cy="0" r="6" fill="var(--brand,#2563eb)" stroke="#fff" stroke-width="1.5"/>
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
                <li class="list-group-item d-flex align-items-start gap-3">
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
.mapc-svg { width:100%; max-width:300px; height:auto; cursor:crosshair; filter:drop-shadow(0 10px 18px rgba(15,23,42,.12)); }
.mapc-g1 { stop-color:color-mix(in srgb, var(--brand,#2563eb) 24%, #fff); }
.mapc-g2 { stop-color:color-mix(in srgb, var(--brand,#2563eb) 7%, #fff); }
.mapc-body { stroke:color-mix(in srgb, var(--brand,#2563eb) 30%, #fff); stroke-width:1.4; }
.mapc-body:hover { stroke:color-mix(in srgb, var(--brand,#2563eb) 45%, #fff); }
.mapc-halo { transform-box:fill-box; transform-origin:center; animation:mapcPulse 1.6s ease-out infinite; }
@keyframes mapcPulse { 0%{opacity:.28} 70%{opacity:.05} 100%{opacity:.28} }
.mapc-leg { display:inline-block; width:11px; height:11px; border-radius:50%; }
html.app-dark .mapc-g1 { stop-color:color-mix(in srgb, var(--brand,#2563eb) 34%, #0b1220); }
html.app-dark .mapc-g2 { stop-color:color-mix(in srgb, var(--brand,#2563eb) 14%, #0b1220); }
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
<?php include __DIR__ . '/../includes/footer.php'; ?>
