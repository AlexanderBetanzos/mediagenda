<?php
/**
 * Cabecera del panel de PLATAFORMA (consola del dueño del sistema).
 * Layout propio (sin el sidebar del consultorio). Define $titulo antes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_superadmin();

$titulo = $titulo ?? 'Plataforma';
$tema   = tema_actual();
$temaCss = $tema === 'light' ? 'app-light' : 'app-dark';
$bsAttr  = $tema === 'light' ? ' data-bs-theme="light"' : '';
$u = current_user();
$parts = preg_split('/\s+/', trim($u['nombre'] ?? 'Admin'));
$ini   = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
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
        :root { --brand: #0b6fb8; --brand-dark: #075089; }
        html.app-dark, html.app-light { --brand: #f66f14; --brand-dark: #d9600f; }
        .plat-badge { font-size:.6rem; letter-spacing:1.5px; font-weight:800; padding:.15rem .5rem; border-radius:6px;
            background:rgba(246,111,20,.18); color:#ffb066; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark app-navbar sticky-top flex-md-nowrap p-0">
    <a class="navbar-brand d-flex me-0 px-3 fs-6 align-items-center gap-2" href="<?= BASE_URL ?>/platform/index">
        <i class="bi bi-diagram-3-fill" style="color:#f66f14"></i>
        <span><?= e(APP_NAME) ?></span> <span class="plat-badge">PLATAFORMA</span>
    </a>
    <div class="navbar-nav flex-row ms-auto align-items-center gap-3 px-3">
        <div class="topbar-clock text-end lh-1 d-none d-sm-block">
            <div id="clkTime" class="fw-bold"></div>
            <div id="clkDate" class="small text-muted text-capitalize"></div>
        </div>
        <a href="<?= BASE_URL ?>/dashboard" class="icon-btn" title="<?= e(t('Ir a mi consultorio')) ?>"><i class="bi bi-hospital"></i></a>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                <span class="avatar-circle"><?= e($ini) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header"><?= e($u['nombre'] ?? '') ?><br><small class="text-muted"><?= et('Dueño del sistema') ?></small></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/dashboard"><i class="bi bi-hospital me-2"></i><?= et('Ir a mi consultorio') ?></a></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/auth/logout"><i class="bi bi-box-arrow-right me-2"></i><?= et('Cerrar sesión') ?></a></li>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid px-md-4 py-4">
