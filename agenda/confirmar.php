<?php
/**
 * Confirmar o cancelar una cita desde el enlace del recordatorio.
 *
 * PÁGINA PÚBLICA: sin sesión. El token de la cita es la credencial —es
 * aleatorio de 128 bits y vive en la propia cita—, así que el paciente no tiene
 * que recordar ninguna contraseña. Es el mismo trato que dan las aerolíneas al
 * check-in: si el paciente tiene que iniciar sesión para decir "sí voy", no lo
 * hace, y la cita se pierde igual.
 *
 * El token identifica la cita Y el consultorio: el tenant se fija desde ahí.
 */
require_once __DIR__ . '/../includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? $_POST['t'] ?? ''));
if (strlen($token) !== 32) { http_response_code(404); die('Enlace no válido.'); }

$st = db()->prepare(
    'SELECT c.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape,
            u.nombre AS med_nombre, u.especialidad
     FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     JOIN usuarios  u ON u.id = c.medico_id
     WHERE c.token = ?'
);
$st->execute([$token]);
$c = $st->fetch();
if (!$c) { http_response_code(404); die('Esta cita ya no existe.'); }

// El tenant sale de la cita: así cfg(), la marca y el logo son los del
// consultorio correcto, aunque no haya sesión.
tenant_forzar((int) $c['consultorio_id']);

