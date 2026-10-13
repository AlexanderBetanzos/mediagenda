<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mercadopago.php';
require_once __DIR__ . '/includes/ia.php';

// Micrositio por consultorio: igual que GymOS con ?t=slug. La raíz del dominio es
// la landing del PRODUCTO; cuando llega el slug de un consultorio vigente, se
// muestra SU página (marca, servicios, médicos, contacto) en vez de la de aquí.
$slugPublico = (string) ($_GET['c'] ?? $_GET['t'] ?? '');
if ($slugPublico !== '' && ($con = consultorio_publico($slugPublico))) {
    track_pageview('clinica');
    require __DIR__ . '/includes/publico_clinica.php';
    exit;
}

$logged = is_logged_in();
/* La landing vende el PRODUCTO: su nombre es el de la configuración pública
   (consultorio 1), no el del consultorio con sesión abierta. Antes, entrar
   logueado como "Prueba Pixel" rebautizaba la landing con ese nombre. */
tenant_override(1);
$marca = marca_nombre();
tenant_override(null, true);
track_pageview('publico');

/* La landing va SIEMPRE en claro: es una página de marketing y el modo oscuro
   se ve mal aquí. No se lee la cookie ni se ofrece el interruptor. */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($marca) ?> · Software para consultorios, clínicas y hospitales</title>
    <meta name="facebook-domain-verification" content="an6t5igljwq4s8ggj27mbj3f1fprhb">
    <meta name="description" content="Toma el control de tu consultorio: agenda que confirma citas, expediente clínico protegido y cobros claros. Prueba 15 días gratis, sin tarjeta.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://images.unsplash.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/material.css') ?>" rel="stylesheet">
    <link href="<?= asset('assets/css/landing-motion.css') ?>" rel="stylesheet">
    <script>
    /* Movimiento solo si el usuario no pidió reducirlo. Si landing-motion.js no
       llega en 4 s, se quita la clase y la página queda visible y estática. */
    (function (d) {
        if (!('IntersectionObserver' in window) || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) return;
        d.classList.add('motion');
        window.__lpMotionSafe = setTimeout(function () { d.classList.remove('motion'); }, 4000);
    })(document.documentElement);
    </script>
    <?= meta_pixel_head() ?>
</head>
<body class="lp">

<!-- Barra de anuncio -->
<div class="lp-announce">
    <div class="container">
        <i class="bi bi-stars"></i> Prueba <strong><?= e($marca) ?></strong> 15 días gratis — acceso completo, sin tarjeta.
        <a href="<?= BASE_URL ?>/auth/registro">Empezar ahora <i class="bi bi-arrow-right"></i></a>
    </div>
</div>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg landing-nav sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold text-brand" href="#"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?></a>
        <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav mx-auto align-items-lg-center gap-lg-1">
                <li class="nav-item"><a class="nav-link" href="#funciones">Funciones</a></li>
                <li class="nav-item"><a class="nav-link" href="#beneficios">Beneficios</a></li>
                <li class="nav-item"><a class="nav-link" href="#planes">Planes</a></li>
                <li class="nav-item"><a class="nav-link" href="#faq">Preguntas</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/portal/login"><i class="bi bi-person-heart"></i> Portal del paciente</a></li>
            </ul>
            <ul class="navbar-nav align-items-lg-center gap-lg-2">
                <?php if ($logged): ?>
                <li class="nav-item"><a class="btn btn-primary px-3" href="<?= BASE_URL ?>/dashboard"><i class="bi bi-speedometer2"></i> Ir al panel</a></li>
                <?php else: ?>
                <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>/auth/login">Iniciar sesión</a></li>
                <li class="nav-item"><a class="btn btn-primary px-3" href="<?= BASE_URL ?>/auth/registro"><i class="bi bi-rocket-takeoff"></i> Prueba gratis</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero claro, estilo clínica: titular partido, franja de datos y mock flotando -->
