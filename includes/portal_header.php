<?php
/**
 * Cabecera del portal del paciente. Define $titulo (y opcionalmente
 * $portalActivo: citas|recetas|vacunas|archivos) antes de incluir.
 * Lleva el logo y el color de la clínica; en el celular la navegación va
 * en una barra fija abajo, al alcance del pulgar.
 */
$pac = current_paciente();
$marca = marca_nombre();
$acento = color_acento();
$portalActivo = $portalActivo ?? '';
if (function_exists('track_pageview')) track_pageview('portal');
$pnav = [
    'citas'    => ['/portal/index', 'bi-calendar-check', t('Mis citas')],
    'recetas'  => ['/portal/recetas', 'bi-capsule', t('Recetas')],
    'vacunas'  => ['/portal/vacunas', 'bi-shield-plus', t('Vacunas')],
    'archivos' => ['/portal/archivos', 'bi-paperclip', t('Estudios')],
];
$ini = mb_strtoupper(mb_substr((string) ($pac['nombre'] ?? ''), 0, 1) . mb_substr((string) ($pac['apellidos'] ?? ''), 0, 1));
?>
<!doctype html>
<html lang="es" class="app-light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($acento) ?>">
    <title><?= e($titulo ?? 'Portal') ?> · <?= e($marca) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/portal.css') ?>" rel="stylesheet">
    <style>:root{--brand:<?= $acento ?>;--brand-dark:color-mix(in srgb,<?= $acento ?> 78%,#000);--brand-light:color-mix(in srgb,<?= $acento ?> 12%,#fff);--brand-ink:<?= color_texto_sobre($acento) ?>}</style>
</head>
<body class="portal">
<header class="pt-top">
    <div class="container d-flex align-items-center gap-3">
        <a class="pt-marca" href="<?= BASE_URL ?>/portal/index">
            <?php if (cfg('marca_logo')): ?><img src="<?= e(cfg('marca_logo')) ?>" alt=""><?php else: ?><i class="bi bi-heart-pulse-fill"></i><?php endif; ?>
            <span><?= e($marca) ?></span>
        </a>
        <nav class="pt-nav d-none d-md-flex">
            <?php foreach ($pnav as $k => [$url, $ic, $lbl]): ?>
            <a href="<?= BASE_URL . $url ?>" class="<?= $portalActivo === $k ? 'on' : '' ?>"><i class="bi <?= $ic ?>"></i> <?= e($lbl) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="ms-auto dropdown">
            <button class="pt-yo" data-bs-toggle="dropdown" aria-label="<?= et('Mi cuenta') ?>"><span><?= e($ini) ?></span><b class="d-none d-sm-inline"><?= e($pac['nombre'] ?? '') ?></b><i class="bi bi-chevron-down small"></i></button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><span class="dropdown-item-text small text-muted"><?= e(($pac['nombre'] ?? '') . ' ' . ($pac['apellidos'] ?? '')) ?></span></li>
                <?php if ($mu = micrositio_url()): ?><li><a class="dropdown-item" href="<?= e($mu) ?>"><i class="bi bi-globe2 me-2"></i><?= et('Página de la clínica') ?></a></li><?php endif; ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/portal/logout"><i class="bi bi-box-arrow-right me-2"></i><?= et('Salir') ?></a></li>
            </ul>
        </div>
    </div>
</header>
<nav class="pt-tabbar d-md-none">
    <?php foreach ($pnav as $k => [$url, $ic, $lbl]): ?>
    <a href="<?= BASE_URL . $url ?>" class="<?= $portalActivo === $k ? 'on' : '' ?>"><i class="bi <?= $ic ?>"></i><span><?= e($lbl) ?></span></a>
    <?php endforeach; ?>
</nav>
<main class="container py-4 pt-main">
<?php foreach (get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show"><?= e($f['msg']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
