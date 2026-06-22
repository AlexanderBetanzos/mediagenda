<?php
/**
 * Odontograma interactivo (odontología). Pinta el estado de cada diente
 * (notación FDI) y lo guarda como JSON por paciente.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }

$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }

// Estado => [etiqueta, color de fondo, color de texto]
$estados = [
    'sano'       => ['Sano',        '#ffffff', '#1f2d3d'],
    'caries'     => ['Caries',      '#dc3545', '#ffffff'],
    'obturado'   => ['Obturado',    '#0d6efd', '#ffffff'],
    'corona'     => ['Corona',      '#ffc107', '#1f2d3d'],
    'endodoncia' => ['Endodoncia',  '#6f42c1', '#ffffff'],
    'ausente'    => ['Ausente',     '#adb5bd', '#ffffff'],
    'extraccion' => ['Extracción',  '#212529', '#ffffff'],
];
$iconos = [
    'sano' => 'bi-check-circle', 'caries' => 'bi-exclamation-circle', 'obturado' => 'bi-bandaid',
    'corona' => 'bi-gem', 'endodoncia' => 'bi-asterisk', 'ausente' => 'bi-dash-circle', 'extraccion' => 'bi-x-octagon',
];
$arribaDer = [18,17,16,15,14,13,12,11]; $arribaIzq = [21,22,23,24,25,26,27,28];
$abajoDer  = [48,47,46,45,44,43,42,41]; $abajoIzq  = [38,37,36,35,34,33,32,31];
$dientesValidos = array_merge($arribaDer, $arribaIzq, $abajoDer, $abajoIzq);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in   = json_decode($_POST['datos'] ?? '{}', true);
    $in   = is_array($in) ? $in : [];
    $estIn = is_array($in['estados'] ?? null) ? $in['estados'] : [];
    $notIn = is_array($in['notas'] ?? null) ? $in['notas'] : [];
    $estLimpio = $notLimpio = [];
    foreach ($estIn as $d => $est) {
        if (in_array((int) $d, $dientesValidos, true) && isset($estados[$est]) && $est !== 'sano') {
            $estLimpio[(int) $d] = $est;
        }
    }
    foreach ($notIn as $d => $n) {
        $n = trim((string) $n);
        if (in_array((int) $d, $dientesValidos, true) && $n !== '') {
            $notLimpio[(int) $d] = mb_substr($n, 0, 200);
        }
    }
    $payload = json_encode(['estados' => $estLimpio, 'notas' => $notLimpio], JSON_UNESCAPED_UNICODE);
    db()->prepare(
        'INSERT INTO odontogramas (consultorio_id, paciente_id, datos, actualizado_por) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE datos = VALUES(datos), actualizado_por = VALUES(actualizado_por)'
    )->execute([tenant_id(), $pid, $payload, current_user()['id']]);
    auditar('odontograma', 'paciente', $pid);
    flash(t('Odontograma guardado.'));
    redirect('/odontograma/index?paciente_id=' . $pid);
}

$row = db()->prepare('SELECT datos FROM odontogramas WHERE paciente_id = ? AND consultorio_id = ?');
$row->execute([$pid, tenant_id()]);
$raw = ($r = $row->fetchColumn()) ? (json_decode($r, true) ?: []) : [];
// Compatibilidad: formato nuevo {estados,notas} o el viejo plano {diente:estado}.
if (isset($raw['estados']) || isset($raw['notas'])) {
    $estAct = $raw['estados'] ?? []; $notAct = $raw['notas'] ?? [];
} else {
    $estAct = $raw ?: []; $notAct = [];
}

/** Grupo de dientes (un cuadrante). $arco = 'sup' | 'inf' para la forma. */
function cuadrante(array $dientes, array $estAct, array $notAct, array $estados, string $arco): string
{
    $h = '<div class="cuadrante">';
    foreach ($dientes as $d) {
        $est = $estAct[$d] ?? 'sano';
        [$lbl, $bg, $fg] = $estados[$est];
        $nota  = trim((string) ($notAct[$d] ?? ''));
        $title = $d . ' · ' . $lbl . ($nota !== '' ? ' · ' . $nota : '');
        $h .= '<button type="button" class="diente diente-' . $arco . ' border' . ($nota !== '' ? ' tiene-nota' : '') . '"'
            . ' data-diente="' . $d . '" style="background:' . $bg . ';color:' . $fg . '" title="' . e($title) . '">'
            . '<span class="num">' . $d . '</span></button>';
    }
    return $h . '</div>';
}

