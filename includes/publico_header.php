<?php
/**
 * Cáscara de las páginas PÚBLICAS que ve el paciente (agendar cita, confirmar).
 *
 * Reutiliza el look de la landing (clases `lp`, tema claro/oscuro) pero con la
 * marca del CONSULTORIO, no la de MediOS Agenda: el paciente está entrando al sitio
 * de su médico, no al de un proveedor de software que no conoce. El white-label
 * es justo lo que se vende.
 *
 * Definir antes de incluir:
 *   $titulo (string)          -> título de la pestaña
 *   $volver (string|null)     -> URL opcional del botón "volver"
 */
require_once __DIR__ . '/functions.php';

$titulo = $titulo ?? marca_nombre();
$marca  = marca_nombre();
$acento = color_acento();

/* Las páginas públicas del paciente (micrositio, agendar, confirmar) van SIEMPRE
   en claro: son la cara de marketing del consultorio y el modo oscuro se ve mal.
   No se lee la cookie ni se ofrece el interruptor. */
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>"><head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · <?= e($marca) ?></title>
    <?php /* El micrositio del consultorio SÍ debe salir en Google (es su cara);
             las páginas de agendar/confirmar no. El micrositio pone $indexable. */ ?>
    <meta name="robots" content="<?= (isset($indexable) && $indexable) ? 'index,follow' : 'noindex' ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Mulish:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        /* El acento es el del consultorio (white-label). */
        :root { --brand: <?= $acento ?>; --brand-dark: color-mix(in srgb, <?= $acento ?> 78%, #000); }
        .pub-wrap { max-width: 640px; margin: 0 auto; padding: 2.5rem 1rem 3rem; }
        .pub-card { border: 0; border-radius: 18px; box-shadow: 0 10px 34px rgba(15,39,71,.10); }
        html.lp-dark .pub-card { box-shadow: 0 10px 34px rgba(0,0,0,.35); }
        .pub-dato { background: color-mix(in srgb, var(--brand) 7%, transparent);
                    border-radius: 14px; padding: 1rem 1.25rem; }
        /* Huecos de horario: botones grandes, pensados para el pulgar. */
        .hueco input { position: absolute; opacity: 0; }
        .hueco span { display: block; text-align: center; padding: .6rem 1rem; border: 1px solid rgba(127,127,127,.35);
                      border-radius: 12px; cursor: pointer; font-weight: 600; min-width: 88px; }
        .hueco input:checked + span { background: var(--brand); color: #fff; border-color: var(--brand); }
        .hueco input:focus-visible + span { outline: 2px solid var(--brand); outline-offset: 2px; }
    </style>
</head>
<body class="lp">

<?php
/* La marca es el botón de "inicio": lleva al micrositio del consultorio. Y se
   arma el header estilo GymOS: navegación + Portal del paciente + Agendar. */
$slugPub    = tenant()['slug'] ?? '';
$inicioUrl  = BASE_URL . '/c/' . $slugPub;
$reservarH  = agenda_online_activa();
$agendarUrl = BASE_URL . '/agenda/reservar?c=' . rawurlencode($slugPub);
?>
<nav class="navbar navbar-expand-lg landing-nav sticky-top">
    <div class="container">
        <a href="<?= e($inicioUrl) ?>" class="navbar-brand fw-bold text-brand d-flex align-items-center gap-2 text-decoration-none">
            <?php if (cfg('marca_logo')): ?>
                <img src="<?= e(cfg('marca_logo')) ?>" alt="" style="max-height:32px;width:auto">
            <?php else: ?>
                <i class="bi bi-heart-pulse-fill"></i>
            <?php endif; ?>
            <?= e($marca) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pubnav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="pubnav">
            <?php /* Enlaces de sección (los define el micrositio en $navLinks). */ ?>
            <ul class="navbar-nav mx-auto align-items-lg-center gap-lg-1">
                <li class="nav-item"><a class="nav-link" href="<?= e($inicioUrl) ?>"><?= et('Inicio') ?></a></li>
                <?php foreach ($navLinks ?? [] as [$lbl, $href]): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e($href) ?>"><?= e($lbl) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <ul class="navbar-nav align-items-lg-center gap-lg-2">
                <?php if (cfg('telefono')): ?>
                <li class="nav-item d-none d-lg-block">
                    <a class="nav-link" href="tel:<?= e(preg_replace('/\s+/', '', cfg('telefono'))) ?>"><i class="bi bi-telephone"></i> <?= e(cfg('telefono')) ?></a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/portal/login"><i class="bi bi-person-heart"></i> <?= et('Portal del paciente') ?></a>
                </li>
                <?php if ($reservarH): ?>
                <li class="nav-item"><a class="btn btn-primary px-3" href="<?= e($agendarUrl) ?>"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="pub-wrap">
