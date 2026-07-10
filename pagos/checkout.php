<?php
/**
 * Checkout embebido (Card Payment Brick de Mercado Pago) para contratar un plan.
 * La tarjeta se tokeniza dentro del sitio y pagos/procesar.php crea la
 * suscripción ya autorizada (con renovación automática). Sin redirección a MP.
 */
define('ALLOW_INACTIVE', true);   // una cuenta bloqueada debe poder pagar
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_once __DIR__ . '/../includes/mercadopago.php';

$plan   = preg_replace('/[^a-z]/', '', (string) ($_GET['plan'] ?? ''));
$planes = planes_mp();

// Sin Public Key no se puede renderizar el Brick: caemos al flujo con redirect.
if (!mp_configurado() || mp_public_key() === '') {
    redirect('/pagos/suscribir?plan=' . $plan);
}
if (!isset($planes[$plan])) {
    flash('Plan no válido.', 'warning');
    redirect('/pagos/index');
}

$p = $planes[$plan];
$u = current_user();
?>
<!doctype html>
<html lang="es" class="app-light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Pagar') ?> <?= e($p['nombre']) ?> · <?= e(marca_nombre()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<div class="container py-5" style="max-width:620px">
    <a href="<?= BASE_URL ?>/pagos/index" class="text-decoration-none small">&larr; <?= et('Volver a planes') ?></a>
    <div class="card border-0 shadow-sm mt-2">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-1">
                <div>
                    <h4 class="fw-bold mb-0">Plan <?= e($p['nombre']) ?></h4>
                    <span class="text-muted small"><?= e($p['descripcion']) ?></span>
                </div>
                <div class="text-end">
                    <div class="h3 fw-bold text-brand mb-0">$<?= number_format($p['precio'], 0) ?></div>
                    <span class="text-muted small">/<?= et('mes') ?></span>
                </div>
            </div>
            <p class="text-muted small mt-2 mb-3"><i class="bi bi-arrow-repeat"></i> <?= et('Suscripción con renovación automática mensual. Cancela cuando quieras.') ?></p>
            <hr>
            <div id="cardBrick_container"></div>
            <div id="pay_status" class="text-center text-muted small mt-3" style="display:none">
                <span class="spinner-border spinner-border-sm"></span> <?= et('Procesando tu pago…') ?>
            </div>
            <div id="pay_error" class="alert alert-danger mt-3" style="display:none"></div>
        </div>
    </div>
    <p class="text-muted small text-center mt-3">
        <i class="bi bi-shield-lock"></i> <?= et('Pago procesado de forma segura por Mercado Pago.') ?>
    </p>
</div>

<script src="https://sdk.mercadopago.com/js/v2"></script>
<script>
const mp = new MercadoPago(<?= json_encode(mp_public_key()) ?>, { locale: 'es-MX' });
const bricksBuilder = mp.bricks();

bricksBuilder.create('cardPayment', 'cardBrick_container', {
    initialization: {
        amount: <?= json_encode((float) $p['precio']) ?>,
        payer: { email: <?= json_encode($u['email']) ?> }
    },
    callbacks: {
        onReady: () => {},
        onSubmit: (cardFormData) => {
            document.getElementById('pay_status').style.display = 'block';
            document.getElementById('pay_error').style.display = 'none';
            return fetch(<?= json_encode(BASE_URL . '/pagos/procesar') ?>, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    plan: <?= json_encode($plan) ?>,
                    csrf: <?= json_encode(csrf_token()) ?>,
                    token: cardFormData.token
                })
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    window.location.href = res.redirect;
                } else {
                    throw new Error(res.error || 'No se pudo procesar el pago.');
                }
            })
            .catch(err => {
                document.getElementById('pay_status').style.display = 'none';
                const box = document.getElementById('pay_error');
                box.textContent = err.message || 'No se pudo procesar el pago.';
                box.style.display = 'block';
                throw err;
            });
        },
        onError: (error) => {
            document.getElementById('pay_status').style.display = 'none';
            const box = document.getElementById('pay_error');
            box.textContent = 'Revisa los datos de tu tarjeta e inténtalo de nuevo.';
            box.style.display = 'block';
            console.error(error);
        }
    }
});
</script>
</body>
</html>
