<?php
/**
 * Cabecera y membrete de las hojas imprimibles de fisioterapia.
 * Espera: $hojaTitulo, $hojaDoc (rótulo a la derecha), $hojaFecha, $hojaVolver.
 */
$acento = color_acento();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($hojaTitulo) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d}
        .hoja{max-width:860px;margin:1.5rem auto;background:#fff;padding:2.2rem 2.5rem;box-shadow:0 4px 24px rgba(15,39,71,.12)}
        .acento{color:<?= $acento ?>}
        .seccion{font-size:.76rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;
                 border-bottom:1px solid #e2e8f0;padding-bottom:.25rem;margin:1.3rem 0 .55rem}
        .narrativa{white-space:pre-wrap;line-height:1.55}
        .tbl th{background:#f6f8fa;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#64748b}
        .tbl td,.tbl th{padding:.3rem .45rem}
        .kv{font-size:.88rem}.kv b{color:#475569;font-weight:600}
        .firma{border-top:1px solid #94a3b8;margin-top:4rem;padding-top:.4rem;font-size:.85rem;color:#64748b}
        @media print{
            body{background:#fff}
            .hoja{max-width:none;margin:0;padding:0;box-shadow:none}
            .no-print{display:none!important}
            .avoid{page-break-inside:avoid}
            *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
        }
    </style>
</head>
<body>
<div class="text-center py-3 no-print">
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir / Guardar como PDF') ?></button>
    <a href="<?= e($hojaVolver) ?>" class="btn btn-light"><?= et('Volver') ?></a>
</div>
<div class="hoja">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div class="d-flex align-items-center gap-3">
            <?php if (cfg('marca_logo')): ?>
                <img src="<?= e(cfg('marca_logo')) ?>" alt="" style="max-height:60px;max-width:220px;width:auto;object-fit:contain">
            <?php else: ?>
                <i class="bi bi-person-walking acento" style="font-size:2.4rem"></i>
            <?php endif; ?>
            <div>
                <h1 class="h4 mb-0 acento"><?= e(marca_nombre()) ?></h1>
                <?php if (cfg('marca_lema')): ?><div class="small text-muted"><?= e(cfg('marca_lema')) ?></div><?php endif; ?>
                <div class="small text-muted">
                    <?php if (cfg('direccion')): ?><?= e(cfg('direccion')) ?><br><?php endif; ?>
                    <?php if (cfg('telefono')): ?><?= e(cfg('telefono')) ?><?php endif; ?>
                    <?php if (cfg('email')): ?> · <?= e(cfg('email')) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="text-end">
            <div class="fw-bold text-uppercase small text-muted"><?= e($hojaDoc) ?></div>
            <div class="small"><?= et('Fecha') ?>: <?= fmt_fecha($hojaFecha) ?></div>
        </div>
    </div>
