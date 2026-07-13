<?php
/**
 * Documento clínico imprimible, con el membrete white-label del consultorio.
 * Se imprime el texto TAL COMO SE GUARDÓ: es un papel que se firma, no se
 * vuelve a resolver nada aquí.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('documentos');

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT d.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            m.nombre AS medico_nombre, m.especialidad
     FROM documentos d
     JOIN pacientes p ON p.id = d.paciente_id
     LEFT JOIN usuarios m ON m.id = d.medico_id
     WHERE d.id = ? AND d.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$d = $st->fetch();
if (!$d) { http_response_code(404); die('Documento no encontrado.'); }

$acento = color_acento();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($d['titulo']) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d}
        .hoja{max-width:820px;margin:1.5rem auto;background:#fff;padding:3rem;box-shadow:0 4px 24px rgba(15,39,71,.12)}
        .acento{color:<?= $acento ?>}
        /* El cuerpo respeta los saltos de línea que escribió el médico. */
        .cuerpo{white-space:pre-wrap;line-height:1.9;font-size:1.02rem;text-align:justify}
        .firma{border-top:1px solid #94a3b8;margin-top:5rem;padding-top:.4rem;font-size:.9rem}
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
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $d['paciente_id'] ?>" class="btn btn-light"><?= et('Volver al paciente') ?></a>
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
            <div class="fw-bold text-uppercase small text-muted"><?= e($d['titulo']) ?></div>
            <div class="small"><?= e($d['folio']) ?></div>
            <div class="small text-muted"><?= fmt_fecha($d['fecha']) ?></div>
        </div>
    </div>

    <!-- Cuerpo -->
    <div class="cuerpo mb-4"><?= e($d['cuerpo']) ?></div>

    <!-- Firma -->
    <div class="row">
        <div class="col-6 offset-6 text-center">
            <div class="firma">
                <div class="fw-semibold"><?= e($d['medico_nombre'] ?: '') ?></div>
                <?php if ($d['especialidad']): ?>
                    <div class="text-muted small"><?= e($d['especialidad']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
