<?php
/**
 * Link de pago del paciente. Página PÚBLICA: la credencial es el token de la
 * URL. Crea (o reutiliza) la preferencia de Mercado Pago del consultorio y
 * manda al paciente al checkout.
 */
require_once __DIR__ . '/../includes/cobros.php';

$cobro = cobro_por_token((string) ($_GET['t'] ?? ''));
if (!$cobro) {
    http_response_code(404);
    $error = 'Este enlace de pago no existe o ya no es válido.';
} else {
    // Sin sesión no hay tenant: lo fija el propio cobro.
    tenant_forzar((int) $cobro['consultorio_id']);
}

$paciente = null;
if ($cobro) {
    $st = db()->prepare('SELECT nombre, apellidos, email FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $st->execute([(int) $cobro['paciente_id'], tenant_id()]);
    $paciente = $st->fetch() ?: [];
}

/* Ir a pagar: se genera la preferencia la primera vez y se guarda, para que
   recargar el enlace no cree una preferencia nueva en cada visita. */
if ($cobro && ($_GET['ir'] ?? '') === '1' && $cobro['estado'] === 'pendiente') {
    try {
        if (!$cobro['mp_init_point']) {
            $pref = mp_crear_preferencia_cobro($cobro, $paciente ?: []);
            db()->prepare('UPDATE cobros SET mp_preference_id = ?, mp_init_point = ? WHERE id = ?')
                ->execute([$pref['id'], $pref['init_point'], (int) $cobro['id']]);
            $cobro['mp_init_point'] = $pref['init_point'];
        }
        header('Location: ' . $cobro['mp_init_point']);
        exit;
    } catch (Throwable $e) {
        $error = 'No se pudo iniciar el pago. ' . $e->getMessage();
    }
}

$marca  = $cobro ? marca_nombre() : APP_NAME;
$acento = $cobro ? color_acento() : '#0b6fb8';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pagar · <?= e($marca) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;color:#1f2d3d;display:flex;align-items:center;min-height:100vh}
        .hoja{max-width:460px;margin:2rem auto;background:#fff;border-radius:14px;padding:2rem;
              box-shadow:0 6px 30px rgba(15,39,71,.14)}
        .acento{color:<?= $acento ?>}
        .monto{font-size:2.4rem;font-weight:800;letter-spacing:-.02em}
    </style>
</head>
<body>
<div class="hoja w-100">
    <?php if (!$cobro): ?>
        <div class="text-center">
            <i class="bi bi-x-circle text-danger" style="font-size:2.5rem"></i>
            <h1 class="h5 mt-3">Enlace no válido</h1>
            <p class="text-muted mb-0"><?= e($error) ?></p>
        </div>

    <?php else: ?>
        <div class="text-center mb-4">
            <?php if (cfg('marca_logo')): ?>
                <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" style="max-height:56px;width:auto">
            <?php else: ?>
                <i class="bi bi-heart-pulse-fill acento" style="font-size:2.2rem"></i>
            <?php endif; ?>
            <div class="fw-bold mt-2"><?= e($marca) ?></div>
        </div>

        <?php if ($cobro['estado'] === 'pagado'): ?>
            <div class="text-center">
                <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
                <h1 class="h5 mt-3">Pago recibido</h1>
                <p class="text-muted">Este cobro ya fue pagado. Gracias.</p>
                <div class="monto text-success"><?= fmt_money($cobro['monto']) ?></div>
                <p class="small text-muted mt-2"><?= e($cobro['concepto']) ?></p>
            </div>

        <?php elseif ($cobro['estado'] === 'cancelado'): ?>
            <div class="text-center">
                <i class="bi bi-slash-circle text-secondary" style="font-size:2.6rem"></i>
                <h1 class="h5 mt-3">Cobro cancelado</h1>
                <p class="text-muted mb-0">Este enlace ya no está activo. Contacta al consultorio.</p>
            </div>

        <?php else: ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger small"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($paciente): ?>
                <p class="text-muted mb-1">Hola <strong><?= e($paciente['nombre']) ?></strong>, tu pago de:</p>
            <?php endif; ?>
            <p class="mb-1"><?= e($cobro['concepto']) ?></p>
            <div class="monto acento mb-4"><?= fmt_money($cobro['monto']) ?></div>

            <?php if (mp_tenant_habilitado()): ?>
                <a href="?t=<?= e($cobro['token']) ?>&ir=1" class="btn btn-primary btn-lg w-100"
                   style="background:<?= $acento ?>;border-color:<?= $acento ?>">
                    <i class="bi bi-credit-card"></i> Pagar con Mercado Pago
                </a>
                <?php if (mp_tenant_es_sandbox()): ?>
                <div class="alert alert-warning small mt-3 mb-0">
                    <i class="bi bi-cone-striped"></i> Modo de pruebas: no se cobrará dinero real.
                </div>
                <?php endif; ?>
                <p class="small text-muted text-center mt-3 mb-0">
                    <i class="bi bi-lock"></i> El pago lo procesa Mercado Pago. No guardamos los datos de tu tarjeta.
                </p>
            <?php else: ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    El consultorio aún no tiene activo el pago en línea. Contáctalo para pagar por otro medio.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
