<?php
/**
 * Rejilla del odontograma (notación FDI) + estilos + pintado.
 * Define `window.odoPintar(marcas)`, que refleja en el DOM la estructura
 * marcas[diente][cara][condicion] = estado. Lo usan el editor y el historial.
 *
 * Cada diente se dibuja como cinco caras: vestibular arriba, lingual abajo,
 * oclusal al centro y mesial/distal a los lados. Mesial apunta a la línea
 * media, así que en los cuadrantes 1 y 4 va a la derecha del recuadro.
 */
require_once __DIR__ . '/../includes/odontograma.php';

$__hall = odo_hallazgos();
$__trat = odo_tratamientos();

/** Un cuadrante de ocho dientes. $arco = 'sup' | 'inf'. */
function odo_cuadrante(array $dientes, string $arco): string
{
    $h = '<div class="cuadrante">';
    foreach ($dientes as $d) {
        $mesialDer = odo_mesial_a_la_derecha($d);
        $izq = $mesialDer ? 'D' : 'M';
        $der = $mesialDer ? 'M' : 'D';
        $h .= '<div class="diente-wrap">'
            . ($arco === 'sup' ? '<span class="num">' . $d . '</span>' : '')
            . '<div class="diente" data-diente="' . $d . '">'
            . '<span class="cara cara-v" data-cara="V" title="Vestibular"></span>'
            . '<span class="cara cara-m" data-cara="' . $izq . '"></span>'
            . '<span class="cara cara-o" data-cara="O" title="Oclusal"></span>'
            . '<span class="cara cara-d" data-cara="' . $der . '"></span>'
            . '<span class="cara cara-l" data-cara="L" title="Lingual/Palatina"></span>'
            . '</div>'
            . ($arco === 'inf' ? '<span class="num">' . $d . '</span>' : '')
            . '</div>';
    }
    return $h . '</div>';
}
?>
<div class="odo-wrap mx-auto">
    <div class="d-flex justify-content-between text-muted small px-1 mb-1">
        <span><i class="bi bi-arrow-left"></i> <?= et('Derecha') ?></span>
        <span class="fw-semibold"><?= et('Superior') ?></span>
        <span><?= et('Izquierda') ?> <i class="bi bi-arrow-right"></i></span>
    </div>
    <div class="arcada">
        <?= odo_cuadrante([18,17,16,15,14,13,12,11], 'sup') ?>
        <div class="midline"></div>
        <?= odo_cuadrante([21,22,23,24,25,26,27,28], 'sup') ?>
    </div>
    <div class="arcada mt-3">
        <?= odo_cuadrante([48,47,46,45,44,43,42,41], 'inf') ?>
        <div class="midline"></div>
        <?= odo_cuadrante([31,32,33,34,35,36,37,38], 'inf') ?>
    </div>
    <div class="text-center text-muted small fw-semibold mt-1"><?= et('Inferior') ?></div>
</div>

