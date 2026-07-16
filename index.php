<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mercadopago.php';

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
$marca  = marca_nombre();
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
    <meta name="description" content="Toma el control de tu consultorio: agenda que confirma citas, expediente clínico protegido y cobros claros. Prueba 15 días gratis, sin tarjeta.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://images.unsplash.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Mulish:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
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

<!-- Hero (banner con foto de fondo) -->
<header class="lp-hero" style="background-image:url('https://images.unsplash.com/photo-1594824476967-48c8b964273f?w=1600&q=80&auto=format&fit=crop')">
    <div class="container">
        <div class="row align-items-center g-4 lp-hero-row">
            <div class="col-lg-6 lp-hero-text">
                <span class="lp-pill mb-3"><i class="bi bi-patch-check-fill"></i> Para consultorios, clínicas y hospitales</span>
                <h1 class="display-4 fw-bold mb-3">Toma el control total de tu consultorio</h1>
                <p class="lead mb-4">Protege el expediente de cada paciente, deja que la agenda confirme las citas por ti y cobra sin perseguir pagos. Tú atiendes; <?= e($marca) ?> se encarga del resto.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-light btn-lg px-4 text-brand fw-semibold"><i class="bi bi-rocket-takeoff"></i> Prueba gratis 15 días</a>
                    <a href="#funciones" class="btn btn-outline-light btn-lg px-4">Ver cómo funciona</a>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-3 mt-4">
                    <div class="lp-stars"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
                    <span class="small">Pensado para consultorios de México 🇲🇽</span>
                </div>
                <div class="d-flex flex-wrap gap-4 mt-3 small opacity-75">
                    <span><i class="bi bi-check-circle-fill"></i> Sin tarjeta</span>
                    <span><i class="bi bi-check-circle-fill"></i> Acceso completo</span>
                    <span><i class="bi bi-check-circle-fill"></i> Cancela cuando quieras</span>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="lp-mock">
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
                                    <div class="lp-tile-n" style="color:<?= $c ?>"><?= $n ?></div>
                                    <div class="lp-tile-l"><?= $l ?></div>
                                </div></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="small fw-semibold text-muted mb-2">Agenda de hoy</div>
                            <?php foreach ([['09:00','Martínez García','Confirmada','info'],['10:30','López Pérez','Programada','secondary'],['11:15','Rodríguez Cruz','Atendida','success']] as [$h,$p,$e,$col]): ?>
                            <div class="lp-ag d-flex justify-content-between align-items-center">
                                <span><span class="lp-ag-h"><?= $h ?></span> <?= $p ?></span>
                                <span class="badge bg-<?= $col ?>"><?= $e ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <!-- Tarjeta flotante: pago en línea confirmado -->
                    <div class="lp-float">
                        <div class="lp-float-ic"><i class="bi bi-check-lg"></i></div>
                        <div>
                            <div class="lp-float-t">Pago recibido</div>
                            <div class="lp-float-s">$1,200 · Mercado Pago</div>
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
        <div class="row g-4 text-center">
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
        <div class="text-center mb-5">
            <span class="lp-eyebrow">Funciones</span>
            <h2 class="section-title">Todo lo que tu consultorio necesita</h2>
            <p class="text-muted">Una plataforma simple para tu especialidad, tu equipo y tu recepción.</p>
            <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                <?php foreach (['Medicina general','Odontología','Oftalmología y ópticas','Nutrición','Pediatría','Dermatología','Psicología','Clínicas y hospitales'] as $esp): ?>
                <span class="badge rounded-pill bg-light text-dark border fw-normal px-3 py-2"><?= e($esp) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="row g-4">
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
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
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
            <div class="col-lg-6">
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
        <div class="row align-items-center g-5 flex-lg-row-reverse">
            <div class="col-lg-6">
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
            <div class="col-lg-6">
                <div class="row g-3 lp-shot">
                    <div class="col-7">
                        <div class="card shadow-sm border-0 h-100"><div class="card-body p-3">
                            <div class="small text-muted">Paciente</div>
                            <div class="fw-semibold mb-2">María García</div>
                            <span class="badge bg-danger-subtle text-danger">Alergia: Penicilina</span>
                            <hr>
                            <div class="small"><i class="bi bi-file-medical text-brand"></i> Cefalea tensional</div>
                            <div class="small text-muted">12/06 · Dra. Laura</div>
                        </div></div>
                    </div>
                    <div class="col-5">
                        <div class="card shadow-sm border-0 h-100"><div class="card-body p-3 text-center">
                            <div class="small text-muted">Cobrado (mes)</div>
                            <div class="h4 text-success mb-0">$48,200</div>
                            <hr>
                            <span class="badge bg-success">Pagada</span>
                            <div class="small text-muted mt-1">F-2026-0042</div>
                        </div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Beneficios (sin/con) -->
