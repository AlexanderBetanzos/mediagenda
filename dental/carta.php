<?php
/**
 * Carta dental: el odontograma con dientes dibujados (corona y raíz) y su
 * vista oclusal por caras, más una vista de arcada para seleccionar piezas.
 *
 * Trabaja sobre los MISMOS datos del odontograma (odontograma_marcas, capas
 * existente / requerido / realizado), así que el historial, el presupuesto
 * y la vista clásica siguen funcionando igual.
 *
 * Flujo: se elige el modo (Existente · Planeado · Realizado) y un
 * procedimiento del panel; después se toca la cara o el diente. Sin
 * procedimiento armado, tocar un diente lo selecciona para ver su detalle.
 * Abajo, el plan de tratamiento con precios del catálogo de servicios.
 */
require __DIR__ . '/_comun.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = json_decode($_POST['datos'] ?? '{}', true);
    $in = is_array($in) ? $in : [];
    odo_guardar($pid, is_array($in['marcas'] ?? null) ? $in['marcas'] : [], is_array($in['notas'] ?? null) ? $in['notas'] : [],
        mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 120) ?: t('Carta dental'));
    auditar('odontograma', 'paciente', $pid);
    flash(t('Carta dental guardada.'));
    redirect('/dental/carta?paciente_id=' . $pid);
}

$estado = odo_cargar($pid);
$versiones = db()->prepare('SELECT COUNT(*) FROM odontograma_historial WHERE paciente_id = ? AND consultorio_id = ?');
$versiones->execute([$pid, tenant_id()]);
$versiones = (int) $versiones->fetchColumn();

/* Precios del catálogo para el plan: cada tratamiento busca su servicio por nombre. */
$serv = db()->prepare('SELECT nombre, precio FROM servicios WHERE consultorio_id = ? AND activo = 1');
$serv->execute([tenant_id()]);
$porNombre = [];
foreach ($serv as $s) $porNombre[mb_strtolower(trim($s['nombre']))] = (float) $s['precio'];
$busca = function (array $nombres) use ($porNombre) {
    foreach ($nombres as $n) if (isset($porNombre[mb_strtolower($n)]) && $porNombre[mb_strtolower($n)] > 0) return $porNombre[mb_strtolower($n)];
    foreach ($nombres as $n) if (isset($porNombre[mb_strtolower($n)])) return $porNombre[mb_strtolower($n)];
    return null;
};
$precios = [
    'obturacion' => ['def' => $busca(['Obturación', 'Resina', 'Obturación con resina', 'Obturación con amalgama'])],
    'sellante'   => ['def' => $busca(['Sellante', 'Sellador de fosetas'])],
    'endodoncia' => ['def' => $busca(['Endodoncia', 'Endodoncia unirradicular']), 'multi' => $busca(['Endodoncia multirradicular', 'Endodoncia'])],
    'corona'     => ['def' => $busca(['Corona', 'Corona de porcelana', 'Corona de zirconia'])],
    'protesis'   => ['def' => $busca(['Prótesis', 'Puente fijo', 'Prótesis fija', 'Prótesis removible'])],
    'implante'   => ['def' => $busca(['Implante', 'Colocación de implante', 'Implante dental'])],
    'extraccion' => ['def' => $busca(['Extracción', 'Extracción simple']), 'tercer' => $busca(['Extracción de tercer molar', 'Extracción quirúrgica / tercer molar', 'Extracción'])],
];

$vocab = function (array $v) { $o = []; foreach ($v as $k => $d) $o[$k] = $d + ['label_t' => t($d['label'])]; return $o; };
$hall = $vocab(odo_hallazgos());
$trat = $vocab(odo_tratamientos());