<header class="lp-hero">
    <span class="lp-blob" aria-hidden="true"></span>
    <div class="container">
        <div class="row align-items-center g-4 g-lg-5 lp-hero-row">
            <div class="col-lg-6 lp-hero-text">
                <span class="lp-pill mb-3"><i class="bi bi-patch-check-fill"></i> Hecho para consultorios de México</span>
                <h1 class="lp-h1 mb-3">Toma el control<br><span class="lp-h1-ac">de tu consultorio</span></h1>
                <p class="lp-sub mb-4">Protege el expediente de cada paciente, deja que la agenda confirme las citas por ti y cobra sin perseguir pagos. Tú atiendes; <?= e($marca) ?> se encarga del resto.</p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="<?= BASE_URL ?>/auth/registro" class="btn lp-btn lp-btn-fill"><i class="bi bi-rocket-takeoff"></i> Prueba gratis <?= trial_dias_oferta() ?> días</a>
                    <a href="#funciones" class="btn lp-btn lp-btn-ghost">Ver cómo funciona</a>
                </div>

                <?php
                /* Franja de datos. Todo lo de aquí es COMPROBABLE en el producto:
                   nada de "10 mil pacientes felices" ni médicos inventados. El
                   16 sale de contar los módulos que exigen require_modulo('especialidades'). */
                $datos = [
                    ['bi-clipboard2-pulse', '16',  'Especialidades clínicas'],
                    ['bi-gift',             trial_dias_oferta() . ' días', 'De prueba, sin tarjeta'],
                    ['bi-cash-coin',        '0%',  'Comisión sobre tus cobros'],
                ];
                ?>
                <div class="lp-stats">
                <?php foreach ($datos as [$ic, $n, $l]): ?>
                <div class="lp-stat">
                    <span class="lp-stat-ic"><i class="bi <?= $ic ?>"></i></span>
                    <span>
                        <span class="lp-stat-n" data-count><?= e($n) ?></span>
                        <span class="lp-stat-l"><?= e($l) ?></span>
                    </span>
                </div>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="lp-mock">
                    <div class="lp-mock-in">
                    <div class="lp-mock-bar"><span></span><span></span><span></span></div>
                    <div class="lp-dash card border-0">
                        <div class="card-body p-3 p-sm-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-semibold text-brand"><i class="bi bi-grid-1x2-fill"></i> Panel del consultorio</span>
                                <span class="badge bg-success">En vivo</span>
                            </div>
                            <div class="row g-2 mb-3">
                                <?php foreach ([['Citas hoy','14','#2563eb'],['Pacientes','231','#14b8a6'],['Ingresos','$48k','#16a34a']] as [$l,$n,$c]): ?>
                                <div class="col-4"><div class="lp-tile">
                                    <div class="lp-tile-n" style="color:<?= $c ?>" data-count><?= $n ?></div>
                                    <div class="lp-tile-l"><?= $l ?></div>
                                </div></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="small fw-semibold text-muted mb-2">Agenda de hoy</div>
                            <?php foreach ([['09:00','Martínez García','Confirmada','info'],['10:30','López Pérez','Programada','secondary'],['11:15','Rodríguez Cruz','Atendida','success']] as [$h,$p,$e,$col]): ?>
                            <div class="lp-ag d-flex justify-content-between align-items-center">
                                <span><span class="lp-ag-h"><?= $h ?></span> <?= $p ?></span>
                                <span class="badge bg-<?= $col ?><?= $p === 'López Pérez' ? ' lp-flip' : '' ?>"><?= $e ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    </div>
                    <!-- Aviso que aparece cuando la cita de López Pérez se confirma sola (landing-motion.js) -->
                    <div class="lp-toast" aria-hidden="true">
                        <i class="bi bi-check-circle-fill"></i>
                        <div><b>Cita confirmada</b><span>López Pérez · 10:30</span></div>
                    </div>
                    <!-- Tarjeta flotante: el equivalente honesto al "24/7" de las clínicas -->
                    <div class="lp-float">
                        <div class="lp-float-ic"><i class="bi bi-shield-lock-fill"></i></div>
                        <div>
                            <div class="lp-float-t">Tus datos, tuyos</div>
                            <div class="lp-float-s">Cifrados y con respaldo</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Franja de confianza -->
<section class="lp-trust">
    <div class="container">
        <div class="row g-4 text-center" data-stagger>
            <?php foreach ([
                ['bi-cloud-check','100% en la nube','Sin instalar nada'],
                ['bi-shield-lock','Datos protegidos','Cifrado y respaldos'],
                ['bi-phone','Multidispositivo','PC, tablet y celular'],
                ['bi-headset','Soporte en español','Te acompañamos'],
            ] as [$ic,$t,$d]): ?>
            <div class="col-6 col-lg-3">
                <i class="bi <?= $ic ?> lp-trust-ic"></i>
                <div class="fw-semibold mt-2"><?= e($t) ?></div>
                <div class="small text-muted"><?= e($d) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Funciones -->
<section id="funciones" class="py-6">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="lp-eyebrow">Funciones</span>
            <h2 class="section-title">Todo lo que tu consultorio necesita</h2>
            <p class="text-muted">Una plataforma simple para tu especialidad, tu equipo y tu recepción.</p>
            <?php /* Cinta infinita: la lista va dos veces para que el bucle no tenga
                     salto; la copia se oculta a lectores de pantalla y, sin
                     movimiento, también a la vista (landing-motion.css). */ ?>
            <div class="lp-marquee">
                <div class="lp-marquee-track">
                    <?php foreach ([false, true] as $copia): ?>
                    <?php foreach (['Medicina general','Odontología','Oftalmología y ópticas','Nutrición','Pediatría','Dermatología','Psicología','Clínicas y hospitales'] as $esp): ?>
                    <span class="badge rounded-pill bg-light text-dark border fw-normal px-3 py-2"<?= $copia ? ' aria-hidden="true"' : '' ?>><?= e($esp) ?></span>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="row g-4" data-stagger>
            <?php
            $funcs = [
                ['bi-calendar-check','Agenda que confirma por ti','Se acabaron los huecos por citas olvidadas: programa, confirma y da seguimiento sin perseguir a nadie.','#2563eb'],
                ['bi-folder2-open','Expediente al instante','Protege el historial de cada paciente: alergias, diagnósticos y tratamientos en dos clics, frente al paciente.','#14b8a6'],
                ['bi-capsule','Recetas en segundos','Imprime recetas con tu marca y tus indicaciones, sin escribir lo mismo dos veces.','#6366f1'],
                ['bi-receipt','Cobros bajo control','Registra pagos y comprobantes, y descubre exactamente cuánto entra cada día.','#16a34a'],
                ['bi-bar-chart','Decide con números','Descubre qué servicios te dejan más y cuándo se llena tu agenda. Deja de decidir a ciegas.','#3b82f6'],
                ['bi-person-badge','Tu equipo, con límites claros','Recepción agenda, el médico atiende y tú lo controlas todo. Cada quien ve solo lo que le toca.','#ef4444'],
            ];
            foreach ($funcs as [$icon,$t,$d,$c]): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card lp-feature h-100 border-0">
                    <div class="card-body p-4">
                        <div class="lp-feature-ic" style="background:<?= $c ?>1a;color:<?= $c ?>"><i class="bi <?= $icon ?>"></i></div>
                        <h5 class="fw-semibold mt-3"><?= e($t) ?></h5>
                        <p class="text-muted mb-0"><?= e($d) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Destacado 1: Agenda -->