<section id="beneficios" class="py-6 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <span class="lp-eyebrow">El cambio</span>
            <h2 class="section-title">De los papeles a la nube</h2>
        </div>
        <div class="row g-4 justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card lp-compare lp-compare-bad h-100"><div class="card-body p-4">
                    <h5 class="text-danger mb-3"><i class="bi bi-emoji-frown"></i> Sin <?= e($marca) ?></h5>
                    <?php foreach (['Citas olvidadas que nadie confirmó','Expedientes en papel que se traspapelan','Cobros anotados en libretas sueltas','Todo depende de tu memoria y tu presencia'] as $x): ?>
                    <div class="lp-compare-item"><i class="bi bi-x-circle text-danger"></i> <?= e($x) ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
            <div class="col-md-6 col-lg-5">
                <div class="card lp-compare lp-compare-good h-100"><div class="card-body p-4">
                    <h5 class="text-success mb-3"><i class="bi bi-emoji-smile"></i> Con <?= e($marca) ?></h5>
                    <?php foreach (['Recordatorios que llenan tu agenda','Expediente electrónico en segundos','Ingresos claros, al día y desde el celular','Tu consultorio funciona aunque tú no estés'] as $x): ?>
                    <div class="lp-compare-item"><i class="bi bi-check-circle text-success"></i> <?= e($x) ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
        </div>
    </div>
</section>

<!-- Testimonios -->
<section class="py-6">
    <div class="container">
        <div class="text-center mb-5">
            <span class="lp-eyebrow">Testimonios</span>
            <h2 class="section-title">Consultorios que ya se digitalizaron</h2>
        </div>
        <div class="row g-4">
            <?php foreach ([
                ['Perdía dos o tres citas por semana porque nadie confirmaba. Hoy la recepción confirma todo y casi no me quedan huecos.','Dra. Laura M.','Medicina General'],
                ['Antes tardaba minutos buscando expedientes en carpetas. Ahora abro el historial en dos clics, con el paciente enfrente.','Dr. Carlos R.','Odontología'],
                ['Llevaba los cobros en una libreta y nunca sabía cuánto ganaba al mes. Ahora lo veo al día, sin enredos.','C.D. Ana T.','Clínica dental'],
            ] as [$q,$n,$r]): ?>
            <div class="col-md-4">
                <div class="card lp-quote h-100 border-0"><div class="card-body p-4">
                    <div class="text-warning mb-2"><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i></div>
                    <p class="mb-3">“<?= e($q) ?>”</p>
                    <div class="d-flex align-items-center gap-2">
                        <span class="lp-avatar"><?= e(mb_substr($n, 0, 1)) ?></span>
                        <div><div class="fw-semibold small"><?= e($n) ?></div><div class="small text-muted"><?= e($r) ?></div></div>
                    </div>
                </div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Planes -->
