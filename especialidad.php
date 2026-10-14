<?php
/**
 * Página por especialidad: /dentistas, /psicologos, /nutriologos, /pediatras,
 * /oftalmologos, /dermatologos, /medicos, /clinicas (ver .htaccess).
 * Contenido en includes/landing/especialidades.php; ilustración animada en
 * includes/landing/ilustraciones.php. Veterinarias tiene su propia página.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mercadopago.php';
require_once __DIR__ . '/includes/landing/especialidades.php';
require_once __DIR__ . '/includes/landing/ilustraciones.php';

$todas = landing_especialidades();
$slug  = (string) ($_GET['e'] ?? '');
if (!isset($todas[$slug])) { http_response_code(404); redirect('/'); }
$E = $todas[$slug];

tenant_override(1);                 // marca pública del producto
$marca = marca_nombre();
tenant_override(null, true);
$logged = is_logged_in();
track_pageview('publico');

$registro = BASE_URL . '/auth/registro?giro=' . rawurlencode($E['giro']);
$wa = 'https://wa.me/' . SOPORTE_WHATSAPP . '?text=' . rawurlencode('Hola, quiero ver cómo funcionaría ' . $marca . ' para mi consultorio (' . $E['nombre'] . ').');
$desde = '$' . number_format(min(array_column(planes_mp(), 'precio')), 0);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($E['title']) ?> · <?= e($marca) ?></title>
    <meta name="description" content="<?= e($E['sub']) ?> Prueba 15 días gratis, sin tarjeta.">
    <link rel="canonical" href="<?= e(url_absoluta('/' . $slug)) ?>">
    <meta property="og:title" content="<?= e($E['title']) ?>">
    <meta property="og:description" content="<?= e($E['sub']) ?>">
    <meta property="og:image" content="<?= e(url_absoluta('/assets/video/mediagenda-anuncio.jpg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/material.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/landing-motion.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/especialidad.css') ?>" rel="stylesheet">
    <script>
    (function (d) {
        if (!('IntersectionObserver' in window) || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
        d.classList.add('motion');
        window.__lpMotionSafe = setTimeout(function () { d.classList.remove('motion'); }, 4000);
    })(document.documentElement);
    </script>
    <?= meta_pixel_head() ?>
    <style>:root { --c: <?= $E['c'] ?>; --cd: <?= $E['cd'] ?>; --cl: color-mix(in srgb, <?= $E['c'] ?> 14%, #fff); }</style>
</head>
<body class="lp es-pg">

<nav class="navbar navbar-expand-lg landing-nav sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/" style="color:var(--c)"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?> <span class="small fw-semibold text-muted">· <?= e($E['nombre']) ?></span></a>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <a class="nav-link d-none d-md-inline" href="#funciones">Funciones</a>
            <a class="nav-link d-none d-md-inline" href="#planes">Planes</a>
            <?php if ($logged): ?><a class="es-btn es-fill py-2" href="<?= BASE_URL ?>/dashboard">Ir al panel</a>
            <?php else: ?><a class="nav-link" href="<?= BASE_URL ?>/auth/login">Iniciar sesión</a>
            <a class="es-btn es-fill py-2" href="<?= e($registro) ?>">Prueba gratis</a><?php endif; ?>
        </div>
    </div>
</nav>

<header class="es-hero">
    <span class="es-orb a" aria-hidden="true"></span><span class="es-orb b" aria-hidden="true"></span>
    <div class="container position-relative">
        <div class="row align-items-center g-4 g-lg-5 py-5" style="min-height:600px">
            <div class="col-lg-6 lp-hero-text">
                <span class="es-eyebrow mb-3"><i class="bi bi-stars"></i> <?= e($E['eyebrow']) ?></span>
                <h1 class="es-h1 mb-3"><?= e($E['h1'][0]) ?><br><em><?= e($E['h1'][1]) ?></em></h1>
                <p class="es-sub mb-4"><?= e($E['sub']) ?></p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="<?= e($registro) ?>" class="es-btn es-fill"><i class="bi bi-rocket-takeoff"></i> Prueba gratis <?= trial_dias_oferta() ?> días</a>
                    <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="es-btn es-ghost"><i class="bi bi-whatsapp"></i> Pregúntanos</a>
                </div>
                <div class="es-trust">
                    <span><i class="bi bi-credit-card-2-front"></i> Sin tarjeta</span>
                    <span><i class="bi bi-cloud-check"></i> Sin instalar nada</span>
                    <span><i class="bi bi-tag"></i> Desde <?= e($desde) ?> al mes con IVA</span>
                </div>
            </div>
            <div class="col-lg-6"><div class="es-stage" aria-hidden="true"><?= ilustracion_especialidad($slug) ?></div></div>
        </div>
    </div>
</header>

<section id="funciones" class="py-6">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="es-eyebrow">Funciones</span>
            <h2 class="es-h2 mt-3">Hecho para tu especialidad</h2>
            <p class="text-muted">Al registrarte, el panel se adapta a tu práctica: su lenguaje y sus herramientas.</p>
        </div>
        <div class="row g-4" data-stagger>
            <?php foreach ($E['func'] as [$ic, $tt, $tx]): ?>
            <div class="col-sm-6 col-lg-3"><div class="es-feat"><span class="es-feat-ic"><i class="bi <?= $ic ?>"></i></span><h3><?= e($tt) ?></h3><p><?= e($tx) ?></p></div></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6 es-tinte">
    <div class="container">
        <div class="text-center mb-5" data-reveal><span class="es-eyebrow">Así se ve un día</span><h2 class="es-h2 mt-3">Un día en tu consultorio</h2></div>
        <div class="es-dia" data-reveal>
            <div class="es-dia-linea" aria-hidden="true"><span></span></div>
            <?php foreach ($E['dia'] as $pi => [$ic, $tt, $tx]): ?>
            <div class="es-paso" style="--i:<?= $pi ?>"><span class="es-paso-ic"><i class="bi <?= $ic ?>"></i></span><b><?= e($tt) ?></b><span class="es-paso-tx"><?= e($tx) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6">
    <div class="container">
        <div class="text-center mb-5" data-reveal><span class="es-eyebrow">Además</span><h2 class="es-h2 mt-3">Todo lo de un consultorio, incluido</h2></div>
        <div class="es-mas" data-stagger>
            <?php foreach ([['bi-calendar-check', 'Agenda y horarios'], ['bi-globe2', 'Página con agenda en línea'], ['bi-whatsapp', 'Recordatorios por WhatsApp'], ['bi-person-heart', 'Portal del paciente'],
                            ['bi-capsule', 'Recetas con tu marca'], ['bi-receipt', 'Cobros y comprobantes'], ['bi-safe2', 'Corte de caja'], ['bi-graph-up-arrow', 'Dashboard con gráficas'],
                            ['bi-shield-plus', 'Vacunación'], ['bi-tv', 'Pantalla de turnos'], ['bi-file-earmark-zip', 'Tus datos en Excel'], ['bi-question-circle', 'Guías de ayuda']] as [$ic, $tt]): ?>
                <div class="es-mas-it"><i class="bi <?= $ic ?>"></i> <?= e($tt) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="planes" class="py-6 es-tinte">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="es-eyebrow">Planes</span>
            <h2 class="es-h2 mt-3">Precios claros, con IVA incluido</h2>
            <p class="text-muted">Prueba el plan Profesional <?= trial_dias_oferta() ?> días gratis, sin tarjeta.</p>
        </div>
        <div class="row g-4 justify-content-center" data-stagger>
            <?php foreach (planes_mp() as $k => $pl): ?>
            <div class="col-md-6 col-lg-4">
                <div class="es-plan<?= $pl['destacado'] ? ' feat' : '' ?>">
                    <div class="fw-semibold fs-5"><?= e($pl['nombre']) ?></div>
                    <div class="text-muted small"><?= e($pl['descripcion']) ?></div>
                    <div class="es-plan-p mt-3">$<?= number_format($pl['precio'], 0) ?><span class="fs-6 text-muted fw-normal">/mes</span></div>
                    <ul><?php foreach (array_slice($pl['items'], 0, 6) as $it): ?><li><i class="bi bi-check-circle-fill"></i> <span><?= e($it) ?></span></li><?php endforeach; ?></ul>
                    <a href="<?= e($registro . '&plan=' . $k) ?>" class="es-btn <?= $pl['destacado'] ? 'es-fill' : 'es-ghost' ?> justify-content-center"><?= e(plan_cta($k)) ?></a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6">
    <div class="container" style="max-width:780px">
        <div class="text-center mb-5" data-reveal><span class="es-eyebrow">Preguntas</span><h2 class="es-h2 mt-3">Preguntas frecuentes</h2></div>
        <div class="accordion es-faq" id="esFaq" data-reveal>
            <?php $faq = array_merge($E['faq'], [
                ['¿Puedo pasar mis pacientes de Excel o de otro sistema?', 'Sí. Mándanos tu Excel o la exportación de tu sistema anterior y te ayudamos a cargarlo para que no captures uno por uno.'],
                ['¿Tengo que instalar algo?', 'No. Funciona en la nube: entras desde el navegador en tu computadora, tablet o celular.'],
            ]);
            foreach ($faq as $fi => [$q, $a]): ?>
            <div class="accordion-item">
                <h3 class="accordion-header"><button class="accordion-button <?= $fi ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#ef<?= $fi ?>"><?= e($q) ?></button></h3>
                <div id="ef<?= $fi ?>" class="accordion-collapse collapse <?= $fi ? '' : 'show' ?>" data-bs-parent="#esFaq"><div class="accordion-body text-muted"><?= e($a) ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6 es-tinte">
    <div class="container" style="max-width:820px" data-reveal>
        <h2 class="es-h2 mb-4">¿Por qué usar un software para <?= e(mb_strtolower($E['nombre'])) ?>?</h2>
        <?php foreach ($E['seo'] as $par): ?><p class="es-seo"><?= e($par) ?></p><?php endforeach; ?>
        <p class="es-seo mb-0"><?= e($marca) ?> está hecho para México, cuesta desde <?= e($desde) ?> al mes con IVA incluido y lo puedes probar <?= trial_dias_oferta() ?> días gratis sin tarjeta.</p>
    </div>
</section>

<section class="py-6">
    <div class="container">
        <div class="text-center mb-4" data-reveal><span class="es-eyebrow">Otras especialidades</span></div>
        <div class="es-otras" data-reveal>
            <?php foreach ($todas as $s2 => $E2): if ($s2 === $slug) continue; ?>
                <a href="<?= BASE_URL ?>/<?= $s2 ?>" style="--c2:<?= $E2['c'] ?>"><?= e($E2['nombre']) ?> <i class="bi bi-arrow-right"></i></a>
            <?php endforeach; ?>
            <a href="<?= BASE_URL ?>/veterinarias" style="--c2:#0d9488">Veterinarias <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>
</section>

<section class="pb-6">
    <div class="container">
        <div class="es-cta" data-reveal="zoom">
            <h2 class="es-h2 mb-3 position-relative">Tu consultorio listo hoy</h2>
            <p class="lead mb-4 position-relative" style="opacity:.92">Sin instalar nada y sin tarjeta. Si tienes dudas, escríbenos por WhatsApp.</p>
            <div class="d-flex flex-wrap justify-content-center gap-2 position-relative">
                <a href="<?= e($registro) ?>" class="es-btn" style="background:#fff;color:var(--cd)"><i class="bi bi-rocket-takeoff"></i> Crear mi cuenta gratis</a>
                <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="es-btn" style="border:1px solid rgba(255,255,255,.5);color:#fff"><i class="bi bi-whatsapp"></i> Hablar por WhatsApp</a>
            </div>
        </div>
    </div>
</section>

<footer class="landing-footer py-4">
    <div class="container d-flex flex-wrap justify-content-between gap-2 small">
        <span>&copy; <?= date('Y') ?> <?= e($marca) ?> · <a class="lp-foot-link d-inline" href="<?= BASE_URL ?>/">Software para consultorios y clínicas</a></span>
        <a class="lp-foot-link d-inline" href="<?= BASE_URL ?>/privacidad">Aviso de privacidad</a>
    </div>
</footer>
<a href="<?= e($wa) ?>" class="lp-wa" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('assets/js/landing-motion.js') ?>"></script>
</body>
</html>
