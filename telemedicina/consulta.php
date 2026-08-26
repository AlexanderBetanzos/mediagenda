<?php
/**
 * Videoconsulta, lado del paciente.
 *
 * PÁGINA PÚBLICA: sin sesión. Mismo trato que agenda/confirmar.php — el token
 * de la cita es la credencial. Pedirle al paciente que recuerde una contraseña
 * cinco minutos antes de su consulta es la forma más segura de que no entre.
 *
 * El token identifica la cita Y el consultorio: el tenant se fija desde ahí.
 */
require_once __DIR__ . '/../includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
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

tenant_forzar((int) $c['consultorio_id']);

// El consultorio pudo bajar de plan, o la cita pudo cambiar a presencial,
// después de que salió el recordatorio con este enlace.
if ($c['modalidad'] !== 'en_linea' || !modulo_activo('telemedicina')) {
    $motivo = 'presencial';
} elseif (in_array($c['estado'], ['cancelada', 'no_asistio'], true)) {
    $motivo = 'cancelada';
} else {
    $motivo = cita_ventana_sala($c['fecha'], $c['hora'], (int) ($c['duracion'] ?: 30));
}

$entrar = $motivo === 'abierta';
if ($entrar) {
    $sala      = cita_sala((int) $c['id']);
    $nombre    = $c['pac_nombre'] . ' ' . $c['pac_ape'];
    $asunto    = t('Consulta con') . ' ' . $c['med_nombre'];
    $volver    = url_absoluta('/agenda/confirmar?t=' . $token);
    $moderador = false;
}

$titulo = t('Tu videoconsulta');
include __DIR__ . '/../includes/publico_header.php';
?>

<?php if ($entrar): ?>

    <?php /* publico_header.php encierra todo en .pub-wrap a 640px, que sirve
             para un ticket de cita pero ahoga una videollamada. */ ?>
    <style>.pub-wrap{max-width:1100px}</style>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="fw-semibold"><i class="bi bi-camera-video text-brand"></i> <?= et('Consulta con') ?> <?= e($c['med_nombre']) ?></div>
            <div class="text-muted small">
                <?= e($c['especialidad'] ?: t('Médico')) ?> · <?= fmt_fecha($c['fecha']) ?> <?= fmt_hora($c['hora']) ?>
            </div>
        </div>
        <div class="text-muted small">
            <i class="bi bi-shield-lock"></i> <?= et('Enlace privado, solo para ti') ?>
        </div>
    </div>

    <?php include __DIR__ . '/_jitsi.php'; ?>

    <p class="text-muted small mt-3 mb-0">
        <i class="bi bi-info-circle"></i>
        <?= et('Si no te ve o no te escucha, permite el acceso a cámara y micrófono en tu navegador. Con audífonos se oye mejor.') ?>
    </p>

<?php else: ?>

    <?php
    /* No se puede entrar: se explica por qué, sin culpar al paciente. */
    [$ico, $tit, $sub] = [
        'antes' => ['bi-clock',
            t('Todavía no es hora'),
            t('Tu videoconsulta abre 15 minutos antes. Vuelve a entrar a este mismo enlace un poco antes de la hora.')],
        'cerrada' => ['bi-clock-history',
            t('Esta consulta ya terminó'),
            t('El horario de esta cita ya pasó. Si necesitas ver a tu médico otra vez, agenda una cita nueva.')],
        'cancelada' => ['bi-x-lg',
            t('Esta cita fue cancelada'),
            t('Si crees que es un error, comunícate con el consultorio.')],
        'presencial' => ['bi-hospital',
            t('Esta cita es presencial'),
            t('Tu consulta no es por video: te esperamos en el consultorio.')],
    ][$motivo] ?? ['bi-question-lg', t('Enlace no disponible'), ''];
    ?>
    <div class="card pub-card">
        <div class="card-body p-4 p-sm-5 text-center">
            <div style="max-width:440px;margin:0 auto">
                <span class="d-inline-flex align-items-center justify-content-center mb-3"
                      style="width:64px;height:64px;border-radius:50%;background:rgba(127,127,127,.14)">
                    <i class="bi <?= $ico ?> text-secondary" style="font-size:2rem"></i>
                </span>
                <h1 class="h4"><?= e($tit) ?></h1>
                <p class="text-muted"><?= e($sub) ?></p>

                <div class="border rounded p-3 text-start small mt-4">
                    <div class="text-muted text-uppercase" style="font-size:.72rem;letter-spacing:.06em"><?= et('Tu cita') ?></div>
                    <div class="fw-semibold"><?= fmt_fecha($c['fecha']) ?> · <?= fmt_hora($c['hora']) ?></div>
                    <div class="text-muted"><?= e($c['med_nombre']) ?><?= $c['especialidad'] ? ' · ' . e($c['especialidad']) : '' ?></div>
                </div>

                <?php if ($motivo === 'antes'): ?>
                <a href="" class="btn btn-primary mt-4" onclick="location.reload();return false">
                    <i class="bi bi-arrow-clockwise"></i> <?= et('Ya es la hora, entrar') ?>
                </a>
                <?php endif; ?>
                <a href="<?= e(url_absoluta('/agenda/confirmar?t=' . $token)) ?>" class="btn btn-link mt-2 d-block">
                    <?= et('Ver los datos de mi cita') ?>
                </a>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
