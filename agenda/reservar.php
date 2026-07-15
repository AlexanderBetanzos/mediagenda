<?php
/**
 * Agenda en línea (página propia): el paciente pide cita solo.
 *   /agenda/reservar?c=<slug-del-consultorio>
 *
 * Es un envoltorio delgado: toda la lógica y el formulario viven en
 * includes/agenda_reservar_logica.php y _render.php, que también usa el
 * micrositio del consultorio (/c/<slug>) para agendar sin salir de su página.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/correo.php';

$slug = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($_GET['c'] ?? $_POST['c'] ?? ''));
$con  = $slug !== '' ? consultorio_publico($slug) : null;
if (!$con) { http_response_code(404); die('Consultorio no encontrado.'); }

tenant_forzar((int) $con['id']);

if (!agenda_online_activa()) {
    http_response_code(403);
    die('Este consultorio no tiene la agenda en línea activada.');
}

require __DIR__ . '/../includes/agenda_reservar_logica.php';

$agAccion = BASE_URL . '/agenda/reservar';
$titulo   = t('Agendar cita');
include __DIR__ . '/../includes/publico_header.php';
?>
<?php if (!$agHecho): ?>
<div class="text-center mb-4">
    <h1 class="h3 fw-bold mb-1"><?= et('Agenda tu cita') ?></h1>
    <p class="text-muted mb-0"><?= et('Elige el día y la hora que mejor te queden. Sin llamadas y sin esperas.') ?></p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/agenda_reservar_render.php'; ?>

<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
