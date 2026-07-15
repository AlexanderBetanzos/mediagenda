<?php
/**
 * Micrositio público de un consultorio: su propia página por slug.
 *   /?c=<slug>   (o el bonito /c/<slug> vía .htaccess)
 *
 * Igual que en GymOS: la raíz del dominio es la landing del PRODUCTO
 * (MediAgenda); con el slug se muestra la página del consultorio, con SU marca,
 * servicios, equipo y contacto — nada de MediAgenda. Es el white-label.
 *
 * Qué se configura y qué sale solo:
 *   · Del dashboard (Configuración): nombre, lema, logo, color, titular, foto de
 *     portada, texto "sobre nosotros" y datos de contacto.
 *   · Automático: los servicios con precio, el equipo médico, el horario (de
 *     medico_horarios) y los contadores.
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

// La reserva corre AQUÍ (antes de imprimir): un POST crea el paciente y la cita.
if ($reservar) {
    require_once __DIR__ . '/correo.php';
    $agAccion = BASE_URL . '/c/' . $con['slug'];
    require __DIR__ . '/agenda_reservar_logica.php';
}

// Servicios agrupados por categoría (la carta de precios que el paciente busca).
$servicios = [];
try {
    $st = db()->prepare('SELECT nombre, categoria, precio FROM servicios
                         WHERE consultorio_id = ? AND activo = 1 ORDER BY categoria, nombre');
    $st->execute([(int) $con['id']]);
    foreach ($st->fetchAll() as $s) { $servicios[$s['categoria'] ?: t('Servicios')][] = $s; }
} catch (Throwable $e) {}

// Equipo médico con especialidad.
$medicos = [];
try {
    $st = db()->prepare("SELECT nombre, especialidad FROM usuarios
                         WHERE consultorio_id = ? AND activo = 1 AND rol IN ('medico','admin') ORDER BY nombre");
    $st->execute([(int) $con['id']]);
    $medicos = $st->fetchAll();
} catch (Throwable $e) {}

// Contadores reales (solo los que dan un número > 0).
$nServicios = array_sum(array_map('count', $servicios));
$especialidades = array_values(array_unique(array_filter(array_map(fn($m) => $m['especialidad'] ?: null, $medicos))));
$anios = max(0, (int) floor((time() - strtotime($con['creado_en'] ?? 'now')) / 31556952));
$stats = array_values(array_filter([
    $anios >= 1        ? ['n' => $anios,               'l' => t('años de experiencia')] : null,
    count($medicos)    ? ['n' => count($medicos),      'l' => count($medicos) === 1 ? t('especialista') : t('especialistas')] : null,
    count($especialidades) ? ['n' => count($especialidades), 'l' => t('especialidades')] : null,
    $nServicios        ? ['n' => $nServicios,          'l' => t('servicios')] : null,
]));

// WhatsApp del consultorio.
$wa = '';
if ($tel) {
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) <= 10) $num = cfg('pais_lada', '52') . $num;
    $wa = 'https://wa.me/' . $num . '?text=' . rawurlencode(t('Hola') . ', ' . t('me interesa información de') . ' ' . $marca . '.');
}

// Beneficios: son ciertos para cualquier consultorio en MediAgenda.
$beneficios = [
    ['bi-clipboard2-pulse', t('Expediente digital'), t('Tu historial, recetas y estudios siempre a la mano y seguros.')],
    ['bi-people', t('Atención personalizada'), t('Un equipo que te conoce y da seguimiento a tu tratamiento.')],
    $reservar
        ? ['bi-calendar-check', t('Agenda en línea'), t('Aparta tu cita cuando quieras, sin llamadas ni esperas.')]
        : ['bi-telephone', t('Fácil de contactar'), t('Estamos a una llamada o un mensaje de distancia.')],
    ['bi-capsule', t('Recetas y estudios'), t('Recibe tus recetas y resultados de forma clara y ordenada.')],
];

$titulo = $marca;
$indexable = true;
include __DIR__ . '/publico_header.php';
?>

<style>
    .pub-wrap { max-width: none; padding: 0; }
    :root { --cl-brand: <?= $acento ?>; }

    /* ---- Hero ---- */
    .cl-hero { position: relative; color: #fff; overflow: hidden;
               <?php if ($foto): ?>background: #10233a;<?php endif; ?> }
    .cl-hero::before { content: ''; position: absolute; inset: 0; z-index: 0;
        <?php if ($foto): ?>
        background: linear-gradient(120deg, color-mix(in srgb, var(--cl-brand) 92%, #000) 0%,
                    color-mix(in srgb, var(--cl-brand) 78%, #000) 42%, rgba(16,35,58,.72) 100%),
                    url('<?= e($foto) ?>') center/cover no-repeat;
        <?php else: ?>
        background: radial-gradient(circle at 88% -10%, color-mix(in srgb, var(--cl-brand) 55%, #38a3e8) 0%, transparent 42%),
                    linear-gradient(135deg, color-mix(in srgb, var(--cl-brand) 90%, #000), var(--cl-brand) 70%);
        <?php endif; ?> }
    .cl-hero-in { position: relative; z-index: 1; max-width: 1040px; margin: 0 auto;
                  padding: 4.5rem 1.25rem 5rem; }
    .cl-eyebrow { display: inline-flex; align-items: center; gap: .45rem; font-weight: 600; font-size: .82rem;
                  letter-spacing: .04em; text-transform: uppercase; background: rgba(255,255,255,.16);
                  padding: .34rem .8rem; border-radius: 999px; backdrop-filter: blur(4px); }
    .cl-hero h1 { font-family: 'Mulish', sans-serif; font-weight: 800; font-size: clamp(2rem, 5vw, 3.3rem);
                  line-height: 1.08; margin: 1.1rem 0 .8rem; max-width: 15ch; }
    .cl-hero .lead { font-size: 1.15rem; opacity: .93; max-width: 46ch; }
    .cl-logo-badge { background: #fff; border-radius: 14px; padding: .5rem .8rem; display: inline-block;
                     box-shadow: 0 8px 26px rgba(0,0,0,.18); margin-bottom: 1.2rem; }
    .cl-stats { display: flex; flex-wrap: wrap; gap: 2.4rem; margin-top: 2.5rem; }
    .cl-stats .num { font-family: 'Mulish', sans-serif; font-weight: 800; font-size: 2.1rem; line-height: 1; }
    .cl-stats .lbl { font-size: .82rem; opacity: .85; margin-top: .2rem; }

    /* ---- Secciones ---- */
    .cl-sec { max-width: 1040px; margin: 0 auto; padding: 4rem 1.25rem; }
    .cl-sec.alt { max-width: none; background: color-mix(in srgb, var(--cl-brand) 5%, #fff); }
    html.lp-dark .cl-sec.alt { background: rgba(255,255,255,.03); }
    .cl-sec.alt > .cl-inner { max-width: 1040px; margin: 0 auto; }
    .cl-eyebrow-dark { color: var(--cl-brand); font-weight: 700; font-size: .8rem; letter-spacing: .05em;
                       text-transform: uppercase; }
    .cl-h2 { font-family: 'Mulish', sans-serif; font-weight: 800; font-size: clamp(1.5rem, 3.4vw, 2.2rem);
             margin-top: .4rem; }

    /* ---- Beneficios ---- */
    .cl-ben { height: 100%; border: 1px solid rgba(127,127,127,.16); border-radius: 18px; padding: 1.6rem;
              transition: transform .16s ease, box-shadow .16s ease; background: var(--bs-body-bg); }
    .cl-ben:hover { transform: translateY(-4px); box-shadow: 0 14px 34px rgba(16,35,58,.10); }
    .cl-ben-ico { width: 52px; height: 52px; border-radius: 14px; display: inline-flex; align-items: center;
                  justify-content: center; font-size: 1.5rem; margin-bottom: .9rem;
                  background: color-mix(in srgb, var(--cl-brand) 14%, transparent); color: var(--cl-brand); }

    /* ---- Servicios ---- */
    .cl-cat { color: var(--cl-brand); font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
              font-size: .82rem; margin: 1.8rem 0 .8rem; }
    .cl-serv { border: 1px solid rgba(127,127,127,.16); border-radius: 14px; padding: 1rem 1.25rem; height: 100%;
               display: flex; justify-content: space-between; align-items: baseline; gap: 1rem;
               transition: border-color .15s ease; }
    .cl-serv:hover { border-color: var(--cl-brand); }
    .cl-serv .precio { font-weight: 800; color: var(--cl-brand); white-space: nowrap; }

    /* ---- Equipo ---- */
    .cl-med { text-align: center; }
    .cl-med-foto { width: 104px; height: 104px; border-radius: 50%; margin: 0 auto .7rem; display: flex;
                   align-items: center; justify-content: center; font-family: 'Mulish', sans-serif;
                   font-size: 2.3rem; font-weight: 800;
                   background: linear-gradient(135deg, color-mix(in srgb, var(--cl-brand) 22%, transparent),
                               color-mix(in srgb, var(--cl-brand) 8%, transparent)); color: var(--cl-brand); }

    /* ---- Contacto ---- */
    .cl-contact-card { border: 1px solid rgba(127,127,127,.16); border-radius: 18px; padding: 1.6rem; height: 100%; }
    .cl-contact-card a { text-decoration: none; }
    .cl-contact-ico { color: var(--cl-brand); }

    /* ---- Cierre CTA ---- */
    .cl-cta { background: linear-gradient(135deg, color-mix(in srgb, var(--cl-brand) 90%, #000), var(--cl-brand) 75%);
              color: #fff; text-align: center; padding: 3.5rem 1.25rem; }
</style>

<!-- ===================== HERO ===================== -->
<header class="cl-hero">
    <div class="cl-hero-in">
        <?php if (cfg('marca_logo')): ?>
            <div class="cl-logo-badge"><img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" style="max-height:52px;width:auto;display:block"></div>
        <?php else: ?>
            <span class="cl-eyebrow"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?></span>
        <?php endif; ?>

        <h1><?= e($titular) ?></h1>
        <?php if ($lema): ?><p class="lead"><?= e($lema) ?></p><?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mt-4">
            <?php if ($reservar): ?>
                <a href="#agendar" class="btn btn-light btn-lg fw-semibold px-4"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
            <?php endif; ?>
            <?php if ($wa): ?>
                <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-light btn-lg px-4"><i class="bi bi-whatsapp"></i> WhatsApp</a>
            <?php elseif ($tel): ?>
                <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="btn btn-outline-light btn-lg px-4"><i class="bi bi-telephone"></i> <?= e($tel) ?></a>
            <?php endif; ?>
        </div>

        <?php if ($stats): ?>
        <div class="cl-stats">
            <?php foreach ($stats as $s): ?>
                <div><div class="num"><?= (int) $s['n'] ?>+</div><div class="lbl"><?= e($s['l']) ?></div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</header>

<!-- ===================== SOBRE NOSOTROS ===================== -->
<?php if ($acerca): ?>
<section class="cl-sec text-center">
    <span class="cl-eyebrow-dark"><?= et('Sobre nosotros') ?></span>
    <h2 class="cl-h2 mb-3"><?= et('Conócenos') ?></h2>
    <p class="lead text-muted mx-auto" style="max-width:60ch"><?= nl2br(e($acerca)) ?></p>
</section>
<?php endif; ?>

<!-- ===================== BENEFICIOS ===================== -->
<section class="cl-sec alt">
    <div class="cl-inner">
        <div class="text-center mb-5">
            <span class="cl-eyebrow-dark"><?= et('Por qué elegirnos') ?></span>
            <h2 class="cl-h2"><?= et('Cuidamos de ti y de tu tiempo') ?></h2>
        </div>
        <div class="row g-4">
            <?php foreach ($beneficios as [$ico, $t1, $t2]): ?>
            <div class="col-md-6 col-lg-3">
                <div class="cl-ben">
                    <div class="cl-ben-ico"><i class="bi <?= $ico ?>"></i></div>
                    <h5 class="fw-bold"><?= e($t1) ?></h5>
                    <p class="small text-muted mb-0"><?= e($t2) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===================== SERVICIOS ===================== -->
<?php if ($servicios): ?>
<section class="cl-sec">
    <div class="text-center mb-4">
        <span class="cl-eyebrow-dark"><?= et('Lo que ofrecemos') ?></span>
        <h2 class="cl-h2"><?= et('Nuestros servicios') ?></h2>
    </div>
    <?php foreach ($servicios as $categoria => $items): ?>
        <div class="cl-cat"><?= e($categoria) ?></div>
        <div class="row g-3">
            <?php foreach ($items as $s): ?>
            <div class="col-md-6">
                <div class="cl-serv">
                    <span><?= e($s['nombre']) ?></span>
                    <?php if ((float) $s['precio'] > 0): ?><span class="precio"><?= fmt_money($s['precio']) ?></span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- ===================== EQUIPO ===================== -->
<?php if ($medicos): ?>
<section class="cl-sec alt">
    <div class="cl-inner">
        <div class="text-center mb-5">
            <span class="cl-eyebrow-dark"><?= et('Quién te atiende') ?></span>
            <h2 class="cl-h2"><?= et('Nuestro equipo') ?></h2>
        </div>
        <div class="row g-4 justify-content-center">
            <?php foreach ($medicos as $m): ?>
            <div class="col-6 col-md-3 cl-med">
                <div class="cl-med-foto"><?= e(strtoupper(mb_substr($m['nombre'], 0, 1))) ?></div>
                <div class="fw-semibold"><?= e($m['nombre']) ?></div>
                <?php if ($m['especialidad']): ?><div class="small text-muted"><?= e($m['especialidad']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===================== CONTACTO + HORARIO ===================== -->
<section class="cl-sec">
    <div class="text-center mb-5">
        <span class="cl-eyebrow-dark"><?= et('Visítanos') ?></span>
        <h2 class="cl-h2"><?= et('Contacto y horario') ?></h2>
    </div>
    <div class="row g-4">
        <div class="col-md-6">
            <div class="cl-contact-card">
                <h5 class="fw-bold mb-3"><i class="bi bi-geo-alt cl-contact-ico"></i> <?= et('Dónde estamos') ?></h5>
                <ul class="list-unstyled mb-0">
                    <?php if ($dir): ?>
                    <li class="mb-2"><a href="https://maps.google.com/?q=<?= rawurlencode($dir) ?>" target="_blank" rel="noopener"><?= e($dir) ?></a></li>
                    <?php endif; ?>
                    <?php if ($tel): ?>
                    <li class="mb-2"><i class="bi bi-telephone cl-contact-ico me-1"></i> <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>"><?= e($tel) ?></a></li>
                    <?php endif; ?>
                    <?php if ($correo): ?>
                    <li class="mb-2"><i class="bi bi-envelope cl-contact-ico me-1"></i> <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a></li>
                    <?php endif; ?>
                    <?php if ($wa): ?>
                    <li><i class="bi bi-whatsapp cl-contact-ico me-1"></i> <a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="col-md-6">
            <div class="cl-contact-card">
                <h5 class="fw-bold mb-3"><i class="bi bi-clock cl-contact-ico"></i> <?= et('Horario de atención') ?></h5>
                <?php if ($horario): ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($horario as $h): ?>
                    <li class="d-flex justify-content-between border-bottom border-opacity-10 py-2">
                        <span class="fw-semibold"><?= e($h['dias']) ?></span>
                        <span class="text-muted"><?= e($h['horas']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="text-muted mb-0"><?= et('Escríbenos o llámanos para conocer nuestros horarios.') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ===================== AGENDAR ===================== -->
<?php if ($reservar): ?>
<section class="cl-cta">
    <span class="cl-eyebrow"><i class="bi bi-calendar-plus"></i> <?= et('Reserva en segundos') ?></span>
    <h2 class="cl-h2 mt-2 mb-4" style="color:#fff"><?= et('Agenda tu cita') ?></h2>
    <div id="agendar" style="max-width:640px;margin:0 auto">
        <?php include __DIR__ . '/agenda_reservar_render.php'; ?>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/publico_footer.php'; ?>