<section class="py-6 bg-white">
    <div class="container">
        <div class="row align-items-center g-4 g-lg-5">
            <div class="col-lg-6" data-reveal="left">
                <span class="lp-eyebrow">Agenda inteligente</span>
                <h2 class="section-title mb-3">Tu día, organizado y bajo control</h2>
                <p class="text-muted mb-4">Visualiza tus citas, cambia su estado con un clic y reduce las inasistencias con recordatorios. Cada médico ve su propia agenda.</p>
                <ul class="lp-checklist">
                    <li><i class="bi bi-check2-circle"></i> Estados: programada, confirmada, atendida, cancelada</li>
                    <li><i class="bi bi-check2-circle"></i> Filtros por médico, fecha y tipo</li>
                    <li><i class="bi bi-check2-circle"></i> Panel de próximas citas del día</li>
                </ul>
                <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-primary mt-2">Probar gratis <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="col-lg-6" data-reveal="right">
                <div class="lp-photo-wrap">
                    <div class="lp-photo" style="background-image:url('https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=1200&q=70&auto=format&fit=crop')"></div>
                    <div class="card shadow border-0 lp-shot lp-shot-over">
                        <div class="card-body p-4">
                            <div class="d-flex gap-2 mb-3">
                                <span class="badge bg-primary">Todos</span>
                                <span class="badge bg-light text-dark border">Dra. Laura</span>
                                <span class="badge bg-light text-dark border">Dr. Carlos</span>
                            </div>
                            <?php foreach ([['09:00','Confirmada','info'],['10:30','Programada','secondary'],['11:15','Atendida','success']] as [$h,$e,$c]): ?>
                            <div class="d-flex justify-content-between align-items-center border rounded-3 p-2 mb-2">
                                <span><span class="badge bg-light text-dark border me-2"><?= $h ?></span> Paciente</span>
                                <span class="badge bg-<?= $c ?>"><?= $e ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Destacado 2: Expediente + Cobros -->
<section class="py-6">
    <div class="container">
        <div class="row align-items-center g-4 g-lg-5 flex-lg-row-reverse">
            <div class="col-lg-6" data-reveal="right">
                <span class="lp-eyebrow">Expediente y cobros</span>
                <h2 class="section-title mb-3">Historial clínico y facturación, sin papeles</h2>
                <p class="text-muted mb-4">Toda la información del paciente al instante: alergias, antecedentes, consultas, recetas y sus pagos. Imprime recetas y comprobantes con tu marca.</p>
                <ul class="lp-checklist">
                    <li><i class="bi bi-check2-circle"></i> Expediente clínico electrónico completo</li>
                    <li><i class="bi bi-check2-circle"></i> Recetas e indicaciones imprimibles</li>
                    <li><i class="bi bi-check2-circle"></i> Facturación y control de ingresos</li>
                </ul>
                <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-primary mt-2">Probar gratis <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="col-lg-6" data-reveal="left">
                <?php /* Ventana de expediente con los mismos datos de ejemplo de siempre
                         (María García, Penicilina, $48,200): antes eran dos tarjetitas
                         sueltas perdidas en la columna. */ ?>
                <div class="lp-mock lp-exp">
                    <div class="lp-mock-bar"><span></span><span></span><span></span></div>
                    <div class="lp-exp-body">
                        <div class="lp-exp-head">
                            <span class="lp-exp-av">MG</span>
                            <div class="flex-grow-1">
                                <div class="lp-exp-nm">María García</div>
                                <div class="lp-exp-sub">Expediente clínico</div>
                            </div>
                            <span class="lp-exp-alg"><i class="bi bi-exclamation-triangle-fill"></i> Alergia: Penicilina</span>
                        </div>
                        <div class="lp-exp-tabs"><span class="on">Consultas</span><span>Recetas</span><span>Pagos</span></div>
                        <div class="lp-exp-item">
                            <span class="lp-exp-ic"><i class="bi bi-file-medical"></i></span>
                            <div class="flex-grow-1"><div class="fw-semibold">Cefalea tensional</div><div class="lp-exp-sub">12/06 · Dra. Laura</div></div>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </div>
                        <div class="lp-exp-item">
                            <span class="lp-exp-ic"><i class="bi bi-capsule"></i></span>
                            <div class="flex-grow-1"><div class="fw-semibold">Receta con tu marca</div><div class="lp-exp-sub">Lista para imprimir</div></div>
                            <i class="bi bi-printer text-muted"></i>
                        </div>
                        <div class="lp-exp-pay">
                            <div><div class="lp-exp-sub">Cobrado (mes)</div><div class="lp-exp-money">$48,200</div></div>
                            <div class="text-end"><span class="badge bg-success">Pagada</span><div class="lp-exp-sub mt-1">F-2026-0042</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Beneficios (sin/con) -->
<section id="beneficios" class="py-6 bg-white">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="lp-eyebrow">El cambio</span>
            <h2 class="section-title">De los papeles a la nube</h2>
        </div>
        <div class="row g-4 justify-content-center" data-stagger>
            <div class="col-md-6 col-lg-5">
                <div class="card lp-compare lp-compare-bad h-100"><div class="card-body p-4" data-stagger>
                    <h5 class="text-danger mb-3"><i class="bi bi-emoji-frown"></i> Sin <?= e($marca) ?></h5>
                    <?php foreach (['Citas olvidadas que nadie confirmó','Expedientes en papel que se traspapelan','Cobros anotados en libretas sueltas','Todo depende de tu memoria y tu presencia'] as $x): ?>
                    <div class="lp-compare-item"><i class="bi bi-x-circle text-danger"></i> <?= e($x) ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
            <div class="col-md-6 col-lg-5">
                <div class="card lp-compare lp-compare-good h-100"><div class="card-body p-4" data-stagger>
                    <h5 class="text-success mb-3"><i class="bi bi-emoji-smile"></i> Con <?= e($marca) ?></h5>
                    <?php foreach (['Recordatorios que llenan tu agenda','Expediente electrónico en segundos','Ingresos claros, al día y desde el celular','Tu consultorio funciona aunque tú no estés'] as $x): ?>
                    <div class="lp-compare-item"><i class="bi bi-check-circle text-success"></i> <?= e($x) ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
        </div>
    </div>
