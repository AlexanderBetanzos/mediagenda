<?php
/**
 * Vuelta del paciente desde Mercado Pago. Página pública.
 *
 * El webhook es quien confirma de verdad, pero puede tardar unos segundos; aquí
 * se intenta confirmar en el momento con el id que trae la URL para que el
 * paciente vea "pagado" enseguida. Si falla, no pasa nada: el webhook lo hará.
 */
require_once __DIR__ . '/../includes/cobros.php';

$cobro = cobro_por_token((string) ($_GET['t'] ?? ''));
if (!$cobro) {
    http_response_code(404);
    die('Enlace no válido.');
}
tenant_forzar((int) $cobro['consultorio_id']);

$pagoId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
if ($cobro['estado'] === 'pendiente' && preg_match('/^\d+$/', $pagoId)) {
    try {
        cobro_confirmar_pago($pagoId);
        $cobro = cobro_por_id((int) $cobro['id']) ?: $cobro;
    } catch (Throwable $e) { /* lo resolverá el webhook */ }
}

$estadoMp = (string) ($_GET['status'] ?? $_GET['collection_status'] ?? '');
$pagado   = $cobro['estado'] === 'pagado';
$marca    = marca_nombre();
$acento   = color_acento();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pagado ? 'Pago recibido' : 'Pago en proceso' ?> · <?= e($marca) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d;display:flex;align-items:center;min-height:100vh}
        .hoja{max-width:460px;margin:2rem auto;background:#fff;border-radius:14px;padding:2.5rem 2rem;
              text-align:center;box-shadow:0 6px 30px rgba(15,39,71,.14)}
        .monto{font-size:2.2rem;font-weight:800}
    </style>
</head>
<body>
<div class="hoja w-100">
    <?php if (cfg('marca_logo')): ?>
        <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" style="max-height:48px;width:auto" class="mb-3">
    <?php endif; ?>

    <?php if ($pagado): ?>
        <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
        <h1 class="h4 mt-3">¡Pago recibido!</h1>
        <div class="monto text-success"><?= fmt_money($cobro['monto']) ?></div>
        <p class="text-muted"><?= e($cobro['concepto']) ?></p>
        <p class="small text-muted mb-0">Ya puedes cerrar esta página. <?= e($marca) ?> lo tiene registrado.</p>

    <?php elseif ($estadoMp === 'rejected' || $estadoMp === 'failure'): ?>
        <i class="bi bi-x-circle-fill text-danger" style="font-size:3rem"></i>
        <h1 class="h4 mt-3">El pago no se completó</h1>
        <p class="text-muted">No se realizó ningún cargo. Puedes intentarlo de nuevo.</p>
        <a href="<?= e(cobro_url($cobro)) ?>" class="btn btn-primary" style="background:<?= $acento ?>;border-color:<?= $acento ?>">
            Reintentar
        </a>

    <?php else: ?>
        <i class="bi bi-hourglass-split text-warning" style="font-size:3rem"></i>
        <h1 class="h4 mt-3">Pago en proceso</h1>
        <p class="text-muted">
            Mercado Pago está confirmando la operación. En cuanto se acredite, el consultorio lo verá reflejado.
        </p>
        <p class="small text-muted mb-0">Puedes cerrar esta página.</p>
    <?php endif; ?>
</div>
</body>
</html>
