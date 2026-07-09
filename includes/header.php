<?php
/**
 * Cabecera + navegación (tema oscuro). Define antes de incluir:
 *   $titulo (string)  -> título de la página
 *   $activo (string)  -> menú activo: dashboard|citas|pacientes|usuarios
 */
require_once __DIR__ . '/functions.php';
require_login();
track_pageview('panel');

$u      = current_user();
$titulo = $titulo ?? APP_NAME;
$activo = $activo ?? '';

function nav_active(string $key, string $current): string
{
    return $key === $current ? ' active' : '';
}

/** Iniciales para el avatar. */
$parts = preg_split('/\s+/', trim($u['nombre']));
$ini   = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));

/* Tema: preferencia del usuario o default del consultorio. 'auto' se resuelve
   en el cliente; el servidor usa oscuro como base (look original de la app). */
$tema     = tema_actual();
$temaCss  = $tema === 'light' ? 'app-light' : 'app-dark';
$marca    = marca_nombre();
$acento   = color_acento();
// El tema oscuro conserva su skin propio (sin data-bs-theme); el claro usa
// el modo claro nativo de Bootstrap.
$bsAttr   = $tema === 'light' ? ' data-bs-theme="light"' : '';
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>" class="<?= $temaCss ?>"<?= $bsAttr ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · <?= e($marca) ?></title>
    <script>
    /* Resuelve el tema antes de pintar (evita parpadeo). */
    (function () {
        var pref = <?= json_encode($tema) ?>;
        var resolved = pref === 'auto'
            ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : pref;
        var el = document.documentElement;
        el.classList.remove('app-dark', 'app-light');
        el.classList.add(resolved === 'light' ? 'app-light' : 'app-dark');
        if (resolved === 'light') { el.setAttribute('data-bs-theme', 'light'); }
        else { el.removeAttribute('data-bs-theme'); }
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Mulish:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        /* Color de acento configurable por consultorio (white-label). En el tema
           claro y la landing manda el acento de marca; el panel oscuro usa el
           skin cálido "GymOS" (naranja), donde --brand queda fijado al naranja
           para que íconos de estadística, enlaces y la gráfica combinen. */
        :root { --brand: <?= $acento ?>; --brand-dark: color-mix(in srgb, <?= $acento ?> 78%, #000); }
        /* El panel (claro y oscuro) usa el acento cálido GymOS; la landing
           pública conserva el color de marca de :root. */
        html.app-dark  { --brand: #f66f14; --brand-dark: #d85f0f; }
        html.app-light { --brand: #f66f14; --brand-dark: #d9600f; }
        /* El botón primario oscuro (degradado naranja) se define en style.css. */
    </style>
</head>
<body>
<nav class="navbar navbar-dark app-navbar sticky-top flex-md-nowrap p-0">
    <!-- Marca en la barra superior: solo en móvil (junto al menú). En desktop
         la marca vive en el sidebar como "ficha" (estilo GymOS). -->
    <a class="navbar-brand d-flex d-md-none me-0 px-3 fs-6 align-items-center gap-2" href="<?= BASE_URL ?>/dashboard">
        <?php if (cfg('marca_logo')): ?>
            <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" style="height:26px;width:auto">
        <?php else: ?>
            <i class="bi bi-heart-pulse-fill text-info"></i>
        <?php endif; ?>
        <span><?= e($marca) ?></span>
    </a>
    <button class="navbar-toggler d-md-none collapsed me-2" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
        <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Título de la página (desktop): arranca sobre el contenido, tras el
         ancho del sidebar, estilo GymOS. -->
    <div class="d-none d-md-block col-md-3 col-lg-2"></div>
    <h1 class="app-page-title h5 fw-bold mb-0 ps-1 d-none d-md-block text-truncate"><?= e($titulo) ?></h1>

    <div class="navbar-nav flex-row ms-auto align-items-center gap-3 px-3">
        <div class="topbar-clock text-end lh-1 d-none d-sm-block">
            <div id="clkTime" class="fw-bold"></div>
            <div id="clkDate" class="small text-muted text-capitalize"></div>
        </div>
        <div class="dropdown">
            <a href="#" class="icon-btn dropdown-toggle" data-bs-toggle="dropdown" title="Tema" aria-label="Cambiar tema">
                <i class="bi bi-circle-half"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header"><?= et('Apariencia') ?></h6></li>
                <li><a class="dropdown-item" href="#" onclick="setTema('light');return false"><i class="bi bi-sun me-2"></i><?= et('Claro') ?></a></li>
                <li><a class="dropdown-item" href="#" onclick="setTema('dark');return false"><i class="bi bi-moon-stars me-2"></i><?= et('Oscuro') ?></a></li>
                <li><a class="dropdown-item" href="#" onclick="setTema('auto');return false"><i class="bi bi-circle-half me-2"></i><?= et('Automático') ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><h6 class="dropdown-header"><?= et('Idioma') ?></h6></li>
                <li><a class="dropdown-item" href="#" onclick="setIdioma('es');return false">🇲🇽 Español</a></li>
                <li><a class="dropdown-item" href="#" onclick="setIdioma('en');return false">🇺🇸 English</a></li>
            </ul>
        </div>
        <a href="<?= BASE_URL ?>/dashboard" class="icon-btn" title="Notificaciones">
            <i class="bi bi-bell"></i><span class="dot"></span>
        </a>
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                <span class="avatar-circle"><?= e($ini) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-header">
                    <?= e($u['nombre']) ?><br>
                    <small class="text-muted"><?= e(rol_label($u['rol'])) ?></small>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/auth/logout"><i class="bi bi-box-arrow-right me-2"></i><?= et('Cerrar sesión') ?></a></li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
            <div class="position-sticky pt-3">
                <a href="<?= BASE_URL ?>/dashboard" class="brand text-decoration-none d-block">
                    <?php if (cfg('marca_logo')): ?>
                        <span class="brand-logo"><img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>"></span>
                    <?php else: ?>
                        <span class="brand-logo"><i class="bi bi-heart-pulse-fill" style="font-size:1.9rem;color:var(--brand)"></i></span>
                    <?php endif; ?>
                    <div class="brand-name"><?= e($marca) ?></div>
                    <small class="brand-sub"><i class="bi bi-lightning-charge-fill"></i> <?= strtoupper(et('Panel')) ?></small>
                </a>
                <ul class="nav flex-column">
                    <li class="nav-item"><a class="nav-link<?= nav_active('dashboard', $activo) ?>" href="<?= BASE_URL ?>/dashboard"><i class="bi bi-grid-1x2-fill"></i> <?= et('Dashboard') ?></a></li>
                    <?php if (modulo_activo('citas')): ?><li class="nav-item"><a class="nav-link<?= nav_active('citas', $activo) ?>" href="<?= BASE_URL ?>/citas/index"><i class="bi bi-calendar-check"></i> <?= et('Agenda') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('pacientes')): ?><li class="nav-item"><a class="nav-link<?= nav_active('pacientes', $activo) ?>" href="<?= BASE_URL ?>/pacientes/index"><i class="bi bi-people"></i> <?= et('Pacientes') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('expediente')): ?><li class="nav-item"><a class="nav-link<?= nav_active('expediente', $activo) ?>" href="<?= BASE_URL ?>/expediente/index"><i class="bi bi-folder2-open"></i> <?= et('Expediente') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('recetas')): ?><li class="nav-item"><a class="nav-link<?= nav_active('recetas', $activo) ?>" href="<?= BASE_URL ?>/recetas/index"><i class="bi bi-capsule"></i> <?= et('Recetas') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('facturacion')): ?><li class="nav-item"><a class="nav-link<?= nav_active('facturacion', $activo) ?>" href="<?= BASE_URL ?>/facturacion/index"><i class="bi bi-receipt"></i> <?= et('Facturación') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('facturacion')): ?><li class="nav-item"><a class="nav-link<?= nav_active('pos', $activo) ?>" href="<?= BASE_URL ?>/pos/index"><i class="bi bi-shop"></i> <?= et('Punto de venta') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('facturacion')): ?><li class="nav-item"><a class="nav-link<?= nav_active('corte', $activo) ?>" href="<?= BASE_URL ?>/corte/index"><i class="bi bi-cash-stack"></i> <?= et('Corte de caja') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('facturacion') && has_role('admin')): ?><li class="nav-item"><a class="nav-link<?= nav_active('egresos', $activo) ?>" href="<?= BASE_URL ?>/egresos/index"><i class="bi bi-arrow-left-right"></i> <?= et('Egresos e ingresos') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('crm')): ?><li class="nav-item"><a class="nav-link<?= nav_active('crm', $activo) ?>" href="<?= BASE_URL ?>/crm/index"><i class="bi bi-people-fill"></i> <?= et('CRM') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('crm')): ?><li class="nav-item"><a class="nav-link<?= nav_active('reactivacion', $activo) ?>" href="<?= BASE_URL ?>/reactivacion/index"><i class="bi bi-arrow-repeat"></i> <?= et('Reactivación') ?></a></li><?php endif; ?>
                    <?php if (modulo_activo('farmacia')): ?><li class="nav-item"><a class="nav-link<?= nav_active('inventario', $activo) ?>" href="<?= BASE_URL ?>/inventario/index"><i class="bi bi-box-seam"></i> <?= et('Inventario') ?></a></li><?php endif; ?>
                    <?php if (has_role('admin')): ?>
                    <li class="nav-item mt-2"><a class="nav-link<?= nav_active('usuarios', $activo) ?>" href="<?= BASE_URL ?>/usuarios/index"><i class="bi bi-person-badge"></i> <?= et('Personal') ?></a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('suscripcion', $activo) ?>" href="<?= BASE_URL ?>/pagos/index"><i class="bi bi-stars"></i> <?= et('Mi suscripción') ?></a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('configuracion', $activo) ?>" href="<?= BASE_URL ?>/configuracion/index"><i class="bi bi-gear"></i> <?= et('Configuración') ?></a></li>
                    <?php endif; ?>
                    <?php if (has_role('medico', 'admin')): ?>
                    <?php if (modulo_activo('plantillas')): ?><li class="nav-item"><a class="nav-link<?= nav_active('plantillas', $activo) ?>" href="<?= BASE_URL ?>/plantillas/index"><i class="bi bi-file-earmark-text"></i> <?= et('Plantillas de consulta') ?></a></li><?php endif; ?>
                    <?php endif; ?>
                    <?php if (es_superadmin()): ?>
                    <li class="nav-item mt-2"><a class="nav-link<?= nav_active('admin', $activo) ?>" href="<?= BASE_URL ?>/platform/index"><i class="bi bi-diagram-3"></i> <?= et('Plataforma') ?></a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('feedback_admin', $activo) ?>" href="<?= BASE_URL ?>/admin/feedback"><i class="bi bi-chat-left-dots"></i> <?= et('Comentarios') ?></a></li>
                    <?php endif; ?>
                    <li class="nav-item mt-2"><a class="nav-link<?= nav_active('feedback', $activo) ?>" href="<?= BASE_URL ?>/feedback/index"><i class="bi bi-chat-left-dots"></i> <?= et('Enviar comentario') ?></a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('soporte', $activo) ?>" href="<?= BASE_URL ?>/soporte/index"><i class="bi bi-life-preserver"></i> <?= et('Ayuda y soporte') ?></a></li>
                </ul>
            </div>
        </nav>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <?php if (!empty($_SESSION['impersonando'])): $__imp = tenant(); ?>
            <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                <span><i class="bi bi-eye-fill"></i> <?= et('Estás viendo como') ?> <strong><?= e($__imp['nombre'] ?? ('#' . (int) $_SESSION['impersonando'])) ?></strong> · <?= et('modo plataforma') ?>.</span>
                <a href="<?= BASE_URL ?>/platform/impersonar?salir=1" class="btn btn-sm btn-dark"><i class="bi bi-arrow-left"></i> <?= et('Volver a la plataforma') ?></a>
            </div>
            <?php endif; ?>
            <?php foreach (get_flash() as $f): ?>
                <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>

            <?php
            /* Banner de prueba: días restantes y aviso al acercarse el fin. */
            $__t = tenant();
            if ($__t && ($__t['estado'] ?? '') === 'trial'):
                $__dias = trial_dias_restantes();
                $__urgente = $__dias !== null && $__dias <= 3;
            ?>
            <div class="alert <?= $__urgente ? 'alert-warning' : 'alert-info' ?> d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
                <span>
                    <i class="bi bi-stopwatch"></i>
                    <?php if ($__dias !== null && $__dias > 0): ?>
                        Prueba gratis con <strong>acceso completo</strong> · te quedan <strong><?= (int) $__dias ?> día<?= $__dias === 1 ? '' : 's' ?></strong>.
                    <?php else: ?>
                        Tu prueba gratis termina <strong>hoy</strong>.
                    <?php endif; ?>
                </span>
                <a href="<?= BASE_URL ?>/auth/suscripcion" class="btn btn-sm <?= $__urgente ? 'btn-warning' : 'btn-primary' ?>">
                    <i class="bi bi-stars"></i> Activar plan
                </a>
            </div>
            <?php endif; ?>
