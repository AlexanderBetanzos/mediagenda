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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/material.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/panel-glass.css') ?>" rel="stylesheet">
    <style>
        /* Color de acento del consultorio (white-label).

           Antes se inyectaba en :root y DOS LÍNEAS DESPUÉS se pisaba con un
           azul fijo para el panel, así que el color elegido solo se veía en el
           micrositio. En un producto que se vende como white-label, el panel
           es justo donde el dueño pasa el día: si su marca no está ahí, no
           está en ninguna parte.

           Ahora el acento manda en los dos temas. Las variantes se derivan con
           color-mix en vez de pedir más colores: --brand-dark oscurece para
           los estados activos, y en el panel OSCURO los acentos se ACLARAN,
           porque un color de marca oscuro sobre fondo casi negro no se ve. */
        :root {
            --brand:      <?= $acento ?>;
            --brand-dark: color-mix(in srgb, <?= $acento ?> 78%, #000);
            --cta:        <?= $acento ?>;
            --cta-dark:   color-mix(in srgb, <?= $acento ?> 82%, #000);
        }
        html.app-dark {
            --d-accent:  <?= $acento ?>;
            /* Aclarados: sobre #0c0d10 un acento oscuro desaparece. */
            --d-accent2: color-mix(in srgb, <?= $acento ?> 62%, #fff);
            --d-accent3: color-mix(in srgb, <?= $acento ?> 48%, #fff);
        }

        /* Bloque de soporte al pie del menú. Hereda el color de texto del
           sidebar, así funciona igual en tema claro y oscuro. */
        .sidebar-soporte { margin-top: 1.25rem; padding: 1rem 1.25rem 1.5rem;
                           border-top: 1px solid rgba(255,255,255,.10); }
        html.app-light .sidebar-soporte { border-top-color: rgba(0,0,0,.08); }
        .ss-titulo { font-size: .72rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: .04em; opacity: .55; margin-bottom: .6rem; }
        .ss-link { display: flex; align-items: center; gap: .55rem; padding: .22rem 0;
                   color: var(--brand); text-decoration: none; font-size: .82rem; font-weight: 600; }
        .ss-link:hover { filter: brightness(1.15); text-decoration: underline; }
        .ss-mail { word-break: break-all; }
        .ss-horario { display: flex; gap: .4rem; margin-top: .6rem; font-size: .7rem; opacity: .5; }
    </style>
    <?= meta_pixel_head(false) /* solo eventos encolados, sin PageView */ ?>
</head>
<body class="ui-glass">
<nav class="navbar navbar-dark app-navbar sticky-top flex-md-nowrap p-0">
    <!-- Marca en la barra superior: solo en móvil (junto al menú). En desktop
         la marca vive en el sidebar como "ficha" (estilo GymOS). -->
    <a class="navbar-brand d-flex d-md-none me-0 px-3 fs-6 align-items-center gap-2" href="<?= BASE_URL ?>/dashboard">
        <?php if (cfg('marca_logo')): ?>
            <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" style="max-height:26px;max-width:150px;width:auto;object-fit:contain">
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
    <?php require_once __DIR__ . '/ayuda.php'; /* mini-tutoriales: el "?" junto al título */ ?>
    <?php require_once __DIR__ . '/ui.php';    /* franja de indicadores por sección */ ?>
    <h1 class="app-page-title h5 fw-bold mb-0 ps-1 d-none d-md-flex align-items-center gap-2 text-truncate"><?= e($titulo) ?> <?= ayuda_btn('page:' . ($activo ?? '')) ?></h1>

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
                <a href="<?= BASE_URL ?>/dashboard" class="brand text-decoration-none">
                    <?php if (cfg('marca_logo')): ?>
                        <span class="brand-logo"><img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>"></span>
                    <?php else: ?>
                        <span class="brand-logo"><i class="bi bi-heart-pulse-fill" style="font-size:1.9rem;color:var(--brand)"></i></span>
                    <?php endif; ?>
                    <div class="brand-name"><?= e($marca) ?></div>
                    <small class="brand-sub"><i class="bi bi-lightning-charge-fill"></i> <?= strtoupper(et('Panel')) ?></small>
                </a>
                <?php
                /* Menú por grupos que no se mezclan: lo clínico, la caja, la
                   administración, tu página y reseñas, y la ayuda hasta abajo.
                   Los módulos que el plan incluye pero no son de la especialidad
                   del consultorio (giro) van plegados en "Más herramientas". */
                $esAdm = has_role('admin');
                $esClin = has_role('medico', 'admin');
                $li = fn (string $key, string $url, string $ic, string $lbl, string $extra = '') =>
                    '<li class="nav-item"><a class="nav-link' . nav_active($key, $activo) . '" href="' . $url . '"' . $extra . '><i class="bi ' . $ic . '"></i> ' . e($lbl) . '</a></li>';
                $sec = fn (string $t) => '<li class="nav-sec">' . et($t) . '</li>';

                $clinico = $caja = $admin = $crece = $mas = [];
                // Clínico
                if ($esClin && modulo_activo('plantillas')) $clinico[] = $li('plantillas', BASE_URL . '/plantillas/index', 'bi-file-earmark-text', t('Plantillas de consulta'));
                if ($esClin && modulo_activo('documentos')) $clinico[] = $li('documentos', BASE_URL . '/documentos/index', 'bi-file-earmark-medical', t('Documentos'));
                if (modulo_activo('expediente')) $clinico[] = $li('vacunacion', BASE_URL . '/vacunacion/index', 'bi-shield-plus', t('Vacunación'));
                if (modulo_activo('expediente') && giro_aplica('hospitalizacion')) {
                    $it = $li('hospitalizacion', BASE_URL . '/hospitalizacion/index', 'bi-hospital', t('Hospitalización'));
                    if (giro_modulo_al_frente('hospitalizacion')) $clinico[] = $it; else $mas[] = $it;
                }
                foreach ([['laboratorio', 'laboratorio', '/laboratorio/index', 'bi-eyedropper', 'Laboratorio'],
                          ['imagenologia', 'imagenologia', '/imagenologia/index', 'bi-soundwave', 'Ultrasonidos'],
                          ['optica', 'optica', '/optica/index', 'bi-eyeglasses', 'Óptica']] as [$mod, $key, $url, $ic, $lbl]) {
                    if (!modulo_activo($mod) || !giro_aplica($mod)) continue;   // p. ej. Óptica en una veterinaria
                    $it = $li($key, BASE_URL . $url, $ic, t($lbl));
                    if (giro_modulo_al_frente($mod)) $clinico[] = $it; else $mas[] = $it;
                }
                // Caja y cobros
                if (modulo_activo('facturacion'))  $caja[] = $li('facturacion', BASE_URL . '/facturacion/index', 'bi-receipt', t('Facturación'));
                if (modulo_activo('presupuestos')) $caja[] = $li('presupuestos', BASE_URL . '/presupuestos/index', 'bi-clipboard2-check', t('Presupuestos'));
                if (modulo_activo('facturacion'))  $caja[] = $li('corte', BASE_URL . '/corte/index', 'bi-cash-stack', t('Corte de caja'));
                if (modulo_activo('facturacion') && $esAdm) $caja[] = $li('egresos', BASE_URL . '/egresos/index', 'bi-arrow-left-right', t('Egresos e ingresos'));
                if (modulo_activo('farmacia') && giro_aplica('farmacia')) {
                    $pos = [$li('pos', BASE_URL . '/pos/index', 'bi-shop', t('Punto de venta')),
                            $li('inventario', BASE_URL . '/inventario/index', 'bi-box-seam', t('Inventario'))];
                    if (giro_modulo_al_frente('farmacia')) array_push($caja, ...$pos); else array_push($mas, ...$pos);
                }
                // Administración (solo admin)
                if ($esAdm) {
                    $admin[] = $li('medicos', BASE_URL . '/medicos/index', 'bi-person-vcard', t('Médicos'));
                    $admin[] = $li('usuarios', BASE_URL . '/usuarios/index', 'bi-person-badge', t('Personal'));
                    if (modulo_activo('presupuestos')) $admin[] = $li('servicios', BASE_URL . '/servicios/index', 'bi-tags', t('Catálogo de servicios'));
                    $admin[] = $li('configuracion', BASE_URL . '/configuracion/index', 'bi-gear', t('Configuración'));
                    $admin[] = $li('suscripcion', BASE_URL . '/pagos/index', 'bi-stars', t('Mi suscripción'));
                }
                // Tu página y reseñas
                if (micrositio_visible()) $crece[] = '<li class="nav-item"><a class="nav-link" href="' . e(micrositio_url()) . '" target="_blank" rel="noopener"><i class="bi bi-globe2"></i> '
                    . et('Mi página pública') . ' <i class="bi bi-box-arrow-up-right ms-auto small opacity-50"></i></a></li>';
                if ($esAdm) $crece[] = $li('resenas', BASE_URL . '/resenas/index', 'bi-star', t('Reseñas'));
                if (modulo_activo('crm')) $crece[] = $li('crm', BASE_URL . '/crm/index', 'bi-people-fill', t('CRM'));
                ?>
                <ul class="nav flex-column">
                    <?= $sec('Principal') ?>
                    <?= $li('dashboard', BASE_URL . '/dashboard', 'bi-grid-1x2-fill', t('Dashboard')) ?>
                    <?php if (modulo_activo('citas')) echo $li('citas', BASE_URL . '/citas/index', 'bi-calendar-check', t('Agenda')); ?>
                    <?php if (modulo_activo('citas') && $esClin) echo $li('horarios', BASE_URL . '/citas/horarios', 'bi-clock-history', $esAdm ? t('Horarios') : t('Mi horario')); ?>
                    <?php if (modulo_activo('pacientes')) echo $li('pacientes', BASE_URL . '/pacientes/index', 'bi-people', t('Pacientes')); ?>
                    <?php if (modulo_activo('expediente')) echo $li('expediente', BASE_URL . '/expediente/index', 'bi-folder2-open', t('Expediente')); ?>
                    <?php if (modulo_activo('recetas')) echo $li('recetas', BASE_URL . '/recetas/index', 'bi-capsule', t('Recetas')); ?>

                    <?php if ($clinico): ?><?= $sec('Clínico') ?><?= implode('', $clinico) ?><?php endif; ?>
                    <?php if ($caja): ?><?= $sec('Caja y cobros') ?><?= implode('', $caja) ?><?php endif; ?>
                    <?php if ($admin): ?><?= $sec('Administración') ?><?= implode('', $admin) ?><?php endif; ?>
                    <?php if ($crece): ?><?= $sec('Tu página y reseñas') ?><?= implode('', $crece) ?><?php endif; ?>

                    <?php if ($mas):
                        // Abierto si estás dentro de uno de esos módulos.
                        $masAbierto = (bool) array_filter($mas, fn ($h) => str_contains($h, 'nav-link active')); ?>
                    <li class="nav-item">
                        <a class="nav-link nav-mas<?= $masAbierto ? '' : ' collapsed' ?>" data-bs-toggle="collapse" href="#navMas" role="button" aria-expanded="<?= $masAbierto ? 'true' : 'false' ?>">
                            <i class="bi bi-grid-3x3-gap"></i> <?= et('Más herramientas') ?> <i class="bi bi-chevron-down ms-auto small nav-mas-ic"></i>
                        </a>
                        <ul class="nav flex-column collapse<?= $masAbierto ? ' show' : '' ?>" id="navMas"><?= implode('', $mas) ?></ul>
                    </li>
                    <?php endif; ?>

                    <?php if (es_superadmin()): ?>
                    <?= $sec('Plataforma') ?>
                    <?= $li('admin', BASE_URL . '/platform/index', 'bi-diagram-3', t('Consola de plataforma')) ?>
                    <?= $li('feedback_admin', BASE_URL . '/admin/feedback', 'bi-chat-left-dots', t('Comentarios')) ?>
                    <?php endif; ?>

                    <?= $sec('Ayuda') ?>
                    <li class="nav-item"><a class="nav-link" href="#" data-ayuda-centro><i class="bi bi-question-circle"></i> <?= et('Centro de ayuda') ?></a></li>
                    <?= $li('soporte', BASE_URL . '/soporte/index', 'bi-life-preserver', t('Ayuda y soporte')) ?>
                    <?= $li('feedback', BASE_URL . '/feedback/index', 'bi-chat-left-dots', t('Enviar comentario')) ?>
                </ul>

                <?php /* Datos del proveedor del sistema (no del consultorio): siempre visibles
                         al pie del menú, para que nadie tenga que buscar cómo pedir ayuda. */ ?>
                <div class="sidebar-soporte">
                    <div class="ss-titulo"><i class="bi bi-headset"></i> <?= et('Soporte') ?> <?= e(APP_NAME) ?></div>
                    <a href="<?= e(wa_link(SOPORTE_WHATSAPP, t('Hola, necesito ayuda con') . ' ' . APP_NAME . '.')) ?>"
                       target="_blank" rel="noopener" class="ss-link">
                        <i class="bi bi-whatsapp"></i> WhatsApp
                    </a>
                    <a href="tel:<?= e(preg_replace('/\s+/', '', SOPORTE_TEL)) ?>" class="ss-link">
                        <i class="bi bi-telephone-fill"></i> <?= e(SOPORTE_TEL) ?>
                    </a>
                    <a href="mailto:<?= e(SOPORTE_EMAIL) ?>" class="ss-link">
                        <i class="bi bi-envelope-fill"></i> <span class="ss-mail"><?= e(SOPORTE_EMAIL) ?></span>
                    </a>
                    <div class="ss-horario"><i class="bi bi-clock"></i> <?= e(SOPORTE_HORARIO) ?></div>
                    <button type="button" class="ss-ayuda" data-ayuda-centro><i class="bi bi-question-circle"></i> <?= et('Centro de ayuda') ?></button>
                </div>
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
