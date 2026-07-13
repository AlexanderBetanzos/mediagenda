<?php
/**
 * Orden de laboratorio imprimible: el papel que el paciente lleva al
 * laboratorio. Si ya hay resultados capturados, imprime también el reporte
 * (con los valores fuera de rango marcados), para entregárselo al paciente.
 *
 * Página independiente del panel, para que el PDF salga limpio; el membrete
 * sale de la configuración white-label del consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('laboratorio');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT o.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            p.telefono AS pac_tel, p.sexo,
            m.nombre AS medico_nombre, m.especialidad
     FROM lab_ordenes o
     JOIN pacientes p ON p.id = o.paciente_id
     LEFT JOIN usuarios m ON m.id = o.medico_id
     WHERE o.id = ? AND o.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$o = $st->fetch();
if (!$o) { http_response_code(404); die('Orden no encontrada.'); }

$items = db()->prepare(
    'SELECT i.*, e.preparacion, e.muestra
     FROM lab_orden_items i
     LEFT JOIN lab_estudios e ON e.id = i.estudio_id
     WHERE i.orden_id = ? ORDER BY i.id'
);
$items->execute([$id]);
$items = $items->fetchAll();

// Con resultados capturados el documento deja de ser una solicitud y pasa a
// ser un reporte: cambia el título y aparece la columna de resultados.
$conResultados = false;
foreach ($items as $i) { if (trim((string) $i['resultado']) !== '') { $conResultados = true; break; } }

// Indicaciones de preparación: se juntan sin repetir, para que el paciente
// lea "Ayuno de 8 h" una sola vez aunque lo pidan tres estudios.
$preparaciones = [];
foreach ($items as $i) {
    $p = trim((string) ($i['preparacion'] ?? ''));
    if ($p !== '' && !in_array($p, $preparaciones, true)) $preparaciones[] = $p;
}

$acento = color_acento();
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
        .fuera{color:#b91c1c;font-weight:600}
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
    <a href="<?= BASE_URL ?>/laboratorio/ver?id=<?= $id ?>" class="btn btn-light"><?= et('Volver') ?></a>
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
                <?= $conResultados ? et('Reporte de laboratorio') : et('Orden de laboratorio') ?>
            </div>
            <div class="h5 mb-1"><?= e($o['folio']) ?></div>
            <div class="small"><?= et('Fecha') ?>: <?= fmt_fecha($o['fecha']) ?></div>
            <?php if ($o['prioridad'] === 'urgente'): ?>
                <div class="small fw-bold text-danger text-uppercase"><?= et('Urgente') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Paciente / solicitante -->
    <div class="row mb-4">
        <div class="col-7">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Paciente') ?></div>
            <div class="fw-semibold"><?= e($o['pac_nombre'] . ' ' . $o['pac_ape']) ?></div>
            <div class="small text-muted">
                <?= e(edad($o['fecha_nacimiento'])) ?>
                <?php if ($o['pac_tel']): ?> · <?= e($o['pac_tel']) ?><?php endif; ?>
            </div>
            <?php if ($o['diagnostico']): ?>
            <div class="small mt-2">
                <span class="text-muted"><?= et('Diagnóstico presuntivo') ?>:</span> <?= e($o['diagnostico']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($o['medico_nombre']): ?>
        <div class="col-5 text-end">
            <div class="text-uppercase small text-muted fw-semibold"><?= et('Solicita') ?></div>
            <div class="fw-semibold"><?= e($o['medico_nombre']) ?></div>
            <div class="small text-muted"><?= e($o['especialidad'] ?: t('Médico')) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($o['proveedor']): ?>
    <div class="small text-muted mb-2"><?= et('Laboratorio') ?>: <strong><?= e($o['proveedor']) ?></strong></div>
    <?php endif; ?>

    <!-- Estudios -->
    <table class="table tabla-items align-middle">
        <thead><tr>
            <th style="width:<?= $conResultados ? '40%' : '60%' ?>"><?= et('Estudio') ?></th>
            <th><?= et('Muestra') ?></th>
            <?php if ($conResultados): ?>
                <th><?= et('Resultado') ?></th>
                <th><?= et('Referencia') ?></th>
            <?php else: ?>
                <th class="text-end"><?= et('Precio') ?></th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <tr>
                <td class="fw-semibold"><?= e($i['nombre']) ?></td>
                <td class="small text-muted"><?= e($i['muestra'] ?: '—') ?></td>
                <?php if ($conResultados): ?>
                    <td class="<?= $i['fuera_rango'] ? 'fuera' : '' ?>">
                        <?= e($i['resultado'] ?: '—') ?>
                        <?php if ($i['unidad']): ?> <span class="small text-muted"><?= e($i['unidad']) ?></span><?php endif; ?>
                        <?php if ($i['fuera_rango']): ?> <i class="bi bi-exclamation-triangle-fill"></i><?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e($i['referencia'] ?: '—') ?></td>
                <?php else: ?>
                    <td class="text-end"><?= fmt_money($i['precio']) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (!$conResultados && (float) $o['total'] > 0): ?>
        <tfoot><tr>
            <td colspan="2" class="text-end fw-semibold"><?= et('Total') ?></td>
            <td class="text-end fw-bold"><?= fmt_money($o['total']) ?></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>

    <?php if (!$conResultados && $preparaciones): ?>
    <div class="border rounded p-3 mb-3">
        <div class="fw-semibold mb-1"><i class="bi bi-info-circle acento"></i> <?= et('Preparación antes del estudio') ?></div>
        <ul class="mb-0 small">
            <?php foreach ($preparaciones as $p): ?><li><?= e($p) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($o['notas']): ?>
    <div class="small text-muted mb-3">
        <span class="fw-semibold"><?= et('Notas') ?>:</span> <?= nl2br(e($o['notas'])) ?>
    </div>
    <?php endif; ?>

    <?php if ($conResultados): ?>
    <p class="small text-muted mt-4">
        <?= et('Los valores marcados en rojo están fuera del rango de referencia. Este reporte no sustituye la valoración de tu médico.') ?>
    </p>
    <?php endif; ?>

    <div class="row mt-5">
        <div class="col-6 offset-6">
            <div class="firma text-center">
                <?= e($o['medico_nombre'] ?: t('Médico solicitante')) ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
