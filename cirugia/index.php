<?php
/**
 * Cirugía general: bitácora quirúrgica del paciente. Registra el procedimiento
 * con su carácter (programada o urgencia), abordaje, riesgo ASA, diagnóstico
 * pre y postoperatorio, hallazgos, tiempo, sangrado y complicaciones.
 *
 * A diferencia de las demás especialidades esto NO es una valoración que se
 * repite: es un evento con estado (programada → realizada), porque lo que el
 * cirujano necesita ver es qué tiene agendado y qué ya operó.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_cirugia_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

$caracteres = ['programada' => 'Programada', 'urgencia' => 'Urgencia'];
$abordajes  = ['abierta' => 'Abierta', 'laparoscopica' => 'Laparoscópica', 'endoscopica' => 'Endoscópica',
               'percutanea' => 'Percutánea', 'mixta' => 'Mixta'];
$anestesias = ['local' => 'Local', 'regional' => 'Regional', 'general' => 'General', 'sedacion' => 'Sedación'];
$estados    = ['programada' => ['Programada', 'secondary'], 'realizada' => ['Realizada', 'success'],
               'suspendida' => ['Suspendida', 'warning'], 'cancelada' => ['Cancelada', 'dark']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    $sel = fn($k, $ops) => in_array($_POST[$k] ?? '', array_keys($ops), true) ? $_POST[$k] : null;
    $n   = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;

    if ($accion === 'add') {
        $proc = trim($_POST['procedimiento'] ?? '');
        if ($proc === '') { flash('El procedimiento necesita un nombre.', 'warning'); redirect('/cirugia/index?paciente_id=' . $pid); }
        db()->prepare('INSERT INTO cirugia_procedimientos
            (consultorio_id, paciente_id, fecha, procedimiento, caracter, abordaje, sede, dx_pre, dx_post,
             hallazgos, asa, duracion_min, sangrado_ml, anestesia, cirujano, ayudante, estado,
             complicaciones, alta_en, seguimiento, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'), mb_substr($proc, 0, 200),
                $sel('caracter', $caracteres) ?: 'programada', $sel('abordaje', $abordajes),
                trim($_POST['sede'] ?? '') ?: null, trim($_POST['dx_pre'] ?? '') ?: null,
                trim($_POST['dx_post'] ?? '') ?: null, trim($_POST['hallazgos'] ?? '') ?: null,
                in_array($_POST['asa'] ?? '', ['I','II','III','IV','V'], true) ? $_POST['asa'] : null,
                $n('duracion_min'), $n('sangrado_ml'), $sel('anestesia', $anestesias),
                trim($_POST['cirujano'] ?? '') ?: $u['nombre'], trim($_POST['ayudante'] ?? '') ?: null,
                $sel('estado', $estados) ?: 'programada', trim($_POST['complicaciones'] ?? '') ?: null,
                ($_POST['alta_en'] ?? '') ?: null, trim($_POST['seguimiento'] ?? '') ?: null,
                trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'cirugia', (int) db()->lastInsertId(), 'Paciente #' . $pid . ': ' . $proc);
        flash('Procedimiento registrado.');
        redirect('/cirugia/index?paciente_id=' . $pid);
    }
    if ($accion === 'estado') {
        $nuevo = $sel('estado', $estados);
        if ($nuevo) {
            db()->prepare('UPDATE cirugia_procedimientos SET estado = ? WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
                ->execute([$nuevo, (int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
            flash('Estado actualizado.');
        }
        redirect('/cirugia/index?paciente_id=' . $pid);
    }
    if ($accion === 'del') {
        db()->prepare('DELETE FROM cirugia_procedimientos WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Procedimiento eliminado.');
        redirect('/cirugia/index?paciente_id=' . $pid);
    }
}

$procs = db()->prepare('SELECT * FROM cirugia_procedimientos WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC');
$procs->execute([$pid, tenant_id()]);
$procs = $procs->fetchAll();

$pendientes = array_filter($procs, fn($p) => $p['estado'] === 'programada');

$titulo = t('Cirugía general');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-scissors text-brand"></i> <?= et('Cirugía general') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($pendientes): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-calendar-event"></i>
    <?= count($pendientes) ?> <?= et('procedimiento(s) programado(s) sin realizar.') ?>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nuevo procedimiento') ?></div>
    <form method="post" class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="add">
        <input type="hidden" name="paciente_id" value="<?= $pid ?>">

        <div class="col-md-2"><label class="form-label"><?= et('Fecha') ?></label>
            <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Procedimiento') ?> *</label>
            <input name="procedimiento" class="form-control" maxlength="200" required
                   placeholder="<?= e(t('Ej. Colecistectomía laparoscópica')) ?>"></div>
        <div class="col-md-2"><label class="form-label"><?= et('Carácter') ?></label>
            <select name="caracter" class="form-select"><?php foreach ($caracteres as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label"><?= et('Estado') ?></label>
            <select name="estado" class="form-select"><?php foreach ($estados as $k => [$l, $c]): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>

        <div class="col-md-3"><label class="form-label"><?= et('Abordaje') ?></label>
            <select name="abordaje" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($abordajes as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label"><?= et('Anestesia') ?></label>
            <select name="anestesia" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($anestesias as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label"><?= et('ASA') ?></label>
            <select name="asa" class="form-select"><option value=""><?= et('—') ?></option><?php foreach (['I','II','III','IV','V'] as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label"><?= et('Sede / quirófano') ?></label>
            <input name="sede" class="form-control" maxlength="160"></div>

        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico preoperatorio') ?></label>
            <input name="dx_pre" class="form-control" maxlength="255"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico postoperatorio') ?></label>
            <input name="dx_post" class="form-control" maxlength="255"></div>

        <div class="col-6 col-md-2"><label class="form-label"><?= et('Duración (min)') ?></label>
            <input type="number" name="duracion_min" class="form-control" min="0" max="1440"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Sangrado (ml)') ?></label>
            <input type="number" name="sangrado_ml" class="form-control" min="0"></div>
        <div class="col-md-4"><label class="form-label"><?= et('Cirujano') ?></label>
            <input name="cirujano" class="form-control" maxlength="160" value="<?= e($u['nombre']) ?>"></div>
        <div class="col-md-4"><label class="form-label"><?= et('Ayudante') ?></label>
            <input name="ayudante" class="form-control" maxlength="160"></div>

        <div class="col-md-6"><label class="form-label"><?= et('Hallazgos') ?></label>
            <textarea name="hallazgos" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label"><?= et('Complicaciones') ?></label>
            <textarea name="complicaciones" class="form-control" rows="2" placeholder="<?= e(t('Ninguna, si no las hubo')) ?>"></textarea></div>
        <div class="col-md-3"><label class="form-label"><?= et('Fecha de alta') ?></label>
            <input type="date" name="alta_en" class="form-control"></div>
        <div class="col-md-9"><label class="form-label"><?= et('Seguimiento') ?></label>
            <input name="seguimiento" class="form-control" placeholder="<?= e(t('Ej. Retiro de puntos en 8 días')) ?>"></div>
        <div class="col-12"><label class="form-label"><?= et('Notas') ?></label>
            <textarea name="notas" class="form-control" rows="2"></textarea></div>

        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar procedimiento') ?></button></div>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Bitácora quirúrgica') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th><?= et('Fecha') ?></th><th><?= et('Procedimiento') ?></th><th><?= et('Abordaje') ?></th>
                <th><?= et('Dx postoperatorio') ?></th><th><?= et('Estado') ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($procs as $p): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($p['fecha']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($p['procedimiento']) ?></div>
                        <small class="text-muted">
                            <?= e($caracteres[$p['caracter']] ?? '') ?>
                            <?php if ($p['asa']): ?> · ASA <?= e($p['asa']) ?><?php endif; ?>
                            <?php if ($p['duracion_min']): ?> · <?= (int) $p['duracion_min'] ?> min<?php endif; ?>
                            <?php if ($p['complicaciones']): ?>
                                <br><span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($p['complicaciones']) ?></span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td class="small"><?= e($abordajes[$p['abordaje']] ?? '—') ?></td>
                    <td class="small"><?= e($p['dx_post'] ?: ($p['dx_pre'] ?: '—')) ?></td>
                    <td>
                        <span class="badge bg-<?= $estados[$p['estado']][1] ?? 'secondary' ?>"><?= e($estados[$p['estado']][0] ?? $p['estado']) ?></span>
                        <?php if ($p['estado'] === 'programada'): ?>
                        <form method="post" class="mt-1">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="estado">
                            <input type="hidden" name="paciente_id" value="<?= $pid ?>">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="estado" value="realizada">
                            <button class="btn btn-sm btn-outline-success py-0"><?= et('Marcar realizada') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <form method="post" onsubmit="return confirm('<?= e(t('¿Eliminar este procedimiento?')) ?>')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="del">
                            <input type="hidden" name="paciente_id" value="<?= $pid ?>">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$procs): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin procedimientos registrados.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