$titulo = t('Odontograma') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($pac['nombre'].' '.$pac['apellidos']) ?></a></li>
    <li class="breadcrumb-item active"><?= et('Odontograma') ?></li>
</ol></nav>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-emoji-smile text-brand"></i> <?= et('Odontograma') ?></h1>
    <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> <?= et('Imprimir / PDF') ?></button>
</div>
<div class="d-none d-print-block mb-2"><strong><?= e($pac['nombre'].' '.$pac['apellidos']) ?></strong> · <?= et('Odontograma') ?> · <?= fmt_fecha(date('Y-m-d')) ?></div>

<form method="post" id="odoForm">
    <?= csrf_field() ?>
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">
    <input type="hidden" name="datos" id="datosInput">

    <!-- Paleta -->
    <div class="card mb-3 no-print"><div class="card-body">
        <div class="small text-muted mb-2"><?= et('Elige un estado y haz clic en los dientes para marcarlos.') ?></div>
        <div class="d-flex flex-wrap gap-2" id="paleta">
            <?php foreach ($estados as $k => [$lbl, $bg, $fg]): ?>
            <button type="button" class="btn btn-sm border paleta-btn <?= $k === 'caries' ? 'active' : '' ?>" data-estado="<?= e($k) ?>"
                    style="background:<?= $bg ?>;color:<?= $fg ?>"><i class="bi <?= $iconos[$k] ?>"></i> <?= et($lbl) ?></button>
            <?php endforeach; ?>
            <button type="button" class="btn btn-sm border paleta-btn" data-estado="__nota"><i class="bi bi-pencil-square"></i> <?= et('Nota') ?></button>
        </div>
        <div class="form-text mt-2"><?= et('Con "Sano" quitas la marca; con "Nota" agregas una observación al diente.') ?></div>
    </div></div>

    <!-- Arcadas -->
    <div class="card mb-3"><div class="card-body">
        <div class="odo-wrap mx-auto">
            <div class="d-flex justify-content-between text-muted small px-1 mb-1">
                <span><i class="bi bi-arrow-left"></i> <?= et('Derecha') ?></span>
                <span class="fw-semibold"><?= et('Superior') ?></span>
                <span><?= et('Izquierda') ?> <i class="bi bi-arrow-right"></i></span>
            </div>
            <div class="arcada">
                <?= cuadrante($arribaDer, $estAct, $notAct, $estados, 'sup') ?>
                <div class="midline"></div>
                <?= cuadrante($arribaIzq, $estAct, $notAct, $estados, 'sup') ?>
            </div>
            <div class="arcada mt-2">
                <?= cuadrante($abajoDer, $estAct, $notAct, $estados, 'inf') ?>
                <div class="midline"></div>
                <?= cuadrante($abajoIzq, $estAct, $notAct, $estados, 'inf') ?>
            </div>
            <div class="text-center text-muted small fw-semibold mt-1"><?= et('Inferior') ?></div>
        </div>
        <hr class="my-3">
        <div class="d-flex flex-wrap justify-content-center gap-2" id="resumen">
            <?php foreach ($estados as $k => [$lbl, $bg, $fg]): if ($k === 'sano') continue; ?>
            <span class="badge border d-inline-flex align-items-center gap-1" data-resumen="<?= e($k) ?>" style="background:<?= $bg ?>;color:<?= $fg ?>">
                <i class="bi <?= $iconos[$k] ?>"></i> <?= et($lbl) ?>: <span class="cnt fw-bold">0</span>
            </span>
            <?php endforeach; ?>
        </div>
        <div id="listaNotas" class="mt-3 small"></div>
        <div class="d-flex justify-content-between align-items-center mt-3 no-print">
            <button type="button" class="btn btn-outline-danger btn-sm" id="limpiarTodo"><i class="bi bi-eraser"></i> <?= et('Limpiar todo') ?></button>
            <div>
                <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-light"><?= et('Cancelar') ?></a>
                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
            </div>
        </div>
    </div></div>
</form>

