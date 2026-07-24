<?php
/**
 * Consentimiento informado imprimible (con las firmas). Página independiente
 * del panel para que el PDF salga limpio; membrete white-label del consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
ensure_consentimientos_table();

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare(
    'SELECT c.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento, p.sexo,
            m.nombre AS medico_nombre, m.especialidad, m.cedula
     FROM consentimientos c
     JOIN pacientes p ON p.id = c.paciente_id
     LEFT JOIN usuarios m ON m.id = c.medico_id
     WHERE c.id = ? AND c.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$c = $st->fetch();
if (!$c) { http_response_code(404); die('Consentimiento no encontrado.'); }

$acento    = color_acento();
$pacNombre = trim($c['pac_nombre'] . ' ' . ($c['pac_ape'] ?? ''));
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($c['titulo']) ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d}
        .hoja{max-width:820px;margin:1.5rem auto;background:#fff;padding:2.5rem;box-shadow:0 4px 24px rgba(15,39,71,.12)}
        .acento{color:<?= $acento ?>}
        .cuerpo{white-space:pre-wrap;line-height:1.7;font-size:.98rem;text-align:justify}
        .firma-box{border-top:1px solid #94a3b8;margin-top:.4rem;padding-top:.4rem;font-size:.85rem;color:#64748b;text-align:center}
        .firma-img{height:90px;max-width:100%;object-fit:contain}
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
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $c['paciente_id'] ?>" class="btn btn-light"><?= et('Volver al paciente') ?></a>
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
        <div class="text-end small text-muted">
            <?= et('Fecha') ?>: <strong><?= e(fmt_fecha($c['creado_en'])) ?></strong>
        </div>
    </div>

    <h2 class="h5 text-center mb-4"><?= e(mb_strtoupper($c['titulo'])) ?></h2>

    <div class="mb-4 small">
        <strong><?= et('Paciente') ?>:</strong> <?= e($pacNombre) ?>
        <?php if ($c['fecha_nacimiento']): ?> · <strong><?= et('Edad') ?>:</strong> <?= e(edad($c['fecha_nacimiento'])) ?><?php endif; ?>
    </div>

    <div class="cuerpo mb-5"><?= e($c['contenido']) ?></div>

    <!-- Firmas -->
    <div class="row g-5 mt-2">
        <div class="col-6 text-center">
            <?php if ($c['firma_paciente']): ?><img src="<?= e($c['firma_paciente']) ?>" class="firma-img" alt=""><?php endif; ?>
            <div class="firma-box">
                <strong><?= e($c['firmante'] ?: $pacNombre) ?></strong><br>
                <?= et('Firma del paciente / tutor') ?>
            </div>
        </div>
        <div class="col-6 text-center">
            <?php if ($c['firma_medico']): ?><img src="<?= e($c['firma_medico']) ?>" class="firma-img" alt=""><?php endif; ?>
            <div class="firma-box">
                <strong><?= e($c['medico_nombre'] ?: '') ?></strong><br>
                <?= et('Firma del médico') ?><?php if ($c['cedula']): ?> · <?= et('Céd.') ?> <?= e($c['cedula']) ?><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (($_GET['print'] ?? '') === '1'): ?><script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 400); });</script><?php endif; ?>
</body>
</html>
