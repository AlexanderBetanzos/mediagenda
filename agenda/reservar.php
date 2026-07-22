<?php
/**
 * Agenda en línea (página propia): el paciente pide cita solo.
 *   /agenda/reservar?c=<slug-del-consultorio>
 *   /agenda/reservar?c=<slug>&ok=<token>   -> pantalla de "listo" tras agendar
 *
 * El flujo usa Post-Redirect-Get: al agendar (POST) se redirige a ?ok=<token>
 * (GET), de modo que REFRESCAR la página no reenvía el formulario ni duplica la
 * cita. Antes el formulario iba embebido en el micrositio y cada refresh
 * reenviaba el POST.
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

$agAccion = BASE_URL . '/agenda/reservar';

/* ── Pantalla de "listo" (GET, tras el redirect) ─────────────────────────── */
$okToken = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['ok'] ?? ''));
if ($okToken !== '') {
    $st = db()->prepare(
        'SELECT c.*, u.nombre AS med_nombre FROM citas c
         LEFT JOIN usuarios u ON u.id = c.medico_id
         WHERE c.token = ? AND c.consultorio_id = ?'
    );
    $st->execute([$okToken, (int) $con['id']]);
    $cita = $st->fetch();
    if (!$cita) { redirect('/agenda/reservar?c=' . rawurlencode($slug)); }

    // ¿Hay un cobro pendiente ligado a esta cita? -> ofrecer pago en línea.
    $pago = null;
    try {
        require_once __DIR__ . '/../includes/cobros.php';
        $q = db()->prepare("SELECT * FROM cobros WHERE cita_id = ? AND consultorio_id = ? AND estado = 'pendiente' ORDER BY id DESC LIMIT 1");
        $q->execute([(int) $cita['id'], (int) $con['id']]);
        if ($cb = $q->fetch()) { $pago = ['url' => cobro_url($cb), 'monto' => (float) $cb['monto']]; }
    } catch (Throwable $e) {}
    $precioCita = round((float) cfg('agenda_online_precio', '0'), 2);

    $titulo = t('Cita agendada');
    include __DIR__ . '/../includes/publico_header.php';
    ?>
    <div class="pub-card card">
        <div class="card-body p-4 p-sm-5 text-center">
            <i class="bi bi-check-circle-fill text-success" style="font-size:3.4rem"></i>
            <h1 class="h4 mt-3"><?= et('¡Listo, tu cita quedó agendada!') ?></h1>
            <div class="pub-dato d-inline-block text-start my-3 px-4 py-3" style="border-radius:14px;background:rgba(127,127,127,.08)">
                <div><i class="bi bi-hash text-brand"></i> <?= et('Folio') ?>: <strong class="font-monospace"><?= e(cita_folio((int) $cita['id'])) ?></strong></div>
                <div class="mt-1"><i class="bi bi-calendar-event text-brand"></i> <strong class="text-capitalize"><?= e(fmt_fecha($cita['fecha'])) ?></strong> · <?= fmt_hora($cita['hora']) ?></div>
                <?php if ($cita['med_nombre']): ?><div class="mt-1"><i class="bi bi-person-badge text-brand"></i> <?= e($cita['med_nombre']) ?></div><?php endif; ?>
            </div>

            <?php if ($pago): ?>
                <div class="alert alert-info py-2"><?= et('Costo de la cita') ?>: <strong><?= fmt_money($pago['monto']) ?></strong>. <?= et('¿Cómo prefieres pagar?') ?></div>
                <a href="<?= e($pago['url']) ?>" class="btn btn-primary btn-lg w-100 mb-2 py-3 fw-semibold"><i class="bi bi-credit-card"></i> <?= et('Pagar en línea ahora') ?></a>
                <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($cita['token']) ?>" class="btn btn-outline-secondary w-100"><i class="bi bi-cash-coin"></i> <?= et('Pagar en el consultorio') ?></a>
            <?php elseif ($precioCita > 0): ?>
                <div class="alert alert-info py-2"><i class="bi bi-cash-coin"></i> <?= et('Costo de la cita') ?>: <strong><?= fmt_money($precioCita) ?></strong>. <?= et('Lo pagas en el consultorio al llegar.') ?></div>
                <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($cita['token']) ?>" class="btn btn-outline-secondary"><?= et('Ver o cancelar mi cita') ?></a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($cita['token']) ?>" class="btn btn-outline-secondary"><?= et('Ver o cancelar mi cita') ?></a>
            <?php endif; ?>

            <p class="text-muted small mt-3 mb-0"><?= et('Te enviamos el detalle por correo. Si no puedes venir, avísanos desde ese enlace.') ?></p>
            <a href="<?= BASE_URL ?>/c/<?= e($slug) ?>" class="d-inline-block mt-3 small text-decoration-none">&larr; <?= et('Volver al inicio') ?></a>
        </div>
    </div>
    <?php
    include __DIR__ . '/../includes/publico_footer.php';
    exit;
}

/* ── Formulario de reserva ────────────────────────────────────────────────
   La lógica corre ANTES de imprimir: en un POST válido redirige a ?ok=token
   (arriba), así que si llegamos aquí es un GET o un POST con error. */
require __DIR__ . '/../includes/agenda_reservar_logica.php';

$titulo = t('Agendar cita');
include __DIR__ . '/../includes/publico_header.php';
?>
<div class="text-center mb-4">
    <h1 class="h3 fw-bold mb-1"><?= et('Agenda tu cita') ?></h1>
    <p class="text-muted mb-0"><?= et('Elige el médico, el día y la hora que mejor te queden.') ?></p>
</div>

<?php include __DIR__ . '/../includes/agenda_reservar_render.php'; ?>

<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
