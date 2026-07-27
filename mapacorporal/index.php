<?php
/**
 * Mapa corporal interactivo: el médico marca hallazgos sobre un cuerpo (SVG).
 * Cada zona con hallazgos muestra un marcador de color según su severidad; al
 * hacer clic se prellenа el formulario para agregar uno nuevo. Estilo "pro"
 * inspirado en dashboards clínicos modernos.
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
        $region = $_POST['region'] ?? '';
        $titulo = trim($_POST['titulo'] ?? '');
        if (isset($regiones[$region]) && $titulo !== '') {
            $sev = in_array($_POST['severidad'] ?? '', ['leve','moderado','grave'], true) ? $_POST['severidad'] : 'moderado';
            db()->prepare('INSERT INTO mapa_corporal_hallazgos (consultorio_id, paciente_id, region, titulo, nota, severidad, creado_por)
                           VALUES (?,?,?,?,?,?,?)')
                ->execute([tenant_id(), $pid, $region, mb_substr($titulo,0,160), trim($_POST['nota'] ?? '') ?: null, $sev, $u['id']]);
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

$hall = db()->prepare('SELECT * FROM mapa_corporal_hallazgos WHERE paciente_id = ? AND consultorio_id = ? AND activo = 1 ORDER BY creado_en DESC');
$hall->execute([$pid, tenant_id()]);
$hall = $hall->fetchAll();

// Agrupar por región + severidad peor por zona (para el color del marcador).
$sevRank = ['leve'=>1,'moderado'=>2,'grave'=>3];
$sevColor = ['leve'=>'#22c55e','moderado'=>'#f59e0b','grave'=>'#ef4444'];
$porRegion = [];
foreach ($hall as $h) { $porRegion[$h['region']][] = $h; }
$marcadores = [];
foreach ($porRegion as $reg => $list) {
    $peor = 'leve';
    foreach ($list as $h) { if ($sevRank[$h['severidad']] > $sevRank[$peor]) $peor = $h['severidad']; }
    $marcadores[$reg] = ['n' => count($list), 'sev' => $peor];
}

$titulo = t('Mapa corporal');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-person-bounding-box text-brand"></i> <?= et('Mapa corporal') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong> · <?= et('Toca una zona del cuerpo para marcar un hallazgo.') ?></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<div class="row g-4">
    <!-- Cuerpo interactivo -->
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <div class="mapc-stage">
                <svg viewBox="0 0 200 440" class="mapc-svg" role="img" aria-label="<?= e(t('Cuerpo humano')) ?>">
                    <!-- Silueta -->
                    <g class="mapc-body">
                        <circle cx="100" cy="40" r="24"/>
                        <path d="M88 62 h24 v10 q14 3 20 14 l6 40 q3 16 -3 30 l-8 -4 -4 44 -6 60 q6 60 5 96 q-1 10 -11 10 q-9 0 -11 -10 l-4 -84 h-4 l-4 84 q-2 10 -11 10 q-10 0 -11 -10 q-1 -36 5 -96 l-6 -60 -4 -44 -8 4 q-6 -14 -3 -30 l6 -40 q6 -11 20 -14 z"/>
                        <path d="M68 82 q-16 4 -20 20 l-14 74 q-2 10 6 12 q8 2 11 -8 l14 -70 z"/>
                        <path d="M132 82 q16 4 20 20 l14 74 q2 10 -6 12 q-8 2 -11 -8 l-14 -70 z"/>
                    </g>
                    <!-- Marcadores por zona -->
                    <?php foreach ($regiones as $clave => [$lbl, $x, $y]):
                        $m = $marcadores[$clave] ?? null; ?>
                        <g class="mapc-mark<?= $m ? ' has' : '' ?>" data-region="<?= e($clave) ?>" data-label="<?= e($lbl) ?>"
                           tabindex="0" role="button" aria-label="<?= e($lbl) ?>">
                            <?php if ($m): ?>
                                <circle class="mapc-halo" cx="<?= $x ?>" cy="<?= $y ?>" r="9" fill="<?= $sevColor[$m['sev']] ?>" opacity="0.22"/>
                                <circle cx="<?= $x ?>" cy="<?= $y ?>" r="6" fill="<?= $sevColor[$m['sev']] ?>"/>
                                <text x="<?= $x ?>" y="<?= $y + 3.2 ?>" text-anchor="middle" font-size="7.5" font-weight="700" fill="#fff"><?= (int) $m['n'] ?></text>
                            <?php else: ?>
                                <circle class="mapc-dot" cx="<?= $x ?>" cy="<?= $y ?>" r="5"/>
                                <line class="mapc-plus" x1="<?= $x - 2.2 ?>" y1="<?= $y ?>" x2="<?= $x + 2.2 ?>" y2="<?= $y ?>"/>
                                <line class="mapc-plus" x1="<?= $x ?>" y1="<?= $y - 2.2 ?>" x2="<?= $x ?>" y2="<?= $y + 2.2 ?>"/>
                            <?php endif; ?>
                        </g>
                    <?php endforeach; ?>
                </svg>
            </div>
            <div class="d-flex justify-content-center gap-3 small text-muted mt-2">
                <span><span class="mapc-leg" style="background:#22c55e"></span> <?= et('Leve') ?></span>
                <span><span class="mapc-leg" style="background:#f59e0b"></span> <?= et('Moderado') ?></span>
                <span><span class="mapc-leg" style="background:#ef4444"></span> <?= et('Grave') ?></span>
            </div>
        </div></div>
    </div>

    <!-- Formulario + lista -->
    <div class="col-lg-7">
        <div class="card mb-4" id="formCard"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Agregar hallazgo') ?> <span id="regLbl" class="text-muted fw-normal"></span></h2>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="accion" value="add"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
                <div class="col-md-5"><label class="form-label small"><?= et('Zona') ?></label>
                    <select name="region" id="selRegion" class="form-select" required>
                        <?php foreach ($regiones as $clave => [$lbl]): ?><option value="<?= e($clave) ?>"><?= e($lbl) ?></option><?php endforeach; ?>
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
            </form>
        </div></div>

        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Hallazgos') ?> (<?= count($hall) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$hall): ?>
                    <li class="list-group-item text-muted text-center py-4"><?= et('Sin hallazgos. Toca una zona del cuerpo para empezar.') ?></li>
                <?php else: foreach ($hall as $h): ?>
                <li class="list-group-item d-flex align-items-start gap-3">
                    <span class="mapc-leg mt-1" style="background:<?= $sevColor[$h['severidad']] ?>"></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= e($h['titulo']) ?> <span class="badge bg-light text-dark border ms-1"><?= e($regiones[$h['region']][0] ?? $h['region']) ?></span></div>
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
.mapc-svg { width:100%; max-width:320px; height:auto; }
.mapc-body { fill:color-mix(in srgb, var(--brand,#2563eb) 13%, #fff); stroke:color-mix(in srgb, var(--brand,#2563eb) 32%, #fff); stroke-width:1.5; }
.mapc-dot  { fill:#fff; stroke:color-mix(in srgb, var(--brand,#2563eb) 48%, #fff); stroke-width:1.5; transition:fill .15s ease; }
.mapc-plus { stroke:var(--brand,#2563eb); stroke-width:1.3; }
.mapc-mark { cursor:pointer; outline:none; }
.mapc-mark:hover .mapc-dot, .mapc-mark:focus .mapc-dot { fill:color-mix(in srgb, var(--brand,#2563eb) 20%, #fff); }
.mapc-halo { transform-box:fill-box; transform-origin:center; animation:mapcPulse 1.6s ease-out infinite; }
@keyframes mapcPulse { 0%{opacity:.30} 70%{opacity:.05} 100%{opacity:.30} }
.mapc-leg { display:inline-block; width:11px; height:11px; border-radius:50%; }
html.app-dark .mapc-body { fill:color-mix(in srgb, var(--brand,#2563eb) 24%, #0b1220); stroke:color-mix(in srgb, var(--brand,#2563eb) 45%, #0b1220); }
html.app-dark .mapc-dot { fill:#0b1220; }
</style>
<script>
(function () {
    var sel = document.getElementById('selRegion');
    var lbl = document.getElementById('regLbl');
    function setRegion(reg, label) {
        if (sel) sel.value = reg;
        if (lbl) lbl.textContent = label ? '· ' + label : '';
    }
    document.querySelectorAll('.mapc-mark').forEach(function (g) {
        function pick() {
            setRegion(g.dataset.region, g.dataset.label);
            document.getElementById('inpTitulo').focus();
            document.getElementById('formCard').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        g.addEventListener('click', pick);
        g.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
    });
    if (sel) sel.addEventListener('change', function () { lbl.textContent = '· ' + sel.options[sel.selectedIndex].text; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
