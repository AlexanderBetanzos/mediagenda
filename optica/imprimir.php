<?php
/**
 * Orden de trabajo imprimible: el papel que se manda al laboratorio y el que se
 * queda el cliente como comprobante.
 *
 * La graduación se imprime en grande y en monoespaciada a propósito: un dígito
 * mal leído (un eje de 85° que se lee 65°) son unos lentes tirados a la basura.
 * Va también la fecha prometida y el saldo, que es lo que el cliente pregunta.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('optica');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT t.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel,
            p.fecha_nacimiento, v.nombre AS vendedor_nombre
     FROM optica_trabajos t
     JOIN pacientes p ON p.id = t.paciente_id
     LEFT JOIN usuarios v ON v.id = t.vendedor_id
     WHERE t.id = ? AND t.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$t = $st->fetch();
if (!$t) { http_response_code(404); die('Orden no encontrada.'); }

$grad = null;
if ($t['graduacion_id']) {
    $g = db()->prepare('SELECT * FROM optica_graduaciones WHERE id = ? AND consultorio_id = ?');
    $g->execute([(int) $t['graduacion_id'], tenant_id()]);
    $grad = $g->fetch() ?: null;
}

$saldo  = (float) $t['total'] - (float) $t['anticipo'];
$acento = color_acento();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($t['folio']) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d}
        .hoja{max-width:820px;margin:1.5rem auto;background:#fff;padding:2.5rem;box-shadow:0 4px 24px rgba(15,39,71,.12)}
        .acento{color:<?= $acento ?>}
        .tabla-items th{background:#f6f8fa;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:#64748b}
        /* La graduación en grande y monoespaciada: un eje mal leído son unos
           lentes perdidos. */
        .grad td, .grad th{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1.15rem;text-align:center}
        .grad thead th{font-size:.72rem;font-family:inherit;text-transform:uppercase;letter-spacing:.03em;color:#64748b}
        .firma{border-top:1px solid #94a3b8;margin-top:4rem;padding-top:.4rem;font-size:.85rem;color:#64748b}
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
    <a href="<?= BASE_URL ?>/optica/ver?id=<?= $id ?>" class="btn btn-light"><?= et('Volver') ?></a>
</div>

<div class="hoja">
    <!-- Membrete -->
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <?php if (cfg('marca_logo')): ?>
                <img src="<?= e(cfg('marca_logo')) ?>" alt="" style="max-height:60px;width:auto">
            <?php else: ?>
                <i class="bi bi-eyeglasses acento" style="font-size:2.5rem"></i>
            <?php endif; ?>
            <div>
                <h1 class="h4 mb-0 acento"><?= e(marca_nombre()) ?></h1>
                <div class="small text-muted">
                    <?php if (cfg('direccion')): ?><?= e(cfg('direccion')) ?><br><?php endif; ?>
                    <?php if (cfg('telefono')): ?><?= e(cfg('telefono')) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="text-end">
            <div class="fw-bold text-uppercase small text-muted"><?= et('Orden de trabajo') ?></div>
            <div class="h5 mb-1"><?= e($t['folio']) ?></div>
            <div class="small"><?= et('Fecha') ?>: <?= fmt_fecha($t['fecha']) ?></div>
            <?php if ($t['fecha_promesa']): ?>
            <div class="small fw-bold"><?= et('Entrega') ?>: <?= fmt_fecha($t['fecha_promesa']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cliente -->
    <div class="row mb-4">
        <div class="col-7">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Cliente') ?></div>
            <div class="fw-semibold"><?= e($t['pac_nombre'] . ' ' . $t['pac_ape']) ?></div>
            <div class="small text-muted">
                <?= e(edad($t['fecha_nacimiento'])) ?>
                <?php if ($t['pac_tel']): ?> · <?= e($t['pac_tel']) ?><?php endif; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <?php if ($t['laboratorio']): ?>
                <div class="text-uppercase small text-muted fw-semibold"><?= et('Laboratorio') ?></div>
                <div class="fw-semibold"><?= e($t['laboratorio']) ?></div>
            <?php endif; ?>
            <?php if ($t['vendedor_nombre']): ?>
                <div class="small text-muted mt-1"><?= et('Atendió') ?>: <?= e($t['vendedor_nombre']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Graduación -->
    <?php if ($grad): ?>
    <div class="text-uppercase small text-muted fw-semibold mb-1"><?= et('Graduación') ?></div>
    <table class="table table-bordered grad mb-3">
        <thead><tr>
            <th style="width:60px"></th>
            <th><?= et('Esfera') ?></th><th><?= et('Cilindro') ?></th><th><?= et('Eje') ?></th>
            <th><?= et('Adición') ?></th><th><?= et('DIP') ?></th><th><?= et('Altura') ?></th>
        </tr></thead>
        <tbody>
            <tr>
                <th>OD</th>
                <td><?= fmt_dioptria($grad['od_esfera']) ?></td>
                <td><?= fmt_dioptria($grad['od_cilindro']) ?></td>
                <td><?= fmt_eje($grad['od_eje']) ?></td>
                <td><?= fmt_dioptria($grad['od_adicion']) ?></td>
                <td><?= e((string) ($grad['od_dip'] ?: '—')) ?></td>
                <td><?= e((string) ($grad['od_altura'] ?: '—')) ?></td>
            </tr>
            <tr>
                <th>OI</th>
                <td><?= fmt_dioptria($grad['oi_esfera']) ?></td>
                <td><?= fmt_dioptria($grad['oi_cilindro']) ?></td>
                <td><?= fmt_eje($grad['oi_eje']) ?></td>
                <td><?= fmt_dioptria($grad['oi_adicion']) ?></td>
                <td><?= e((string) ($grad['oi_dip'] ?: '—')) ?></td>
                <td><?= e((string) ($grad['oi_altura'] ?: '—')) ?></td>
            </tr>
        </tbody>
    </table>
    <div class="small text-muted mb-4">
        <?php if ($grad['dip']): ?><strong><?= et('DIP total') ?>:</strong> <?= e((string) $grad['dip']) ?> mm · <?php endif; ?>
        <?php if ($grad['tipo_lente']): ?><strong><?= et(optica_tipos_lente()[$grad['tipo_lente']]) ?></strong><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Lo que se vendió -->
    <table class="table tabla-items align-middle">
        <thead><tr>
            <th style="width:70%"><?= et('Concepto') ?></th>
            <th class="text-end"><?= et('Importe') ?></th>
        </tr></thead>
        <tbody>
            <tr>
                <td>
                    <strong><?= et('Armazón') ?></strong><br>
                    <span class="small text-muted"><?= e($t['armazon_desc'] ?: t('El cliente trajo el suyo')) ?></span>
                </td>
                <td class="text-end"><?= fmt_money($t['armazon_precio']) ?></td>
            </tr>
            <tr>
                <td>
                    <strong><?= et('Micas') ?></strong><br>
                    <span class="small text-muted">
                        <?= e($t['mica_desc'] ?: '—') ?>
                        <?php if ($t['tratamientos']): ?> · <?= e($t['tratamientos']) ?><?php endif; ?>
                    </span>
                </td>
                <td class="text-end"><?= fmt_money($t['mica_precio']) ?></td>
            </tr>
            <?php if ((float) $t['descuento'] > 0): ?>
            <tr>
                <td class="text-muted"><?= et('Descuento') ?></td>
                <td class="text-end">− <?= fmt_money($t['descuento']) ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="text-end fw-semibold"><?= et('Total') ?></td>
                <td class="text-end fw-bold"><?= fmt_money($t['total']) ?></td>
            </tr>
            <tr>
                <td class="text-end text-muted"><?= et('Anticipo') ?></td>
                <td class="text-end"><?= fmt_money($t['anticipo']) ?></td>
            </tr>
            <tr>
                <td class="text-end fw-semibold"><?= et('Saldo al recoger') ?></td>
                <td class="text-end fw-bold h5 mb-0"><?= fmt_money($saldo) ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($t['notas']): ?>
    <div class="small text-muted mb-3"><strong><?= et('Notas') ?>:</strong> <?= nl2br(e($t['notas'])) ?></div>
    <?php endif; ?>

    <div class="row mt-5">
        <div class="col-6">
            <div class="firma text-center"><?= et('Recibí conforme (cliente)') ?></div>
        </div>
        <div class="col-6">
            <div class="firma text-center"><?= e($t['vendedor_nombre'] ?: t('Atendió')) ?></div>
        </div>
    </div>
</div>

</body>
</html>
