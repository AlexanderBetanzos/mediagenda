<?php
/**
 * Presupuesto imprimible: documento que el paciente se lleva o firma.
 * Página independiente (sin el panel) para que el PDF salga limpio; el
 * membrete sale de la configuración white-label del consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('presupuestos');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT pr.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento, p.telefono AS pac_tel,
            m.nombre AS medico_nombre, m.especialidad
     FROM presupuestos pr
     JOIN pacientes p ON p.id = pr.paciente_id
     LEFT JOIN usuarios m ON m.id = pr.medico_id
     WHERE pr.id = ? AND pr.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$pre = $st->fetch();
if (!$pre) { http_response_code(404); die('Presupuesto no encontrado.'); }

$items = db()->prepare('SELECT * FROM presupuesto_items WHERE presupuesto_id = ? ORDER BY orden, id');
$items->execute([$id]);
$items = $items->fetchAll();

$pagos = db()->prepare('SELECT fecha, monto, metodo FROM presupuesto_pagos
                        WHERE presupuesto_id = ? AND consultorio_id = ? ORDER BY fecha, id');
$pagos->execute([$id, tenant_id()]);
$pagos = $pagos->fetchAll();

$pagado = array_sum(array_column($pagos, 'monto'));
$saldo  = (float) $pre['total'] - $pagado;
$acento = color_acento();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pre['folio']) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d}
        .hoja{max-width:820px;margin:1.5rem auto;background:#fff;padding:2.5rem;box-shadow:0 4px 24px rgba(15,39,71,.12)}
        .acento{color:<?= $acento ?>}
        .tabla-items th{background:#f6f8fa;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:#64748b}
        .firma{border-top:1px solid #94a3b8;margin-top:4.5rem;padding-top:.4rem;font-size:.85rem;color:#64748b}
        @media print{
            body{background:#fff}
            .hoja{max-width:none;margin:0;padding:0;box-shadow:none}
            .no-print{display:none!important}
            *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
        }
    </style>
</head>
<body>

<div class="text-center py-3 no-print">
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir / Guardar como PDF') ?></button>
    <a href="<?= BASE_URL ?>/presupuestos/ver?id=<?= $id ?>" class="btn btn-light"><?= et('Volver') ?></a>
</div>

<div class="hoja">
    <!-- Membrete -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <?php if (cfg('marca_logo')): ?>
                <img src="<?= e(cfg('marca_logo')) ?>" alt="" style="max-height:60px;width:auto">
            <?php else: ?>
                <i class="bi bi-heart-pulse-fill acento" style="font-size:2.5rem"></i>
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
            <div class="fw-bold text-uppercase small text-muted"><?= et('Presupuesto') ?></div>
            <div class="h5 mb-1"><?= e($pre['folio']) ?></div>
            <div class="small"><?= et('Fecha') ?>: <?= fmt_fecha($pre['fecha']) ?></div>
            <?php if ($pre['vigencia']): ?>
            <div class="small text-muted"><?= et('Vigente hasta') ?>: <?= fmt_fecha($pre['vigencia']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Paciente -->
    <div class="row mb-4">
        <div class="col-7">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Paciente') ?></div>
            <div class="fw-semibold"><?= e($pre['pac_nombre'] . ' ' . $pre['pac_ape']) ?></div>
            <div class="small text-muted">
                <?= e(edad($pre['fecha_nacimiento'])) ?>
                <?php if ($pre['pac_tel']): ?> · <?= e($pre['pac_tel']) ?><?php endif; ?>
            </div>
        </div>
        <?php if ($pre['medico_nombre']): ?>
        <div class="col-5 text-end">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Atiende') ?></div>
            <div class="fw-semibold"><?= e($pre['medico_nombre']) ?></div>
            <div class="small text-muted"><?= e($pre['especialidad'] ?: t('Médico / Dentista')) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Conceptos -->
    <table class="table tabla-items align-middle">
        <thead><tr>
            <th style="width:45%"><?= et('Procedimiento') ?></th>
            <th><?= et('Diente') ?></th>
            <th class="text-end"><?= et('Cant.') ?></th>
            <th class="text-end"><?= et('Precio') ?></th>
            <th class="text-end"><?= et('Importe') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['descripcion']) ?></td>
                <td class="text-muted">
                    <?= $it['diente'] ? e($it['diente']) : '—' ?>
                    <?php if ($it['caras']): ?><small>(<?= e($it['caras']) ?>)</small><?php endif; ?>
                </td>
                <td class="text-end"><?= (int) $it['cantidad'] ?></td>
                <td class="text-end"><?= fmt_money($it['precio']) ?></td>
                <td class="text-end"><?= fmt_money($it['importe']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totales -->
    <div class="row justify-content-end">
        <div class="col-sm-6 col-md-5">
            <div class="d-flex justify-content-between"><span class="text-muted"><?= et('Subtotal') ?></span><span><?= fmt_money($pre['subtotal']) ?></span></div>
            <?php if ((float) $pre['descuento'] > 0): ?>
            <div class="d-flex justify-content-between"><span class="text-muted"><?= et('Descuento') ?></span><span>−<?= fmt_money($pre['descuento']) ?></span></div>
            <?php endif; ?>
            <div class="d-flex justify-content-between border-top mt-2 pt-2 fs-5 fw-bold">
                <span><?= et('Total') ?></span><span class="acento"><?= fmt_money($pre['total']) ?></span>
            </div>
            <?php if ($pagado > 0): ?>
            <div class="d-flex justify-content-between mt-2"><span class="text-muted"><?= et('Abonado') ?></span><span><?= fmt_money($pagado) ?></span></div>
            <div class="d-flex justify-content-between fw-semibold"><span><?= et('Saldo') ?></span><span><?= fmt_money($saldo) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($pagos): ?>
    <div class="mt-4">
        <div class="text-uppercase small text-muted fw-semibold mb-2"><?= et('Abonos recibidos') ?></div>
        <table class="table table-sm w-auto">
            <?php foreach ($pagos as $pg): ?>
            <tr>
                <td class="text-muted pe-4"><?= fmt_fecha($pg['fecha']) ?></td>
                <td class="text-muted pe-4"><?= e($pg['metodo'] ?: '—') ?></td>
                <td class="text-end fw-semibold"><?= fmt_money($pg['monto']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($pre['notas']): ?>
    <div class="mt-4 small">
        <div class="text-uppercase text-muted fw-semibold mb-1"><?= et('Notas') ?></div>
        <p style="white-space:pre-line"><?= e($pre['notas']) ?></p>
    </div>
    <?php endif; ?>

    <!-- Aceptación -->
    <div class="row mt-5">
        <div class="col-6">
            <div class="firma"><?= et('Firma del paciente') ?></div>
        </div>
        <div class="col-6">
            <div class="firma"><?= et('Firma del profesional') ?></div>
        </div>
    </div>
    <p class="small text-muted mt-4 mb-0">
        <?= et('Este presupuesto es informativo y no constituye un comprobante fiscal.') ?>
        <?php if ($pre['vigencia']): ?>
            <?= et('Los precios se respetan hasta la fecha de vigencia indicada.') ?>
        <?php endif; ?>
    </p>
</div>

</body>
</html>
