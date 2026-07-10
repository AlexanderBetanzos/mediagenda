<?php
/**
 * Odontograma interactivo por caras (notación FDI).
 * Registra tres capas por cara: el hallazgo actual, el tratamiento requerido y
 * el tratamiento ya realizado. Cada guardado deja una foto en el historial.
 */
require_once __DIR__ . '/../includes/odontograma.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }

$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $in = json_decode($_POST['datos'] ?? '{}', true);
    $in = is_array($in) ? $in : [];
    odo_guardar(
        $pid,
        is_array($in['marcas'] ?? null) ? $in['marcas'] : [],
        is_array($in['notas'] ?? null) ? $in['notas'] : [],
        t('Edición manual')
    );
    auditar('odontograma', 'paciente', $pid);
    flash(t('Odontograma guardado.'));
    redirect('/odontograma/index?paciente_id=' . $pid);
}

$estado     = odo_cargar($pid);
$requeridos = odo_requeridos($pid);
$versiones  = db()->prepare('SELECT COUNT(*) FROM odontograma_historial WHERE paciente_id = ? AND consultorio_id = ?');
$versiones->execute([$pid, tenant_id()]);
$versiones  = (int) $versiones->fetchColumn();

$titulo = t('Odontograma') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($pac['nombre'].' '.$pac['apellidos']) ?></a></li>
    <li class="breadcrumb-item active"><?= et('Odontograma') ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-emoji-smile text-brand"></i> <?= et('Odontograma') ?></h1>
    <div class="d-flex flex-wrap gap-2 no-print">
        <?php if ($versiones): ?>
        <a href="<?= BASE_URL ?>/odontograma/historial?paciente_id=<?= $pid ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history"></i> <?= et('Historial') ?> (<?= $versiones ?>)
        </a>
        <?php endif; ?>
        <?php if ($requeridos && modulo_activo('presupuestos')): ?>
        <form method="post" action="<?= BASE_URL ?>/odontograma/presupuestar">
            <?= csrf_field() ?>
            <input type="hidden" name="paciente_id" value="<?= $pid ?>">
            <button class="btn btn-outline-primary btn-sm">
                <i class="bi bi-clipboard2-plus"></i> <?= et('Generar presupuesto') ?> (<?= count($requeridos) ?>)
            </button>
        </form>
        <?php endif; ?>
        <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> <?= et('Imprimir / PDF') ?></button>
    </div>
</div>
<div class="d-none d-print-block mb-2">
    <strong><?= e($pac['nombre'].' '.$pac['apellidos']) ?></strong> · <?= et('Odontograma') ?> · <?= fmt_fecha(date('Y-m-d')) ?>
</div>

