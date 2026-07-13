<?php
/**
 * Agenda en línea: página PÚBLICA donde un paciente pide cita solo.
 *   /agenda/reservar?c=<slug-del-consultorio>
 *
 * Sin sesión y sin cuenta: pedirle a alguien que se registre antes de agendar
 * es la forma más segura de que no agende. Con nombre y teléfono basta; el
 * consultorio ya completará el resto en la ficha.
 *
 * Los huecos que se ofrecen son REALES: salen del horario que el médico
 * configuró, menos sus bloqueos y menos las citas ya tomadas (agenda_huecos()).
 * Ofrecer un hueco que no existe es peor que no ofrecer nada.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/correo.php';

$slug = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($_GET['c'] ?? $_POST['c'] ?? ''));
if ($slug === '') { http_response_code(404); die('Consultorio no encontrado.'); }

$st = db()->prepare('SELECT * FROM consultorios WHERE slug = ?');
$st->execute([$slug]);
$con = $st->fetch();
if (!$con) { http_response_code(404); die('Consultorio no encontrado.'); }

// Todo lo que sigue (cfg, marca, huecos) es de ESTE consultorio.
tenant_forzar((int) $con['id']);

if (!agenda_online_activa()) {
    http_response_code(403);
    die('Este consultorio no tiene la agenda en línea activada.');
}

$duracion = max(10, (int) cfg('agenda_online_duracion', '30'));
$maxDias  = max(1, (int) cfg('agenda_online_dias', '30'));

/* Médicos que atienden (los que tienen horario configurado: si no lo tienen,
   no hay huecos que ofrecer y no tiene sentido listarlos). */
$medicos = db()->prepare(
    "SELECT DISTINCT u.id, u.nombre, u.especialidad
     FROM usuarios u
     JOIN medico_horarios h ON h.medico_id = u.id AND h.consultorio_id = u.consultorio_id
     WHERE u.consultorio_id = ? AND u.activo = 1 AND u.rol IN ('medico','admin')
     ORDER BY u.nombre"
);
$medicos->execute([(int) $con['id']]);
$medicos = $medicos->fetchAll();

$medId = (int) ($_GET['m'] ?? $_POST['medico_id'] ?? 0);
if (!$medId && count($medicos) === 1) { $medId = (int) $medicos[0]['id']; }

$fecha = (string) ($_GET['f'] ?? $_POST['fecha'] ?? '');
if ($fecha === '' || !strtotime($fecha)) { $fecha = date('Y-m-d'); }
// Nadie reserva ayer, ni más allá de la ventana que el consultorio permite.
$fecha = max($fecha, date('Y-m-d'));
$fecha = min($fecha, date('Y-m-d', strtotime("+$maxDias days")));

$huecos = ($medId && $fecha) ? agenda_huecos($medId, $fecha, $duracion) : [];

$hecho = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reservar') {
    // Trampa para robots: un campo que un humano nunca ve ni llena.
    if (trim((string) ($_POST['website'] ?? '')) !== '') { http_response_code(400); exit; }

    $nombre    = trim((string) ($_POST['nombre'] ?? ''));
    $apellidos = trim((string) ($_POST['apellidos'] ?? ''));
    $tel       = trim((string) ($_POST['telefono'] ?? ''));
    $email     = trim((string) ($_POST['email'] ?? ''));
    $motivo    = trim((string) ($_POST['motivo'] ?? ''));
    $hora      = (string) ($_POST['hora'] ?? '');

    if ($nombre === '' || $apellidos === '' || $tel === '') {
        $error = t('Necesitamos tu nombre, apellidos y teléfono.');
    } elseif (!in_array($hora, $huecos, true)) {
        // El hueco se revalida contra la base: entre que se pintó la página y
        // el envío, alguien más pudo tomarlo. Sin esto se agendan dos pacientes
        // a la misma hora.
        $error = t('Ese horario acaba de ocuparse. Elige otro, por favor.');
        $huecos = agenda_huecos($medId, $fecha, $duracion);
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            /* ¿Ya es paciente? Se busca por teléfono (y correo, si lo dio) para
               no crear un duplicado cada vez que el mismo paciente reserva. */
            $pac = null;
            $q = $pdo->prepare(
                'SELECT id FROM pacientes
                 WHERE consultorio_id = ? AND (telefono = ? OR (email <> "" AND email = ?))
                 LIMIT 1'
            );
            $q->execute([(int) $con['id'], $tel, $email]);
            $pac = $q->fetchColumn() ?: null;

            if (!$pac) {
                $pdo->prepare(
                    'INSERT INTO pacientes (consultorio_id, nombre, apellidos, telefono, email)
                     VALUES (?,?,?,?,?)'
                )->execute([(int) $con['id'], mb_substr($nombre, 0, 120), mb_substr($apellidos, 0, 120),
                            mb_substr($tel, 0, 40), mb_substr($email, 0, 150) ?: null]);
                $pac = (int) $pdo->lastInsertId();
            }

            $token = bin2hex(random_bytes(16));
            $pdo->prepare(
                "INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion,
                                    motivo, estado, origen, token)
                 VALUES (?,?,?,?,?,?,?,'programada','online',?)"
            )->execute([(int) $con['id'], (int) $pac, $medId, $fecha, $hora, $duracion,
                        mb_substr($motivo, 0, 255) ?: null, $token]);
            $citaId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = t('No pudimos guardar tu cita. Inténtalo de nuevo.');
        }

        if (!$error) {
            auditar('cita_online', 'cita', $citaId, $fecha . ' ' . $hora, (int) $con['id'],
                    ['nombre' => $nombre . ' ' . $apellidos]);

            // Comprobante con el enlace para confirmar o cancelar después.
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $med = '';
                foreach ($medicos as $m) { if ((int) $m['id'] === $medId) $med = $m['nombre']; }
                correo_cita_agendada($email, $nombre . ' ' . $apellidos, fmt_fecha($fecha),
                                     fmt_hora($hora), $med, url_absoluta('/agenda/confirmar?t=' . $token));
            }

            $hecho = ['fecha' => $fecha, 'hora' => $hora, 'token' => $token];
        }
    }
}

