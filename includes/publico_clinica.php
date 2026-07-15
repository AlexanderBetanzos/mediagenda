<?php
/**
 * Micrositio público de un consultorio: su propia página por slug.
 *   /?c=<slug>   (o el bonito /c/<slug> vía .htaccess)
 *
 * Igual que en GymOS: la raíz del dominio es la landing del PRODUCTO
 * (MediAgenda); cuando llega el slug de un consultorio, se muestra SU página,
 * con su marca, sus servicios, sus médicos y su contacto — nada de MediAgenda.
 *
 * Espera $con (fila de consultorios) ya resuelta por index.php.
 */

// Todo lo que sigue (cfg, marca, logo, color) es de ESTE consultorio.
tenant_forzar((int) $con['id']);

$marca   = marca_nombre();
$lema    = cfg('marca_lema');
$dir     = cfg('direccion');
$tel     = cfg('telefono');
$correo  = cfg('email');
$reservar = agenda_online_activa();
$horario  = horario_atencion_texto((int) $con['id']);

// Reserva integrada: la lógica corre AQUÍ, antes de imprimir nada, porque un
// POST crea el paciente y la cita. El formulario se pinta más abajo, en la
// sección #agendar, sin sacar al paciente de la página del consultorio.
if ($reservar) {
    require_once __DIR__ . '/correo.php';
    $agAccion = BASE_URL . '/c/' . $con['slug'];
    require __DIR__ . '/agenda_reservar_logica.php';
}

// Servicios: se muestran los del catálogo, agrupados por categoría. Es la carta
// de precios que el paciente quiere ver antes de llamar.
$servicios = [];
try {
    $st = db()->prepare(
        'SELECT nombre, categoria, precio FROM servicios
         WHERE consultorio_id = ? AND activo = 1 ORDER BY categoria, nombre'
    );
    $st->execute([(int) $con['id']]);
    foreach ($st->fetchAll() as $s) {
        $servicios[$s['categoria'] ?: 'Servicios'][] = $s;
    }
} catch (Throwable $e) { /* módulo de servicios no instalado: se omite */ }

// Médicos que atienden, con su especialidad y foto.
$medicos = [];
try {
    $st = db()->prepare(
        "SELECT id, nombre, especialidad FROM usuarios
         WHERE consultorio_id = ? AND activo = 1 AND rol IN ('medico','admin')
         ORDER BY nombre"
    );
    $st->execute([(int) $con['id']]);
    $medicos = $st->fetchAll();
} catch (Throwable $e) { /* ignora */ }

// WhatsApp del consultorio (si tiene teléfono).
$wa = '';
if ($tel) {
    $num = preg_replace('/\D+/', '', $tel);
    if (strlen($num) <= 10) $num = (cfg('pais_lada', '52')) . $num;   // teléfono local -> con lada
    $wa = 'https://wa.me/' . $num . '?text=' . rawurlencode(t('Hola') . ', ' . t('me interesa información de') . ' ' . $marca . '.');
}

$titulo = $marca;
$indexable = true;   // esta página SÍ debe salir en Google
include __DIR__ . '/publico_header.php';   // navbar con la marca del consultorio
?>