<style>
    .lp-planes { background: linear-gradient(180deg, #eaeefb 0%, #f3f6fd 55%, #fff 100%); }
    .lp-planes .lp-eyebrow { color: var(--brand); }
    .lp-plan { position: relative; background: #fff; border: 1px solid #e9edf7; border-radius: 26px;
               padding: 2.4rem 1.9rem; height: 100%; display: flex; flex-direction: column;
               box-shadow: 0 12px 40px rgba(30,45,80,.07); transition: transform .2s ease, box-shadow .2s ease; }
    .lp-plan:hover { transform: translateY(-8px); box-shadow: 0 26px 64px rgba(30,45,80,.14); }
    .lp-plan.feat { border: 0; box-shadow: 0 28px 70px rgba(37,99,235,.22);
                    background: linear-gradient(180deg, #fff, #fbfdfd); }
    .lp-plan.feat::before { content: ''; position: absolute; inset: 0; border-radius: 26px; padding: 2px;
                            background: linear-gradient(135deg, var(--brand), var(--cta));
                            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
                            -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
    .lp-plan-pop { position: absolute; top: -14px; left: 50%; transform: translateX(-50%);
                   background: var(--cta); color: #fff; font-weight: 700; font-size: .72rem; letter-spacing: .06em;
                   text-transform: uppercase; padding: .35rem 1rem; border-radius: 999px;
                   box-shadow: 0 8px 20px rgba(37,99,235,.35); }
    .lp-plan-ic { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center;
                  justify-content: center; font-size: 1.5rem; color: var(--brand); margin-bottom: 1.1rem;
                  background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 16%, #fff), color-mix(in srgb, var(--brand) 6%, #fff)); }
    .lp-plan.feat .lp-plan-ic { color: #fff; background: linear-gradient(135deg, var(--brand-dark), var(--brand)); }
    .lp-plan-name { font-weight: 800; color: #1f2d3d; font-size: 1.15rem; }
    .lp-plan-desc { color: #8a94a6; font-size: .9rem; margin-bottom: 1rem; }
    .lp-plan-price { font-family: 'Mulish', sans-serif; font-weight: 800; color: #1f2d3d; font-size: 2.6rem; line-height: 1; }
    .lp-plan-price .per { font-size: 1rem; color: #8a94a6; font-weight: 500; }
    .lp-plan-feats { list-style: none; padding: 0; margin: 1.4rem 0 1.6rem; flex: 1; }
    .lp-plan-feats li { display: flex; align-items: flex-start; gap: .55rem; padding: .38rem 0; color: #48566a; font-size: .94rem; }
    .lp-plan-feats .bi { color: var(--brand); margin-top: .15rem; }
    .lp-plan .btn-plan { border-radius: 999px; padding: .8rem; font-weight: 700; width: 100%; border: 1.5px solid var(--brand); color: var(--brand); background: #fff; }
    .lp-plan .btn-plan:hover { background: var(--brand); color: #fff; }
    .lp-plan.feat .btn-plan { background: var(--cta); border-color: var(--cta); color: #fff; }
    .lp-plan.feat .btn-plan:hover { background: var(--cta-dark); border-color: var(--cta-dark); }
</style>

<section id="planes" class="lp-planes py-6">
    <div class="container">
        <div class="text-center mb-4">
            <span class="lp-eyebrow">Planes</span>
            <h2 class="section-title">Elige cómo quieres crecer</h2>
            <p class="text-muted">Tres planes, precios claros. Los tres empiezan igual: <strong>15 días gratis con acceso completo, sin tarjeta</strong>.</p>
        </div>
        <div class="row g-4 justify-content-center align-items-stretch">
            <?php
            // Solo los 3 planes de la tabla `planes`: al cerebro le cuesta decidir
            // con más (o menos) de tres opciones; la prueba gratis va en el texto.
            $planes = [];
            foreach (planes_mp() as $planKey => $pl) {
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
                    <ul class="lp-plan-feats">
                        <?php foreach ($items as $it): ?>
                            <li><i class="bi bi-check-circle-fill"></i> <span><?= e($it) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= e($href) ?>" class="btn btn-plan">Probarlo 15 días gratis</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Quita-miedos: lo que frena la decisión, respondido antes de que pregunten -->
        <div class="row g-3 text-center mt-4">
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
        <div class="text-center mb-5">
            <h2 class="section-title">Preguntas frecuentes</h2>
        </div>
        <div class="accordion lp-faq" id="faqAcc">
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
<section class="lp-cta" style="background-image:url('https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=1600&q=75&auto=format&fit=crop')">
    <div class="container text-center position-relative">
        <h2 class="display-6 fw-bold mb-3">Tu consultorio puede funcionar así desde mañana</h2>
        <p class="lead mb-4">Cada semana que pasa son citas olvidadas y expedientes en papel. 15 días gratis con acceso completo: sin tarjeta, sin compromiso.</p>
        <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-light btn-lg px-5 text-brand fw-semibold"><i class="bi bi-rocket-takeoff"></i> Tomar el control de mi consultorio</a>
        <div class="d-flex justify-content-center flex-wrap gap-4 mt-4 small opacity-75">
            <span><i class="bi bi-check-circle-fill"></i> Configúralo en minutos</span>
            <span><i class="bi bi-check-circle-fill"></i> Soporte en español</span>
            <span><i class="bi bi-check-circle-fill"></i> Cancela cuando quieras</span>
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
</body>
</html>
