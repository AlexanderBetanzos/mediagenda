<?php
/** Cabecera del portal del paciente. Define $titulo antes de incluir. */
$pac = current_paciente();
$marca = marca_nombre();
if (function_exists('track_pageview')) track_pageview('portal');
?>
<!doctype html>
<html lang="es" class="app-light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo ?? 'Portal') ?> · <?= e($marca) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold text-brand" href="<?= BASE_URL ?>/portal/index">
            <i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pnav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="pnav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/portal/index"><i class="bi bi-calendar-check"></i> <?= et('Mis citas') ?></a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/portal/recetas"><i class="bi bi-capsule"></i> <?= et('Recetas') ?></a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/portal/archivos"><i class="bi bi-paperclip"></i> <?= et('Estudios') ?></a></li>
            </ul>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small"><i class="bi bi-person-circle"></i> <?= e(($pac['nombre'] ?? '') . ' ' . ($pac['apellidos'] ?? '')) ?></span>
                <a href="<?= BASE_URL ?>/portal/logout" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> <?= et('Salir') ?></a>
            </div>
        </div>
    </div>
</nav>
<main class="container py-4">
<?php foreach (get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show"><?= e($f['msg']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