<style>
    /* El micrositio manda a la cáscara a ancho completo (la cáscara centra en
       640px, bueno para formularios; aquí queremos una página de verdad). */
    .pub-wrap { max-width: none; padding: 0 0 3rem; }
    .cl-hero { background: linear-gradient(135deg, var(--brand-dark), var(--brand) 65%,
               color-mix(in srgb, var(--brand) 60%, #38a3e8) 130%); color: #fff; padding: 4rem 1rem 4.5rem; }
    .cl-hero .display-5 { font-weight: 800; }
    .cl-sec { max-width: 960px; margin: 0 auto; padding: 3rem 1rem; }
    .cl-serv { border: 1px solid rgba(127,127,127,.18); border-radius: 14px; padding: 1rem 1.25rem;
               display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
    .cl-serv .precio { font-weight: 700; color: var(--brand); white-space: nowrap; }
    .cl-med { text-align: center; }
    .cl-med-foto { width: 92px; height: 92px; border-radius: 50%; object-fit: cover; margin: 0 auto .6rem;
                   display: flex; align-items: center; justify-content: center; font-size: 2rem; font-weight: 700;
                   background: color-mix(in srgb, var(--brand) 15%, transparent); color: var(--brand); }
    .cl-cta-fixed { position: sticky; bottom: 0; }
</style>

<!-- Portada -->
<div class="cl-hero text-center">
    <?php if (cfg('marca_logo')): ?>
        <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>"
             style="max-height:76px;width:auto;margin-bottom:1rem;background:#fff;border-radius:12px;padding:.4rem .7rem">
    <?php endif; ?>
    <h1 class="display-5 mb-2"><?= e($marca) ?></h1>
    <?php if ($lema): ?><p class="lead mb-4" style="opacity:.92"><?= e($lema) ?></p><?php endif; ?>

    <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php if ($reservar): ?>
            <a href="#agendar" class="btn btn-light btn-lg fw-semibold px-4">
                <i class="bi bi-calendar-plus"></i> <?= et('Agendar cita en línea') ?>
            </a>
        <?php endif; ?>
        <?php if ($wa): ?>
            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-light btn-lg px-4">
                <i class="bi bi-whatsapp"></i> WhatsApp
            </a>
        <?php elseif ($tel): ?>
            <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="btn btn-outline-light btn-lg px-4">
                <i class="bi bi-telephone"></i> <?= e($tel) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Servicios -->
<?php if ($servicios): ?>
<div class="cl-sec">
    <h2 class="h3 fw-bold text-center mb-4"><?= et('Nuestros servicios') ?></h2>
    <?php foreach ($servicios as $categoria => $items): ?>
        <div class="text-uppercase small fw-semibold text-muted mt-4 mb-2" style="letter-spacing:.05em">
            <?= e($categoria) ?>
        </div>
        <div class="row g-2">
            <?php foreach ($items as $s): ?>
            <div class="col-md-6">
                <div class="cl-serv">
                    <span><?= e($s['nombre']) ?></span>
                    <?php if ((float) $s['precio'] > 0): ?>
                        <span class="precio"><?= fmt_money($s['precio']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Médicos -->
<?php if ($medicos): ?>
<div class="cl-sec pt-0">
    <h2 class="h3 fw-bold text-center mb-4"><?= et('Nuestro equipo') ?></h2>
    <div class="row g-4 justify-content-center">
        <?php foreach ($medicos as $m):
            $ini = strtoupper(mb_substr($m['nombre'], 0, 1)); ?>
        <div class="col-6 col-md-3 cl-med">
            <div class="cl-med-foto"><?= e($ini) ?></div>
            <div class="fw-semibold"><?= e($m['nombre']) ?></div>
            <?php if ($m['especialidad']): ?>
                <div class="small text-muted"><?= e($m['especialidad']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Contacto y horario -->
<div class="cl-sec pt-0">
    <div class="row g-4">
        <div class="col-md-6">
            <h2 class="h4 fw-bold mb-3"><?= et('Contacto') ?></h2>
            <ul class="list-unstyled">
                <?php if ($dir): ?>
                <li class="mb-2"><i class="bi bi-geo-alt text-brand me-2"></i>
                    <a href="https://maps.google.com/?q=<?= rawurlencode($dir) ?>" target="_blank" rel="noopener"
                       class="text-decoration-none"><?= e($dir) ?></a>
                </li>
                <?php endif; ?>
                <?php if ($tel): ?>
                <li class="mb-2"><i class="bi bi-telephone text-brand me-2"></i>
                    <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="text-decoration-none"><?= e($tel) ?></a>
                </li>
                <?php endif; ?>
                <?php if ($correo): ?>
                <li class="mb-2"><i class="bi bi-envelope text-brand me-2"></i>
                    <a href="mailto:<?= e($correo) ?>" class="text-decoration-none"><?= e($correo) ?></a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <?php if ($horario): ?>
        <div class="col-md-6">
            <h2 class="h4 fw-bold mb-3"><?= et('Horario de atención') ?></h2>
            <ul class="list-unstyled">
                <?php foreach ($horario as $h): ?>
                <li class="d-flex justify-content-between border-bottom border-opacity-10 py-2">
                    <span class="fw-semibold"><?= e($h['dias']) ?></span>
                    <span class="text-muted"><?= e($h['horas']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($reservar): ?>
<!-- Agendar: el formulario de reserva, en la misma página -->
<div id="agendar" class="cl-sec pt-0" style="max-width:640px">
    <h2 class="h3 fw-bold text-center mb-1"><?= et('Agenda tu cita') ?></h2>
    <p class="text-muted text-center mb-4"><?= et('Elige el día y la hora que mejor te queden. Sin llamadas y sin esperas.') ?></p>
    <?php include __DIR__ . '/agenda_reservar_render.php'; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/publico_footer.php'; ?>