$titulo = t('Carta dental') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
dental_encabezado('Carta dental', 'bi-emoji-smile', $pac, $pid, 'carta');
?>
<style>
.cd-wrap{--cd-plan:#e11d48;--cd-done:#2563eb;--cd-sel:#2563eb}
.cd-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
.cd-mode .btn{font-weight:600}
.cd-mode .btn[data-m=existente].active{background:#475569;border-color:#475569;color:#fff}
.cd-mode .btn[data-m=requerido].active{background:var(--cd-plan);border-color:var(--cd-plan);color:#fff}
.cd-mode .btn[data-m=realizado].active{background:var(--cd-done);border-color:var(--cd-done);color:#fff}
.cd-chart{overflow-x:auto;background:linear-gradient(180deg,var(--bs-body-bg),var(--bs-tertiary-bg));border-radius:.75rem}
.cd-chart svg{display:block;min-width:760px;width:100%;height:auto;user-select:none}
.cd-tooth{cursor:pointer}
.cd-tooth .hit{fill:transparent}
.cd-tooth:hover .hit{fill:rgba(37,99,235,.06)}
.cd-tooth.sel .hit{fill:rgba(37,99,235,.13);stroke:var(--cd-sel);stroke-width:1.2}
.cd-face{cursor:pointer;stroke:#94a3b8;stroke-width:.8;transition:filter .12s}
.cd-face:hover{filter:brightness(.9)}
.cd-face.fsel{stroke:var(--cd-sel);stroke-width:2}
.cd-num{font:600 11px system-ui,sans-serif;fill:#64748b;text-anchor:middle}
.cd-tooth.sel .cd-num,.cd-num.sel{fill:var(--cd-sel)}
.cd-arch svg{display:block;width:100%;max-width:560px;height:auto;margin:auto;user-select:none}
.cd-arch .at{cursor:pointer;transition:transform .12s}
.cd-arch .at:hover .ab{filter:brightness(.93)}
.cd-arch .at.sel .ab{fill:url(#gSel);stroke:#1d4ed8}
.cd-arch .an{font:600 12px system-ui,sans-serif;text-anchor:middle;dominant-baseline:middle;fill:#475569}
.cd-arch .anb{fill:#fff;stroke:#cbd5e1}
.cd-arch .at.sel .anb{fill:#2563eb;stroke:#2563eb}.cd-arch .at.sel .an{fill:#fff}
.cd-panel{position:sticky;top:76px}
.cd-list{max-height:330px;overflow:auto}
.cd-cat{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--bs-secondary-color);font-weight:700;padding:.5rem .6rem .2rem}
.cd-it{display:flex;align-items:center;gap:.55rem;padding:.38rem .6rem;border-radius:.45rem;cursor:pointer;font-size:.88rem}
.cd-it:hover{background:var(--bs-tertiary-bg)}
.cd-it.armed{background:color-mix(in srgb,var(--cd-sel) 12%,transparent);outline:1.5px solid var(--cd-sel)}
.cd-sw{width:14px;height:14px;border-radius:4px;flex:none;border:1px solid rgba(0,0,0,.12)}
.cd-chip{border:1px solid var(--bs-border-color);border-radius:.4rem;padding:.12rem .5rem;font-size:.78rem;font-weight:600;background:var(--bs-body-bg);cursor:pointer}
.cd-chip.on{background:var(--cd-sel);color:#fff;border-color:var(--cd-sel)}
.cd-badge{font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:99px;color:#fff}
.cd-b-existente{background:#64748b}.cd-b-requerido{background:var(--cd-plan)}.cd-b-realizado{background:var(--cd-done)}
.cd-plan th{font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:var(--bs-secondary-color)}
.cd-plan td{vertical-align:middle;font-size:.88rem}
.cd-legend{display:flex;flex-wrap:wrap;gap:.9rem;font-size:.75rem;color:var(--bs-secondary-color)}
.cd-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:-2px;margin-right:.25rem}
.cd-dirty{display:none}.cd-wrap.dirty .cd-dirty{display:inline-flex}
.cd-hint{font-size:.78rem}
@media (max-width:991px){.cd-panel{position:static}}
</style>

<div class="cd-wrap" id="cd">
<form method="post" id="cdForm">
<?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="datos" id="cdDatos">

<div class="card mb-3"><div class="card-body py-2 cd-bar">
    <div class="btn-group btn-group-sm cd-mode" role="group" aria-label="<?= e(t('Capa')) ?>">
        <button type="button" class="btn btn-outline-secondary" data-m="requerido"><i class="bi bi-circle"></i> <?= et('Planeado') ?></button>
        <button type="button" class="btn btn-outline-secondary" data-m="realizado"><i class="bi bi-check-circle"></i> <?= et('Realizado') ?></button>
        <button type="button" class="btn btn-outline-secondary" data-m="existente"><i class="bi bi-record-circle"></i> <?= et('Existente') ?></button>
    </div>
    <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary active" data-view="carta"><i class="bi bi-layout-three-columns"></i> <?= et('Carta') ?></button>
        <button type="button" class="btn btn-outline-secondary" data-view="arcada"><i class="bi bi-circle-half"></i> <?= et('Arcada') ?></button>
    </div>
    <select class="form-select form-select-sm w-auto" id="cdFiltro" title="<?= e(t('Mostrar')) ?>">
        <option value="todo"><?= et('Ver: todo') ?></option><option value="existente"><?= et('Solo existente') ?></option>
        <option value="requerido"><?= et('Solo planeado') ?></option><option value="realizado"><?= et('Solo realizado') ?></option>
    </select>
    <div class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-secondary" id="cdUndo" title="<?= e(t('Deshacer')) ?> (Ctrl+Z)" disabled><i class="bi bi-arrow-counterclockwise"></i></button>
        <button type="button" class="btn btn-outline-secondary" id="cdRedo" title="<?= e(t('Rehacer')) ?>" disabled><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
        <span class="cd-dirty badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-dot"></i><?= et('Cambios sin guardar') ?></span>
        <?php if ($versiones): ?><a href="<?= BASE_URL ?>/odontograma/historial<?= $P ?>" class="btn btn-sm btn-light" title="<?= e(t('Historial')) ?>"><i class="bi bi-clock-history"></i> <?= $versiones ?></a><?php endif; ?>
        <a href="<?= BASE_URL ?>/odontograma/index<?= $P ?>&clasica=1" class="btn btn-sm btn-light" title="<?= e(t('Vista clásica por caras')) ?>"><i class="bi bi-grid-3x3"></i></a>
        <button type="button" class="btn btn-sm btn-light" onclick="window.print()" title="<?= e(t('Imprimir')) ?>"><i class="bi bi-printer"></i></button>
        <button class="btn btn-sm btn-primary" id="cdSave"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
    </div>
</div></div>

<div class="row g-3">
<div class="col-12 col-lg-9">
    <div class="card mb-3"><div class="card-body p-2 p-md-3">
        <div id="vCarta" class="cd-chart"><svg id="cdSvg" viewBox="0 0 860 372" role="img" aria-label="<?= e(t('Carta dental')) ?>"></svg></div>
        <div id="vArcada" class="cd-arch" hidden><svg id="cdArch" viewBox="0 0 560 600" role="img" aria-label="<?= e(t('Arcada dental')) ?>"></svg></div>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2 px-1">
            <div class="cd-legend">
                <span><i style="background:var(--cd-plan)"></i><?= et('Planeado') ?></span>
                <span><i style="background:var(--cd-done)"></i><?= et('Realizado') ?></span>
                <span><i style="background:#cbd5e1"></i><?= et('Existente (color del hallazgo)') ?></span>
            </div>
            <div class="cd-hint text-muted" id="cdHint"></div>
        </div>
    </div></div>

    <div class="card mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-list-task text-brand"></i> <?= et('Plan de tratamiento') ?></span>
            <div class="d-flex gap-2 align-items-center">
                <span class="small text-muted" id="cdTotales"></span>
                <?php if (modulo_activo('presupuestos')): ?>
                <button type="button" class="btn btn-sm btn-outline-primary" id="cdPresu"><i class="bi bi-clipboard2-plus"></i> <?= et('Presupuesto') ?></button>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive"><table class="table table-hover cd-plan mb-0">
            <thead><tr><th><?= et('Estado') ?></th><th><?= et('Diente') ?></th><th><?= et('Caras') ?></th><th><?= et('Tratamiento') ?></th><th class="text-end"><?= et('Precio') ?></th><th></th></tr></thead>
            <tbody id="cdPlan"></tbody>
        </table></div>
    </div>
</div>

<div class="col-12 col-lg-3"><div class="cd-panel">
    <div class="card mb-3">
        <div class="card-header bg-transparent fw-semibold small"><i class="bi bi-ui-checks-grid text-brand"></i> <?= et('Procedimientos y hallazgos') ?></div>
        <div class="p-2 border-bottom"><input type="search" class="form-control form-control-sm" id="cdQ" placeholder="<?= e(t('Buscar…')) ?>"></div>
        <div class="cd-list p-1" id="cdList"></div>
        <div class="p-2 border-top">
            <div class="small text-muted mb-1"><?= et('Caras para aplicar a los dientes seleccionados') ?></div>
            <div class="d-flex gap-1 flex-wrap" id="cdChips">
                <?php foreach (odo_caras() as $k => $l): ?><button type="button" class="cd-chip" data-c="<?= $k ?>" title="<?= e(t($l)) ?>"><?= $k ?></button><?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-primary w-100 mt-2" id="cdApply" disabled><i class="bi bi-check2-all"></i> <span><?= et('Aplicar a seleccionados') ?></span></button>
        </div>
    </div>
    <div class="card" id="cdDet">
        <div class="card-body small text-muted" id="cdDetBody"></div>
    </div>
</div></div>
</div>
</form>
</div>

<script>
(function () {
'use strict';
var marcas = <?= json_encode((object) $estado['marcas'], JSON_UNESCAPED_UNICODE) ?>;
var notas  = <?= json_encode((object) $estado['notas'], JSON_UNESCAPED_UNICODE) ?>;
var HALL = <?= json_encode($hall, JSON_UNESCAPED_UNICODE) ?>;
var TRAT = <?= json_encode($trat, JSON_UNESCAPED_UNICODE) ?>;
var PRECIO = <?= json_encode($precios) ?>;
var MONEDA = <?= json_encode(fmt_money(0)) ?>;
var PRESU_URL = <?= json_encode(BASE_URL . '/odontograma/presupuestar') ?>;
var T = <?= json_encode([
    'planeado' => t('Planeado'), 'realizado' => t('Realizado'), 'existente' => t('Existente'),
    'hallazgos' => t('Hallazgos'), 'restauradora' => t('Restauradora'), 'endo' => t('Endodoncia'), 'protesis' => t('Prótesis'), 'cirugia' => t('Cirugía e implantes'),
    'sinPlan' => t('Sin tratamientos planeados. Elige "Planeado", un tratamiento del panel y toca el diente.'),
    'hintArm' => t('Toca una cara o un diente para aplicar:'), 'hintNo' => t('Elige un procedimiento del panel, o toca un diente para ver su detalle.'),
    'hintFace' => t('Este procedimiento va por caras: toca la cara en la vista oclusal, o elige caras abajo.'),
    'eligeCaras' => t('Elige al menos una cara (O, M, D, V, L).'),
    'nada' => t('Toca un diente para ver su detalle. Usa Ctrl o ⌘ para seleccionar varios.'),
    'nota' => t('Nota del diente'), 'sinMarcas' => t('Sin registros en este diente.'), 'marcarHecho' => t('Marcar realizado'),
    'quitar' => t('Quitar'), 'total' => t('Planeado'), 'hecho' => t('Realizado'), 'salir' => t('Hay cambios sin guardar en la carta dental.'),
    'guardaPrimero' => t('Guarda la carta antes de generar el presupuesto.'), 'diente' => t('Diente'), 'completo' => t('completo'),
    'caras' => ['O' => t('Oclusal'), 'M' => t('Mesial'), 'D' => t('Distal'), 'V' => t('Vestibular'), 'L' => t('Lingual/Palatina'), 'C' => t('Diente completo')],
    'tipos' => [1 => t('Incisivo central'), 2 => t('Incisivo lateral'), 3 => t('Canino'), 4 => t('Primer premolar'), 5 => t('Segundo premolar'), 6 => t('Primer molar'), 7 => t('Segundo molar'), 8 => t('Tercer molar')],
    'cuad' => [1 => t('superior derecho'), 2 => t('superior izquierdo'), 3 => t('inferior izquierdo'), 4 => t('inferior derecho')],
    'evol' => t('Registrar nota de evolución'),
], JSON_UNESCAPED_UNICODE) ?>;
var EVOL_URL = <?= json_encode(BASE_URL . '/dental/evolucion?paciente_id=' . $pid) ?>;

var SUP = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
var INF = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
var COL = { plan: '#e11d48', done: '#2563eb' };
var NS = 'http://www.w3.org/2000/svg';

var modo = 'requerido', armado = null, filtro = 'todo', vista = 'carta';
var sel = [];                 // dientes seleccionados (strings)
var caraSel = {};             // caras elegidas en los chips
var hist = [], redo = [], sucio = false;

/* ---------------- utilidades ---------------- */
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function el(tag, attrs, parent) { var n = document.createElementNS(NS, tag); for (var k in attrs) n.setAttribute(k, attrs[k]); if (parent) parent.appendChild(n); return n; }
function tipo(d) { return d % 10; }
function clase(d) { var u = tipo(d); return u <= 2 ? 'inc' : u === 3 ? 'can' : u <= 5 ? 'pre' : 'mol'; }
function nombre(d) { return T.tipos[tipo(d)] + ' ' + T.cuad[Math.floor(d / 10)]; }
function vocab(cond) { return cond === 'existente' ? HALL : TRAT; }
function defDe(cond, est) { return vocab(cond)[est]; }
function mesialDer(d) { var q = Math.floor(d / 10); return q === 1 || q === 4; }
function get(d, c, cond) { return marcas[d] && marcas[d][c] ? marcas[d][c][cond] : undefined; }
function set(d, c, cond, est) { marcas[d] = marcas[d] || {}; marcas[d][c] = marcas[d][c] || {}; marcas[d][c][cond] = est; }
function del(d, c, cond) {
    if (!marcas[d] || !marcas[d][c]) return;
    delete marcas[d][c][cond];
    if (!Object.keys(marcas[d][c]).length) delete marcas[d][c];
    if (!Object.keys(marcas[d]).length) delete marcas[d];
}
function visible(cond) { return filtro === 'todo' || filtro === cond; }
function colorDe(cond, est) { return cond === 'requerido' ? COL.plan : cond === 'realizado' ? COL.done : (HALL[est] ? HALL[est].color : '#94a3b8'); }
function dinero(n) { return MONEDA.replace(/[\d.,]+/, Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })); }

function snapshot() { return JSON.stringify({ m: marcas, n: notas }); }
function cambio(fn) {
    hist.push(snapshot()); if (hist.length > 80) hist.shift(); redo = [];
    fn(); sucio = true; render();
}
function restaurar(s) { var o = JSON.parse(s); marcas = o.m; notas = o.n; sucio = true; render(); }

/* ---------------- formas de diente ----------------
 * Diente "inferior" (corona arriba, raíz abajo) en una caja de 40×100.
 * Los superiores se dibujan reflejados. */
var FORMAS = {
    inc: { corona: 'M10,40 C9,27 11,11 13.5,4.5 Q20,2 26.5,4.5 C29,11 31,27 30,40 Z',
           raiz:   ['M11.5,39 C12.5,60 16.5,86 20,95 C23.5,86 27.5,60 28.5,39 Z'], canal: ['M20,42 L20,90'] },
    can: { corona: 'M8.5,42 C7.5,29 11,14 20,2.5 C29,14 32.5,29 31.5,42 Z',
           raiz:   ['M10.5,41 C12,64 16,90 20,99 C24,90 28,64 29.5,41 Z'], canal: ['M20,44 L20,94'] },
    pre: { corona: 'M6.5,41 C5,27 7.5,12 12.5,7 L16,11 L20,5 L24,11 L27.5,7 C32.5,12 35,27 33.5,41 Z',
           raiz:   ['M9.5,40 C11,62 16,86 20,94 C24,86 29,62 30.5,40 Z'], canal: ['M20,43 L20,89'] },
    mol: { corona: 'M2.5,40 C1.5,25 3,11 7,6.5 L11.5,10 L16.5,5 L20,8.5 L23.5,5 L28.5,10 L33,6.5 C37,11 38.5,25 37.5,40 Z',
           raiz:   ['M5,39 C6,57 8,79 11,90 C14,82 16,62 18,46 L22,46 C24,62 26,82 29,90 C32,79 34,57 35,39 Z'], canal: ['M11.5,44 C12,60 11.5,74 11,86', 'M28.5,44 C28,60 28.5,74 29,86'] }
};

function dibujaLateral(g, d, sup) {
    var f = FORMAS[clase(d)];
    var c = (marcas[d] || {}).C || {};
    var ausente = c.existente === 'ausente' && visible('existente');
    var t = el('g', { transform: sup ? 'translate(0,100) scale(1,-1)' : '' }, g);
    if (ausente) t.setAttribute('opacity', '.16');

    var implante = (c.existente === 'implante' && visible('existente')) ? colorDe('existente', 'implante')
                 : (c.realizado === 'implante' && visible('realizado')) ? COL.done
                 : (c.requerido === 'implante' && visible('requerido')) ? COL.plan : null;
    if (implante) {
        el('rect', { x: 14, y: 42, width: 12, height: 50, rx: 3, fill: implante, opacity: .85 }, t);
        for (var y = 47; y < 90; y += 6) el('path', { d: 'M12.5,' + y + ' L27.5,' + (y + 2.5), stroke: '#fff', 'stroke-width': 1.4, opacity: .8 }, t);
    } else {
        f.raiz.forEach(function (p) { el('path', { d: p, fill: 'url(#gRaiz)', stroke: '#c9b48f', 'stroke-width': .9 }, t); });
    }
    // Corona
    var corona = null, coronaCond = null;
    ['existente', 'realizado', 'requerido'].forEach(function (cond) {
        if (visible(cond) && (c[cond] === 'corona' || c[cond] === 'protesis')) { corona = colorDe(cond, c[cond]); coronaCond = cond; }
    });
    var plan = coronaCond === 'requerido';
    el('path', { d: f.corona, fill: plan ? 'url(#gCorona)' : (corona || 'url(#gCorona)'), 'fill-opacity': corona && !plan ? .9 : 1, stroke: '#94a3b8', 'stroke-width': 1 }, t);
    if (plan) el('path', { d: f.corona, fill: 'url(#pPlan)', stroke: COL.plan, 'stroke-width': 1.6 }, t);
    // Endodoncia (conductos)
    ['existente', 'realizado', 'requerido'].forEach(function (cond) {
        if (!visible(cond) || c[cond] !== 'endodoncia' || implante) return;
        f.canal.forEach(function (p) { el('path', { d: p, stroke: colorDe(cond, 'endodoncia'), 'stroke-width': 3, 'stroke-linecap': 'round', fill: 'none',
            'stroke-dasharray': cond === 'requerido' ? '4 3' : '' }, t); });
    });
    // Hallazgos por cara visibles de perfil: caries / obturación / fractura
    var porCara = marcas[d] || {}, hayCaries = false, hayObt = null, hayFract = false;
    Object.keys(porCara).forEach(function (k) {
        if (k === 'C') return;
        var m = porCara[k];
        if (visible('existente') && m.existente === 'caries') hayCaries = true;
        if (visible('existente') && m.existente === 'fractura') hayFract = true;
        if (visible('existente') && m.existente === 'obturado') hayObt = hayObt || HALL.obturado.color;
        if (visible('realizado') && m.realizado === 'obturacion') hayObt = COL.done;
        if (visible('requerido') && m.requerido === 'obturacion') hayObt = hayObt || COL.plan;
    });
    if (hayObt) el('path', { d: 'M13,14 Q20,10 27,14 L26,24 Q20,27 14,24 Z', fill: hayObt, opacity: .85 }, t);
    if (hayCaries) el('circle', { cx: 24, cy: 14, r: 4.2, fill: HALL.caries.color, opacity: .9 }, t);
    if (hayFract) el('path', { d: 'M9,10 L15,16 L12,20 L19,26', stroke: HALL.fractura.color, 'stroke-width': 1.8, fill: 'none' }, t);
    // Extracción planeada: X roja sobre todo el diente
    if (visible('requerido') && c.requerido === 'extraccion') {
        el('path', { d: 'M5,5 L35,95 M35,5 L5,95', stroke: COL.plan, 'stroke-width': 2.6, 'stroke-linecap': 'round' }, g);
    }
    if (ausente) el('path', { d: 'M8,20 L32,80 M32,20 L8,80', stroke: '#94a3b8', 'stroke-width': 1.5 }, g);
}

/* Vista oclusal: anillo de 4 caras + centro, radio 15, centro (20,20). */
function arco(r1, r2, a1, a2) {
    var rad = Math.PI / 180, p = function (r, a) { return [20 + r * Math.cos(a * rad), 20 + r * Math.sin(a * rad)]; };
    var A = p(r2, a1), B = p(r2, a2), C = p(r1, a2), D = p(r1, a1);
    return 'M' + A + ' A' + r2 + ',' + r2 + ' 0 0 1 ' + B + ' L' + C + ' A' + r1 + ',' + r1 + ' 0 0 0 ' + D + ' Z';
}
function carasPos(d, sup) {
    // arriba / abajo: vestibular hacia fuera de la boca; mesial hacia la línea media.
    var md = mesialDer(d);
    return { top: sup ? 'V' : 'L', bottom: sup ? 'L' : 'V', right: md ? 'M' : 'D', left: md ? 'D' : 'M' };
}
function dibujaOclusal(g, d, sup) {
    var pos = carasPos(d, sup), r1 = 6.5, r2 = 15.5;
    var segs = { top: arco(r1, r2, -135, -45), right: arco(r1, r2, -45, 45), bottom: arco(r1, r2, 45, 135), left: arco(r1, r2, 135, 225) };
    var c = (marcas[d] || {}).C || {};
    var ausente = c.existente === 'ausente' && visible('existente');
    var og = el('g', { opacity: ausente ? .25 : 1 }, g);
    Object.keys(segs).forEach(function (k) { cara(og, d, pos[k], segs[k]); });
    cara(og, d, 'O', null);
    // Estado de diente completo: aro exterior
    ['existente', 'realizado', 'requerido'].forEach(function (cond) {
        var est = c[cond]; if (!est || !visible(cond) || est === 'ausente') return;
        el('circle', { cx: 20, cy: 20, r: 17.6, fill: 'none', stroke: colorDe(cond, est), 'stroke-width': 2.2,
            'stroke-dasharray': cond === 'requerido' ? '3 2' : '' }, og);
    });
}
function cara(g, d, k, path) {
    var m = marcas[d] && marcas[d][k] ? marcas[d][k] : {};
    var base = (visible('existente') && m.existente && HALL[m.existente]) ? HALL[m.existente].color : '#fff';
    var attrs = { 'class': 'cd-face' + (caraSelDe(d, k) ? ' fsel' : ''), fill: base, 'data-d': d, 'data-c': k };
    var n = path ? el('path', Object.assign({ d: path }, attrs), g) : el('circle', Object.assign({ cx: 20, cy: 20, r: 6.5 }, attrs), g);
    var shape = function (fill) { return path ? el('path', { d: path, fill: fill, 'pointer-events': 'none' }, g) : el('circle', { cx: 20, cy: 20, r: 6.5, fill: fill, 'pointer-events': 'none' }, g); };
    if (visible('realizado') && m.realizado) shape('url(#pDone)');
    if (visible('requerido') && m.requerido) shape('url(#pPlan)');
    var tt = el('title', {}, n); tt.textContent = d + ' · ' + T.caras[k];
}
function caraSelDe(d, k) { return sel.indexOf(String(d)) !== -1 && caraSel[k]; }

/* ---------------- carta (16 × 2) ---------------- */
var COLW = 52, X0 = 14, GAP = 12;
function xDe(i) { return X0 + i * COLW + (i >= 8 ? GAP : 0); }
function defs(svg) {
    var d = el('defs', {}, svg);
    var g1 = el('linearGradient', { id: 'gCorona', x1: 0, y1: 0, x2: 0, y2: 1 }, d);
    el('stop', { offset: '0', 'stop-color': '#ffffff' }, g1); el('stop', { offset: '1', 'stop-color': '#e8edf3' }, g1);
    var g2 = el('linearGradient', { id: 'gRaiz', x1: 0, y1: 0, x2: 0, y2: 1 }, d);
    el('stop', { offset: '0', 'stop-color': '#f7ecd9' }, g2); el('stop', { offset: '1', 'stop-color': '#ead6b4' }, g2);
    var p1 = el('pattern', { id: 'pPlan', width: 5, height: 5, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, d);
    el('rect', { width: 5, height: 5, fill: 'rgba(225,29,72,.18)' }, p1); el('line', { x1: 0, y1: 0, x2: 0, y2: 5, stroke: COL.plan, 'stroke-width': 2.4 }, p1);
    var p2 = el('pattern', { id: 'pDone', width: 5, height: 5, patternUnits: 'userSpaceOnUse' }, d);
    el('rect', { width: 5, height: 5, fill: 'rgba(37,99,235,.25)' }, p2); el('circle', { cx: 2.5, cy: 2.5, r: 1.3, fill: COL.done }, p2);
    var g3 = el('radialGradient', { id: 'gSel', cx: '.4', cy: '.35', r: '.8' }, d);
    el('stop', { offset: '0', 'stop-color': '#93c5fd' }, g3); el('stop', { offset: '1', 'stop-color': '#2563eb' }, g3);
    var g4 = el('radialGradient', { id: 'gDiente', cx: '.4', cy: '.35', r: '.85' }, d);
    el('stop', { offset: '0', 'stop-color': '#ffffff' }, g4); el('stop', { offset: '1', 'stop-color': '#dfe6ee' }, g4);
    var g5 = el('radialGradient', { id: 'gEncia', cx: '.5', cy: '.5', r: '.6' }, d);
    el('stop', { offset: '0', 'stop-color': '#f9c4c4' }, g5); el('stop', { offset: '1', 'stop-color': '#ec8f95' }, g5);
}
function renderCarta() {
    var svg = document.getElementById('cdSvg'); svg.innerHTML = ''; defs(svg);
    el('line', { x1: X0 + 8 * COLW + GAP / 2 - 4, y1: 6, x2: X0 + 8 * COLW + GAP / 2 - 4, y2: 366, stroke: '#cbd5e1', 'stroke-dasharray': '3 4' }, svg);
    el('line', { x1: 6, y1: 186, x2: 854, y2: 186, stroke: '#cbd5e1', 'stroke-dasharray': '3 4' }, svg);
    [[SUP, true], [INF, false]].forEach(function (fila) {
        fila[0].forEach(function (d, i) {
            var x = xDe(i), sup = fila[1];
            var g = el('g', { 'class': 'cd-tooth' + (sel.indexOf(String(d)) !== -1 ? ' sel' : ''), 'data-d': d }, svg);
            el('rect', { 'class': 'hit', x: x - 4, y: sup ? 2 : 192, width: 48, height: 178, rx: 8 }, g);
            var num = el('text', { 'class': 'cd-num', x: x + 20, y: sup ? 15 : 362 }, g); num.textContent = d;
            if (notas[d]) el('circle', { cx: x + 34, cy: sup ? 11 : 358, r: 3, fill: '#f59e0b' }, g);
            var lat = el('g', { transform: 'translate(' + x + ',' + (sup ? 22 : 240) + ')', 'data-d': d, 'class': 'lat' }, g);
            dibujaLateral(lat, d, sup);
            var oc = el('g', { transform: 'translate(' + x + ',' + (sup ? 130 : 196) + ')' }, g);
            dibujaOclusal(oc, d, sup);
        });
    });
}

/* ---------------- arcada ---------------- */
var ANCHO = { 1: 1, 2: .8, 3: .88, 4: .86, 5: .86, 6: 1.18, 7: 1.1, 8: 1 };
function posArcada(dientes, sup) {
    // Reparte los dientes sobre media elipse según su ancho relativo.
    var tot = 0; dientes.forEach(function (d) { tot += ANCHO[tipo(d)]; });
    var cx = 280, cy = sup ? 292 : 308, rx = 200, ry = 232, a0 = Math.PI * .97, a1 = Math.PI * .03, acc = 0, out = [];
    dientes.forEach(function (d) {
        var w = ANCHO[tipo(d)], mid = (acc + w / 2) / tot; acc += w;
        var a = a0 + (a1 - a0) * mid;
        var x = cx + rx * Math.cos(a), y = sup ? cy - ry * Math.sin(a) : cy + ry * Math.sin(a);
        var ang = Math.atan2(sup ? -ry * Math.cos(a) : ry * Math.cos(a), -rx * Math.sin(a)) * 180 / Math.PI;
        out.push({ d: d, x: x, y: y, w: w, ang: ang, nx: cx + (rx + 42) * Math.cos(a), ny: sup ? cy - (ry + 40) * Math.sin(a) : cy + (ry + 40) * Math.sin(a) });
    });
    return out;
}
function renderArcada() {
    var svg = document.getElementById('cdArch'); svg.innerHTML = ''; defs(svg);
    el('ellipse', { cx: 280, cy: 300, rx: 262, ry: 290, fill: '#fde8e8', opacity: .55 }, svg);
    [[SUP, true], [INF, false]].forEach(function (f) {
        var ps = posArcada(f[0], f[1]);
        var dpath = ps.map(function (p, i) { return (i ? 'L' : 'M') + p.x.toFixed(1) + ',' + p.y.toFixed(1); }).join(' ');
        el('path', { d: dpath, fill: 'none', stroke: 'url(#gEncia)', 'stroke-width': 58, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', opacity: .9 }, svg);
    });
    el('ellipse', { cx: 280, cy: 300, rx: 120, ry: 44, fill: '#f3b3b8', opacity: .35 }, svg);
    [[SUP, true], [INF, false]].forEach(function (f) {
        posArcada(f[0], f[1]).forEach(function (p) {
            var d = p.d, c = (marcas[d] || {}).C || {}, g = el('g', { 'class': 'at' + (sel.indexOf(String(d)) !== -1 ? ' sel' : ''), 'data-d': d }, svg);
            // rw: mesiodistal (a lo largo de la arcada); rh: vestíbulo-lingual.
            var rw = { 1: 13, 2: 10.5, 3: 12, 4: 11.5, 5: 11.5, 6: 16.5, 7: 15.5, 8: 14 }[tipo(d)] * 1.2;
            var rh = { 1: 10.5, 2: 9.5, 3: 12.5, 4: 14.5, 5: 14.5, 6: 17, 7: 16, 8: 15 }[tipo(d)] * 1.2;
            var aus = c.existente === 'ausente' && visible('existente');
            var fill = 'url(#gDiente)', stroke = '#b8c2cf', dash = '';
            ['existente', 'realizado'].forEach(function (cond) { if (visible(cond) && c[cond] && c[cond] !== 'ausente') fill = colorDe(cond, c[cond]); });
            var tienePlan = false, tieneHecho = false, tieneCaries = false;
            Object.keys(marcas[d] || {}).forEach(function (k) {
                var m = marcas[d][k];
                if (m.requerido && visible('requerido')) tienePlan = true;
                if (m.realizado && visible('realizado')) tieneHecho = true;
                if (m.existente === 'caries' && visible('existente')) tieneCaries = true;
            });
            if (tienePlan) { stroke = COL.plan; }
            if (aus) { fill = 'none'; dash = '4 3'; }
            el('ellipse', { 'class': 'ab', cx: p.x, cy: p.y, rx: rw, ry: rh, fill: fill, stroke: stroke, 'stroke-width': tienePlan ? 3 : 1.4,
                'stroke-dasharray': dash, transform: 'rotate(' + p.ang.toFixed(1) + ' ' + p.x.toFixed(1) + ' ' + p.y.toFixed(1) + ')' }, g);
            if (!aus && fill === 'url(#gDiente)') el('ellipse', { cx: p.x, cy: p.y, rx: rw * .5, ry: rh * .45, fill: 'none', stroke: '#cfd8e3', 'stroke-width': 1,
                transform: 'rotate(' + p.ang.toFixed(1) + ' ' + p.x.toFixed(1) + ' ' + p.y.toFixed(1) + ')', 'pointer-events': 'none' }, g);
            if (tieneCaries) el('circle', { cx: p.x, cy: p.y, r: 4, fill: HALL.caries.color, 'pointer-events': 'none' }, g);
            if (tieneHecho) el('circle', { cx: p.x + rw * .55, cy: p.y - rh * .55, r: 4, fill: COL.done, stroke: '#fff', 'stroke-width': 1.2, 'pointer-events': 'none' }, g);
            if (c.requerido === 'extraccion' && visible('requerido')) el('path', { d: 'M' + (p.x - 9) + ',' + (p.y - 9) + ' L' + (p.x + 9) + ',' + (p.y + 9) + ' M' + (p.x + 9) + ',' + (p.y - 9) + ' L' + (p.x - 9) + ',' + (p.y + 9), stroke: COL.plan, 'stroke-width': 2.6, 'stroke-linecap': 'round', 'pointer-events': 'none' }, g);
            el('circle', { 'class': 'anb', cx: p.nx, cy: p.ny, r: 13 }, g);
            var t = el('text', { 'class': 'an', x: p.nx, y: p.ny + .5 }, g); t.textContent = d;
            var tt = el('title', {}, g); tt.textContent = d + ' · ' + nombre(d);
        });
    });
    var l1 = el('text', { x: 18, y: 300, 'class': 'an', 'text-anchor': 'start', fill: '#94a3b8' }, svg); l1.textContent = 'D';
    var l2 = el('text', { x: 542, y: 300, 'class': 'an', fill: '#94a3b8' }, svg); l2.textContent = 'I';
}

/* ---------------- aplicar ---------------- */
function aplicar(d, carasObj, sinAlternar) {
    d = String(d);
    var def = defDe(modo, armado); if (!def) return;
    var lista = def.completo ? ['C'] : carasObj;
    if (!lista.length) return false;
    lista.forEach(function (k) {
        if (!sinAlternar && get(d, k, modo) === armado && lista.length === 1) { del(d, k, modo); return; }   // segundo toque = quitar
        set(d, k, modo, armado);
        if (modo === 'realizado') {
            // Como en el sillón: lo realizado deja su resultado como hallazgo y sale del plan.
            if (get(d, k, 'requerido') === armado) del(d, k, 'requerido');
            var res = TRAT[armado] && TRAT[armado].resultado;
            if (res === 'ausente') { Object.keys(marcas[d] || {}).forEach(function (c2) { if (c2 !== 'C') del(d, c2, 'existente'); }); }
            if (res) set(d, k, 'existente', res);
        }
    });
    return true;
}
function marcarHecho(d, est, carasLista) {
    cambio(function () {
        var res = TRAT[est] && TRAT[est].resultado;
        carasLista.forEach(function (k) {
            del(d, k, 'requerido'); set(d, k, 'realizado', est);
            if (res === 'ausente') Object.keys(marcas[d] || {}).forEach(function (c2) { if (c2 !== 'C') del(d, c2, 'existente'); });
            if (res) set(d, k, 'existente', res);
        });
    });
}

/* ---------------- panel ---------------- */
function renderLista() {
    var q = document.getElementById('cdQ').value.trim().toLowerCase(), h = '';
    var grupos = modo === 'existente'
        ? [[T.hallazgos, Object.keys(HALL)]]
        : [[T.restauradora, ['obturacion', 'sellante']], [T.endo, ['endodoncia']], [T.protesis, ['corona', 'protesis']], [T.cirugia, ['extraccion', 'implante']]];
    grupos.forEach(function (gr) {
        var items = gr[1].filter(function (k) { var dd = vocab(modo)[k]; return dd && (!q || dd.label_t.toLowerCase().indexOf(q) !== -1); });
        if (!items.length) return;
        h += '<div class="cd-cat">' + esc(gr[0]) + '</div>';
        items.forEach(function (k) {
            var dd = vocab(modo)[k], sw = modo === 'existente' ? dd.color : (modo === 'requerido' ? COL.plan : COL.done);
            h += '<div class="cd-it' + (armado === k ? ' armed' : '') + '" data-k="' + k + '"><span class="cd-sw" style="background:' + sw + '"></span>'
               + '<span class="flex-grow-1">' + esc(dd.label_t) + '</span>' + (dd.completo ? '<i class="bi bi-circle-square text-muted" title="' + esc(T.caras.C) + '"></i>' : '<span class="small text-muted">' + 'OMDVL' + '</span>') + '</div>';
        });
    });
    document.getElementById('cdList').innerHTML = h;
}
function renderHint() {
    var h = document.getElementById('cdHint'), def = armado && defDe(modo, armado);
    h.innerHTML = def ? '<i class="bi bi-hand-index"></i> ' + esc(T.hintArm) + ' <b>' + esc(def.label_t) + '</b> (' + esc(modo === 'requerido' ? T.planeado : modo === 'realizado' ? T.realizado : T.existente) + ')'
        + (def.completo ? '' : ' · ' + esc(T.hintFace)) : esc(T.hintNo);
    var b = document.getElementById('cdApply');
    b.disabled = !(def && sel.length);
}
function renderDetalle() {
    var box = document.getElementById('cdDetBody');
    if (sel.length !== 1) {
        box.innerHTML = sel.length > 1 ? '<div class="fw-semibold text-body mb-1">' + sel.length + ' ' + esc(T.diente).toLowerCase() + 's</div>' + sel.join(', ') : esc(T.nada);
        return;
    }
    var d = sel[0], h = '<div class="d-flex justify-content-between align-items-start mb-2"><div><div class="h5 mb-0 text-body">' + d + '</div><div>' + esc(nombre(+d)) + '</div></div></div>';
    var filas = [];
    Object.keys(marcas[d] || {}).forEach(function (k) {
        Object.keys(marcas[d][k]).forEach(function (cond) { filas.push([k, cond, marcas[d][k][cond]]); });
    });
    if (!filas.length) h += '<div class="mb-2">' + esc(T.sinMarcas) + '</div>';
    filas.forEach(function (f) {
        var dd = defDe(f[1], f[2]); if (!dd) return;
        h += '<div class="d-flex align-items-center gap-2 py-1 border-bottom"><span class="cd-badge cd-b-' + f[1] + '">' + esc(f[1] === 'requerido' ? T.planeado : f[1] === 'realizado' ? T.realizado : T.existente) + '</span>'
           + '<span class="flex-grow-1 text-body">' + esc(dd.label_t) + ' <span class="text-muted">· ' + esc(f[0] === 'C' ? T.completo : f[0]) + '</span></span>'
           + (f[1] === 'requerido' ? '<button type="button" class="btn btn-sm btn-link p-0 text-primary" data-hecho="' + f[0] + '|' + f[2] + '" title="' + esc(T.marcarHecho) + '"><i class="bi bi-check2-circle"></i></button>' : '')
           + '<button type="button" class="btn btn-sm btn-link p-0 text-danger" data-del="' + f[0] + '|' + f[1] + '" title="' + esc(T.quitar) + '"><i class="bi bi-x-lg"></i></button></div>';
    });
    h += '<label class="form-label small mt-2 mb-1">' + esc(T.nota) + '</label><textarea class="form-control form-control-sm" id="cdNota" rows="2" maxlength="200">' + esc(notas[d] || '') + '</textarea>';
    h += '<a class="btn btn-sm btn-outline-secondary w-100 mt-2" href="' + EVOL_URL + '&diente=' + d + '"><i class="bi bi-journal-plus"></i> ' + esc(T.evol) + '</a>';
    box.innerHTML = h;
}

/* ---------------- plan de tratamiento ---------------- */
function precioDe(est, d) {
    var p = PRECIO[est] || {};
    if (est === 'endodoncia' && clase(+d) === 'mol' || est === 'endodoncia' && clase(+d) === 'pre') return p.multi != null ? p.multi : p.def;
    if (est === 'extraccion' && tipo(+d) === 8) return p.tercer != null ? p.tercer : p.def;
    return p.def;
}
function renderPlan() {
    var filas = {};
    Object.keys(marcas).forEach(function (d) {
        Object.keys(marcas[d]).forEach(function (k) {
            ['requerido', 'realizado'].forEach(function (cond) {
                var est = marcas[d][k][cond]; if (!est) return;
                var key = cond + '|' + d + '|' + est;
                filas[key] = filas[key] || { cond: cond, d: d, est: est, caras: [] };
                filas[key].caras.push(k);
            });
        });
    });
    var lista = Object.values(filas).sort(function (a, b) {
        return (a.cond === b.cond ? 0 : a.cond === 'requerido' ? -1 : 1) || (+a.d - +b.d);
    });
    var tPlan = 0, tHecho = 0, h = '';
    lista.forEach(function (f) {
        var dd = TRAT[f.est]; if (!dd) return;
        var pr = precioDe(f.est, f.d);
        if (pr) { if (f.cond === 'requerido') tPlan += pr; else tHecho += pr; }
        var caras = f.caras.filter(function (c) { return c !== 'C'; }).sort().join('');
        h += '<tr data-d="' + f.d + '"><td><span class="cd-badge cd-b-' + f.cond + '">' + esc(f.cond === 'requerido' ? T.planeado : T.realizado) + '</span></td>'
           + '<td class="fw-semibold">' + f.d + '</td><td>' + (caras || '<span class="text-muted">—</span>') + '</td><td>' + esc(dd.label_t) + '</td>'
           + '<td class="text-end">' + (pr != null ? dinero(pr) : '<span class="text-muted">—</span>') + '</td>'
           + '<td class="text-end text-nowrap">' + (f.cond === 'requerido' ? '<button type="button" class="btn btn-sm btn-outline-primary py-0" data-hechop="' + f.d + '|' + f.est + '|' + f.caras.join(',') + '"><i class="bi bi-check2"></i> ' + esc(T.marcarHecho) + '</button> ' : '')
           + '<button type="button" class="btn btn-sm btn-link text-danger py-0" data-quitap="' + f.d + '|' + f.cond + '|' + f.caras.join(',') + '" title="' + esc(T.quitar) + '"><i class="bi bi-trash"></i></button></td></tr>';
    });
    document.getElementById('cdPlan').innerHTML = h || '<tr><td colspan="6" class="text-center text-muted py-4">' + esc(T.sinPlan) + '</td></tr>';
    document.getElementById('cdTotales').innerHTML = (tPlan || tHecho) ? esc(T.total) + ': <b>' + dinero(tPlan) + '</b> · ' + esc(T.hecho) + ': <b>' + dinero(tHecho) + '</b>' : '';
}

function render() {
    if (vista === 'carta') renderCarta(); else renderArcada();
    renderLista(); renderHint(); renderDetalle(); renderPlan();
    document.getElementById('cd').classList.toggle('dirty', sucio);
    document.getElementById('cdUndo').disabled = !hist.length;
    document.getElementById('cdRedo').disabled = !redo.length;
    document.querySelectorAll('#cdChips .cd-chip').forEach(function (b) { b.classList.toggle('on', !!caraSel[b.dataset.c]); });
}

/* ---------------- eventos ---------------- */
function seleccionar(d, multi) {
    d = String(d);
    var i = sel.indexOf(d);
    if (multi) { if (i === -1) sel.push(d); else sel.splice(i, 1); }
    else sel = (i !== -1 && sel.length === 1) ? [] : [d];
}
function clicDiente(ev, d, caraK) {
    var def = armado && defDe(modo, armado);
    if (def) {
        var cs = def.completo ? ['C'] : (caraK ? [caraK] : Object.keys(caraSel).filter(function (k) { return caraSel[k]; }));
        if (!def.completo && !cs.length) { seleccionar(d, ev.ctrlKey || ev.metaKey); render(); flashHint(T.eligeCaras); return; }
        cambio(function () { aplicar(d, cs); });
        return;
    }
    seleccionar(d, ev.ctrlKey || ev.metaKey || ev.shiftKey);
    render();
}
function flashHint(t) { var h = document.getElementById('cdHint'); h.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' + esc(t) + '</span>'; }

document.getElementById('cdSvg').addEventListener('click', function (ev) {
    var face = ev.target.closest('.cd-face');
    var tooth = ev.target.closest('.cd-tooth'); if (!tooth) return;
    clicDiente(ev, tooth.dataset.d, face ? face.dataset.c : null);
});
document.getElementById('cdArch').addEventListener('click', function (ev) {
    var t = ev.target.closest('.at'); if (!t) return;
    var def = armado && defDe(modo, armado);
    if (def && !def.completo && !Object.keys(caraSel).some(function (k) { return caraSel[k]; })) { seleccionar(t.dataset.d, true); render(); flashHint(T.eligeCaras); return; }
    if (def) clicDiente(ev, t.dataset.d, null); else { seleccionar(t.dataset.d, true); render(); }
});
document.querySelectorAll('.cd-mode .btn').forEach(function (b) {
    b.addEventListener('click', function () {
        modo = b.dataset.m; armado = null;
        document.querySelectorAll('.cd-mode .btn').forEach(function (x) { x.classList.toggle('active', x === b); });
        render();
    });
});
document.querySelectorAll('[data-view]').forEach(function (b) {
    b.addEventListener('click', function () {
        vista = b.dataset.view;
        document.querySelectorAll('[data-view]').forEach(function (x) { x.classList.toggle('active', x === b); });
        document.getElementById('vCarta').hidden = vista !== 'carta';
        document.getElementById('vArcada').hidden = vista !== 'arcada';
        try { localStorage.setItem('cdVista', vista); } catch (e) {}
        render();
    });
});
document.getElementById('cdFiltro').addEventListener('change', function () { filtro = this.value; render(); });
document.getElementById('cdQ').addEventListener('input', renderLista);
document.getElementById('cdList').addEventListener('click', function (ev) {
    var it = ev.target.closest('.cd-it'); if (!it) return;
    armado = armado === it.dataset.k ? null : it.dataset.k; render();
});
document.getElementById('cdChips').addEventListener('click', function (ev) {
    var b = ev.target.closest('.cd-chip'); if (!b) return;
    caraSel[b.dataset.c] = !caraSel[b.dataset.c]; render();
});
document.getElementById('cdApply').addEventListener('click', function () {
    var def = armado && defDe(modo, armado); if (!def || !sel.length) return;
    var cs = def.completo ? ['C'] : Object.keys(caraSel).filter(function (k) { return caraSel[k]; });
    if (!cs.length) { flashHint(T.eligeCaras); return; }
    cambio(function () { sel.forEach(function (d) { aplicar(d, cs, true); }); });
});
document.getElementById('cdDetBody').addEventListener('click', function (ev) {
    var d = sel[0], b;
    if ((b = ev.target.closest('[data-del]'))) { var p = b.dataset.del.split('|'); cambio(function () { del(d, p[0], p[1]); }); }
    else if ((b = ev.target.closest('[data-hecho]'))) { var q = b.dataset.hecho.split('|'); marcarHecho(d, q[1], [q[0]]); }
});
document.getElementById('cdDetBody').addEventListener('change', function (ev) {
    if (ev.target.id !== 'cdNota') return;
    var d = sel[0], v = ev.target.value.trim();
    cambio(function () { if (v) notas[d] = v; else delete notas[d]; });
});
document.getElementById('cdPlan').addEventListener('click', function (ev) {
    var b;
    if ((b = ev.target.closest('[data-hechop]'))) { var p = b.dataset.hechop.split('|'); marcarHecho(p[0], p[1], p[2].split(',')); }
    else if ((b = ev.target.closest('[data-quitap]'))) { var q = b.dataset.quitap.split('|'); cambio(function () { q[2].split(',').forEach(function (k) { del(q[0], k, q[1]); }); }); }
    else if ((b = ev.target.closest('tr[data-d]'))) { sel = [b.dataset.d]; render(); }
});
document.getElementById('cdUndo').addEventListener('click', function () { if (!hist.length) return; redo.push(snapshot()); restaurar(hist.pop()); });
document.getElementById('cdRedo').addEventListener('click', function () { if (!redo.length) return; hist.push(snapshot()); restaurar(redo.pop()); });
document.addEventListener('keydown', function (ev) {
    if (ev.target.matches('input,textarea,select')) return;
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'z') { ev.preventDefault(); document.getElementById(ev.shiftKey ? 'cdRedo' : 'cdUndo').click(); }
    if (ev.key === 'Escape') { armado = null; sel = []; render(); }
});
document.getElementById('cdForm').addEventListener('submit', function () {
    document.getElementById('cdDatos').value = JSON.stringify({ marcas: marcas, notas: notas });
    sucio = false;
});
window.addEventListener('beforeunload', function (ev) { if (sucio) { ev.preventDefault(); ev.returnValue = T.salir; } });
var presu = document.getElementById('cdPresu');
if (presu) presu.addEventListener('click', function () {
    if (sucio) { flashHint(T.guardaPrimero); return; }
    var f = document.createElement('form'); f.method = 'post'; f.action = PRESU_URL;
    f.innerHTML = <?= json_encode(csrf_field()) ?> + '<input type="hidden" name="paciente_id" value="<?= $pid ?>">';
    document.body.appendChild(f); f.submit();
});

try { if (localStorage.getItem('cdVista') === 'arcada') { document.querySelector('[data-view=arcada]').click(); } } catch (e) {}
document.querySelector('.cd-mode [data-m=requerido]').classList.add('active');
render();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