$titulo = t('Agendar cita');
include __DIR__ . '/../includes/publico_header.php';
?>

<?php if ($hecho): ?>
<div class="card pub-card">
    <div class="card-body p-4 p-sm-5 text-center">
        <i class="bi bi-check-circle-fill text-success" style="font-size:3.2rem"></i>
        <h1 class="h4 mt-3"><?= et('¡Listo, tu cita quedó agendada!') ?></h1>
        <p class="text-muted">
            <strong class="text-capitalize"><?= e(fmt_fecha($hecho['fecha'])) ?></strong>
            <?= et('a las') ?> <strong><?= fmt_hora($hecho['hora']) ?></strong>
        </p>
        <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($hecho['token']) ?>" class="btn btn-outline-secondary">
            <?= et('Ver o cancelar mi cita') ?>
        </a>
        <p class="text-muted small mt-3 mb-0">
            <?= et('Te enviamos el detalle por correo. Si no puedes venir, avísanos desde ese enlace.') ?>
        </p>
    </div>
</div>

<?php else: ?>
<div class="text-center mb-4">
    <h1 class="h3 fw-bold mb-1"><?= et('Agenda tu cita') ?></h1>
    <p class="text-muted mb-0"><?= et('Elige el día y la hora que mejor te queden. Sin llamadas y sin esperas.') ?></p>
</div>

<div class="card pub-card">
    <div class="card-body p-4 p-sm-5">

        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <?php if (cfg('agenda_online_aviso')): ?>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> <?= e(cfg('agenda_online_aviso')) ?></div>
        <?php endif; ?>

        <?php if (!$medicos): ?>
            <div class="alert alert-warning mb-0">
                <?= et('Por ahora no hay horarios publicados. Comunícate con el consultorio.') ?>
            </div>
        <?php else: ?>

        <?php /* Paso 1: con quién y qué día. */ ?>
        <form method="get" class="row g-3 mb-4">
            <input type="hidden" name="c" value="<?= e($slug) ?>">
            <div class="col-sm-7">
                <label class="form-label small fw-semibold"><?= et('¿Con quién?') ?></label>
                <select name="m" class="form-select form-select-lg" onchange="this.form.submit()">
                    <option value=""><?= et('Selecciona…') ?></option>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $medId === (int) $m['id'] ? 'selected' : '' ?>>
                            <?= e($m['nombre']) ?><?= $m['especialidad'] ? ' · ' . e($m['especialidad']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-5">
                <label class="form-label small fw-semibold"><?= et('¿Qué día?') ?></label>
                <input type="date" name="f" class="form-control form-control-lg" value="<?= e($fecha) ?>"
                       min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime("+$maxDias days")) ?>"
                       onchange="this.form.submit()">
            </div>
        </form>

        <?php if (!$medId): ?>
            <p class="text-muted small mb-0"><?= et('Elige con quién quieres tu cita para ver los horarios libres.') ?></p>

        <?php elseif (!$huecos): ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-calendar-x"></i>
                <?= et('No hay horarios libres ese día. Prueba con otra fecha.') ?>
            </div>

        <?php else: ?>
        <?php /* Paso 2: el hueco y los datos de contacto. */ ?>
        <form method="post">
            <input type="hidden" name="c" value="<?= e($slug) ?>">
            <input type="hidden" name="accion" value="reservar">
            <input type="hidden" name="medico_id" value="<?= $medId ?>">
            <input type="hidden" name="fecha" value="<?= e($fecha) ?>">
            <?php /* Trampa para robots: invisible para una persona. */ ?>
            <input type="text" name="website" tabindex="-1" autocomplete="off"
                   style="position:absolute;left:-9999px" aria-hidden="true">

            <label class="form-label small fw-semibold"><?= et('Horarios libres') ?></label>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php foreach ($huecos as $i => $h): ?>
                <label class="hueco">
                    <input type="radio" name="hora" value="<?= e($h) ?>" required <?= $i === 0 ? 'checked' : '' ?>>
                    <span><?= e($h) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" required maxlength="120"
                           value="<?= e($_POST['nombre'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Apellidos') ?> *</label>
                    <input name="apellidos" class="form-control" required maxlength="120"
                           value="<?= e($_POST['apellidos'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Teléfono') ?> *</label>
                    <input name="telefono" type="tel" class="form-control" required maxlength="40"
                           value="<?= e($_POST['telefono'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Correo') ?></label>
                    <input name="email" type="email" class="form-control" maxlength="150"
                           value="<?= e($_POST['email'] ?? '') ?>">
                    <div class="form-text"><?= et('Para enviarte el comprobante.') ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold"><?= et('Motivo (opcional)') ?></label>
                    <input name="motivo" class="form-control" maxlength="255"
                           placeholder="<?= e(t('Ej. Revisión general')) ?>">
                </div>
            </div>

            <button class="btn btn-primary w-100 mt-4 py-3 fw-semibold">
                <i class="bi bi-check-lg"></i> <?= et('Agendar mi cita') ?>
            </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