$hecho = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $pasada = strtotime($c['fecha'] . ' ' . $c['hora']) < time();

    if ($pasada) {
        $hecho = 'pasada';
    } elseif ($accion === 'confirmar' && $c['estado'] === 'programada') {
        db()->prepare("UPDATE citas SET estado = 'confirmada', confirmada_en = NOW()
                       WHERE id = ? AND consultorio_id = ?")
            ->execute([(int) $c['id'], (int) $c['consultorio_id']]);
        auditar('cita_confirmada_paciente', 'cita', (int) $c['id'], null, (int) $c['consultorio_id'],
                ['nombre' => $c['pac_nombre'] . ' ' . $c['pac_ape']]);
        $hecho = 'confirmada';
        $c['estado'] = 'confirmada';
    } elseif ($accion === 'cancelar' && in_array($c['estado'], ['programada', 'confirmada'], true)) {
        // Una cancelación a tiempo LIBERA el hueco: ese es todo el punto.
        db()->prepare("UPDATE citas SET estado = 'cancelada', cancelada_en = NOW(),
                              cancelada_por = 'paciente'
                       WHERE id = ? AND consultorio_id = ?")
            ->execute([(int) $c['id'], (int) $c['consultorio_id']]);
        auditar('cita_cancelada_paciente', 'cita', (int) $c['id'], null, (int) $c['consultorio_id'],
                ['nombre' => $c['pac_nombre'] . ' ' . $c['pac_ape']]);
        $hecho = 'cancelada';
        $c['estado'] = 'cancelada';
    }
}

$pasada  = strtotime($c['fecha'] . ' ' . $c['hora']) < time();
$abierta = in_array($c['estado'], ['programada', 'confirmada'], true) && !$pasada;
$reservar = agenda_online_activa();

$titulo = t('Tu cita');
include __DIR__ . '/../includes/publico_header.php';
?>
<?php
/* Encabezado (ícono + título) y la insignia del ticket según el estado. */
$cancelada = $hecho === 'cancelada' || (!$hecho && $c['estado'] === 'cancelada');
if ($hecho === 'confirmada') {
    $ico = 'bi-check-lg'; $icoBg = '#e7f7ee'; $icoCol = 'text-success';
    $encTit = t('¡Cita confirmada!'); $encSub = t('Te esperamos. Gracias por avisarnos.');
} elseif ($cancelada) {
    $ico = 'bi-x-lg'; $icoBg = 'rgba(127,127,127,.14)'; $icoCol = 'text-secondary';
    $encTit = t('Cita cancelada'); $encSub = t('Gracias por avisar a tiempo: así podemos ofrecer ese horario a alguien más.');
} elseif ($hecho === 'pasada') {
    $ico = 'bi-clock-history'; $icoBg = 'rgba(127,127,127,.14)'; $icoCol = 'text-secondary';
    $encTit = t('Esta cita ya pasó'); $encSub = '';
} else {
    $ico = 'bi-calendar-check'; $icoBg = 'color-mix(in srgb, var(--brand) 12%, transparent)'; $icoCol = 'text-brand';
    $encTit = t('Hola') . ', ' . $c['pac_nombre']; $encSub = t('Esta es tu próxima cita:');
}
$badgeTxt = $cancelada ? t('Cancelada') : ($c['estado'] === 'confirmada' || $hecho === 'confirmada' ? t('Confirmada') : t('Agendada'));
$badgeCls = $cancelada ? 'ag-badge-off' : 'ag-badge-ok';
?>
<div class="card pub-card">
    <div class="card-body p-4 p-sm-5">
        <div style="max-width:440px;margin:0 auto">
            <div class="text-center">
                <span class="d-inline-flex align-items-center justify-content-center"
                      style="width:64px;height:64px;border-radius:50%;background:<?= $icoBg ?>">
                    <i class="bi <?= $ico ?> <?= $icoCol ?>" style="font-size:2rem"></i>
                </span>
                <h1 class="h4 fw-bold mt-3 mb-1"><?= e($encTit) ?></h1>
                <?php if ($encSub): ?><p class="text-muted small mb-4"><?= e($encSub) ?></p><?php endif; ?>
            </div>

            <div class="ag-ticket mb-4">
                <div class="ag-ticket-top">
                    <div>
                        <div class="ag-ticket-lbl"><?= et('Folio') ?></div>
                        <div class="ag-ticket-folio"><?= e(cita_folio((int) $c['id'])) ?></div>
                    </div>
                    <span class="ag-badge <?= $badgeCls ?>"><?= e($badgeTxt) ?></span>
                </div>
                <div class="ag-ticket-row">
                    <div class="ag-ticket-col">
                        <div class="ag-ticket-lbl"><i class="bi bi-calendar-event"></i> <?= et('Fecha') ?></div>
                        <div class="ag-ticket-val text-capitalize"><?= e(fmt_fecha($c['fecha'])) ?></div>
                    </div>
                    <div class="ag-ticket-col">
                        <div class="ag-ticket-lbl"><i class="bi bi-clock"></i> <?= et('Hora') ?></div>
                        <div class="ag-ticket-val"><?= fmt_hora($c['hora']) ?></div>
                    </div>
                </div>
                <div class="ag-ticket-med">
                    <div class="ag-ticket-lbl"><i class="bi bi-person-badge"></i> <?= et('Te atiende') ?></div>
                    <div class="ag-ticket-val" style="font-size:1rem">
                        <?= e($c['med_nombre']) ?><?php if ($c['especialidad']): ?> <span class="text-muted fw-normal small">· <?= e($c['especialidad']) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($abierta && $c['estado'] === 'programada'): ?>
                <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="t" value="<?= e($token) ?>">
                    <button name="accion" value="confirmar" class="btn btn-success btn-lg py-3 fw-semibold">
                        <i class="bi bi-check-lg"></i> <?= et('Sí, ahí estaré') ?>
                    </button>
                    <button name="accion" value="cancelar" class="btn btn-outline-secondary"
                            onclick="return confirm('<?= e(t('¿Seguro que quieres cancelar tu cita?')) ?>')">
                        <?= et('No puedo asistir') ?>
                    </button>
                </form>
            <?php elseif ($abierta && $c['estado'] === 'confirmada'): ?>
                <form method="post" class="d-grid">
                    <input type="hidden" name="t" value="<?= e($token) ?>">
                    <button name="accion" value="cancelar" class="btn btn-outline-secondary"
                            onclick="return confirm('<?= e(t('¿Seguro que quieres cancelar tu cita?')) ?>')">
                        <?= et('Ya no puedo asistir: cancelar') ?>
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($c['estado'] === 'cancelada' && $reservar): ?>
                <a href="<?= e(agenda_online_url(tenant()['slug'] ?? '')) ?>" class="btn btn-primary btn-lg w-100 mt-2 py-3 fw-semibold">
                    <i class="bi bi-calendar-plus"></i> <?= et('Agendar otra fecha') ?>
                </a>
            <?php endif; ?>

            <div class="text-center">
                <a href="<?= BASE_URL ?>/c/<?= e(tenant()['slug'] ?? '') ?>" class="d-inline-block mt-3 small text-decoration-none">
                    <i class="bi bi-house"></i> <?= et('Ir al inicio') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
