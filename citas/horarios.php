<?php
/**
 * Horarios laborales por médico y bloqueos de agenda.
 * El admin gestiona a cualquier médico y bloqueos de todo el consultorio;
 * el médico gestiona los suyos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');
if (!has_role('admin', 'medico')) { http_response_code(403); die('Solo admin o médico.'); }

$u      = current_user();
$esAdmin = has_role('admin');

$medicos = db()->prepare("SELECT id, nombre FROM usuarios WHERE rol='medico' AND activo=1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

// Médico en contexto: el admin elige; el médico es él mismo.
$medicoId = $esAdmin ? (int) ($_GET['medico'] ?? $_POST['medico_id'] ?? ($medicos[0]['id'] ?? 0)) : (int) $u['id'];

$dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'horarios' && $medicoId && pertenece_al_tenant('usuarios', $medicoId)) {
        // Reemplaza el horario semanal del médico.
        db()->prepare('DELETE FROM medico_horarios WHERE medico_id = ? AND consultorio_id = ?')
            ->execute([$medicoId, tenant_id()]);
        $ins = db()->prepare(
            'INSERT INTO medico_horarios (consultorio_id, medico_id, dia_semana, hora_inicio, hora_fin) VALUES (?,?,?,?,?)'
        );
        foreach (($_POST['dia'] ?? []) as $d => $r) {
            $ini = $r['inicio'] ?? ''; $fin = $r['fin'] ?? '';
            if (isset($dias[(int) $d]) && $ini !== '' && $fin !== '' && $fin > $ini) {
                $ins->execute([tenant_id(), $medicoId, (int) $d, $ini, $fin]);
            }
        }
        auditar('horarios_editar', 'usuario', $medicoId);
        flash('Horario del médico actualizado.');
        redirect('/citas/horarios?medico=' . $medicoId);
    }

    if ($accion === 'bloqueo_add') {
        $ini = trim(($_POST['b_inicio_f'] ?? '') . ' ' . ($_POST['b_inicio_h'] ?? ''));
        $fin = trim(($_POST['b_fin_f'] ?? '') . ' ' . ($_POST['b_fin_h'] ?? ''));
        $ti = strtotime($ini); $tf = strtotime($fin);
        if ($ti && $tf && $tf > $ti) {
            // Ámbito: médico concreto o todo el consultorio (solo admin).
            $mid = (!empty($_POST['b_todos']) && $esAdmin) ? null : ($medicoId ?: ($esAdmin ? null : (int) $u['id']));
            db()->prepare('INSERT INTO bloqueos (consultorio_id, medico_id, inicio, fin, motivo) VALUES (?,?,?,?,?)')
                ->execute([tenant_id(), $mid, date('Y-m-d H:i:s', $ti), date('Y-m-d H:i:s', $tf), trim($_POST['b_motivo'] ?? '') ?: null]);
            auditar('bloqueo_add', 'usuario', $mid);
            flash('Bloqueo agregado.');
        } else {
            flash('Revisa las fechas del bloqueo (el fin debe ser posterior al inicio).', 'warning');
        }
        redirect('/citas/horarios?medico=' . $medicoId);
    }

    if ($accion === 'bloqueo_del') {
        $bid = (int) ($_POST['bloqueo_id'] ?? 0);
        // El médico solo borra los suyos; el admin, cualquiera del consultorio.
        $cond = $esAdmin ? '' : ' AND (medico_id = ' . (int) $u['id'] . ')';
        db()->prepare("DELETE FROM bloqueos WHERE id = ? AND consultorio_id = ?$cond")
            ->execute([$bid, tenant_id()]);
        auditar('bloqueo_del', null, $bid);
        redirect('/citas/horarios?medico=' . $medicoId);
    }
}

// Horario actual del médico (un tramo por día en esta UI).
$hor = [];
if ($medicoId) {
    $st = db()->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM medico_horarios WHERE medico_id = ? AND consultorio_id = ?');
    $st->execute([$medicoId, tenant_id()]);
    foreach ($st as $r) { $hor[(int) $r['dia_semana']] = $r; }
}

// Bloqueos próximos (del médico en contexto + los de todo el consultorio).
$bl = db()->prepare(
    'SELECT b.*, u.nombre AS med_nombre FROM bloqueos b
     LEFT JOIN usuarios u ON u.id = b.medico_id
     WHERE b.consultorio_id = ? AND b.fin >= NOW()
       AND (b.medico_id = ? OR b.medico_id IS NULL)
     ORDER BY b.inicio'
);
$bl->execute([tenant_id(), $medicoId]);
$bloqueos = $bl->fetchAll();

$titulo = 'Horarios y bloqueos';
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-clock-history text-brand"></i> Horarios y bloqueos</h1>
    <a href="<?= BASE_URL ?>/citas/calendario" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar3"></i> Calendario</a>
</div>

<?php if ($esAdmin): ?>
<form class="row g-2 mb-3 align-items-end" method="get">
    <div class="col-sm-6 col-md-4">
        <label class="form-label small mb-1">Médico</label>
        <select name="medico" class="form-select" onchange="this.form.submit()">
            <?php foreach ($medicos as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $medicoId === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
<?php endif; ?>

<?php if (!$medicoId): ?>
    <div class="alert alert-info">No hay médicos para gestionar.</div>
<?php else: ?>
<div class="row g-4">
    <!-- Horario semanal -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar-week text-brand"></i> Horario semanal</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="horarios">
                    <input type="hidden" name="medico_id" value="<?= $medicoId ?>">
                    <?php foreach ($dias as $d => $nom): $h = $hor[$d] ?? null; ?>
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-4 col-sm-3"><?= $nom ?></div>
                        <div class="col"><input type="time" name="dia[<?= $d ?>][inicio]" class="form-control form-control-sm" value="<?= e($h['hora_inicio'] ?? '') ?>"></div>
                        <div class="col-auto text-muted">a</div>
                        <div class="col"><input type="time" name="dia[<?= $d ?>][fin]" class="form-control form-control-sm" value="<?= e($h['hora_fin'] ?? '') ?>"></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="form-text mb-2">Deja un día en blanco para marcarlo como no laborable.</div>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Guardar horario</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Bloqueos -->
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-slash-circle text-brand"></i> Nuevo bloqueo</div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="bloqueo_add">
                    <input type="hidden" name="medico_id" value="<?= $medicoId ?>">
                    <div class="col-7"><label class="form-label small mb-1">Inicio</label><input type="date" name="b_inicio_f" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-5"><label class="form-label small mb-1">Hora</label><input type="time" name="b_inicio_h" class="form-control form-control-sm" required value="09:00"></div>
                    <div class="col-7"><label class="form-label small mb-1">Fin</label><input type="date" name="b_fin_f" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-5"><label class="form-label small mb-1">Hora</label><input type="time" name="b_fin_h" class="form-control form-control-sm" required value="10:00"></div>
                    <div class="col-12"><input type="text" name="b_motivo" class="form-control form-control-sm" placeholder="Motivo (comida, vacaciones…)" maxlength="120"></div>
                    <?php if ($esAdmin): ?>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="b_todos" id="b_todos" value="1"><label class="form-check-label small" for="b_todos">Aplicar a todo el consultorio</label></div></div>
                    <?php endif; ?>
                    <div class="col-12"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-plus-lg"></i> Agregar bloqueo</button></div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-white fw-semibold">Bloqueos próximos</div>
            <ul class="list-group list-group-flush">
                <?php if (!$bloqueos): ?>
                    <li class="list-group-item text-muted text-center py-3">Sin bloqueos.</li>
                <?php else: foreach ($bloqueos as $b): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="small fw-semibold"><?= fmt_fecha($b['inicio']) ?> <?= date('H:i', strtotime($b['inicio'])) ?> – <?= fmt_fecha($b['fin']) ?> <?= date('H:i', strtotime($b['fin'])) ?></div>
                        <div class="small text-muted"><?= e($b['motivo'] ?: 'Bloqueo') ?> · <?= $b['medico_id'] ? e($b['med_nombre']) : 'Todo el consultorio' ?></div>
                    </div>
                    <form method="post" onsubmit="return confirm('¿Eliminar este bloqueo?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="bloqueo_del">
                        <input type="hidden" name="medico_id" value="<?= $medicoId ?>">
                        <input type="hidden" name="bloqueo_id" value="<?= $b['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
