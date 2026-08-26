<?php
/**
 * Informe de ultrasonido imprimible: el documento que se entrega al paciente y
 * al médico que lo solicitó. Sin impresión diagnóstica todavía es una solicitud
 * (el papel con la preparación que el paciente se lleva), y por eso cambia el
 * título y el contenido.
 *
 * Página independiente del panel, para que el PDF salga limpio; el membrete
 * sale de la configuración white-label del consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('imagenologia');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT e.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            p.telefono AS pac_tel, p.sexo,
            m.nombre AS medico_nombre, m.especialidad, m.cedula,
            pl.preparacion
     FROM img_estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     LEFT JOIN usuarios m ON m.id = e.medico_id
     LEFT JOIN img_plantillas pl ON pl.id = e.plantilla_id
     WHERE e.id = ? AND e.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$o = $st->fetch();
if (!$o) { http_response_code(404); die('Estudio no encontrado.'); }

$hl = db()->prepare('SELECT * FROM img_hallazgos WHERE estudio_id = ? ORDER BY orden, id');
$hl->execute([$id]);
$hallazgos = $hl->fetchAll();

// Solo se imprimen los renglones capturados: un informe con veinte "—" no lo
// lee nadie, y deja la duda de si se exploró o no.
$medidos = array_values(array_filter($hallazgos, fn($h) => trim((string) $h['valor']) !== ''));

$im = db()->prepare(
    "SELECT * FROM archivos
     WHERE img_estudio_id = ? AND consultorio_id = ? AND mime LIKE 'image/%'
     ORDER BY creado_en, id"
);
$im->execute([$id, tenant_id()]);
$imagenes = $im->fetchAll();

$esInforme = trim((string) $o['impresion']) !== '' || $medidos;
$acento    = color_acento();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($o['folio']) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d}
        .hoja{max-width:820px;margin:1.5rem auto;background:#fff;padding:2.5rem;box-shadow:0 4px 24px rgba(15,39,71,.12)}
        .acento{color:<?= $acento ?>}
        .tabla-items th{background:#f6f8fa;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:#64748b}
        .anormal{color:#b91c1c;font-weight:600}
        .seccion{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;
                 border-bottom:1px solid #e2e8f0;padding-bottom:.25rem;margin:1.5rem 0 .6rem}
        .narrativa{white-space:pre-wrap;line-height:1.6}
        .placa{width:100%;aspect-ratio:4/3;object-fit:contain;background:#0b1220;border-radius:4px}
        .firma{border-top:1px solid #94a3b8;margin-top:4.5rem;padding-top:.4rem;font-size:.85rem;color:#64748b}
        @media print{
            body{background:#fff}
            .hoja{max-width:none;margin:0;padding:0;box-shadow:none}
            .no-print{display:none!important}
            .placas{page-break-inside:avoid}
            *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
        }
    </style>
</head>
<body>

<div class="text-center py-3 no-print">
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir / Guardar como PDF') ?></button>
    <a href="<?= BASE_URL ?>/imagenologia/ver?id=<?= $id ?>" class="btn btn-light"><?= et('Volver') ?></a>
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
            <div class="fw-bold text-uppercase small text-muted">
                <?= $esInforme ? et('Informe de ultrasonido') : et('Solicitud de ultrasonido') ?>
            </div>
            <div class="h5 mb-1"><?= e($o['folio']) ?></div>
            <div class="small"><?= et('Fecha') ?>: <?= fmt_fecha($o['fecha']) ?></div>
        </div>
    </div>

    <!-- Paciente / solicitante -->
    <div class="row mb-3">
        <div class="col-7">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Paciente') ?></div>
            <div class="fw-semibold"><?= e($o['pac_nombre'] . ' ' . $o['pac_ape']) ?></div>
            <div class="small text-muted">
                <?= e(edad($o['fecha_nacimiento'])) ?>
                <?php if ($o['pac_tel']): ?> · <?= e($o['pac_tel']) ?><?php endif; ?>
            </div>
            <?php if ($o['indicacion']): ?>
            <div class="small mt-2">
                <span class="text-muted"><?= et('Indicación') ?>:</span> <?= e($o['indicacion']) ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-5 text-end">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Estudio') ?></div>
            <div class="fw-semibold"><?= e($o['nombre']) ?></div>
            <?php if ($o['referente']): ?>
                <div class="small text-muted"><?= et('Solicita') ?>: <?= e($o['referente']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($o['equipo'] || $o['transductor']): ?>
    <div class="small text-muted mb-2">
        <?= et('Equipo') ?>: <strong><?= e(trim($o['equipo'] . ' · ' . $o['transductor'], ' ·')) ?></strong>
    </div>
    <?php endif; ?>

    <?php if (!$esInforme): ?>
        <?php /* Todavía no hay informe: el papel sirve para agendar y preparar. */ ?>
        <?php if ($o['preparacion']): ?>
        <div class="border rounded p-3 my-4">
            <div class="fw-semibold mb-1"><i class="bi bi-info-circle acento"></i> <?= et('Preparación antes del estudio') ?></div>
            <div class="small"><?= e($o['preparacion']) ?></div>
        </div>
        <?php endif; ?>
        <?php if ((float) $o['precio'] > 0): ?>
        <div class="d-flex justify-content-between border-top pt-2">
            <span class="fw-semibold"><?= et('Costo del estudio') ?></span>
            <span class="fw-bold"><?= fmt_money($o['precio']) ?></span>
        </div>
        <?php endif; ?>
    <?php else: ?>

        <?php if ($o['tecnica']): ?>
            <div class="seccion"><?= et('Técnica') ?></div>
            <div class="narrativa small"><?= e($o['tecnica']) ?></div>
        <?php endif; ?>

        <?php if ($medidos): ?>
            <div class="seccion"><?= et('Mediciones y hallazgos') ?></div>
            <table class="table table-sm tabla-items align-middle">
                <thead><tr>
                    <th style="width:40%"><?= et('Renglón') ?></th>
                    <th><?= et('Valor') ?></th>
                    <th style="width:22%"><?= et('Referencia') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($medidos as $h): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($h['etiqueta']) ?></td>
                        <td class="<?= $h['anormal'] ? 'anormal' : '' ?>">
                            <?= e($h['valor']) ?>
                            <?php if ($h['unidad']): ?> <span class="small text-muted"><?= e($h['unidad']) ?></span><?php endif; ?>
                            <?php if ($h['anormal']): ?> <i class="bi bi-exclamation-triangle-fill"></i><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e($h['referencia'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($o['hallazgos']): ?>
            <div class="seccion"><?= et('Hallazgos') ?></div>
            <div class="narrativa"><?= e($o['hallazgos']) ?></div>
        <?php endif; ?>

        <?php if ($o['impresion']): ?>
            <div class="seccion"><?= et('Impresión diagnóstica') ?></div>
            <div class="narrativa fw-semibold"><?= e($o['impresion']) ?></div>
        <?php endif; ?>

        <?php if ($o['recomendaciones']): ?>
            <div class="seccion"><?= et('Recomendaciones') ?></div>
            <div class="narrativa"><?= e($o['recomendaciones']) ?></div>
        <?php endif; ?>

        <?php if ($imagenes): ?>
            <div class="seccion"><?= et('Imágenes del estudio') ?></div>
            <div class="row g-2 placas">
                <?php foreach ($imagenes as $a): ?>
                <div class="col-6">
                    <img class="placa" src="<?= BASE_URL ?>/pacientes/archivo?id=<?= (int) $a['id'] ?>&ver=1" alt="">
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="small text-muted mt-4">
            <?= et('Este informe se basa en un estudio ecográfico operador-dependiente y debe interpretarse junto con la clínica del paciente. No sustituye la valoración de tu médico.') ?>
        </p>
    <?php endif; ?>

    <div class="row mt-5">
        <div class="col-6 offset-6">
            <div class="firma text-center">
                <?= e($o['medico_nombre'] ?: t('Médico responsable')) ?>
                <?php if ($o['especialidad']): ?><br><?= e($o['especialidad']) ?><?php endif; ?>
                <?php if ($o['cedula']): ?><br><?= et('Cédula') ?> <?= e($o['cedula']) ?><?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
