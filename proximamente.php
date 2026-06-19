<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$modulo = $_GET['m'] ?? 'Este módulo';
$titulo = $modulo;
$activo = '';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-column align-items-center justify-content-center text-center py-5">
    <div class="display-1 text-brand mb-3"><i class="bi bi-cone-striped"></i></div>
    <h1 class="h3"><?= e($modulo) ?></h1>
    <p class="text-muted mb-4" style="max-width:420px">
        Este módulo está en desarrollo y estará disponible próximamente.
        Mientras tanto puedes usar la agenda, los pacientes y el expediente clínico.
    </p>
    <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> Volver al panel</a>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
