<?php
/**
 * Membrete y estilos comunes de los documentos impresos (cobro, receta):
 * logo, nombre y datos del consultorio con su color, y reglas de impresión
 * que dejan solo la hoja.
 */

function comprobante_estilos(): string
{
    $c = color_acento();
    return '<style>
.hoja-doc{--c:' . $c . ';max-width:820px;margin:0 auto;background:#fff;color:#1f2d3d;border-radius:18px;box-shadow:0 10px 40px -18px rgba(15,23,42,.35);overflow:hidden}
.hoja-doc .franja{height:6px;background:linear-gradient(90deg,var(--c),color-mix(in srgb,var(--c) 45%,#fff))}
.hoja-doc .cuerpo{padding:32px 36px}
.hoja-doc .membrete{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;padding-bottom:18px;margin-bottom:20px;border-bottom:1px solid #e5e9f0}
.hoja-doc .membrete h2{color:var(--c);font-weight:800;font-size:1.35rem;margin:0}
.hoja-doc .membrete .datos{font-size:.8rem;color:#64748b;line-height:1.45}
.hoja-doc .doc-tipo{font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:#64748b;font-weight:700}
.hoja-doc .doc-folio{font-size:1.25rem;font-weight:800;color:#0f172a}
.hoja-doc .acento{color:var(--c)}
.hoja-doc .caja-suave{background:color-mix(in srgb,var(--c) 6%,#fff);border:1px solid color-mix(in srgb,var(--c) 18%,#fff);border-radius:12px;padding:12px 16px}
.hoja-doc table.tabla-doc{width:100%;border-collapse:collapse;font-size:.92rem}
.hoja-doc table.tabla-doc th{font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;border-bottom:2px solid #e5e9f0;padding:8px 6px;font-weight:700}
.hoja-doc table.tabla-doc td{border-bottom:1px solid #eef1f5;padding:9px 6px}
.hoja-doc .pie-doc{margin-top:26px;padding-top:12px;border-top:1px dashed #cbd5e1;font-size:.75rem;color:#94a3b8;text-align:center}
@media (max-width:576px){.hoja-doc .cuerpo{padding:20px 16px}.hoja-doc .membrete{flex-direction:column}}
@media print{
 .app-navbar,.sidebar,.breadcrumb,.no-print,footer,.ayuda-fab{display:none!important}
 body{background:#fff!important}
 main{width:100%!important;max-width:100%!important;flex:0 0 100%!important;padding:0!important}
 .hoja-doc{box-shadow:none;border-radius:0;max-width:none}
 .hoja-doc .cuerpo{padding:0 4mm}
 *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>';
}

/** Membrete: logo + datos del consultorio a la izquierda; tipo, folio y fecha a la derecha. */
function comprobante_membrete(string $tipo, string $folio, string $fechaTxt, string $extraDerecha = ''): string
{
    $logo = cfg('marca_logo');
    $datos = array_filter([
        cfg('razon_social') && cfg('razon_social') !== marca_nombre() ? e(cfg('razon_social')) : '',
        cfg('rfc') ? 'RFC ' . e(cfg('rfc')) : '',
        e(cfg('direccion')),
        trim(e(cfg('telefono')) . (cfg('telefono') && cfg('email') ? ' · ' : '') . e(cfg('email'))),
    ]);
    $izq = '<div class="d-flex align-items-center gap-3">'
        . ($logo ? '<img src="' . e($logo) . '" alt="" style="max-height:58px;max-width:200px;width:auto;object-fit:contain">'
                 : '<i class="bi bi-heart-pulse-fill acento" style="font-size:2.3rem"></i>')
        . '<div><h2>' . e(marca_nombre()) . '</h2>'
        . (cfg('marca_lema') ? '<div class="datos">' . e(cfg('marca_lema')) . '</div>' : '')
        . ($datos ? '<div class="datos">' . implode('<br>', $datos) . '</div>' : '')
        . '</div></div>';
    $der = '<div class="text-sm-end"><div class="doc-tipo">' . e($tipo) . '</div><div class="doc-folio">' . e($folio) . '</div>'
        . '<div class="small text-muted">' . $fechaTxt . '</div>' . $extraDerecha . '</div>';
    return '<div class="membrete">' . $izq . $der . '</div>';
}