<style>
.odo-wrap{max-width:720px}
.arcada{display:flex;justify-content:center;align-items:center}
.cuadrante{display:flex;gap:6px}
.midline{width:2px;align-self:stretch;background:#cbd5e1;margin:0 14px;border-radius:2px}
.diente-wrap{display:flex;flex-direction:column;align-items:center;gap:2px}
.diente-wrap .num{font-size:.68rem;font-weight:700;color:#94a3b8}

.diente{position:relative;display:grid;width:34px;height:34px;
        grid-template-columns:8px 1fr 8px;grid-template-rows:8px 1fr 8px;
        grid-template-areas:"v v v" "m o d" "l l l";
        border:2px solid #cbd5e1;border-radius:5px;overflow:hidden;background:#fff;
        cursor:pointer;transition:transform .06s,box-shadow .06s}
.diente:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(15,39,71,.18)}
.cara{display:block;background:#fff;box-shadow:inset 0 0 0 .5px #e2e8f0}
.cara-v{grid-area:v} .cara-m{grid-area:m} .cara-o{grid-area:o} .cara-d{grid-area:d} .cara-l{grid-area:l}
.cara:hover{filter:brightness(.9)}

/* Tratamiento requerido: rayado diagonal en el color del tratamiento. */
.cara.req{background-image:repeating-linear-gradient(45deg,var(--req) 0 3px,transparent 3px 6px)}
/* Tratamiento realizado: punto central. */
.cara.hecho{position:relative}
.cara.hecho::after{content:'';position:absolute;inset:0;margin:auto;width:5px;height:5px;
                   border-radius:50%;background:var(--hecho);box-shadow:0 0 0 1px #fff}

/* Marca de diente completo: el borde del recuadro. */
.diente.completo-req{border-style:dashed}
.diente.ausente{opacity:.5}
.diente.ausente::after{content:'\00d7';position:absolute;inset:0;display:flex;align-items:center;
                       justify-content:center;font-size:1.5rem;font-weight:700;color:#475569;
                       line-height:1;pointer-events:none}

.tiene-nota .num::after{content:'';display:inline-block;width:6px;height:6px;margin-left:3px;
                        border-radius:50%;background:var(--brand,#1f6b73)}

@media print{
  .app-navbar,.sidebar,.breadcrumb,.no-print,footer{display:none!important}
  .card{border:none!important;box-shadow:none!important}
  main{width:100%!important;max-width:100%!important;flex:0 0 100%!important}
  .diente{box-shadow:none!important}
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}
</style>

<script>
window.ODO_HALL = <?= json_encode(array_map(fn($v) => ['color' => $v['color'], 'completo' => $v['completo'], 'label' => t($v['label'])], $__hall), JSON_UNESCAPED_UNICODE) ?>;
window.ODO_TRAT = <?= json_encode(array_map(fn($v) => ['color' => $v['color'], 'completo' => $v['completo'], 'label' => t($v['label'])], $__trat), JSON_UNESCAPED_UNICODE) ?>;
window.ODO_CARAS = <?= json_encode(array_keys(odo_caras())) ?>;

/**
 * Refleja `marcas` (y opcionalmente `notas`) en la rejilla. Es idempotente:
 * limpia lo anterior antes de pintar, así se puede llamar en cada cambio.
 */
window.odoPintar = function (marcas, notas) {
    marcas = marcas || {};
    notas  = notas  || {};
    document.querySelectorAll('.diente').forEach(function (box) {
        var d = box.dataset.diente;
        var m = marcas[d] || {};

        window.ODO_CARAS.forEach(function (c) {
            var el = box.querySelector('[data-cara="' + c + '"]');
            if (!el) return;
            var st = m[c] || {};
            el.style.background = st.existente ? window.ODO_HALL[st.existente].color : '';
            el.classList.toggle('req', !!st.requerido);
            el.style.setProperty('--req', st.requerido ? window.ODO_TRAT[st.requerido].color : 'transparent');
            el.classList.toggle('hecho', !!st.realizado);
            el.style.setProperty('--hecho', st.realizado ? window.ODO_TRAT[st.realizado].color : 'transparent');
            el.title = [
                st.existente ? window.ODO_HALL[st.existente].label : '',
                st.requerido ? '→ ' + window.ODO_TRAT[st.requerido].label : '',
                st.realizado ? '✓ ' + window.ODO_TRAT[st.realizado].label : ''
            ].filter(Boolean).join(' · ');
        });

        /* Diente completo: el borde y, si está ausente, la cruz. */
        var c = m['C'] || {};
        var borde = c.realizado ? window.ODO_TRAT[c.realizado].color
                  : c.requerido ? window.ODO_TRAT[c.requerido].color
                  : c.existente ? window.ODO_HALL[c.existente].color : '';
        box.style.borderColor = borde || '';
        box.classList.toggle('completo-req', !!c.requerido && !c.realizado);
        box.classList.toggle('ausente', c.existente === 'ausente');
        box.parentElement.classList.toggle('tiene-nota', !!notas[d]);
        if (borde) {
            var lbl = c.realizado ? '✓ ' + window.ODO_TRAT[c.realizado].label
                    : c.requerido ? '→ ' + window.ODO_TRAT[c.requerido].label
                    : window.ODO_HALL[c.existente].label;
            box.setAttribute('aria-label', d + ': ' + lbl);
        } else {
            box.removeAttribute('aria-label');
        }
    });
};
</script>
