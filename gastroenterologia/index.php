<?php
/**
 * Gastroenterología: consultas y estudios endoscópicos. Registra la indicación,
 * calidad de preparación (con escala de Boston para colonoscopia), hallazgos
 * por segmento, clasificación de Los Ángeles para esofagitis, Helicobacter,
 * pólipos y biopsias, más la fecha del próximo control.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_gastro_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

$estudios = ['consulta' => 'Consulta', 'endoscopia_alta' => 'Endoscopia alta', 'colonoscopia' => 'Colonoscopia',
             'rectosigmoidoscopia' => 'Rectosigmoidoscopia', 'cpre' => 'CPRE', 'manometria' => 'Manometría',
             'ph_metria' => 'pH-metría', 'otro' => 'Otro'];
$preps    = ['excelente' => 'Excelente', 'buena' => 'Buena', 'regular' => 'Regular', 'mala' => 'Mala', 'no_aplica' => 'No aplica'];
$hp       = ['positivo' => 'Positivo', 'negativo' => 'Negativo', 'no_buscado' => 'No buscado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'add') {
        $n   = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        $sel = fn($k, $ops) => in_array($_POST[$k] ?? '', array_keys($ops), true) ? $_POST[$k] : null;
        db()->prepare('INSERT INTO gastro_estudios
            (consultorio_id, paciente_id, fecha, estudio, indicacion, preparacion, boston, esofago, estomago,
             duodeno, colon, los_angeles, helicobacter, polipos, biopsias, diagnostico, plan,
             proximo_control, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
                $sel('estudio', $estudios) ?: 'consulta', trim($_POST['indicacion'] ?? '') ?: null,
                $sel('preparacion', $preps), $n('boston'),
                trim($_POST['esofago'] ?? '') ?: null, trim($_POST['estomago'] ?? '') ?: null,
                trim($_POST['duodeno'] ?? '') ?: null, trim($_POST['colon'] ?? '') ?: null,
                in_array($_POST['los_angeles'] ?? '', ['A','B','C','D','no_aplica'], true) ? $_POST['los_angeles'] : null,
                $sel('helicobacter', $hp), $n('polipos'), trim($_POST['biopsias'] ?? '') ?: null,
                trim($_POST['diagnostico'] ?? '') ?: null, trim($_POST['plan'] ?? '') ?: null,
                ($_POST['proximo_control'] ?? '') ?: null, trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'gastroenterologia', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Estudio registrado.');
        redirect('/gastroenterologia/index?paciente_id=' . $pid);
    }
    if (($_POST['accion'] ?? '') === 'del') {
        db()->prepare('DELETE FROM gastro_estudios WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Estudio eliminado.');
        redirect('/gastroenterologia/index?paciente_id=' . $pid);
    }
}

$vals = db()->prepare('SELECT * FROM gastro_estudios WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC');
$vals->execute([$pid, tenant_id()]);
$vals = $vals->fetchAll();

/* Próximo control pendiente: es el dato que hace volver al paciente. */
$proximo = null;
foreach ($vals as $v) {
    if ($v['proximo_control'] && $v['proximo_control'] >= date('Y-m-d')) { $proximo = $v['proximo_control']; break; }
}