</section>

<!-- Planes -->
<style>
    /* Tarjetas de plan al estilo de apple.com: fondo gris muy claro, tarjeta
       blanca flotando encima, esquinas grandes y sombra apenas perceptible.
       Lo que da el aire "Apple" no es un efecto, es la RESTA: menos borde,
       menos peso tipográfico y más aire. */
    .lp-planes { background: linear-gradient(180deg, var(--surface) 0%, #fafafc 60%, #fff 100%); }
    .lp-planes .lp-eyebrow { color: var(--brand); }
    .lp-plan { position: relative; background: #fff; border: 1px solid rgba(0,0,0,.04); border-radius: 22px;
               padding: 2.5rem 2rem; height: 100%; display: flex; flex-direction: column;
               box-shadow: 0 2px 8px rgba(0,0,0,.03), 0 12px 32px rgba(0,0,0,.05);
               transition: transform .35s cubic-bezier(.28,.11,.32,1), box-shadow .35s cubic-bezier(.28,.11,.32,1); }
    .lp-plan:hover { transform: translateY(-6px); box-shadow: 0 4px 12px rgba(0,0,0,.04), 0 24px 56px rgba(0,0,0,.09); }
    /* El plan destacado se marca con un filo azul de 1px, no con una sombra de
       color: en iOS la jerarquía se da con contorno, no con dramatismo. */
    .lp-plan.feat { border-color: rgba(37,99,235,.35);
                    box-shadow: 0 4px 14px rgba(37,99,235,.08), 0 24px 56px rgba(37,99,235,.10); }
    .lp-plan-pop { position: absolute; top: -13px; left: 50%; transform: translateX(-50%);
                   background: var(--brand); color: #fff; font-weight: 590; font-size: .74rem;
                   letter-spacing: -.01em; padding: .34rem 1rem; border-radius: 999px;
                   box-shadow: 0 4px 14px rgba(37,99,235,.28); }
    .lp-plan-ic { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center;
                  justify-content: center; font-size: 1.4rem; color: var(--brand); margin-bottom: 1.4rem;
                  background: color-mix(in srgb, var(--brand) 9%, #fff); }
    .lp-plan.feat .lp-plan-ic { color: #fff; background: var(--brand); }
    .lp-plan-name { font-family: var(--font-display); font-weight: 600; color: var(--ink);
                    font-size: 1.35rem; letter-spacing: -.022em; }
    .lp-plan-desc { color: var(--muted); font-size: .95rem; margin-bottom: 1.6rem; letter-spacing: -.01em; }
    /* Precio en Display, Semibold y con tracking muy cerrado: es como Apple
       compone las cifras grandes. El 800 de antes se veía pesado. */
    .lp-plan-price { font-family: var(--font-display); font-weight: 600; color: var(--ink);
                     font-size: 3rem; line-height: 1; letter-spacing: -.045em;
                     font-variant-numeric: tabular-nums; }
    .lp-plan-price .per { font-size: 1.05rem; color: var(--muted); font-weight: 400; letter-spacing: -.01em; }
    .lp-plan-iva { font-size: .82rem; color: var(--muted); margin-top: .35rem; letter-spacing: -.005em; }
    .lp-plan-feats { list-style: none; padding: 0; margin: 1.8rem 0 1.6rem; flex: 1; }
    .lp-plan-feats li { display: flex; align-items: flex-start; gap: .6rem; padding: .42rem 0;
                        color: #424245; font-size: .97rem; line-height: 1.4; letter-spacing: -.01em; }
    .lp-plan-feats .bi { color: var(--brand); margin-top: .18rem; font-size: .9rem; }
    /* Botón tipo iOS: cápsula, Semibold, sin borde grueso. */
    .lp-plan .btn-plan { border-radius: 980px; padding: .82rem; font-weight: 590; font-size: 1rem;
                         letter-spacing: -.01em; width: 100%; border: 1px solid var(--brand);
                         color: var(--brand); background: #fff;
                         transition: background .25s ease, color .25s ease, transform .15s ease; }
    .lp-plan .btn-plan:hover { background: var(--brand); color: #fff; }
    .lp-plan .btn-plan:active { transform: scale(.975); }
    .lp-plan.feat .btn-plan { background: var(--brand); border-color: var(--brand); color: #fff; }
    .lp-plan.feat .btn-plan:hover { background: var(--cta-dark); border-color: var(--cta-dark); }

    /* Encabezado de sección: el titular grande pide tracking negativo fuerte. */
    body.lp .section-title { font-family: var(--font-display); font-weight: 600; color: var(--ink);
                             letter-spacing: -.035em; line-height: 1.06; }
    body.lp .lp-eyebrow { font-weight: 590; letter-spacing: .02em; font-size: .8rem; text-transform: uppercase; }

    /* ── Hero claro ────────────────────────────────────────────────────
       El banner deja de ser una foto oscura con texto encima. Texto en
       negro sobre fondo claro se lee mejor y aguanta cualquier pantalla:
       la foto de stock tapada por un degradado siempre pierde contraste
       en algún punto. El color lo pone una mancha orgánica detrás. */
    .lp-hero { position: relative; overflow: hidden; color: var(--ink);
               background: linear-gradient(160deg, #eef3fe 0%, #f6f9ff 46%, #fff 100%); }
    .lp-hero::before { content: none; }
    .lp-hero-row { min-height: 640px; padding: 4.5rem 0 5rem; align-items: center; }
    .lp-hero-text { text-shadow: none; }
    /* Mancha de color detrás del mock: es lo que da el aire de clínica sin
       pagar un banco de imágenes ni cargar 400 KB de foto. */
    .lp-blob { position: absolute; top: -18%; right: -12%; width: 62%; aspect-ratio: 1;
               background: radial-gradient(circle at 32% 32%,
                           color-mix(in srgb, var(--brand) 26%, transparent),
                           color-mix(in srgb, var(--brand) 6%, transparent) 62%, transparent 74%);
               border-radius: 46% 54% 58% 42% / 52% 44% 56% 48%; pointer-events: none; }
    .lp-hero .lp-pill { background: #fff; border: 1px solid rgba(37,99,235,.18); color: var(--brand);
                        box-shadow: 0 2px 10px rgba(30,45,80,.06); font-weight: 590; }
    .lp-h1 { font-family: var(--font-display); font-weight: 600; font-size: clamp(2.5rem, 5.2vw, 4rem);
             line-height: 1.05; letter-spacing: -.038em; color: var(--ink); }
    .lp-h1-ac { color: var(--brand); }
    .lp-sub { color: var(--muted); font-size: 1.12rem; line-height: 1.5; max-width: 33rem; letter-spacing: -.011em; }
    .lp-btn { border-radius: 980px; padding: .82rem 1.6rem; font-weight: 590; font-size: 1rem;
              letter-spacing: -.01em; border: 1px solid transparent;
              transition: background .25s ease, color .25s ease, transform .15s ease; }
    .lp-btn:active { transform: scale(.975); }
    .lp-btn-fill { background: var(--brand); color: #fff; }
    .lp-btn-fill:hover { background: var(--cta-dark); color: #fff; }
    .lp-btn-ghost { background: #fff; color: var(--brand); border-color: rgba(37,99,235,.28); }
    .lp-btn-ghost:hover { background: var(--brand); color: #fff; border-color: var(--brand); }

    /* Franja de datos: icono en pastilla, cifra en Display, etiqueta debajo. */
    .lp-stat { display: inline-flex; align-items: center; gap: .7rem; margin: 0 2.2rem .6rem 0; vertical-align: top; }
    .lp-stat-ic { width: 42px; height: 42px; flex: 0 0 42px; border-radius: 50%; display: flex;
                  align-items: center; justify-content: center; font-size: 1.05rem;
                  color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, #fff); }
    .lp-stat-n { display: block; font-family: var(--font-display); font-weight: 600; font-size: 1.32rem;
                 line-height: 1.1; letter-spacing: -.03em; color: var(--ink); font-variant-numeric: tabular-nums; }
    .lp-stat-l { display: block; font-size: .82rem; color: var(--muted); letter-spacing: -.005em; }

    /* El mock deja de flotar sobre un fondo oscuro, así que necesita su
       propio contorno para no disolverse en el blanco. */
    .lp-hero .lp-mock .lp-dash { box-shadow: 0 2px 10px rgba(15,30,60,.05), 0 30px 70px rgba(15,30,60,.13); }
    .lp-hero .lp-mock-bar { border: 1px solid rgba(0,0,0,.05); border-bottom: 0; }

    /* ── Tarjetas de sección ───────────────────────────────────────────
       Mismo lenguaje que las tarjetas de plan: blanco, esquina grande,
       sombra en dos capas y el icono en un cuadrado redondeado. */
    .lp-feature { background: #fff; border: 1px solid rgba(0,0,0,.05) !important; border-radius: 20px;
                  box-shadow: 0 1px 4px rgba(0,0,0,.03), 0 10px 28px rgba(0,0,0,.045);
                  transition: transform .35s cubic-bezier(.28,.11,.32,1), box-shadow .35s cubic-bezier(.28,.11,.32,1); }
    .lp-feature:hover { transform: translateY(-6px); box-shadow: 0 4px 12px rgba(0,0,0,.05), 0 22px 48px rgba(0,0,0,.09); }
    .lp-feature .lp-feature-ic { width: 58px; height: 58px; border-radius: 16px; display: flex;
                                 align-items: center; justify-content: center; font-size: 1.5rem; }
    .lp-feature h5 { font-family: var(--font-display); font-weight: 600; letter-spacing: -.022em;
                     color: var(--ink); font-size: 1.12rem; }
    .lp-feature p { font-size: .95rem; line-height: 1.5; letter-spacing: -.008em; }

    @media (max-width: 991.98px) {
        .lp-hero-row { min-height: 0; padding: 3rem 0 3.5rem; text-align: center; }
        .lp-sub { margin-inline: auto; }
        .lp-hero .d-flex.flex-wrap.gap-2 { justify-content: center; }
        .lp-stat { margin-right: 1.4rem; text-align: left; }
        .lp-blob { top: -10%; right: -30%; width: 110%; opacity: .7; }
    }

    /* ── Variantes oscuras ─────────────────────────────────────────────
       style.css ya trae un bloque html.lp-dark para la landing, pero estos
       selectores nuevos viven en el bloque de la landing, que se carga después,
       así que
       sus variantes tienen que estar aquí también o el hero se quedaría en
       blanco sobre fondo negro. */
    html.lp-dark .lp-hero { background: linear-gradient(160deg, #14171d 0%, #0f1116 55%, var(--d-bg) 100%); color: var(--d-ink); }
    html.lp-dark .lp-blob { opacity: .5; }
    html.lp-dark .lp-h1, html.lp-dark .lp-stat-n { color: var(--d-ink); }
    html.lp-dark .lp-h1-ac { color: var(--d-accent2); }
    html.lp-dark .lp-sub, html.lp-dark .lp-stat-l { color: var(--d-muted); }
    html.lp-dark .lp-hero .lp-pill { background: var(--d-panel); border-color: var(--d-border); color: var(--d-accent2); box-shadow: none; }
    html.lp-dark .lp-stat-ic { background: rgba(37,99,235,.16); color: var(--d-accent2); }
    html.lp-dark .lp-btn-ghost { background: transparent; color: var(--d-accent2); border-color: var(--d-border); }
    html.lp-dark .lp-btn-ghost:hover { background: var(--brand); color: #fff; border-color: var(--brand); }
    html.lp-dark .lp-planes { background: var(--d-bg); }
    html.lp-dark .lp-plan { background: var(--d-panel); border-color: var(--d-border);
                            box-shadow: 0 18px 46px rgba(0,0,0,.5); }
    html.lp-dark .lp-plan.feat { border-color: rgba(96,165,250,.45); }
    html.lp-dark .lp-plan-name, html.lp-dark .lp-plan-price { color: var(--d-ink); }
    html.lp-dark .lp-plan-desc, html.lp-dark .lp-plan-price .per { color: var(--d-muted); }
    html.lp-dark .lp-plan-feats li { color: #c5c9d1; }
    html.lp-dark .lp-plan .btn-plan { background: transparent; color: var(--d-accent2); border-color: var(--d-border); }
    html.lp-dark .lp-plan .btn-plan:hover,
    html.lp-dark .lp-plan.feat .btn-plan { background: var(--brand); color: #fff; border-color: var(--brand); }
    html.lp-dark .lp-feature h5 { color: var(--d-ink); }
    html.lp-dark body.lp .section-title { color: var(--d-ink); }

    /* ── Pulido ────────────────────────────────────────────────────────── */
    /* Eyebrow en pastilla: marca el inicio de cada sección sin gritar. */
    body.lp .lp-eyebrow { display: inline-flex; align-items: center; gap: .35rem; color: var(--brand);
                          background: color-mix(in srgb, var(--brand) 8%, #fff); padding: .36rem .85rem;
                          border-radius: 999px; font-size: .74rem; letter-spacing: .12em; margin-bottom: .9rem; }
    body.lp .section-title { font-size: clamp(1.9rem, 3.6vw, 2.75rem); }

    /* Mancha del hero: difuminada, sin el borde duro del círculo. */
    .lp-blob { filter: blur(50px); opacity: .85; }

    /* Cifras del hero: una franja pareja de tres, con divisores finos. Antes
       el 0% caía solo en una segunda fila. */
    .lp-stats { display: grid; grid-template-columns: repeat(3, auto); justify-content: start; gap: 0;
                margin-top: .4rem; }
    .lp-stats .lp-stat { margin: 0; padding: 0 1.6rem; border-left: 1px solid rgba(0,0,0,.08); }
    .lp-stats .lp-stat:first-child { padding-left: 0; border-left: 0; }
    @media (max-width: 991.98px) {
        .lp-stats { justify-content: center; }
    }
    @media (max-width: 575.98px) {
        .lp-stats { grid-template-columns: repeat(3, 1fr); width: 100%; }
        .lp-stats .lp-stat { flex-direction: column; text-align: center; gap: .4rem; padding: 0 .4rem; }
        .lp-stats .lp-stat-l { font-size: .74rem; line-height: 1.25; }
    }

    /* Foto de la sección de agenda: encuadre a la cara, no a la frente. */
    .lp-photo { background-position: center 22% !important; }
    .lp-checklist li i { color: var(--brand); }

    /* Ventana del expediente */
    .lp-exp { max-width: 500px; margin: 0 auto 0 0; }
    @media (max-width: 991.98px) { .lp-exp { margin: 0 auto; } }
    .lp-exp-body { background: #fff; border-radius: 0 0 18px 18px; padding: 1.4rem;
                   box-shadow: 0 2px 10px rgba(15,30,60,.05), 0 30px 70px rgba(15,30,60,.13); }
    .lp-exp-head { display: flex; align-items: center; gap: .85rem; flex-wrap: wrap; }
    .lp-exp-av { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
                 font-weight: 700; color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, #fff); }
    .lp-exp-nm { font-family: var(--font-display); font-weight: 650; font-size: 1.12rem; letter-spacing: -.02em; color: var(--ink); }
    .lp-exp-sub { font-size: .8rem; color: var(--muted); }
    .lp-exp-alg { font-size: .78rem; font-weight: 600; color: #b91c1c; background: #fee2e2; border-radius: 999px; padding: .32rem .75rem; }
    .lp-exp-tabs { display: flex; gap: .4rem; margin: 1.1rem 0 .9rem; }
    .lp-exp-tabs span { font-size: .8rem; font-weight: 600; color: var(--muted); padding: .38rem .8rem; border-radius: 999px; background: var(--surface); }
    .lp-exp-tabs span.on { background: var(--brand); color: #fff; }
    .lp-exp-item { display: flex; align-items: center; gap: .8rem; padding: .8rem .9rem; border: 1px solid rgba(0,0,0,.06);
                   border-radius: 14px; margin-bottom: .55rem; font-size: .92rem; color: var(--ink); }
    .lp-exp-ic { width: 38px; height: 38px; flex: none; border-radius: 11px; display: flex; align-items: center; justify-content: center;
                 color: var(--brand); background: color-mix(in srgb, var(--brand) 9%, #fff); }
    .lp-exp-pay { display: flex; justify-content: space-between; align-items: center; margin-top: .9rem; padding: .95rem 1rem;
                  border-radius: 14px; background: linear-gradient(135deg, #f0fdf4, #ecfdf5); }
    .lp-exp-money { font-family: var(--font-display); font-weight: 700; font-size: 1.5rem; letter-spacing: -.03em; color: #15803d; }

    /* Comparación: tarjetas con más aire y sin el borde de color pesado. */
    .lp-compare { border-radius: 22px !important; border-width: 1px !important; }
    .lp-compare .card-body { padding: 2rem !important; }
    .lp-compare h5 { font-family: var(--font-display); font-weight: 650; letter-spacing: -.02em; }

    /* Preguntas: tarjetas limpias; la abierta se marca con borde, no con un bloque azul. */
    .lp-faq .accordion-item { border: 1px solid rgba(0,0,0,.06) !important; border-radius: 18px !important; margin-bottom: .75rem;
                              box-shadow: 0 1px 4px rgba(0,0,0,.02), 0 8px 24px rgba(15,30,60,.04);
                              transition: border-color .25s ease, box-shadow .25s ease; }
    .lp-faq .accordion-item:has(.accordion-button:not(.collapsed)) { border-color: color-mix(in srgb, var(--brand) 35%, transparent) !important;
                              box-shadow: 0 2px 8px rgba(0,0,0,.03), 0 16px 40px rgba(37,99,235,.08); }
    .lp-faq .accordion-button { padding: 1.15rem 1.35rem; font-weight: 600; letter-spacing: -.012em; color: var(--ink); background: #fff; }
    .lp-faq .accordion-button:not(.collapsed) { background: #fff; color: var(--brand); box-shadow: none; }
    .lp-faq .accordion-body { padding: 0 1.35rem 1.25rem; line-height: 1.6; }

    /* CTA final: panel redondeado con degradado y círculos de luz. */
    .lp-cta-sec { padding: 1rem 0 5rem; }
    .lp-cta2 { position: relative; overflow: hidden; border-radius: 32px; color: #fff; padding: 4.5rem 1.5rem;
               background: linear-gradient(125deg, var(--brand-dark) 0%, var(--brand) 55%, #38bdf8 135%);
               box-shadow: 0 30px 80px rgba(37,99,235,.28); }
    .lp-cta2::before, .lp-cta2::after { content: ''; position: absolute; border-radius: 50%; background: rgba(255,255,255,.10); pointer-events: none; }
    .lp-cta2::before { width: 440px; height: 440px; right: -140px; top: -200px; }
    .lp-cta2::after  { width: 320px; height: 320px; left: -120px; bottom: -170px; }
    .lp-cta2 > * { position: relative; z-index: 1; }
    .lp-cta2-t { font-family: var(--font-display); font-weight: 700; letter-spacing: -.04em; line-height: 1.05;
                 font-size: clamp(2rem, 4.6vw, 3.2rem); }
    .lp-cta2-p { font-size: 1.12rem; color: rgba(255,255,255,.9); max-width: 46ch; margin-inline: auto; line-height: 1.55; }
    .lp-cta2 .btn-light { border-radius: 980px; padding: .95rem 2.2rem; box-shadow: 0 14px 34px rgba(0,0,0,.18); }
    @media (max-width: 575.98px) { .lp-cta2 { border-radius: 24px; padding: 3.2rem 1.2rem; } }
</style>

<section id="planes" class="lp-planes py-6">
    <div class="container">
        <div class="text-center mb-4" data-reveal>
            <span class="lp-eyebrow">Planes</span>
            <h2 class="section-title">Elige cómo quieres crecer</h2>
            <p class="text-muted">Tres planes, precios claros. El <strong>Profesional</strong> lo pruebas
               <strong><?= trial_dias_oferta() ?> días gratis, con acceso completo y sin tarjeta</strong>.
               Todos los precios <strong>ya incluyen IVA</strong>.</p>
        </div>
        <div class="row g-4 justify-content-center align-items-stretch" data-stagger>
            <?php
            // Solo los 3 planes de la tabla `planes`: al cerebro le cuesta decidir
            // con más (o menos) de tres opciones; la prueba gratis va en el texto.
            $planes = [];
            foreach (planes_mp() as $planKey => $pl) {
                if ($planKey === 'clinica' && ia_configurada()) {
                    $pl['items'][] = 'IA que redacta la consulta por ti';
                }
                $planes[] = [$pl['nombre'], '$' . number_format($pl['precio'], 0), $pl['descripcion'], $pl['items'], $pl['destacado'], $planKey];
            }
            $iconos = ['bi-heart-pulse', 'bi-star-fill', 'bi-hospital', 'bi-building'];
            foreach ($planes as $ix => [$nombre,$precio,$desc,$items,$feat,$planKey]):
                $href = BASE_URL . '/auth/registro?plan=' . $planKey; ?>
            <div class="col-md-6 col-lg-4">
                <div class="lp-plan <?= $feat ? 'feat' : '' ?>">
                    <?php if ($feat): ?><span class="lp-plan-pop">El que más eligen</span><?php endif; ?>
                    <div class="lp-plan-ic"><i class="bi <?= $iconos[$ix] ?? 'bi-check-circle' ?>"></i></div>
                    <div class="lp-plan-name"><?= e($nombre) ?></div>
                    <div class="lp-plan-desc"><?= e($desc) ?></div>
                    <div class="lp-plan-price"><?= e($precio) ?><span class="per">/mes</span></div>
                    <div class="lp-plan-iva">IVA incluido · sin costo de instalación</div>
                    <ul class="lp-plan-feats">
                        <?php foreach ($items as $it): ?>
                            <li><i class="bi bi-check-circle-fill"></i> <span><?= e($it) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php /* Insignia y rótulo salen de la misma regla: solo Profesional lleva
                             prueba, los otros dos van a contratar. Antes los tres botones
                             decían "Probarlo 15 días gratis" y dos mandaban a pagar. */ ?>
                    <?php if (plan_con_prueba($planKey)): ?>
                        <div class="small text-center text-muted mb-2">
                            <i class="bi bi-gift"></i> <?= trial_dias_oferta() ?> días gratis · sin tarjeta
                        </div>
                    <?php endif; ?>
                    <a href="<?= e($href) ?>" class="btn btn-plan"><?= e(plan_cta($planKey)) ?></a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Quita-miedos: lo que frena la decisión, respondido antes de que pregunten -->
        <div class="row g-3 text-center mt-4" data-stagger>
            <?php foreach ([
                ['bi-shield-check','Sin contratos forzosos'],
                ['bi-x-circle','Cancelas cuando quieras'],
                ['bi-database-lock','Tus datos siempre son tuyos'],
                ['bi-headset','Te acompañamos en español'],
            ] as [$ic,$t]): ?>
            <div class="col-6 col-lg-3">
                <div class="small text-muted"><i class="bi <?= $ic ?> text-brand"></i> <?= e($t) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- FAQ -->
<section id="faq" class="py-6">
    <div class="container" style="max-width:760px">
        <div class="text-center mb-5" data-reveal>
            <span class="lp-eyebrow">Preguntas</span>
            <h2 class="section-title">Preguntas frecuentes</h2>
        </div>
        <div class="accordion lp-faq" id="faqAcc" data-reveal>
            <?php foreach ([
                ['¿La prueba de 15 días tiene acceso completo?','Sí. Durante los 15 días usas todas las funciones sin límites y sin tarjeta. Al terminar, eliges un plan para continuar.'],
                ['¿Y si no soy bueno con la tecnología?','Si sabes usar WhatsApp, sabes usar ' . $marca . '. Está hecho para médicos y recepcionistas, no para ingenieros: pantallas simples, en español y sin manuales. Si tienes problemas o dudas de cómo usarlo, nosotros te acompañamos.'],
                ['¿Se necesita la instalación de algún software adicional?','No. Es 100% en la nube: entras desde el navegador en computadora, tablet o celular.'],
                ['¿Mis datos están seguros?','Tus datos viajan cifrados, con respaldos y acceso por roles para tu equipo. Cada consultorio tiene su información aislada.'],
                ['¿Qué pasa con mi información si cancelo?','Tus pacientes y expedientes son tuyos, no nuestros. Cancelas desde el panel, conservas el acceso hasta el final del periodo pagado y te ayudamos a llevarte tu información.'],
                ['¿Sirve para mi especialidad?','Sí. Lo usan médicos generales, dentistas, oftalmólogos y ópticas, nutriólogos, psicólogos y clínicas u hospitales con varias sucursales. Las plantillas de consulta se adaptan a tu especialidad.'],
            ] as $i => [$q,$a]): ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $i ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"><?= e($q) ?></button>
                </h2>
                <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i ? '' : 'show' ?>" data-bs-parent="#faqAcc">
                    <div class="accordion-body text-muted"><?= e($a) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA final -->
<?php /* Panel redondeado en vez de la foto con velo azul: la foto se veía
         borrosa detrás del degradado y no aportaba nada. */ ?>
<section class="lp-cta-sec">
    <div class="container">
    <div class="lp-cta2 text-center" data-reveal="zoom">
        <h2 class="lp-cta2-t mb-3">Lleva tu consultorio al siguiente nivel</h2>
        <p class="lp-cta2-p mb-4">Cada semana que pasa son citas olvidadas y expedientes en papel. 15 días gratis con acceso completo: sin tarjeta, sin compromiso.</p>
        <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-light btn-lg px-5 text-brand fw-semibold"><i class="bi bi-rocket-takeoff"></i> Prueba <?= e($marca) ?> hoy</a>
        <div class="d-flex justify-content-center flex-wrap gap-4 mt-4 small opacity-75">
            <span><i class="bi bi-check-circle-fill"></i> Configúralo en minutos</span>
            <span><i class="bi bi-check-circle-fill"></i> Soporte en español</span>
            <span><i class="bi bi-check-circle-fill"></i> Cancela cuando quieras</span>
        </div>
    </div>
    </div>
</section>

<!-- Footer -->
<footer class="landing-footer py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="h5 mb-2"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?></div>
                <p class="small mb-0" style="max-width:280px">Software de gestión para consultorios, clínicas y hospitales de cualquier especialidad: agenda, expediente, recetas y facturación.</p>
            </div>
            <div class="col-6 col-lg-2">
                <div class="fw-semibold mb-2">Producto</div>
                <a class="lp-foot-link" href="#funciones">Funciones</a>
                <a class="lp-foot-link" href="#planes">Planes</a>
                <a class="lp-foot-link" href="#faq">Preguntas</a>
            </div>
            <div class="col-6 col-lg-2">
                <div class="fw-semibold mb-2">Cuenta</div>
                <a class="lp-foot-link" href="<?= BASE_URL ?>/auth/registro">Prueba gratis</a>
                <a class="lp-foot-link" href="<?= BASE_URL ?>/auth/login">Iniciar sesión</a>
                <a class="lp-foot-link" href="<?= BASE_URL ?>/privacidad">Aviso de privacidad</a>
            </div>
            <div class="col-lg-4">
                <div class="fw-semibold mb-2">¿Listo para empezar?</div>
                <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-primary"><i class="bi bi-rocket-takeoff"></i> Crear cuenta gratis</a>
            </div>
        </div>
        <hr class="border-secondary my-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center small">
            <span>&copy; <?= date('Y') ?> <?= e($marca) ?> · Todos los derechos reservados</span>
            <span>Hecho para consultorios de México 🇲🇽</span>
        </div>
    </div>
</footer>

<!-- WhatsApp flotante -->
<?php $waLanding = 'https://wa.me/' . SOPORTE_WHATSAPP . '?text=' . rawurlencode('Hola, quiero ver cómo funcionaría ' . $marca . ' en mi consultorio. ¿Me ayudas?'); ?>
<a href="<?= e($waLanding) ?>" class="lp-wa" target="_blank" rel="noopener"
   aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp">
    <i class="bi bi-whatsapp"></i>
</a>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script src="<?= asset('assets/js/landing-motion.js') ?>"></script>
</body>
</html>
