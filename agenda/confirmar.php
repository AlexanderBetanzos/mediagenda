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
$acento  = color_acento();
$reservar = agenda_online_activa();
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= et('Tu cita') ?> · <?= e(marca_nombre()) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body{background:#eef1f5;font-family:Inter,system-ui,sans-serif;color:#1f2d3d}
        .wrap{max-width:520px;margin:0 auto;padding:2rem 1rem}
        .card{border:0;border-radius:18px;box-shadow:0 8px 30px rgba(15,39,71,.10)}
        .acento{color:<?= $acento ?>}
        .dato{background:#f6f8fb;border-radius:12px;padding:1rem 1.25rem}
        .btn-lg{padding:.85rem 1rem;font-weight:600}
    </style>
</head>
<body>
<div class="wrap">
    <div class="text-center mb-3">
        <?php if (cfg('marca_logo')): ?>
            <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e(marca_nombre()) ?>" style="max-height:56px">
        <?php else: ?>
            <i class="bi bi-heart-pulse-fill acento" style="font-size:2.4rem"></i>
        <?php endif; ?>
        <div class="h5 mt-2 mb-0"><?= e(marca_nombre()) ?></div>
    </div>

    <div class="card">
        <div class="card-body p-4">

            <?php if ($hecho === 'confirmada'): ?>
                <div class="text-center mb-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
                    <h1 class="h4 mt-2"><?= et('¡Cita confirmada!') ?></h1>
                    <p class="text-muted mb-0"><?= et('Te esperamos. Gracias por avisarnos.') ?></p>
                </div>
            <?php elseif ($hecho === 'cancelada'): ?>
                <div class="text-center mb-3">
                    <i class="bi bi-x-circle-fill text-secondary" style="font-size:3rem"></i>
                    <h1 class="h4 mt-2"><?= et('Cita cancelada') ?></h1>
                    <p class="text-muted mb-0"><?= et('Gracias por avisar a tiempo: así podemos ofrecer ese horario a alguien más.') ?></p>
                </div>
            <?php elseif ($hecho === 'pasada'): ?>
                <div class="alert alert-warning"><?= et('Esta cita ya pasó.') ?></div>
            <?php else: ?>
                <h1 class="h4 mb-1"><?= et('Hola') ?>, <?= e($c['pac_nombre']) ?></h1>
                <p class="text-muted"><?= et('Esta es tu próxima cita:') ?></p>
            <?php endif; ?>

            <div class="dato mb-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-calendar-event acento"></i>
                    <strong class="text-capitalize"><?= e(fmt_fecha($c['fecha'])) ?></strong>
                </div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-clock acento"></i> <strong><?= fmt_hora($c['hora']) ?></strong>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-person-badge acento"></i>
                    <?= e($c['med_nombre']) ?>
                    <?php if ($c['especialidad']): ?>
                        <span class="text-muted small">· <?= e($c['especialidad']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($c['estado'] === 'confirmada' && !$hecho): ?>
                    <div class="mt-2"><span class="badge bg-success"><?= et('Confirmada') ?></span></div>
                <?php elseif ($c['estado'] === 'cancelada'): ?>
                    <div class="mt-2"><span class="badge bg-secondary"><?= et('Cancelada') ?></span></div>
                <?php endif; ?>
            </div>

            <?php if ($abierta && $c['estado'] === 'programada'): ?>
                <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="t" value="<?= e($token) ?>">
                    <button name="accion" value="confirmar" class="btn btn-success btn-lg">
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
                <a href="<?= e(agenda_online_url(tenant()['slug'] ?? '')) ?>" class="btn btn-primary btn-lg w-100 mt-2">
                    <i class="bi bi-calendar-plus"></i> <?= et('Agendar otra fecha') ?>
                </a>
            <?php endif; ?>

            <?php if (cfg('telefono')): ?>
            <p class="text-center text-muted small mb-0 mt-3">
                <?= et('¿Dudas?') ?> <a href="tel:<?= e(preg_replace('/\s+/', '', cfg('telefono'))) ?>"><?= e(cfg('telefono')) ?></a>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-center text-muted small mt-3 mb-0"><?= e(marca_nombre()) ?></p>
</div>
</body>
</html>
