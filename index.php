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

/* La landing está diseñada en claro: sin elección previa arranca en claro,
   aunque el panel tenga el oscuro por defecto. Comparte la cookie `tema` con
   el panel, así la preferencia viaja con el usuario. */
$tema    = in_array($_COOKIE['tema'] ?? '', ['dark', 'light', 'auto'], true) ? $_COOKIE['tema'] : 'light';
$temaCss = $tema === 'dark' ? 'lp-dark' : '';
?>
<!doctype html>
<html lang="es" class="<?= $temaCss ?>"<?= $tema === 'dark' ? ' data-bs-theme="dark"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
    /* Resuelve el tema antes de pintar, para que 'auto' no provoque parpadeo. */
    (function () {
        var pref = <?= json_encode($tema) ?>;
        var oscuro = pref === 'auto'
            ? matchMedia('(prefers-color-scheme: dark)').matches
            : pref === 'dark';
        var el = document.documentElement;
        el.classList.toggle('lp-dark', oscuro);
        if (oscuro) { el.setAttribute('data-bs-theme', 'dark'); }
        else { el.removeAttribute('data-bs-theme'); }
    })();
    </script>
    <title><?= e($marca) ?> · Software para consultorios médicos y dentales</title>
    <meta name="description" content="Agenda de citas, expediente clínico electrónico, recetas y facturación para consultorios médicos y dentales. Prueba 15 días gratis.">
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
                <li class="nav-item">
                    <button type="button" id="lpTema" class="lp-tema" title="Cambiar entre modo claro y oscuro"
                            aria-label="Cambiar entre modo claro y oscuro">
                        <i class="bi bi-sun-fill lp-tema-sol"></i>
                        <i class="bi bi-moon-stars-fill lp-tema-luna"></i>
                    </button>
                </li>
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
<header class="lp-hero" style="background-image:url('https://images.unsplash.com/photo-1612349317150-e413f6a5b16d?w=1600&q=80&auto=format&fit=crop')">
    <div class="container">
        <div class="row align-items-center g-4 lp-hero-row">
            <div class="col-lg-6 lp-hero-text">
                <span class="lp-pill mb-3"><i class="bi bi-patch-check-fill"></i> Software médico y dental</span>
                <h1 class="display-4 fw-bold mb-3">La forma simple de gestionar tu consultorio</h1>
                <p class="lead mb-4">Agenda, expediente clínico, recetas, presupuestos y cobros en un solo lugar. Empieza en minutos, desde cualquier dispositivo.</p>
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
                                <?php foreach ([['Citas hoy','14','#1f6b73'],['Pacientes','231','#14b8a6'],['Ingresos','$48k','#16a34a']] as [$l,$n,$c]): ?>
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
            <p class="text-muted">Una plataforma simple para médicos, dentistas y recepción.</p>
        </div>
        <div class="row g-4">
            <?php
            $funcs = [
                ['bi-calendar-check','Agenda de citas','Programa, confirma y da seguimiento. Filtra por médico, fecha y estado.','#1f6b73'],
                ['bi-folder2-open','Expediente clínico','Consultas, diagnósticos, tratamientos y signos vitales por paciente.','#14b8a6'],
                ['bi-capsule','Recetas','Genera e imprime recetas con tus medicamentos e indicaciones.','#6366f1'],
                ['bi-receipt','Facturación','Cobros, comprobantes y control de ingresos del consultorio.','#16a34a'],
                ['bi-bar-chart','Reportes','Citas, ingresos y métricas clave para tomar decisiones.','#3f9aa3'],
                ['bi-person-badge','Acceso por roles','Permisos para administrador, médicos/dentistas y recepción.','#ef4444'],
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
                    <div class="lp-photo" style="background-image:url('https://images.unsplash.com/photo-1622253692010-333f2da6031d?w=1200&q=70&auto=format&fit=crop')"></div>
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
                    <?php foreach (['Citas en libreta y llamadas dispersas','Expedientes físicos difíciles de consultar','Pacientes que olvidan sus citas','Sin respaldo ni control de acceso'] as $x): ?>
                    <div class="lp-compare-item"><i class="bi bi-x-circle text-danger"></i> <?= e($x) ?></div>
                    <?php endforeach; ?>
                </div></div>
            </div>
            <div class="col-md-6 col-lg-5">
                <div class="card lp-compare lp-compare-good h-100"><div class="card-body p-4">
                    <h5 class="text-success mb-3"><i class="bi bi-emoji-smile"></i> Con <?= e($marca) ?></h5>
                    <?php foreach (['Agenda centralizada con estados de cita','Expediente electrónico al instante','Recordatorios de próximas citas','Acceso seguro por roles para tu equipo'] as $x): ?>
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
                ['Reduje las inasistencias y por fin tengo el expediente de cada paciente a la mano.','Dra. Laura M.','Medicina General'],
                ['La agenda y la facturación en un solo lugar me ahorran horas cada semana.','Dr. Carlos R.','Odontología'],
                ['Mi recepción agenda y confirma citas sin enredos. Fácil de usar desde el primer día.','C.D. Ana T.','Clínica dental'],
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
<section id="planes" class="py-6 bg-white">
    <div class="container">
        <div class="text-center mb-5">
            <span class="lp-eyebrow">Planes</span>
            <h2 class="section-title">Precios claros para cada consultorio</h2>
            <p class="text-muted">Empieza gratis. Sin contratos ni costos ocultos.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <?php
            // Tarjeta de prueba + los planes definidos en la tabla `planes`.
            $planes = [
                ['Prueba gratis','$0','15 días con acceso completo', ['Todas las funciones','Pacientes y citas','Expediente clínico','Sin tarjeta'], false, ''],
            ];
            foreach (planes_mp() as $planKey => $pl) {
                $planes[] = [$pl['nombre'], '$' . number_format($pl['precio'], 0), $pl['descripcion'], $pl['items'], $pl['destacado'], $planKey];
            }
            foreach ($planes as [$nombre,$precio,$desc,$items,$feat,$planKey]):
                $href = BASE_URL . '/auth/registro' . ($planKey ? '?plan=' . $planKey : '');
                $btn  = $planKey ? 'Contratar ahora' : 'Probar 15 días gratis'; ?>
            <div class="col-md-6 col-lg-4">
                <div class="card price-card h-100 shadow-sm <?= $feat ? 'featured' : '' ?>">
                    <div class="card-body p-4 text-center">
                        <?php if ($feat): ?><span class="badge bg-primary mb-2">Más popular</span><?php endif; ?>
                        <h5 class="fw-bold"><?= e($nombre) ?></h5>
                        <div class="display-6 fw-bold text-brand"><?= e($precio) ?><span class="fs-6 text-muted fw-normal">/mes</span></div>
                        <p class="text-muted small"><?= e($desc) ?></p>
                        <ul class="list-unstyled text-start my-4">
                            <?php foreach ($items as $it): ?>
                                <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i><?= e($it) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?= e($href) ?>" class="btn <?= $feat ? 'btn-primary' : 'btn-outline-primary' ?> w-100"><?= $btn ?></a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- FAQ -->
<section id="faq" class="py-6">
    <div class="container" style="max-width:760px">
        <div class="text-center mb-5">
            <span class="lp-eyebrow">Preguntas frecuentes</span>
            <h2 class="section-title">¿Te quedó alguna duda?</h2>
        </div>
        <div class="accordion lp-faq" id="faqAcc">
            <?php foreach ([
                ['¿La prueba de 15 días tiene acceso completo?','Sí. Durante los 15 días usas todas las funciones sin límites y sin tarjeta. Al terminar, eliges un plan para continuar.'],
                ['¿Necesito instalar algo?','No. Es 100% en la nube: entras desde el navegador en computadora, tablet o celular.'],
                ['¿Mis datos están seguros?','Tus datos viajan cifrados, con respaldos y acceso por roles para tu equipo. Cada consultorio tiene su información aislada.'],
                ['¿Puedo cancelar cuando quiera?','Sí. Cancelas tu suscripción desde el panel y conservas el acceso hasta el final del periodo ya pagado.'],
                ['¿Sirve para consultorios dentales?','Sí, está pensado para consultorios médicos y dentales por igual.'],
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
<section class="lp-cta" style="background-image:url('https://images.unsplash.com/photo-1666214280557-f1b5022eb634?w=1600&q=75&auto=format&fit=crop')">
    <div class="container text-center position-relative">
        <h2 class="display-6 fw-bold mb-3">Empieza a digitalizar tu consultorio hoy</h2>
        <p class="lead mb-4">15 días gratis con acceso completo. Sin tarjeta, sin compromiso.</p>
        <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-light btn-lg px-5 text-brand fw-semibold"><i class="bi bi-rocket-takeoff"></i> Crear mi cuenta gratis</a>
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
                <p class="small mb-0" style="max-width:280px">Software de gestión para consultorios médicos y dentales: agenda, expediente, recetas y facturación.</p>
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
<?php $waLanding = 'https://wa.me/' . SOPORTE_WHATSAPP . '?text=' . rawurlencode('Hola, me interesa ' . $marca . ' para mi consultorio.'); ?>
<a href="<?= e($waLanding) ?>" class="lp-wa" target="_blank" rel="noopener"
   aria-label="Escríbenos por WhatsApp" title="Escríbenos por WhatsApp">
    <i class="bi bi-whatsapp"></i>
</a>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Alterna claro/oscuro y guarda la preferencia en la misma cookie que el panel. */
(function () {
    var boton = document.getElementById('lpTema');
    if (!boton) return;
    boton.addEventListener('click', function () {
        var el = document.documentElement;
        var oscuro = !el.classList.contains('lp-dark');
        el.classList.toggle('lp-dark', oscuro);
        if (oscuro) { el.setAttribute('data-bs-theme', 'dark'); }
        else { el.removeAttribute('data-bs-theme'); }
        document.cookie = 'tema=' + (oscuro ? 'dark' : 'light') +
                          ';path=<?= BASE_URL ?>/;max-age=31536000;samesite=Lax';
    });
})();
</script>
</body>
</html>
