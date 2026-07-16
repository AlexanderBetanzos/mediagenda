<?php
/** Ayuda y soporte: datos de contacto del proveedor (MediOS). */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$waMsg = rawurlencode(t('Hola, necesito ayuda con MediOS.'));
$waUrl = 'https://wa.me/' . SOPORTE_WHATSAPP . '?text=' . $waMsg;

$titulo = t('Ayuda y soporte');
$activo = 'soporte';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-life-preserver text-brand"></i> <?= et('Ayuda y soporte') ?></h1>
        <p class="text-muted mb-0"><?= et('¿Necesitas ayuda con MediOS? Contáctanos por el medio que prefieras.') ?></p>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="d-block text-center text-decoration-none card h-100 border-0 support-card">
            <div class="card-body py-4">
                <i class="bi bi-whatsapp d-block mb-2" style="font-size:2.4rem;color:#25d366"></i>
                <div class="fw-semibold"><?= et('WhatsApp') ?></div>
                <div class="small text-muted"><?= et('Chatea con soporte') ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="tel:<?= e(preg_replace('/\s+/', '', SOPORTE_TEL)) ?>" class="d-block text-center text-decoration-none card h-100 border-0 support-card">
            <div class="card-body py-4">
                <i class="bi bi-telephone-fill d-block mb-2" style="font-size:2.4rem;color:var(--brand)"></i>
                <div class="fw-semibold"><?= et('Teléfono') ?></div>
                <div class="small text-muted"><?= e(SOPORTE_TEL) ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="mailto:<?= e(SOPORTE_EMAIL) ?>" class="d-block text-center text-decoration-none card h-100 border-0 support-card">
            <div class="card-body py-4">
                <i class="bi bi-envelope-fill d-block mb-2" style="font-size:2.4rem;color:var(--brand)"></i>
                <div class="fw-semibold"><?= et('Correo') ?></div>
                <div class="small text-muted"><?= e(SOPORTE_EMAIL) ?></div>
            </div>
        </a>
    </div>
</div>

<p class="text-muted small mb-0 mt-3"><i class="bi bi-clock"></i> <?= et('Horario de atención') ?>: <?= e(SOPORTE_HORARIO) ?></p>

<style>
.support-card{background:color-mix(in srgb,var(--brand) 5%,transparent);transition:transform .15s ease,box-shadow .15s ease}
.support-card:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.12)}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
