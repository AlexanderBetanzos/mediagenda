<?php
/**
 * Micrositio público de un consultorio: su propia página por slug.
 *   /?c=<slug>   (o el bonito /c/<slug> vía .htaccess)
 *
 * Estética "médico limpio": fondo claro, mucho aire, azul suave, títulos
 * centrados. La misma plantilla para todos, pero cada consultorio pone su color,
 * su logo y sus datos. Lo configurable sale de Configuración; lo demás
 * (servicios, equipo, horario) se saca solo de la data del consultorio.
 *
 * Espera $con (fila de consultorios) ya resuelta por index.php.
 */

tenant_forzar((int) $con['id']);

$marca    = marca_nombre();
$lema     = cfg('marca_lema');
$titular  = cfg('web_titular') ?: $marca;
$acerca   = cfg('web_acerca');
$foto     = cfg('web_foto');
$dir      = cfg('direccion');
$tel      = cfg('telefono');
$correo   = cfg('email');
$acento   = color_acento();
$reservar = agenda_online_activa();
$horario  = horario_atencion_texto((int) $con['id']);

if ($reservar) {
    require_once __DIR__ . '/correo.php';
    $agAccion = BASE_URL . '/c/' . $con['slug'];
    require __DIR__ . '/agenda_reservar_logica.php';
}

$servicios = [];
try {
    $st = db()->prepare('SELECT nombre, categoria, precio FROM servicios
                         WHERE consultorio_id = ? AND activo = 1 ORDER BY categoria, nombre');
    $st->execute([(int) $con['id']]);
    foreach ($st->fetchAll() as $s) { $servicios[$s['categoria'] ?: t('Servicios')][] = $s; }
} catch (Throwable $e) {}

$medicos = [];
try {
    $st = db()->prepare("SELECT nombre, especialidad FROM usuarios
                         WHERE consultorio_id = ? AND activo = 1 AND rol IN ('medico','admin') ORDER BY nombre");
    $st->execute([(int) $con['id']]);
    $medicos = $st->fetchAll();
} catch (Throwable $e) {}

$wa = '';
if ($tel) {
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) <= 10) $num = cfg('pais_lada', '52') . $num;
    $wa = 'https://wa.me/' . $num . '?text=' . rawurlencode(t('Hola') . ', ' . t('me interesa información de') . ' ' . $marca . '.');
}

$titulo = $marca;
$indexable = true;
include __DIR__ . '/publico_header.php';
?>