<script>
(function () {
    var estados = <?= json_encode(array_map(fn($v) => ['bg'=>$v[1],'fg'=>$v[2]], $estados), JSON_UNESCAPED_UNICODE) ?>;
    var T_NOTA  = <?= json_encode(t('Nota para el diente')) ?>;
    var T_TITULO= <?= json_encode(t('Notas por diente')) ?>;
    var activo  = 'caries';
    var datos   = <?= json_encode((object) $estAct, JSON_UNESCAPED_UNICODE) ?>;
    var notas   = <?= json_encode((object) $notAct, JSON_UNESCAPED_UNICODE) ?>;

    function esc(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    document.querySelectorAll('.paleta-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            activo = b.dataset.estado;
            document.querySelectorAll('.paleta-btn').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
        });
    });

    function updateResumen() {
        var c = {};
        Object.values(datos).forEach(function (e) { c[e] = (c[e] || 0) + 1; });
        document.querySelectorAll('#resumen [data-resumen]').forEach(function (b) {
            var n = c[b.dataset.resumen] || 0;
            b.querySelector('.cnt').textContent = n;
            b.style.opacity = n ? '1' : '.4';
        });
    }

    function rebuildNotas() {
        var keys = Object.keys(notas).sort((a, b) => a - b);
        var el = document.getElementById('listaNotas');
        if (!keys.length) { el.innerHTML = ''; return; }
        var h = '<div class="fw-semibold mb-1"><i class="bi bi-card-text"></i> ' + esc(T_TITULO) + '</div><ul class="mb-0">';
        keys.forEach(d => { h += '<li><strong>' + d + ':</strong> ' + esc(notas[d]) + '</li>'; });
        el.innerHTML = h + '</ul>';
    }

    document.querySelectorAll('.diente').forEach(function (t) {
        t.addEventListener('click', function () {
            var d = t.dataset.diente;
            if (activo === '__nota') {
                var n = prompt(T_NOTA + ' ' + d + ':', notas[d] || '');
                if (n === null) return;
                n = n.trim();
                if (n) { notas[d] = n; t.classList.add('tiene-nota'); }
                else { delete notas[d]; t.classList.remove('tiene-nota'); }
                t.title = d + (notas[d] ? ' · ' + notas[d] : '');
                rebuildNotas();
                return;
            }
            if (activo === 'sano') { delete datos[d]; } else { datos[d] = activo; }
            t.style.background = estados[activo].bg;
            t.style.color = estados[activo].fg;
            updateResumen();
        });
    });

    document.getElementById('limpiarTodo').addEventListener('click', function () {
        if (!confirm(<?= json_encode(t('¿Quitar todas las marcas del odontograma?')) ?>)) return;
        Object.keys(datos).forEach(k => delete datos[k]);
        Object.keys(notas).forEach(k => delete notas[k]);
        document.querySelectorAll('.diente').forEach(function (t) {
            t.style.background = estados['sano'].bg;
            t.style.color = estados['sano'].fg;
            t.classList.remove('tiene-nota');
        });
        updateResumen(); rebuildNotas();
    });

    document.getElementById('odoForm').addEventListener('submit', function () {
        document.getElementById('datosInput').value = JSON.stringify({ estados: datos, notas: notas });
    });

    updateResumen(); rebuildNotas();
})();
</script>
<style>
.odo-wrap{max-width:640px}
.arcada{display:flex;justify-content:center;align-items:center}
.cuadrante{display:flex;gap:5px}
.midline{width:2px;align-self:stretch;background:#cbd5e1;margin:0 12px;border-radius:2px}
.diente{width:38px;height:46px;display:flex;align-items:center;justify-content:center;
        font-weight:700;font-size:.8rem;padding:0;cursor:pointer;background:#fff;
        box-shadow:0 1px 2px rgba(15,39,71,.08);transition:transform .06s,box-shadow .06s}
.diente:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(15,39,71,.18)}
.diente-sup{border-radius:9px 9px 15px 15px}
.diente-inf{border-radius:15px 15px 9px 9px}
.paleta-btn{font-weight:600;border-radius:8px}
.paleta-btn.active{outline:3px solid var(--brand,#0b6fb8);outline-offset:2px;box-shadow:0 2px 8px rgba(0,0,0,.18)}
.tiene-nota{position:relative}
.tiene-nota::after{content:'';position:absolute;top:3px;right:3px;width:8px;height:8px;border-radius:50%;background:#0b6fb8;border:1.5px solid #fff}
@media print{
  .app-navbar,.sidebar,.breadcrumb,.no-print,footer{display:none!important}
  .card{border:none!important;box-shadow:none!important}
  main{width:100%!important;max-width:100%!important;flex:0 0 100%!important}
  .diente{box-shadow:none!important}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
