<?php
/** CRM: seguimientos de pacientes y cumpleaños del mes. */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('crm');

$tid = tenant_id();
$u   = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $pid = (int) ($_POST['paciente_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        if ($pid && pertenece_al_tenant('pacientes', $pid) && $titulo !== '') {
            $tipo = in_array($_POST['tipo'] ?? '', ['llamada','mensaje','revision','otro'], true) ? $_POST['tipo'] : 'otro';
            db()->prepare('INSERT INTO seguimientos (consultorio_id, paciente_id, tipo, titulo, fecha_objetivo, nota, creado_por) VALUES (?,?,?,?,?,?,?)')
                ->execute([$tid, $pid, $tipo, mb_substr($titulo, 0, 160), ($_POST['fecha_objetivo'] ?? '') ?: null, trim($_POST['nota'] ?? '') ?: null, $u['id']]);
            auditar('crear', 'seguimiento', $pid, $titulo);
            flash('Seguimiento creado.');
        } else {
            flash('Selecciona paciente y escribe un título.', 'warning');
        }
        redirect('/crm/index');
    }

    if ($accion === 'hecho') {
        $sid = (int) ($_POST['seguimiento_id'] ?? 0);
        db()->prepare("UPDATE seguimientos SET estado='hecho', completado_en=NOW() WHERE id=? AND consultorio_id=?")
            ->execute([$sid, $tid]);
        flash('Seguimiento completado.');
        redirect('/crm/index');
    }
}

// Pendientes (vencidos primero, luego por fecha; sin fecha al final).
$pend = db()->prepare(
    "SELECT s.*, p.nombre, p.apellidos, p.telefono, COALESCE(p.foto_mime, p.foto) AS foto
     FROM seguimientos s JOIN pacientes p ON p.id = s.paciente_id
     WHERE s.consultorio_id = ? AND s.estado = 'pendiente'
     ORDER BY s.fecha_objetivo IS NULL, s.fecha_objetivo ASC, s.id DESC"
);
$pend->execute([$tid]);
$pendientes = $pend->fetchAll();

// Cumpleaños del mes actual.
$cumple = db()->prepare(
    "SELECT id, nombre, apellidos, telefono, fecha_nacimiento, COALESCE(foto_mime, foto) AS foto
     FROM pacientes WHERE consultorio_id = ? AND fecha_nacimiento IS NOT NULL
       AND MONTH(fecha_nacimiento) = MONTH(CURDATE())
     ORDER BY DAY(fecha_nacimiento)"
);
$cumple->execute([$tid]);
$cumpleanos = $cumple->fetchAll();

// Pacientes para el selector.
$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([$tid]);
$pacientes = $pacientes->fetchAll();

$tipoLbl = ['llamada'=>t('Llamada'),'mensaje'=>t('Mensaje'),'revision'=>t('Revisión'),'otro'=>t('Otro')];
$hoy = date('Y-m-d');

$titulo = t('CRM');
$activo = 'crm';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-people-fill text-brand"></i> <?= et('CRM · Seguimiento') ?></h1>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/crm/campanas" class="btn btn-outline-success"><i class="bi bi-megaphone"></i> <?= et('Campañas') ?></a>
        <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#formSeg"><i class="bi bi-plus-lg"></i> <?= et('Nuevo seguimiento') ?></button>
    </div>
</div>

<div class="collapse mb-4" id="formSeg">
    <div class="card card-body">
        <form method="post" class="row g-2">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="col-md-4">
                <label class="form-label"><?= et('Paciente') ?> *</label>
                <select name="paciente_id" class="form-select" required>
                    <option value=""><?= et('— Selecciona —') ?></option>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['apellidos'].', '.$p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= et('Tipo') ?></label>
                <select name="tipo" class="form-select">
                    <?php foreach ($tipoLbl as $k=>$l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Título') ?> *</label>
                <input type="text" name="titulo" class="form-control" required maxlength="160" placeholder="<?= et('Ej. Llamar para control de presión') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= et('Fecha') ?></label>
                <input type="date" name="fecha_objetivo" class="form-control">
            </div>
            <div class="col-12">
                <input type="text" name="nota" class="form-control" placeholder="<?= et('Nota (opcional)') ?>">
            </div>
            <div class="col-12 text-end"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button></div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-list-check text-brand"></i> <?= et('Pendientes') ?> (<?= count($pendientes) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$pendientes): ?>
                    <li class="list-group-item text-muted text-center py-4"><?= et('Sin seguimientos pendientes. 🎉') ?></li>
                <?php else: foreach ($pendientes as $s):
                    $vencido = $s['fecha_objetivo'] && $s['fecha_objetivo'] < $hoy; ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="badge bg-light text-dark border"><?= $tipoLbl[$s['tipo']] ?></span>
                        <span class="fw-semibold"><?= e($s['titulo']) ?></span>
                        <div class="small text-muted">
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $s['paciente_id'] ?>"><?= e($s['nombre'].' '.$s['apellidos']) ?></a>
                            <?php if ($s['fecha_objetivo']): ?>
                                · <span class="<?= $vencido ? 'text-danger fw-semibold' : '' ?>"><i class="bi bi-calendar-event"></i> <?= fmt_fecha($s['fecha_objetivo']) ?><?= $vencido ? ' ' . et('(vencido)') : '' ?></span>
                            <?php endif; ?>
                            <?php if ($s['nota']): ?> · <?= e($s['nota']) ?><?php endif; ?>
                        </div>
                    </div>
                    <form method="post" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="hecho">
                        <input type="hidden" name="seguimiento_id" value="<?= $s['id'] ?>">
                        <button class="btn btn-sm btn-outline-success" title="<?= et('Marcar como hecho') ?>"><i class="bi bi-check2"></i> <?= et('Hecho') ?></button>
                    </form>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-gift text-brand"></i> <?= et('Cumpleaños de este mes') ?></div>
            <ul class="list-group list-group-flush">
                <?php if (!$cumpleanos): ?>
                    <li class="list-group-item text-muted text-center py-3"><?= et('Ninguno este mes.') ?></li>
                <?php else: foreach ($cumpleanos as $c):
                    $msg = '¡Feliz cumpleaños, ' . $c['nombre'] . '! Te deseamos un excelente día. — ' . marca_nombre();
                    $wa = modulo_activo('whatsapp') ? wa_link($c['telefono'], $msg) : ''; ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <?= avatar_paciente((int) $c['id'], $c['nombre'], $c['apellidos'], $c['foto'] ?? null, 36) ?>
                        <div>
                        <div class="fw-semibold"><?= e($c['nombre'].' '.$c['apellidos']) ?></div>
                        <?php $mesesC = ['','ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic']; $tc = strtotime($c['fecha_nacimiento']); ?>
                        <small class="text-muted"><i class="bi bi-calendar-heart"></i> <?= (int) date('j', $tc) ?> <?= $mesesC[(int) date('n', $tc)] ?> · <?= e(edad($c['fecha_nacimiento'])) ?></small>
                        </div>
                    </div>
                    <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="<?= et('Felicitar por WhatsApp') ?>"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