<style>
    .pub-wrap { max-width: none; padding: 0; }
    .clx { --cl-brand: <?= $acento ?>;
           --cl-cta: #e07a5f;   /* coral: botones de acción, como la referencia */
           --cl-soft: color-mix(in srgb, <?= $acento ?> 6%, #fff);
           --cl-ink: #223a52; --cl-mut: #6b7c93; }
    html.lp-dark .clx { --cl-soft: rgba(255,255,255,.035); --cl-ink: #e6e8ec; --cl-mut: #9aa0aa; }

    .clx { font-family: 'Inter', system-ui, sans-serif; }
    .clx section { padding: 4.5rem 1.25rem; }
    .clx .wrap { max-width: 1060px; margin: 0 auto; }
    .clx .soft { background: var(--cl-soft); }
    .clx .eyebrow { display: inline-block; color: var(--cl-brand); font-weight: 700; font-size: .78rem;
                    letter-spacing: .12em; text-transform: uppercase; margin-bottom: .5rem; }
    .clx h2.t { color: var(--cl-ink); font-weight: 800; font-size: clamp(1.6rem, 3.4vw, 2.3rem); margin: 0; }
    .clx .sub { color: var(--cl-mut); max-width: 54ch; margin: .9rem auto 0; }

    /* Portada: pálida y centrada, sin hero oscuro */
    .clx .hero { background: var(--cl-soft); text-align: center; padding: 5rem 1.25rem 4.5rem; }
    .clx .hero .logo { max-height: 60px; width: auto; margin-bottom: 1.4rem; }
    .clx .hero h1 { color: var(--cl-ink); font-weight: 800; font-size: clamp(2rem, 5.2vw, 3.2rem);
                    line-height: 1.1; margin: 0 auto .9rem; max-width: 18ch; }
    .clx .hero p { color: var(--cl-mut); font-size: 1.12rem; max-width: 52ch; margin: 0 auto 1.6rem; }

    /* Botones suaves y redondeados */
    .clx .btn-cl { background: var(--cl-cta); color: #fff; border: 0; border-radius: 999px;
                   padding: .8rem 1.8rem; font-weight: 600; }
    .clx .btn-cl:hover { filter: brightness(1.07); color: #fff; }
    .clx .btn-cl-o { background: transparent; color: var(--cl-brand); border: 1.5px solid var(--cl-brand);
                     border-radius: 999px; padding: .8rem 1.8rem; font-weight: 600; }
    .clx .btn-cl-o:hover { background: var(--cl-brand); color: #fff; }

    /* Fila de íconos (contacto / datos) */
    .clx .feat { text-align: center; }
    .clx .feat .ico { width: 78px; height: 78px; border-radius: 50%; margin: 0 auto 1rem; display: flex;
                      align-items: center; justify-content: center; font-size: 1.9rem;
                      background: color-mix(in srgb, var(--cl-brand) 12%, #fff); color: var(--cl-brand); }
    html.lp-dark .clx .feat .ico { background: color-mix(in srgb, var(--cl-brand) 22%, transparent); }
    .clx .feat h5 { color: var(--cl-ink); font-weight: 700; font-size: 1.05rem; margin-bottom: .3rem; }
    .clx .feat .det { color: var(--cl-mut); font-size: .92rem; line-height: 1.5; }
    .clx .feat a { color: inherit; text-decoration: none; }

    /* Servicios */
    .clx .cat { color: var(--cl-brand); font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
                font-size: .8rem; margin: 1.6rem 0 .8rem; }
    .clx .serv { background: #fff; border: 1px solid color-mix(in srgb, var(--cl-brand) 14%, #fff);
                 border-radius: 14px; padding: 1rem 1.25rem; height: 100%; display: flex;
                 justify-content: space-between; align-items: baseline; gap: 1rem; }
    html.lp-dark .clx .serv { background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.08); }
    .clx .serv .p { color: var(--cl-brand); font-weight: 800; white-space: nowrap; }
    .clx .serv .n { color: var(--cl-ink); }

    /* Equipo */
    .clx .med { text-align: center; }
    .clx .med .av { width: 108px; height: 108px; border-radius: 50%; margin: 0 auto .7rem; display: flex;
                    align-items: center; justify-content: center; font-weight: 800; font-size: 2.3rem;
                    background: color-mix(in srgb, var(--cl-brand) 12%, #fff); color: var(--cl-brand); }
    html.lp-dark .clx .med .av { background: color-mix(in srgb, var(--cl-brand) 22%, transparent); }
    .clx .med .nm { color: var(--cl-ink); font-weight: 700; }
    .clx .med .sp { color: var(--cl-mut); font-size: .9rem; }

    /* Formulario de reserva: campos rellenos en azul pálido (estilo de la ref) */
    .clx .cl-form .pub-card { box-shadow: none; border: 1px solid color-mix(in srgb, var(--cl-brand) 14%, #fff);
                              border-radius: 20px; }
    html.lp-dark .clx .cl-form .pub-card { border-color: rgba(255,255,255,.08); }
    .clx .cl-form .form-control, .clx .cl-form .form-select {
        background: color-mix(in srgb, var(--cl-brand) 7%, #fff); border: 1px solid transparent;
        border-radius: 12px; padding: .7rem .9rem; }
    html.lp-dark .clx .cl-form .form-control, html.lp-dark .clx .cl-form .form-select {
        background: rgba(255,255,255,.05); }
    .clx .cl-form .form-control:focus, .clx .cl-form .form-select:focus {
        border-color: var(--cl-brand); box-shadow: 0 0 0 .18rem color-mix(in srgb, var(--cl-brand) 20%, transparent); }
    .clx .cl-form .btn-primary { background: var(--cl-cta); border-color: var(--cl-cta); border-radius: 999px; }
    .clx .cl-form .foto-lado { border-radius: 20px; width: 100%; height: 100%; min-height: 320px;
                               object-fit: cover; }

    /* CTA final: tarjeta pálida redondeada */
    .clx .cta { background: var(--cl-soft); border-radius: 24px; text-align: center; padding: 3.2rem 1.5rem; }
</style>

<div class="clx">

<!-- ===== HERO ===== -->
<section class="hero">
    <?php if (cfg('marca_logo')): ?>
        <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" class="logo">
    <?php else: ?>
        <div class="eyebrow"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?></div>
    <?php endif; ?>
    <h1><?= e($titular) ?></h1>
    <?php if ($lema): ?><p><?= e($lema) ?></p><?php endif; ?>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php if ($reservar): ?>
            <a href="#agendar" class="btn-cl"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
        <?php endif; ?>
        <?php if ($wa): ?>
            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn-cl-o"><i class="bi bi-whatsapp"></i> WhatsApp</a>
        <?php elseif ($tel): ?>
            <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="btn-cl-o"><i class="bi bi-telephone"></i> <?= e($tel) ?></a>
        <?php endif; ?>
    </div>
</section>

<!-- ===== FILA DE DATOS ===== -->
<section>
    <div class="wrap">
        <div class="row g-4">
            <?php if ($correo): ?>
            <div class="col-6 col-lg-3 feat">
                <div class="ico"><i class="bi bi-envelope"></i></div>
                <h5><?= et('Correo') ?></h5>
                <div class="det"><a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a></div>
            </div>
            <?php endif; ?>
            <?php if ($tel): ?>
            <div class="col-6 col-lg-3 feat">
                <div class="ico"><i class="bi bi-telephone"></i></div>
                <h5><?= et('Teléfono') ?></h5>
                <div class="det"><a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>"><?= e($tel) ?></a></div>
            </div>
            <?php endif; ?>
            <?php if ($dir): ?>
            <div class="col-6 col-lg-3 feat">
                <div class="ico"><i class="bi bi-geo-alt"></i></div>
                <h5><?= et('Ubicación') ?></h5>
                <div class="det"><a href="https://maps.google.com/?q=<?= rawurlencode($dir) ?>" target="_blank" rel="noopener"><?= e($dir) ?></a></div>
            </div>
            <?php endif; ?>
            <?php if ($horario): ?>
            <div class="col-6 col-lg-3 feat">
                <div class="ico"><i class="bi bi-clock"></i></div>
                <h5><?= et('Horario') ?></h5>
                <div class="det">
                    <?php foreach ($horario as $h): ?><?= e($h['dias']) ?>: <?= e($h['horas']) ?><br><?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===== SOBRE NOSOTROS ===== -->
<?php if ($acerca): ?>
<section class="soft">
    <div class="wrap text-center">
        <span class="eyebrow"><?= et('Sobre nosotros') ?></span>
        <h2 class="t mb-3"><?= et('Conócenos') ?></h2>
        <p class="sub"><?= nl2br(e($acerca)) ?></p>
    </div>
</section>
<?php endif; ?>

<!-- ===== SERVICIOS ===== -->
<?php if ($servicios): ?>
<section>
    <div class="wrap">
        <div class="text-center mb-4">
            <span class="eyebrow"><?= et('Lo que ofrecemos') ?></span>
            <h2 class="t"><?= et('Nuestros servicios') ?></h2>
        </div>
        <?php foreach ($servicios as $categoria => $items): ?>
            <div class="cat"><?= e($categoria) ?></div>
            <div class="row g-3">
                <?php foreach ($items as $s): ?>
                <div class="col-md-6">
                    <div class="serv">
                        <span class="n"><?= e($s['nombre']) ?></span>
                        <?php if ((float) $s['precio'] > 0): ?><span class="p"><?= fmt_money($s['precio']) ?></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ===== EQUIPO ===== -->
<?php if ($medicos): ?>
<section class="soft">
    <div class="wrap">
        <div class="text-center mb-5">
            <span class="eyebrow"><?= et('Quién te atiende') ?></span>
            <h2 class="t"><?= et('Nuestro equipo') ?></h2>
        </div>
        <div class="row g-4 justify-content-center">
            <?php foreach ($medicos as $m): ?>
            <div class="col-6 col-md-3 med">
                <div class="av"><?= e(strtoupper(mb_substr($m['nombre'], 0, 1))) ?></div>
                <div class="nm"><?= e($m['nombre']) ?></div>
                <?php if ($m['especialidad']): ?><div class="sp"><?= e($m['especialidad']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== AGENDAR (form + imagen, estilo de la referencia) ===== -->
<?php if ($reservar): ?>
<section id="agendar">
    <div class="wrap">
        <div class="text-center mb-5">
            <span class="eyebrow"><?= et('Reserva en línea') ?></span>
            <h2 class="t"><?= et('Agenda tu cita') ?></h2>
            <p class="sub"><?= et('Elige el día y la hora que mejor te queden. Sin llamadas y sin esperas.') ?></p>
        </div>
        <div class="row g-4 align-items-stretch cl-form">
            <div class="col-lg-7">
                <?php include __DIR__ . '/agenda_reservar_render.php'; ?>
            </div>
            <?php if ($foto): ?>
            <div class="col-lg-5 d-none d-lg-block">
                <img src="<?= e($foto) ?>" alt="<?= e($marca) ?>" class="foto-lado">
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<!-- Sin agenda en línea: cierre con contacto -->
<section>
    <div class="wrap">
        <div class="cta">
            <span class="eyebrow"><?= et('¿Agendamos tu cita?') ?></span>
            <h2 class="t mb-3"><?= et('Estamos para atenderte') ?></h2>
            <?php if ($wa): ?>
                <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn-cl"><i class="bi bi-whatsapp"></i> <?= et('Escríbenos por WhatsApp') ?></a>
            <?php elseif ($tel): ?>
                <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="btn-cl"><i class="bi bi-telephone"></i> <?= et('Llámanos') ?></a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

</div><!-- /.clx -->

<?php include __DIR__ . '/publico_footer.php'; ?>
