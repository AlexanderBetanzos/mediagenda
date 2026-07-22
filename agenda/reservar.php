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
        <div class="card-body p-4 p-sm-5">
            <div style="max-width:440px;margin:0 auto">
                <div class="text-center">
                    <span class="d-inline-flex align-items-center justify-content-center"
                          style="width:64px;height:64px;border-radius:50%;background:#e7f7ee">
                        <i class="bi bi-check-lg text-success" style="font-size:2rem"></i>
                    </span>
                    <h1 class="h4 fw-bold mt-3 mb-1"><?= et('¡Listo, tu cita quedó agendada!') ?></h1>
                    <p class="text-muted small mb-4"><?= et('Te enviamos el detalle por correo.') ?></p>
                </div>

                <!-- Ticket de la cita -->
                <div class="ag-ticket mb-4">
                    <div class="ag-ticket-top">
                        <div>
                            <div class="ag-ticket-lbl"><?= et('Folio') ?></div>
                            <div class="ag-ticket-folio"><?= e(cita_folio((int) $cita['id'])) ?></div>
                        </div>
                        <span class="ag-badge"><i class="bi bi-check-lg"></i> <?= et('Confirmada') ?></span>
                    </div>
                    <div class="ag-ticket-row">
                        <div class="ag-ticket-col">
                            <div class="ag-ticket-lbl"><i class="bi bi-calendar-event"></i> <?= et('Fecha') ?></div>
                            <div class="ag-ticket-val text-capitalize"><?= e(fmt_fecha($cita['fecha'])) ?></div>
                        </div>
                        <div class="ag-ticket-col">
                            <div class="ag-ticket-lbl"><i class="bi bi-clock"></i> <?= et('Hora') ?></div>
                            <div class="ag-ticket-val"><?= fmt_hora($cita['hora']) ?></div>
                        </div>
                    </div>
                    <?php if ($cita['med_nombre']): ?>
                    <div class="ag-ticket-med">
                        <div class="ag-ticket-lbl"><i class="bi bi-person-badge"></i> <?= et('Te atiende') ?></div>
                        <div class="ag-ticket-val" style="font-size:1rem"><?= e($cita['med_nombre']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="d-grid gap-2">
                    <?php if ($pago): ?>
                        <div class="alert alert-info py-2 mb-1 text-center"><?= et('Costo de la cita') ?>: <strong><?= fmt_money($pago['monto']) ?></strong>. <?= et('¿Cómo prefieres pagar?') ?></div>
                        <a href="<?= e($pago['url']) ?>" class="btn btn-primary btn-lg py-3 fw-semibold"><i class="bi bi-credit-card"></i> <?= et('Pagar en línea ahora') ?></a>
                        <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($cita['token']) ?>" class="btn btn-outline-secondary"><i class="bi bi-cash-coin"></i> <?= et('Pagar en el consultorio') ?></a>
                    <?php elseif ($precioCita > 0): ?>
                        <div class="alert alert-info py-2 mb-1 text-center"><i class="bi bi-cash-coin"></i> <?= et('Costo de la cita') ?>: <strong><?= fmt_money($precioCita) ?></strong>. <?= et('Lo pagas en el consultorio al llegar.') ?></div>
                        <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($cita['token']) ?>" class="btn btn-outline-secondary"><i class="bi bi-calendar-check"></i> <?= et('Ver o cancelar mi cita') ?></a>
                    <?php else: ?>
                        <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($cita['token']) ?>" class="btn btn-outline-secondary"><i class="bi bi-calendar-check"></i> <?= et('Ver o cancelar mi cita') ?></a>
                    <?php endif; ?>
                </div>

                <p class="text-muted small text-center mt-3 mb-0"><?= et('Si no puedes venir, avísanos desde ese enlace.') ?></p>
                <div class="text-center">
                    <a href="<?= BASE_URL ?>/c/<?= e($slug) ?>" class="d-inline-block mt-3 small text-decoration-none">&larr; <?= et('Volver al inicio') ?></a>
                </div>
            </div>
        </div>
    </div>

    <style>
        .ag-ticket { border:1px solid rgba(127,127,127,.18); border-radius:16px; overflow:hidden;
                     background:color-mix(in srgb, var(--brand, #2563eb) 4%, transparent); }
        .ag-ticket-top { display:flex; align-items:center; justify-content:space-between; gap:12px;
                         padding:14px 18px; border-bottom:1px solid rgba(127,127,127,.14); }
        .ag-ticket-row { display:flex; }
        .ag-ticket-col { flex:1; padding:14px 18px; }
        .ag-ticket-col + .ag-ticket-col { border-left:1px solid rgba(127,127,127,.14); }
        .ag-ticket-med { padding:14px 18px; border-top:1px solid rgba(127,127,127,.14); }
        .ag-ticket-lbl { font-size:.7rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:#94a3b8; }
        .ag-ticket-val { font-size:1.05rem; font-weight:800; color:inherit; margin-top:3px; }
        .ag-ticket-folio { font-family:ui-monospace,Menlo,Consolas,monospace; font-weight:800; font-size:1rem; margin-top:2px; }
        .ag-badge { display:inline-flex; align-items:center; gap:5px; white-space:nowrap; background:#e7f7ee; color:#1a7f47;
                    font-size:.78rem; font-weight:700; padding:5px 12px; border-radius:999px; }
    </style>
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