<form method="post" id="odoForm">
    <?= csrf_field() ?>
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">
    <input type="hidden" name="datos" id="datosInput">

    <div class="card mb-3 no-print"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div class="btn-group btn-group-sm" role="group" id="condiciones">
                <button type="button" class="btn btn-outline-secondary active" data-condicion="existente"><?= et('Hallazgo') ?></button>
                <button type="button" class="btn btn-outline-secondary" data-condicion="requerido"><?= et('A tratar') ?></button>
                <button type="button" class="btn btn-outline-secondary" data-condicion="realizado"><?= et('Realizado') ?></button>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-danger" id="btnBorrar"><i class="bi bi-eraser"></i> <?= et('Borrar') ?></button>
                <button type="button" class="btn btn-outline-secondary" id="btnNota"><i class="bi bi-pencil-square"></i> <?= et('Nota') ?></button>
            </div>
        </div>

        <?php foreach (['existente' => odo_hallazgos(), 'requerido' => odo_tratamientos(), 'realizado' => odo_tratamientos()] as $cond => $vocab): ?>
        <div class="d-flex flex-wrap gap-2 paleta" data-paleta="<?= $cond ?>" <?= $cond === 'existente' ? '' : 'hidden' ?>>
            <?php foreach ($vocab as $clave => $def): ?>
            <button type="button" class="btn btn-sm border paleta-btn" data-estado="<?= e($clave) ?>"
                    style="background:<?= $def['color'] ?>;color:<?= $def['color'] === '#ffc107' ? '#1f2d3d' : '#fff' ?>">
                <?= et($def['label']) ?>
                <?php if ($def['completo']): ?><i class="bi bi-circle-square ms-1" title="<?= et('Afecta al diente completo') ?>"></i><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div class="form-text mt-2">
            <?= et('Elige capa y estado, luego haz clic en una cara del diente. El icono ▣ marca los estados que afectan al diente completo.') ?>
        </div>
    </div></div>

    <div class="card mb-3"><div class="card-body">
        <?php include __DIR__ . '/_grid.php'; ?>

        <hr class="my-3">
        <div class="row g-3">
            <div class="col-md-8">
                <div class="small fw-semibold text-muted mb-2"><?= et('Resumen') ?></div>
                <div id="resumen" class="d-flex flex-wrap gap-2"></div>
            </div>
            <div class="col-md-4">
                <div class="small fw-semibold text-muted mb-2"><?= et('Leyenda') ?></div>
                <ul class="small text-muted mb-0 ps-3">
                    <li><?= et('Relleno sólido: hallazgo actual.') ?></li>
                    <li><?= et('Rayado: tratamiento por hacer.') ?></li>
                    <li><?= et('Punto central: tratamiento realizado.') ?></li>
                    <li><?= et('Borde del recuadro: estado del diente completo.') ?></li>
                </ul>
            </div>
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
    var marcas = <?= json_encode((object) $estado['marcas'], JSON_UNESCAPED_UNICODE) ?>;
    var notas  = <?= json_encode((object) $estado['notas'],  JSON_UNESCAPED_UNICODE) ?>;
    var T = {
        nota:   <?= json_encode(t('Nota para el diente')) ?>,
        notas:  <?= json_encode(t('Notas por diente')) ?>,
        limpiar:<?= json_encode(t('¿Quitar todas las marcas del odontograma?')) ?>,
        capas:  <?= json_encode(['existente' => t('Hallazgos'), 'requerido' => t('Por tratar'), 'realizado' => t('Realizados')], JSON_UNESCAPED_UNICODE) ?>
    };
    var condicion = 'existente';
    var estado    = 'caries';
    var herramienta = 'marcar';   // marcar | borrar | nota

    function esc(s) { return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function vocab(cond) { return cond === 'existente' ? window.ODO_HALL : window.ODO_TRAT; }

    /* Paleta: primer estado de la capa activa por defecto. */
    function mostrarPaleta() {
        document.querySelectorAll('.paleta').forEach(function (p) {
            var visible = p.dataset.paleta === condicion;
            p.hidden = !visible;
            if (visible && herramienta === 'marcar') {
                var activo = p.querySelector('.paleta-btn.active') || p.querySelector('.paleta-btn');
                p.querySelectorAll('.paleta-btn').forEach(b => b.classList.remove('active'));
                activo.classList.add('active');
                estado = activo.dataset.estado;
            }
        });
    }

    function setHerramienta(h) {
        herramienta = h;
        document.getElementById('btnBorrar').classList.toggle('active', h === 'borrar');
        document.getElementById('btnNota').classList.toggle('active', h === 'nota');
        if (h === 'marcar') mostrarPaleta();
    }

    document.querySelectorAll('#condiciones button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#condiciones button').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            condicion = b.dataset.condicion;
            setHerramienta('marcar');
        });
    });

    document.querySelectorAll('.paleta-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            b.closest('.paleta').querySelectorAll('.paleta-btn').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            estado = b.dataset.estado;
            setHerramienta('marcar');
        });
    });

    document.getElementById('btnBorrar').addEventListener('click', () => setHerramienta(herramienta === 'borrar' ? 'marcar' : 'borrar'));
    document.getElementById('btnNota').addEventListener('click',   () => setHerramienta(herramienta === 'nota'   ? 'marcar' : 'nota'));

    function limpiaVacios(d, cara) {
        if (marcas[d] && marcas[d][cara] && !Object.keys(marcas[d][cara]).length) delete marcas[d][cara];
        if (marcas[d] && !Object.keys(marcas[d]).length) delete marcas[d];
    }

    document.querySelectorAll('.cara').forEach(function (celda) {
        celda.addEventListener('click', function () {
            var box = celda.closest('.diente');
            var d   = box.dataset.diente;

            if (herramienta === 'nota') {
                var n = prompt(T.nota + ' ' + d + ':', notas[d] || '');
                if (n === null) return;
                n = n.trim();
                if (n) notas[d] = n; else delete notas[d];
                repintar();
                return;
            }

            /* Los estados de diente completo viven en la cara 'C'. */
            var def  = vocab(condicion)[estado];
            var cara = (herramienta === 'marcar' && def && def.completo) ? 'C' : celda.dataset.cara;

            if (herramienta === 'borrar') {
                // Borra la capa activa en la cara tocada; si ahí no había nada,
                // borra la del diente completo.
                var tocada = marcas[d] && marcas[d][cara] && marcas[d][cara][condicion] ? cara : 'C';
                if (marcas[d] && marcas[d][tocada]) {
                    delete marcas[d][tocada][condicion];
                    limpiaVacios(d, tocada);
                }
                repintar();
                return;
            }

            marcas[d] = marcas[d] || {};
            marcas[d][cara] = marcas[d][cara] || {};
            if (marcas[d][cara][condicion] === estado) {
                delete marcas[d][cara][condicion];   // segundo clic = quitar
                limpiaVacios(d, cara);
            } else {
                marcas[d][cara][condicion] = estado;
            }
            repintar();
        });
    });

    function resumen() {
        var cuenta = { existente: {}, requerido: {}, realizado: {} };
        Object.values(marcas).forEach(function (porCara) {
            Object.values(porCara).forEach(function (porCond) {
                Object.entries(porCond).forEach(function ([cond, est]) {
                    cuenta[cond][est] = (cuenta[cond][est] || 0) + 1;
                });
            });
        });
        var h = '';
        Object.entries(cuenta).forEach(function ([cond, mapa]) {
            var entradas = Object.entries(mapa);
            if (!entradas.length) return;
            h += '<div class="me-3"><div class="text-muted small mb-1">' + esc(T.capas[cond]) + '</div>';
            entradas.forEach(function ([est, n]) {
                var def = vocab(cond)[est];
                if (!def) return;
                h += '<span class="badge border me-1 mb-1" style="background:' + def.color + ';color:#fff">' +
                     esc(def.label) + ': <strong>' + n + '</strong></span>';
            });
            h += '</div>';
        });
        document.getElementById('resumen').innerHTML = h || '<span class="text-muted small">—</span>';
    }

    function listaNotas() {
        var keys = Object.keys(notas).sort((a, b) => a - b);
        var el = document.getElementById('listaNotas');
        if (!keys.length) { el.innerHTML = ''; return; }
        var h = '<div class="fw-semibold mb-1"><i class="bi bi-card-text"></i> ' + esc(T.notas) + '</div><ul class="mb-0">';
        keys.forEach(d => { h += '<li><strong>' + d + ':</strong> ' + esc(notas[d]) + '</li>'; });
        el.innerHTML = h + '</ul>';
    }

    function repintar() { window.odoPintar(marcas, notas); resumen(); listaNotas(); }

    document.getElementById('limpiarTodo').addEventListener('click', function () {
        if (!confirm(T.limpiar)) return;
        Object.keys(marcas).forEach(k => delete marcas[k]);
        Object.keys(notas).forEach(k => delete notas[k]);
        repintar();
    });

    document.getElementById('odoForm').addEventListener('submit', function () {
        document.getElementById('datosInput').value = JSON.stringify({ marcas: marcas, notas: notas });
    });

    mostrarPaleta();
    repintar();
})();
</script>
<style>
/* `.d-flex` lleva !important y ganaría al atributo `hidden`. */
.paleta[hidden]{display:none!important}
.paleta-btn{font-weight:600;border-radius:8px;opacity:.6}
.paleta-btn.active{opacity:1;outline:3px solid var(--brand,#0b6fb8);outline-offset:2px;box-shadow:0 2px 8px rgba(0,0,0,.18)}
#btnBorrar.active,#btnNota.active{color:#fff;background:var(--brand,#0b6fb8);border-color:var(--brand,#0b6fb8)}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