$titulo = t('Gastroenterología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Gastroenterología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($proximo): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-calendar-check"></i>
    <?= et('Próximo control programado para el') ?> <strong><?= fmt_fecha($proximo) ?></strong>.
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-plus-circle text-brand"></i> <?= et('Nuevo estudio o consulta') ?></div>
    <form method="post" class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="add">
        <input type="hidden" name="paciente_id" value="<?= $pid ?>">

        <div class="col-md-2"><label class="form-label"><?= et('Fecha') ?></label>
            <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Estudio') ?></label>
            <select name="estudio" class="form-select"><?php foreach ($estudios as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-7"><label class="form-label"><?= et('Indicación') ?></label>
            <input name="indicacion" class="form-control" maxlength="255"></div>

        <div class="col-md-3"><label class="form-label"><?= et('Preparación') ?></label>
            <select name="preparacion" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($preps as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Boston (0-9)') ?></label>
            <input type="number" name="boston" class="form-control" min="0" max="9"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Los Ángeles') ?></label>
            <select name="los_angeles" class="form-select"><option value=""><?= et('—') ?></option><?php foreach (['A','B','C','D','no_aplica'] as $la): ?><option value="<?= $la ?>"><?= $la === 'no_aplica' ? et('No aplica') : $la ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label">Helicobacter pylori</label>
            <select name="helicobacter" class="form-select"><option value=""><?= et('—') ?></option><?php foreach ($hp as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Pólipos') ?></label>
            <input type="number" name="polipos" class="form-control" min="0"></div>

        <div class="col-md-3"><label class="form-label"><?= et('Esófago') ?></label>
            <textarea name="esofago" class="form-control" rows="2"></textarea></div>
        <div class="col-md-3"><label class="form-label"><?= et('Estómago') ?></label>
            <textarea name="estomago" class="form-control" rows="2"></textarea></div>
        <div class="col-md-3"><label class="form-label"><?= et('Duodeno') ?></label>
            <textarea name="duodeno" class="form-control" rows="2"></textarea></div>
        <div class="col-md-3"><label class="form-label"><?= et('Colon') ?></label>
            <textarea name="colon" class="form-control" rows="2"></textarea></div>

        <div class="col-md-6"><label class="form-label"><?= et('Biopsias') ?></label>
            <input name="biopsias" class="form-control" maxlength="255" placeholder="<?= e(t('Sitio y número de tomas')) ?>"></div>
        <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label>
            <input name="diagnostico" class="form-control" maxlength="255"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Próximo control') ?></label>
            <input type="date" name="proximo_control" class="form-control"></div>
        <div class="col-md-9"><label class="form-label"><?= et('Plan') ?></label>
            <textarea name="plan" class="form-control" rows="2"></textarea></div>
        <div class="col-12"><label class="form-label"><?= et('Notas') ?></label>
            <textarea name="notas" class="form-control" rows="2"></textarea></div>

        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar estudio') ?></button></div>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Estudio') ?></th><th><?= et('Hallazgos') ?></th><th><?= et('Diagnóstico') ?></th><th><?= et('Control') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vals as $v): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($v['fecha']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($estudios[$v['estudio']] ?? $v['estudio']) ?></div>
                        <small class="text-muted">
                            <?php if ($v['boston'] !== null): ?>Boston <?= (int) $v['boston'] ?>/9<?php endif; ?>
                            <?php if ($v['los_angeles'] && $v['los_angeles'] !== 'no_aplica'): ?> · LA <?= e($v['los_angeles']) ?><?php endif; ?>
                        </small>
                    </td>
                    <td class="small">
                        <?php $seg = array_filter(['Esófago' => $v['esofago'], 'Estómago' => $v['estomago'],
                                                   'Duodeno' => $v['duodeno'], 'Colon' => $v['colon']]);
                        echo $seg ? e(implode(' · ', array_map(fn($k, $x) => "$k: $x", array_keys($seg), $seg))) : '—'; ?>
                        <?php if ($v['helicobacter'] === 'positivo'): ?>
                            <br><span class="badge bg-danger">H. pylori +</span>
                        <?php endif; ?>
                        <?php if ((int) $v['polipos'] > 0): ?>
                            <span class="badge bg-warning text-dark"><?= (int) $v['polipos'] ?> <?= et('pólipo(s)') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($v['diagnostico'] ?: '—') ?></td>
                    <td class="small text-muted"><?= $v['proximo_control'] ? fmt_fecha($v['proximo_control']) : '—' ?></td>
                    <td class="text-end">
                        <form method="post" onsubmit="return confirm('<?= e(t('¿Eliminar este estudio?')) ?>')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="del">
                            <input type="hidden" name="paciente_id" value="<?= $pid ?>">
                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$vals): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin estudios todavía.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
