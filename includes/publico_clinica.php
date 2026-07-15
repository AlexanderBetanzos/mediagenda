<?php
/**
 * Micrositio público de un consultorio: su propia página por slug.
 *   /?c=<slug>   (o el bonito /c/<slug> vía .htaccess)
 *
 * Diseño clínico "pro": verde petróleo como identidad, coral en los botones de
 * acción y tarjetas pastel tipo widget — la misma línea que el resto del sistema.
 * Pensado para verse bien AUNQUE el consultorio no haya llenado todo: el hero es
 * un degradado sólido con o sin foto, y siempre hay tarjetas y datos que mostrar.
 *
 * Se configura desde el dashboard: titular, foto, "sobre nosotros", marca y
 * contacto. Lo demás sale solo de la data: servicios, equipo y horario.
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

// Equipo = el catálogo de médicos (rol 'medico'), igual que /medicos/index. NO
// se incluye 'admin': el dueño/administrador es personal, no necesariamente un
// médico que atiende. Cada médico trae SU propio horario (cada quien su agenda).
$medicos = [];
try {
    $st = db()->prepare("SELECT id, nombre, especialidad FROM usuarios
                         WHERE consultorio_id = ? AND activo = 1 AND rol = 'medico' ORDER BY nombre");
    $st->execute([(int) $con['id']]);
    $medicos = $st->fetchAll();
    foreach ($medicos as &$m) { $m['horario'] = horario_atencion_texto((int) $con['id'], (int) $m['id']); }
    unset($m);
} catch (Throwable $e) {}

$nServicios = array_sum(array_map('count', $servicios));
$especialidades = array_values(array_unique(array_filter(array_map(fn($m) => $m['especialidad'] ?: null, $medicos))));
$anios = max(0, (int) floor((time() - strtotime($con['creado_en'] ?? 'now')) / 31556952));

$wa = '';
if ($tel) {
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) <= 10) $num = cfg('pais_lada', '52') . $num;
    $wa = 'https://wa.me/' . $num . '?text=' . rawurlencode(t('Hola') . ', ' . t('me interesa información de') . ' ' . $marca . '.');
}

// Tarjetas de beneficios (widgets pastel). Ciertas para cualquier consultorio.
$benes = [
    ['bi-clipboard2-pulse', t('Expediente digital'), t('Tu historial, recetas y estudios, siempre a la mano y seguros.'), 'coral'],
    ['bi-calendar-heart',   $reservar ? t('Agenda en línea') : t('Atención puntual'),
        $reservar ? t('Aparta tu cita cuando quieras, sin llamadas ni esperas.') : t('Respetamos tu tiempo: consulta a la hora acordada.'), 'sage'],
    ['bi-shield-check',     t('Atención de confianza'), t('Un equipo que te conoce y da seguimiento a tu tratamiento.'), 'teal'],
];

$titulo = $marca;
$indexable = true;
include __DIR__ . '/publico_header.php';
?>

<style>
    .pub-wrap { max-width: none; padding: 0; }
    .clx { --cl: <?= $acento ?>; --cl-d: color-mix(in srgb, <?= $acento ?> 78%, #000);
           --cta: #e07a5f; --cta-d: #cf6a50;
           --ink: #21384e; --mut: #6b7c93; --soft: color-mix(in srgb, <?= $acento ?> 6%, #fff);
           font-family: 'Inter', system-ui, sans-serif; }
    html.lp-dark .clx { --ink: #e6e8ec; --mut: #9aa0aa; --soft: rgba(255,255,255,.035); }

    .clx section { padding: 4.5rem 1.25rem; }
    .clx .wrap { max-width: 1080px; margin: 0 auto; }
    .clx h2.t { font-family: 'Mulish', sans-serif; color: var(--ink); font-weight: 800;
                font-size: clamp(1.6rem, 3.4vw, 2.25rem); margin: 0; }
    .clx .sub { color: var(--mut); max-width: 56ch; margin: .8rem auto 0; }
    .clx .eyebrow { display: inline-block; color: var(--cl); font-weight: 700; font-size: .78rem;
                    letter-spacing: .12em; text-transform: uppercase; }
    .clx .btn-cta { background: var(--cta); color: #fff; border: 0; border-radius: 999px;
                    padding: .85rem 1.9rem; font-weight: 700; }
    .clx .btn-cta:hover { background: var(--cta-d); color: #fff; }
    .clx .btn-gho { background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,.6);
                    border-radius: 999px; padding: .85rem 1.7rem; font-weight: 600; }
    .clx .btn-gho:hover { background: rgba(255,255,255,.14); color: #fff; }

    /* ===== HERO (banner profesional con imagen) ===== */
    .clx .hero { position: relative; color: #fff; overflow: hidden; background: var(--cl-d); }
    .clx .hero::before { content: ''; position: absolute; inset: 0; z-index: 0;
        background: linear-gradient(105deg, var(--cl-d) 0%, color-mix(in srgb, var(--cl-d) 82%, transparent) 46%,
                    color-mix(in srgb, var(--cl) 35%, transparent) 78%, rgba(0,0,0,.15) 100%),
                    url('<?= e($foto ?: 'https://images.unsplash.com/photo-1631217868264-e5b90bb7e133?w=1600&q=80&auto=format&fit=crop') ?>') center/cover no-repeat; }
    .clx .hero .wrap { position: relative; z-index: 1; padding: 5rem 1.25rem; }
    .clx .hero .pill { display: inline-flex; align-items: center; gap: .45rem; background: rgba(255,255,255,.16);
                       padding: .34rem .85rem; border-radius: 999px; font-weight: 600; font-size: .8rem; }
    .clx .hero h1 { font-family: 'Mulish', sans-serif; font-weight: 800; font-size: clamp(2rem, 4.8vw, 3.1rem);
                    line-height: 1.1; margin: 1rem 0 .8rem; }
    .clx .hero .lead { font-size: 1.12rem; opacity: .94; max-width: 40ch; }
    .clx .hero .logo { max-height: 46px; background: #fff; border-radius: 10px; padding: .35rem .6rem; margin-bottom: 1rem; }
    .clx .hero .trust { display: flex; flex-wrap: wrap; gap: 1.6rem; margin-top: 2rem; }
    .clx .hero .trust .n { font-family: 'Mulish', sans-serif; font-weight: 800; font-size: 1.7rem; line-height: 1; }
    .clx .hero .trust .l { font-size: .8rem; opacity: .85; }
    /* Imagen / tarjeta al lado */
    .clx .hero-img { border-radius: 22px; width: 100%; height: 340px; object-fit: cover;
                     box-shadow: 0 24px 60px rgba(0,0,0,.28); }
    .clx .hero-card { background: #fff; color: var(--ink); border-radius: 22px; padding: 1.6rem;
                      box-shadow: 0 24px 60px rgba(0,0,0,.24); }
    .clx .hero-card .row-i { display: flex; align-items: center; gap: .8rem; padding: .7rem 0;
                             border-bottom: 1px solid rgba(0,0,0,.06); }
    .clx .hero-card .row-i:last-child { border-bottom: 0; }
    .clx .hero-card .ci { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center;
                          justify-content: center; font-size: 1.2rem;
                          background: color-mix(in srgb, var(--cl) 12%, #fff); color: var(--cl); }

    /* ===== Widgets pastel (beneficios) ===== */
    .clx .bene { border-radius: 20px; padding: 1.8rem; height: 100%; }
    .clx .bene.coral { background: #fbe6df; } .clx .bene.coral .bi { color: #d1694e; }
    .clx .bene.sage  { background: #dcebe4; } .clx .bene.sage  .bi { color: #3f7a63; }
    .clx .bene.teal  { background: #d7e8ea; } .clx .bene.teal  .bi { color: #2b6d76; }
    html.lp-dark .clx .bene { background: rgba(255,255,255,.05); }
    .clx .bene .ic { font-size: 2rem; margin-bottom: .8rem; display: block; }
    .clx .bene h5 { font-weight: 800; color: #2a2f36; }
    html.lp-dark .clx .bene h5 { color: #e6e8ec; }
    .clx .bene p { color: #4b5560; font-size: .93rem; margin: 0; }
    html.lp-dark .clx .bene p { color: #b8bcc4; }

    /* ===== Servicios ===== */
    .clx .soft { background: var(--soft); }
    .clx .cat { color: var(--cl); font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
                font-size: .8rem; margin: 1.6rem 0 .8rem; }
    .clx .serv { background: var(--bs-body-bg); border: 1px solid color-mix(in srgb, var(--cl) 14%, #fff);
                 border-radius: 14px; padding: 1rem 1.25rem; height: 100%; display: flex;
                 justify-content: space-between; align-items: baseline; gap: 1rem; transition: border-color .15s, transform .15s; }
    .clx .serv:hover { border-color: var(--cl); transform: translateY(-2px); }
    html.lp-dark .clx .serv { border-color: rgba(255,255,255,.08); }
    .clx .serv .n { color: var(--ink); } .clx .serv .p { color: var(--cta); font-weight: 800; white-space: nowrap; }

    /* ===== Equipo (tarjeta por médico, con su horario) ===== */
    .clx .medcard { background: var(--bs-body-bg); border: 1px solid color-mix(in srgb, var(--cl) 14%, #fff);
                    border-radius: 20px; padding: 1.6rem; height: 100%; }
    html.lp-dark .clx .medcard { border-color: rgba(255,255,255,.08); }
    .clx .medcard .av { width: 92px; height: 92px; border-radius: 50%; margin: 0 auto .8rem; display: flex;
                    align-items: center; justify-content: center; font-family: 'Mulish', sans-serif;
                    font-weight: 800; font-size: 2rem; color: #fff;
                    background: linear-gradient(135deg, var(--cl-d), var(--cl)); box-shadow: 0 10px 26px rgba(0,0,0,.12); }
    .clx .medcard .nm { color: var(--ink); font-weight: 700; } .clx .medcard .sp { color: var(--mut); font-size: .9rem; }
    .clx .medhor { border-top: 1px solid color-mix(in srgb, var(--cl) 12%, #fff); margin-top: .4rem; padding-top: .8rem;
                   font-size: .86rem; color: var(--ink); }
    html.lp-dark .clx .medhor { border-top-color: rgba(255,255,255,.08); }
    .clx .medhor-t { color: var(--cl); font-weight: 700; font-size: .75rem; text-transform: uppercase;
                     letter-spacing: .05em; margin-bottom: .4rem; }

    /* ===== Contacto (fila de íconos) ===== */
    .clx .feat { text-align: center; }
    .clx .feat .ico { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto .9rem; display: flex;
                      align-items: center; justify-content: center; font-size: 1.7rem;
                      background: color-mix(in srgb, var(--cl) 12%, #fff); color: var(--cl); }
    html.lp-dark .clx .feat .ico { background: color-mix(in srgb, var(--cl) 24%, transparent); }
    .clx .feat h6 { color: var(--ink); font-weight: 700; } .clx .feat .det { color: var(--mut); font-size: .9rem; }
    .clx .feat a { color: inherit; text-decoration: none; }

    /* ===== Banda de reserva ===== */
    .clx .book { background: linear-gradient(120deg, var(--cl-d), var(--cl)); }
    .clx .book h2.t, .clx .book .sub { color: #fff; }
    .clx .book .eyebrow { color: rgba(255,255,255,.8); }
    .clx .book .pub-card { border: 0; border-radius: 22px; box-shadow: 0 24px 60px rgba(0,0,0,.24); }
    .clx .book .form-control, .clx .book .form-select {
        background: var(--soft); border: 1px solid transparent; border-radius: 12px; padding: .7rem .9rem; }
    .clx .book .form-control:focus, .clx .book .form-select:focus {
        border-color: var(--cl); box-shadow: 0 0 0 .18rem color-mix(in srgb, var(--cl) 20%, transparent); }
    .clx .book .btn-primary { background: var(--cta); border-color: var(--cta); border-radius: 999px; }
    .clx .book .btn-primary:hover { background: var(--cta-d); border-color: var(--cta-d); }
    .clx .book .hueco span { border-radius: 12px; }
</style>

<div class="clx">

<!-- ===== HERO (banner) ===== -->
<header class="hero">
    <div class="wrap">
        <div class="row">
            <div class="col-lg-7">
                <?php if (cfg('marca_logo')): ?>
                    <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" class="logo d-block">
                <?php else: ?>
                    <span class="pill"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?></span>
                <?php endif; ?>
                <h1><?= e($titular) ?></h1>
                <p class="lead"><?= e($lema ?: t('Cuidamos de tu salud con atención cercana y profesional.')) ?></p>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <?php if ($reservar): ?>
                        <a href="#agendar" class="btn-cta"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
                    <?php endif; ?>
                    <?php if ($wa): ?>
                        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn-gho"><i class="bi bi-whatsapp"></i> WhatsApp</a>
                    <?php elseif ($tel): ?>
                        <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="btn-gho"><i class="bi bi-telephone"></i> <?= e($tel) ?></a>
                    <?php endif; ?>
                </div>
                <div class="trust">
                    <?php if ($anios >= 1): ?><div><div class="n"><?= $anios ?>+</div><div class="l"><?= et('años de experiencia') ?></div></div><?php endif; ?>
                    <?php if (count($medicos)): ?><div><div class="n"><?= count($medicos) ?></div><div class="l"><?= count($medicos) === 1 ? et('especialista') : et('especialistas') ?></div></div><?php endif; ?>
                    <?php if ($nServicios): ?><div><div class="n"><?= $nServicios ?></div><div class="l"><?= et('servicios') ?></div></div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ===== BENEFICIOS (widgets) ===== -->
<section>
    <div class="wrap">
        <div class="row g-4">
            <?php foreach ($benes as [$ic, $t1, $t2, $tono]): ?>
            <div class="col-md-4">
                <div class="bene <?= $tono ?>">
                    <i class="bi <?= $ic ?> ic"></i>
                    <h5 class="fw-bold"><?= e($t1) ?></h5>
                    <p><?= e($t2) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
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
            <div class="col-sm-6 col-lg-4">
                <div class="medcard">
                    <div class="av"><?= e(strtoupper(mb_substr($m['nombre'], 0, 1))) ?></div>
                    <div class="text-center">
                        <div class="nm"><?= e($m['nombre']) ?></div>
                        <?php if ($m['especialidad']): ?><div class="sp mb-2"><?= e($m['especialidad']) ?></div><?php endif; ?>
                    </div>
                    <?php /* Cada médico con SU propio horario (cada quien su agenda). */ ?>
                    <?php if (!empty($m['horario'])): ?>
                    <div class="medhor">
                        <div class="medhor-t"><i class="bi bi-clock"></i> <?= et('Horario') ?></div>
                        <?php foreach ($m['horario'] as $h): ?>
                        <div class="d-flex justify-content-between">
                            <span><?= e($h['dias']) ?></span><span class="text-muted"><?= e($h['horas']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="medhor text-muted small text-center"><?= et('Consulta con cita') ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== CONTACTO ===== -->
<section>
    <div class="wrap">
        <div class="text-center mb-5">
            <span class="eyebrow"><?= et('Visítanos') ?></span>
            <h2 class="t"><?= et('Contacto') ?></h2>
        </div>
        <div class="row g-4">
            <?php if ($correo): ?>
            <div class="col-6 col-lg-3 feat"><div class="ico"><i class="bi bi-envelope"></i></div>
                <h6><?= et('Correo') ?></h6><div class="det"><a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a></div></div>
            <?php endif; ?>
            <?php if ($tel): ?>
            <div class="col-6 col-lg-3 feat"><div class="ico"><i class="bi bi-telephone"></i></div>
                <h6><?= et('Teléfono') ?></h6><div class="det"><a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>"><?= e($tel) ?></a></div></div>
            <?php endif; ?>
            <?php if ($dir): ?>
            <div class="col-6 col-lg-3 feat"><div class="ico"><i class="bi bi-geo-alt"></i></div>
                <h6><?= et('Ubicación') ?></h6><div class="det"><a href="https://maps.google.com/?q=<?= rawurlencode($dir) ?>" target="_blank" rel="noopener"><?= e($dir) ?></a></div></div>
            <?php endif; ?>
            <?php if ($wa): ?>
            <div class="col-6 col-lg-3 feat"><div class="ico"><i class="bi bi-whatsapp"></i></div>
                <h6>WhatsApp</h6><div class="det"><a href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= et('Escríbenos') ?></a></div></div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===== RESERVA ===== -->
<?php if ($reservar): ?>
<section class="book">
    <div class="wrap">
        <div class="text-center mb-5">
            <span class="eyebrow"><?= et('Reserva en línea') ?></span>
            <h2 class="t"><?= et('Agenda tu cita') ?></h2>
            <p class="sub"><?= et('Elige el día y la hora que mejor te queden. Sin llamadas y sin esperas.') ?></p>
        </div>
        <div id="agendar" style="max-width:660px;margin:0 auto">
            <?php include __DIR__ . '/agenda_reservar_render.php'; ?>
        </div>
    </div>
</section>
<?php endif; ?>

</div><!-- /.clx -->

<?php include __DIR__ . '/publico_footer.php'; ?>
