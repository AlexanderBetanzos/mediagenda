<?php
/**
 * Cabecera del panel de PLATAFORMA (consola del dueño del sistema).
 * Layout propio (sin el sidebar del consultorio). Define $titulo antes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_platform();
track_pageview('plataforma');

$titulo = $titulo ?? 'Plataforma';
$tema   = tema_actual();
$temaCss = $tema === 'light' ? 'app-light' : 'app-dark';
$bsAttr  = $tema === 'light' ? ' data-bs-theme="light"' : '';
$pa      = platform_admin();
$esSuper = platform_es_super();
$parts   = preg_split('/\s+/', trim($pa['nombre'] ?? 'Admin'));
$ini     = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
?>
<!doctype html>
<html lang="es" class="<?= $temaCss ?>"<?= $bsAttr ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · <?= e(APP_NAME) ?> Plataforma</title>
    <script>
    (function () {
        var pref = <?= json_encode($tema) ?>;
        var resolved = pref === 'auto' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : pref;
        var el = document.documentElement;
        el.classList.remove('app-dark', 'app-light');
        el.classList.add(resolved === 'light' ? 'app-light' : 'app-dark');
        if (resolved === 'light') el.setAttribute('data-bs-theme', 'light'); else el.removeAttribute('data-bs-theme');
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Mulish:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        :root { --brand: #2563eb; --brand-dark: #075089; }
        html.app-dark, html.app-light { --brand: #2563eb; --brand-dark: #1e40af; }
        .plat-badge { font-size:.6rem; letter-spacing:1.5px; font-weight:800; padding:.15rem .5rem; border-radius:6px;
            background:rgba(37,99,235,.18); color:#93c5fd; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark app-navbar sticky-top flex-md-nowrap p-0">
    <a class="navbar-brand d-flex me-0 px-3 fs-6 align-items-center gap-2" href="<?= BASE_URL ?>/platform/index">
        <i class="bi bi-diagram-3-fill" style="color:#2563eb"></i>
        <span><?= e(APP_NAME) ?></span> <span class="plat-badge">PLATAFORMA</span>
    </a>
    <ul class="navbar-nav flex-row top-menu gap-1 ms-2 d-none d-md-flex">
        <li class="nav-item"><a class="nav-link<?= ($platNav ?? '') === 'consultorios' ? ' active' : '' ?>" href="<?= BASE_URL ?>/platform/index"><i class="bi bi-buildings"></i> <?= et('Consultorios') ?></a></li>
        <?php if ($esSuper): ?>
        <li class="nav-item"><a class="nav-link<?= ($platNav ?? '') === 'metrics' ? ' active' : '' ?>" href="<?= BASE_URL ?>/platform/metrics"><i class="bi bi-graph-up-arrow"></i> <?= et('Métricas') ?></a></li>
        <li class="nav-item"><a class="nav-link<?= ($platNav ?? '') === 'socios' ? ' active' : '' ?>" href="<?= BASE_URL ?>/platform/socios"><i class="bi bi-people"></i> <?= et('Socios') ?></a></li>
        <li class="nav-item"><a class="nav-link<?= ($platNav ?? '') === 'ajustes' ? ' active' : '' ?>" href="<?= BASE_URL ?>/platform/ajustes"><i class="bi bi-gear"></i> <?= et('Ajustes') ?></a></li>
        <?php endif; ?>
    </ul>
    <div class="navbar-nav flex-row ms-auto align-items-center gap-3 px-3">
        <div class="topbar-clock text-end lh-1 d-none d-sm-block">
            <div id="clkTime" class="fw-bold"></div>
            <div id="clkDate" class="small text-muted text-capitalize"></div>
        </div>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                <span class="avatar-circle"><?= e($ini) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header"><?= e($pa['nombre'] ?? '') ?><br><small class="text-muted"><?= $esSuper ? et('Dueño del sistema') : et('Socio') ?></small></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/platform/logout"><i class="bi bi-box-arrow-right me-2"></i><?= et('Cerrar sesión') ?></a></li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid px-md-4 py-4">
