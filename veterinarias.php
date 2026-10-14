<?php
/**
 * Landing para veterinarias: /veterinarias
 *
 * Misma marca y planes que la landing general, con el mensaje y las funciones
 * de la interfaz veterinaria (mascotas, tutores, vacunas, recordatorios). Es el
 * destino de los anuncios dirigidos a veterinarios y su página para Google.
 *
 * Todo lo que se anuncia aquí existe en el producto. Nada de cifras de
 * clientes ni testimonios inventados (regla de la landing).
 * El botón de registro llega con la interfaz veterinaria ya elegida.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mercadopago.php';

tenant_override(1);                 // marca pública del producto
$marca = marca_nombre();
tenant_override(null, true);
$logged = is_logged_in();
track_pageview('publico');

$registro = BASE_URL . '/auth/registro?giro=veterinaria';
$wa = 'https://wa.me/' . SOPORTE_WHATSAPP . '?text=' . rawurlencode('Hola, tengo una veterinaria y quiero ver cómo funcionaría ' . $marca . '.');
$desde = '$' . number_format(min(array_column(planes_mp(), 'precio')), 0);
$paw = '<svg viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="42" rx="14" ry="12"/><ellipse cx="14" cy="26" rx="6" ry="8"/><ellipse cx="26" cy="16" rx="6" ry="8"/><ellipse cx="38" cy="16" rx="6" ry="8"/><ellipse cx="50" cy="26" rx="6" ry="8"/></svg>';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Software veterinario en México · <?= e($marca) ?></title>
    <meta name="description" content="Software para veterinarias: expediente de cada mascota, cartilla de vacunas con recordatorios al tutor, agenda, cobros e inventario. En la nube y en español. Prueba 15 días gratis, sin tarjeta.">
    <link rel="canonical" href="<?= e(url_absoluta('/veterinarias')) ?>">
    <meta property="og:title" content="Software veterinario · <?= e($marca) ?>">
    <meta property="og:description" content="Mascotas, tutores, vacunas y recordatorios en un solo lugar. Prueba 15 días gratis.">
    <meta property="og:image" content="<?= e(url_absoluta('/assets/video/mediagenda-anuncio.jpg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/material.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/landing-motion.css') ?>" rel="stylesheet">
    <script>
    (function (d) {
        if (!('IntersectionObserver' in window) || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
        d.classList.add('motion');
        window.__lpMotionSafe = setTimeout(function () { d.classList.remove('motion'); }, 4000);
    })(document.documentElement);
    </script>
    <?= meta_pixel_head() ?>
<style>
:root { --vt: #0d9488; --vt-d: #0f766e; --vt-l: #ccfbf1; }
body.lp { background: #fff; color: var(--ink); }
.vt-eyebrow { display: inline-flex; align-items: center; gap: .4rem; color: var(--vt-d); background: var(--vt-l); padding: .38rem .9rem; border-radius: 999px;
              font-weight: 650; font-size: .76rem; letter-spacing: .12em; text-transform: uppercase; }
.vt-h2 { font-family: var(--font-display); font-weight: 700; letter-spacing: -.035em; font-size: clamp(1.9rem, 3.8vw, 2.8rem); line-height: 1.08; }
.vt-btn { border-radius: 980px; padding: .85rem 1.6rem; font-weight: 600; display: inline-flex; align-items: center; gap: .5rem; text-decoration: none; transition: transform .2s ease, filter .2s ease; }
.vt-btn:hover { transform: translateY(-2px); }
.vt-btn-fill { background: var(--vt); color: #fff; box-shadow: 0 12px 28px rgba(13,148,136,.32); }
.vt-btn-fill:hover { color: #fff; filter: brightness(1.06); }
.vt-btn-ghost { background: #fff; color: var(--vt-d); border: 1px solid rgba(13,148,136,.3); }
.vt-btn-ghost:hover { color: var(--vt-d); }

/* ── Hero con ilustración animada propia ── */
.vt-hero { position: relative; overflow: hidden; background: radial-gradient(900px 520px at 90% -10%, rgba(13,148,136,.18), transparent 60%),
           radial-gradient(700px 420px at 0% 100%, rgba(37,99,235,.10), transparent 60%), linear-gradient(170deg, #f0fdfa, #fff 70%); }
.vt-hero .container { position: relative; z-index: 1; }
.vt-h1 { font-family: var(--font-display); font-weight: 750; letter-spacing: -.045em; line-height: 1.02; font-size: clamp(2.5rem, 5.6vw, 4.2rem); }
.vt-h1 em { font-style: normal; color: var(--vt); }
.vt-sub { font-size: 1.15rem; color: var(--muted); max-width: 36rem; line-height: 1.55; }
.vt-trust { display: flex; flex-wrap: wrap; gap: .5rem; } .vt-trust span { font-size: .85rem; font-weight: 600; padding: .35rem .8rem; border-radius: 999px; background: #fff; border: 1px solid rgba(0,0,0,.06); }
.vt-trust .bi { color: var(--vt); }
/* Huellas que caminan por el fondo */
.vt-huellas { position: absolute; inset: 0; pointer-events: none; z-index: 0; }
.vt-huellas svg { position: absolute; width: 26px; height: 26px; fill: rgba(13,148,136,.16); opacity: 0; }
@keyframes huella { 0% { opacity: 0; transform: scale(.6) rotate(var(--r)); } 15% { opacity: 1; transform: scale(1) rotate(var(--r)); } 70% { opacity: 1; } 100% { opacity: 0; transform: scale(1) rotate(var(--r)); } }
html.motion .vt-huellas svg { animation: huella 6s ease-in-out infinite; animation-delay: calc(var(--i) * .45s); }
html:not(.motion) .vt-huellas svg { opacity: .6; transform: rotate(var(--r)); }
/* Ficha de mascota flotando */
.vt-stage { position: relative; max-width: 440px; margin: 0 auto; }
.vt-card { background: #fff; border-radius: 26px; padding: 1.4rem; box-shadow: 0 2px 10px rgba(15,30,60,.05), 0 34px 80px rgba(15,30,60,.16); position: relative; z-index: 2; }
@keyframes flota { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
html.motion .vt-card { animation: lpUp .9s .2s cubic-bezier(.22,1,.36,1) both, flota 6s 1.2s ease-in-out infinite; }
.vt-pet { display: flex; align-items: center; gap: 1rem; }
.vt-pet-av { width: 64px; height: 64px; border-radius: 20px; display: flex; align-items: center; justify-content: center; background: linear-gradient(150deg, #5eead4, var(--vt)); }
.vt-pet-av svg { width: 36px; height: 36px; fill: #fff; }
html.motion .vt-pet-av svg { animation: lpPop 2.6s 1.5s ease-in-out infinite; }
.vt-pet-n { font-family: var(--font-display); font-weight: 750; font-size: 1.35rem; letter-spacing: -.02em; }
.vt-pet-s { font-size: .85rem; color: var(--muted); }
.vt-row { display: flex; align-items: center; gap: .7rem; padding: .7rem .8rem; border-radius: 14px; background: #f8fafc; margin-top: .55rem; font-size: .9rem; }
.vt-row .bi { color: var(--vt); font-size: 1.1rem; }
.vt-row .ok { margin-left: auto; color: #16a34a; font-weight: 700; font-size: .78rem; }
.vt-row.pend { background: #fff7ed; } .vt-row.pend .bi { color: #f59e0b; } .vt-row.pend .ok { color: #c2410c; }
@keyframes latido { 0%, 100% { box-shadow: 0 0 0 0 rgba(245,158,11,.4); } 60% { box-shadow: 0 0 0 10px rgba(245,158,11,0); } }
html.motion .vt-row.pend { animation: latido 2.2s 1.8s ease-out infinite; }
.vt-toast { position: absolute; z-index: 3; right: -18px; bottom: -26px; display: flex; align-items: center; gap: .6rem; background: #fff; border-radius: 16px;
            padding: .7rem .9rem; box-shadow: 0 16px 40px rgba(15,30,60,.18); border: 1px solid rgba(34,197,94,.3); font-size: .85rem; }
.vt-toast .bi { font-size: 1.4rem; color: #25d366; } .vt-toast b { display: block; }
html.motion .vt-toast { animation: lpUp .8s 2.2s cubic-bezier(.22,1,.36,1) both; }
.vt-chip { position: absolute; z-index: 3; left: -24px; top: 18%; background: var(--vt); color: #fff; border-radius: 999px; padding: .5rem .9rem; font-weight: 600; font-size: .82rem;
           box-shadow: 0 12px 28px rgba(13,148,136,.35); }
html.motion .vt-chip { animation: lpUp .8s 1.6s cubic-bezier(.22,1,.36,1) both, flota 5s 2.4s ease-in-out infinite; }
@media (max-width: 575.98px) { .vt-toast { right: 6px; } .vt-chip { left: 6px; top: -14px; } }

/* ── Funciones ── */
.vt-feat { height: 100%; padding: 1.5rem; border-radius: 22px; background: #fff; border: 1px solid rgba(0,0,0,.05);
           box-shadow: 0 1px 4px rgba(0,0,0,.03), 0 12px 30px rgba(15,30,60,.06); transition: transform .35s cubic-bezier(.22,1,.36,1), box-shadow .35s ease; }
.vt-feat:hover { transform: translateY(-6px); box-shadow: 0 4px 12px rgba(0,0,0,.04), 0 24px 54px rgba(15,30,60,.11); }
.vt-feat-ic { width: 54px; height: 54px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.45rem; color: var(--vt);
              background: var(--vt-l); margin-bottom: 1rem; transition: transform .45s cubic-bezier(.34,1.56,.64,1); }
.vt-feat:hover .vt-feat-ic { transform: scale(1.1) rotate(-6deg); }
.vt-feat h3 { font-family: var(--font-display); font-weight: 650; font-size: 1.12rem; letter-spacing: -.02em; }
.vt-feat p { color: var(--muted); font-size: .95rem; margin: 0; }

/* ── Un día en tu veterinaria (recorrido animado) ── */
.vt-dia { position: relative; display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; }
.vt-dia-linea { position: absolute; top: 34px; left: 10%; right: 10%; height: 3px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
.vt-dia-linea span { display: block; height: 100%; background: linear-gradient(90deg, var(--vt), #5eead4); transform-origin: 0 50%; transform: scaleX(0); transition: transform 2s cubic-bezier(.22,1,.36,1) .3s; }
.vt-dia.is-in .vt-dia-linea span, html:not(.motion) .vt-dia-linea span { transform: none; }
.vt-paso { text-align: center; position: relative; }
.vt-paso-ic { width: 68px; height: 68px; margin: 0 auto .8rem; border-radius: 22px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: #fff;
              background: linear-gradient(150deg, #2dd4bf, var(--vt-d)); box-shadow: 0 12px 26px rgba(13,148,136,.3); }
html.motion .vt-dia .vt-paso-ic { transform: scale(.5); opacity: 0; transition: transform .6s cubic-bezier(.34,1.56,.64,1), opacity .4s ease; transition-delay: calc(.3s * var(--i) + .2s); }
html.motion .vt-dia.is-in .vt-paso-ic { transform: none; opacity: 1; }
.vt-paso b { display: block; font-family: var(--font-display); letter-spacing: -.01em; }
.vt-paso span { font-size: .88rem; color: var(--muted); }
@media (max-width: 767.98px) { .vt-dia { grid-template-columns: 1fr; } .vt-dia-linea { display: none; } }

/* ── Planes, FAQ, CTA ── */
.vt-plan { height: 100%; padding: 1.8rem; border-radius: 22px; background: #fff; border: 1px solid rgba(0,0,0,.06); display: flex; flex-direction: column;
           box-shadow: 0 10px 28px rgba(15,30,60,.06); }
.vt-plan.feat { border-color: rgba(13,148,136,.45); box-shadow: 0 18px 44px rgba(13,148,136,.16); }
.vt-plan-p { font-family: var(--font-display); font-size: 2.6rem; font-weight: 700; letter-spacing: -.045em; }
.vt-plan ul { list-style: none; padding: 0; margin: 1rem 0 1.4rem; flex: 1; } .vt-plan li { padding: .3rem 0; font-size: .93rem; display: flex; gap: .5rem; }
.vt-plan li .bi { color: var(--vt); }
.vt-faq .accordion-item { border: 1px solid rgba(0,0,0,.06) !important; border-radius: 18px !important; margin-bottom: .7rem; overflow: hidden; }
.vt-faq .accordion-button { font-weight: 600; padding: 1.1rem 1.3rem; } .vt-faq .accordion-button:not(.collapsed) { background: #fff; color: var(--vt-d); box-shadow: none; }
.vt-cta { position: relative; overflow: hidden; border-radius: 32px; padding: 4rem 1.5rem; color: #fff; text-align: center;
          background: linear-gradient(125deg, var(--vt-d), var(--vt) 55%, #2dd4bf 130%); box-shadow: 0 30px 80px rgba(13,148,136,.3); }
.vt-cta::before { content: ''; position: absolute; width: 420px; height: 420px; border-radius: 50%; background: rgba(255,255,255,.1); right: -140px; top: -200px; }
.vt-seo p { color: #4b5563; line-height: 1.75; }
.vt-video { width: 100%; border-radius: 20px; background: #000; }
</style>
</head>
<body class="lp">

<nav class="navbar navbar-expand-lg landing-nav sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/" style="color:var(--vt)"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?> <span class="small fw-semibold text-muted">· <?= et('Veterinarias') ?></span></a>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <a class="nav-link d-none d-md-inline" href="#funciones"><?= et('Funciones') ?></a>
            <a class="nav-link d-none d-md-inline" href="#planes"><?= et('Planes') ?></a>
            <?php if ($logged): ?><a class="vt-btn vt-btn-fill py-2" href="<?= BASE_URL ?>/dashboard"><?= et('Ir al panel') ?></a>
            <?php else: ?><a class="nav-link" href="<?= BASE_URL ?>/auth/login"><?= et('Iniciar sesión') ?></a>
            <a class="vt-btn vt-btn-fill py-2" href="<?= e($registro) ?>"><?= et('Prueba gratis') ?></a><?php endif; ?>
        </div>
    </div>
</nav>

<header class="vt-hero">
    <?php /* Huellas: ilustración propia en SVG que camina en diagonal por el fondo. */ ?>
    <div class="vt-huellas" aria-hidden="true">
        <?php foreach ([[6, 78, -20], [11, 66, -10], [16, 74, -20], [21, 62, -10], [26, 70, -18], [31, 58, -8], [70, 18, 160], [75, 28, 170], [80, 16, 160], [85, 26, 172], [90, 14, 160]] as $hi => [$x, $y, $r]): ?>
            <span style="position:absolute;left:<?= $x ?>%;top:<?= $y ?>%;--i:<?= $hi ?>;--r:<?= $r ?>deg"><?= str_replace('<svg ', '<svg style="--i:' . $hi . ';--r:' . $r . 'deg" ', $paw) ?></span>
        <?php endforeach; ?>
    </div>
    <div class="container">
        <div class="row align-items-center g-5 py-5" style="min-height:620px">
            <div class="col-lg-6 lp-hero-text">
                <span class="vt-eyebrow mb-3"><i class="bi bi-heart-fill"></i> <?= et('Software para veterinarias') ?></span>
                <h1 class="vt-h1 mb-3"><?= et('Tu veterinaria,') ?><br><em><?= et('de la consulta a la vacuna') ?></em></h1>
                <p class="vt-sub mb-4"><?= et('El expediente de cada mascota, su cartilla de vacunas con recordatorios al tutor, la agenda y los cobros, en un solo lugar. Tú atiendes; el sistema se encarga de que regresen.') ?></p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="<?= e($registro) ?>" class="vt-btn vt-btn-fill"><i class="bi bi-rocket-takeoff"></i> <?= sprintf(et('Prueba gratis %d días'), trial_dias_oferta()) ?></a>
                    <button type="button" class="vt-btn vt-btn-ghost" data-bs-toggle="modal" data-bs-target="#vtVideo"><i class="bi bi-play-circle-fill"></i> <?= et('Ver video') ?></button>
                </div>
                <div class="vt-trust">
                    <span><i class="bi bi-credit-card-2-front"></i> <?= et('Sin tarjeta') ?></span>
                    <span><i class="bi bi-cloud-check"></i> <?= et('Sin instalar nada') ?></span>
                    <span><i class="bi bi-tag"></i> <?= et('Desde') ?> <?= e($desde) ?> <?= et('al mes con IVA') ?></span>
                </div>
            </div>
            <div class="col-lg-6">
                <?php /* Ilustración propia: la ficha de una mascota con sus vacunas. Datos de ejemplo. */ ?>
                <div class="vt-stage" aria-hidden="true">
                    <div class="vt-chip"><i class="bi bi-shield-plus"></i> <?= et('Cartilla al día') ?></div>
                    <div class="vt-card">
                        <div class="vt-pet">
                            <span class="vt-pet-av"><?= $paw ?></span>
                            <div>
                                <div class="vt-pet-n">Luna</div>
                                <div class="vt-pet-s"><?= et('Gato · Siamés · 2 años') ?></div>
                            </div>
                            <span class="badge rounded-pill ms-auto" style="background:var(--vt-l);color:var(--vt-d)">DEMO</span>
                        </div>
                        <div class="vt-row"><i class="bi bi-person-heart"></i> <span><?= et('Tutor') ?>: Ana Pérez</span></div>
                        <div class="vt-row"><i class="bi bi-check-circle-fill"></i> <span><?= et('Triple felina') ?></span><span class="ok"><?= et('Aplicada') ?></span></div>
                        <div class="vt-row"><i class="bi bi-check-circle-fill"></i> <span><?= et('Desparasitación') ?></span><span class="ok"><?= et('Aplicada') ?></span></div>
                        <div class="vt-row pend"><i class="bi bi-calendar-event"></i> <span><?= et('Rabia') ?></span><span class="ok"><?= et('en 3 días') ?></span></div>
                    </div>
                    <div class="vt-toast"><i class="bi bi-whatsapp"></i><div><b><?= et('Recordatorio enviado') ?></b><span class="text-muted"><?= et('al tutor de Luna') ?></span></div></div>
                </div>
            </div>
        </div>
    </div>
</header>

<section id="funciones" class="py-6">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="vt-eyebrow"><?= et('Funciones') ?></span>
            <h2 class="vt-h2 mt-3"><?= et('Todo lo de tu veterinaria, sin papeles') ?></h2>
            <p class="text-muted"><?= et('Pensado para el día a día de una clínica veterinaria.') ?></p>
        </div>
        <div class="row g-4" data-stagger>
            <?php foreach ([
                ['bi-clipboard2-pulse', 'Expediente de cada mascota', 'Especie, raza, sexo, color, esterilizado y microchip; consultas, alergias, cirugías y estudios en su historial.'],
                ['bi-shield-plus', 'Cartilla de vacunas', 'Registra cada vacuna con su lote. La próxima dosis se calcula sola y ves quién tiene vacunas vencidas.'],
                ['bi-whatsapp', 'Recordatorios al tutor', 'Citas y vacunas: recordatorio por WhatsApp en un clic y por correo automático. Menos citas olvidadas.'],
                ['bi-person-heart', 'Portal del tutor', 'Cada tutor ve las citas, recetas, vacunas y estudios de su mascota desde el celular.'],
                ['bi-shop', 'Cobros, venta e inventario', 'Cobra consultas y vende alimento o medicamentos en el punto de venta; el inventario te avisa cuando algo se acaba.'],
                ['bi-tv', 'Sala de espera y turnos', 'Check-in, pasar a consulta y una pantalla de turnos para la TV de tu recepción.'],
                ['bi-globe2', 'Tu página con agenda en línea', 'Tu clínica en internet con servicios, equipo y botón para que los tutores agenden solos.'],
                ['bi-safe2', 'Corte de caja real', 'Cada quien cierra su caja: lo cobrado, lo contado y la diferencia, con aviso al dueño de la clínica.'],
            ] as [$ic, $tt, $tx]): ?>
            <div class="col-sm-6 col-lg-3">
                <div class="vt-feat"><span class="vt-feat-ic"><i class="bi <?= $ic ?>"></i></span><h3><?= et($tt) ?></h3><p><?= et($tx) ?></p></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6" style="background:linear-gradient(180deg,#f0fdfa,#fff)">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="vt-eyebrow"><?= et('Así se ve un día') ?></span>
            <h2 class="vt-h2 mt-3"><?= et('Un día en tu veterinaria') ?></h2>
        </div>
        <div class="vt-dia" data-reveal>
            <div class="vt-dia-linea" aria-hidden="true"><span></span></div>
            <?php foreach ([['bi-calendar-check', 'Llega Luna', 'Su tutor agendó en línea y confirmó por WhatsApp.'],
                            ['bi-door-open', 'Check-in', 'Recepción la registra y aparece en la pantalla de turnos.'],
                            ['bi-clipboard2-pulse', 'Consulta', 'Peso, signos y diagnóstico en su expediente.'],
                            ['bi-shield-plus', 'Vacuna', 'Se registra y la próxima dosis queda programada.'],
                            ['bi-receipt', 'Cobro', 'Consulta y alimento en un solo cobro; entra al corte del día.']] as $pi => [$ic, $tt, $tx]): ?>
            <div class="vt-paso" style="--i:<?= $pi ?>"><span class="vt-paso-ic"><i class="bi <?= $ic ?>"></i></span><b><?= et($tt) ?></b><span><?= et($tx) ?></span></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="planes" class="py-6">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="vt-eyebrow"><?= et('Planes') ?></span>
            <h2 class="vt-h2 mt-3"><?= et('Precios claros, con IVA incluido') ?></h2>
            <p class="text-muted"><?= sprintf(et('Prueba el plan Profesional %d días gratis, sin tarjeta.'), trial_dias_oferta()) ?></p>
        </div>
        <div class="row g-4 justify-content-center" data-stagger>
            <?php foreach (planes_mp() as $k => $pl): ?>
            <div class="col-md-6 col-lg-4">
                <div class="vt-plan<?= $pl['destacado'] ? ' feat' : '' ?>">
                    <div class="fw-semibold fs-5"><?= e($pl['nombre']) ?></div>
                    <div class="text-muted small"><?= e($pl['descripcion']) ?></div>
                    <div class="vt-plan-p mt-3">$<?= number_format($pl['precio'], 0) ?><span class="fs-6 text-muted fw-normal">/<?= et('mes') ?></span></div>
                    <ul>
                        <?php foreach (array_slice($pl['items'], 0, 6) as $it): ?><li><i class="bi bi-check-circle-fill"></i> <span><?= e($it) ?></span></li><?php endforeach; ?>
                    </ul>
                    <a href="<?= e(BASE_URL . '/auth/registro?giro=veterinaria&plan=' . $k) ?>" class="vt-btn <?= $pl['destacado'] ? 'vt-btn-fill' : 'vt-btn-ghost' ?> justify-content-center"><?= e(plan_cta($k)) ?></a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6" style="background:#f8fafc">
    <div class="container" style="max-width:780px">
        <div class="text-center mb-5" data-reveal><span class="vt-eyebrow"><?= et('Preguntas') ?></span><h2 class="vt-h2 mt-3"><?= et('Preguntas frecuentes') ?></h2></div>
        <div class="accordion vt-faq" id="vtFaq" data-reveal>
            <?php foreach ([
                ['¿Sirve para perros, gatos y otras especies?', 'Sí. Registras perros, gatos, aves, conejos, roedores, hurones, reptiles, caballos y más, cada uno con su especie, raza, sexo, microchip y tutor. El catálogo de vacunas va por especie y lo ajustas a tu práctica.'],
                ['¿Puedo pasar mis pacientes de Excel o de otro sistema?', 'Sí. Mándanos tu Excel, la exportación de tu sistema anterior o lo que tengas y te ayudamos a cargarlo para que no captures uno por uno.'],
                ['¿Me ayudan a empezar?', 'Sí. Te acompañamos por WhatsApp, en español, para configurar tu clínica: tus servicios, tu equipo, tu página y tus recordatorios.'],
                ['¿El tutor recibe los recordatorios?', 'Sí. Los recordatorios de cita y de vacuna le llegan al tutor: por WhatsApp en un clic desde la ficha y por correo de forma automática.'],
                ['¿Tengo que instalar algo?', 'No. Funciona en la nube: entras desde el navegador en tu computadora, tablet o celular.'],
                ['¿Qué pasa con mis datos?', 'Viajan cifrados, con respaldos y acceso por roles para tu equipo. Tus datos son tuyos: los puedes descargar en Excel cuando quieras.'],
            ] as $fi => [$q, $a]): ?>
            <div class="accordion-item">
                <h3 class="accordion-header"><button class="accordion-button <?= $fi ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#vf<?= $fi ?>"><?= et($q) ?></button></h3>
                <div id="vf<?= $fi ?>" class="accordion-collapse collapse <?= $fi ? '' : 'show' ?>" data-bs-parent="#vtFaq"><div class="accordion-body text-muted"><?= et($a) ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="py-6 vt-seo">
    <div class="container" style="max-width:820px" data-reveal>
        <h2 class="vt-h2 mb-4"><?= et('¿Por qué usar un software veterinario?') ?></h2>
        <p><?= et('Una clínica veterinaria vive de que las mascotas regresen: a su refuerzo de vacuna, a su desparasitación, a su revisión. Cuando todo eso depende de una libreta o de la memoria de recepción, las citas se olvidan y los tutores no vuelven a tiempo.') ?></p>
        <p><?= et('Con un software veterinario cada mascota tiene su expediente completo y su cartilla de vacunas, la agenda recuerda sola a los tutores y sabes cuánto cobraste cada día. Recepción, médicos y dueño ven lo que les toca, desde cualquier dispositivo.') ?></p>
        <p class="mb-0"><?= sprintf(et('%s está hecho para México, cuesta desde %s al mes con IVA incluido y lo puedes probar %d días gratis sin tarjeta.'), e($marca), e($desde), trial_dias_oferta()) ?></p>
    </div>
</section>

<section class="pb-6">
    <div class="container">
        <div class="vt-cta" data-reveal="zoom">
            <h2 class="vt-h2 mb-3 position-relative"><?= et('Tu veterinaria lista hoy') ?></h2>
            <p class="lead mb-4 position-relative" style="opacity:.92"><?= et('Sin instalar nada y sin tarjeta. Si tienes dudas, escríbenos por WhatsApp.') ?></p>
            <div class="d-flex flex-wrap justify-content-center gap-2 position-relative">
                <a href="<?= e($registro) ?>" class="vt-btn" style="background:#fff;color:var(--vt-d)"><i class="bi bi-rocket-takeoff"></i> <?= et('Crear mi cuenta gratis') ?></a>
                <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="vt-btn" style="border:1px solid rgba(255,255,255,.5);color:#fff"><i class="bi bi-whatsapp"></i> <?= et('Hablar por WhatsApp') ?></a>
            </div>
        </div>
    </div>
</section>

<footer class="landing-footer py-4">
    <div class="container d-flex flex-wrap justify-content-between gap-2 small">
        <span>&copy; <?= date('Y') ?> <?= e($marca) ?> · <a class="lp-foot-link d-inline" href="<?= BASE_URL ?>/"><?= et('Software para consultorios y clínicas') ?></a></span>
        <a class="lp-foot-link d-inline" href="<?= BASE_URL ?>/privacidad"><?= et('Aviso de privacidad') ?></a>
    </div>
</footer>

<a href="<?= e($wa) ?>" class="lp-wa" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="bi bi-whatsapp"></i></a>

<div class="modal fade" id="vtVideo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content border-0 bg-transparent">
            <video class="vt-video" controls playsinline preload="none" poster="<?= BASE_URL ?>/assets/video/mediagenda-anuncio.jpg">
                <source src="<?= BASE_URL ?>/assets/video/mediagenda-anuncio.mp4" type="video/mp4">
            </video>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('assets/js/landing-motion.js') ?>"></script>
<script>
/* El video se reproduce al abrir el modal y se pausa al cerrarlo. */
(function () {
    var m = document.getElementById('vtVideo'), v = m.querySelector('video');
    m.addEventListener('shown.bs.modal', function () { v.play().catch(function () {}); });
    m.addEventListener('hidden.bs.modal', function () { v.pause(); });
})();
</script>
</body>
</html>
