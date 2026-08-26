<?php
/**
 * Cirugía plástica y medicina estética: bitácora de procedimientos por sesión.
 *
 * Lo que aquí importa y no cabe en una consulta normal es la TRAZABILIDAD del
 * producto: qué se aplicó, cuánto, de qué lote y con qué caducidad. Si un
 * relleno da reacción tres meses después, esa fila es la que responde. También
 * lleva la cuenta de sesiones y la fecha de la siguiente, que es de lo que
 * vive un consultorio estético.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_estetica_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

$tipos = ['no_invasivo' => 'No invasivo', 'minimamente_invasivo' => 'Mínimamente invasivo', 'quirurgico' => 'Quirúrgico'];
$unidades = ['U', 'ml', 'cc', 'sesión', 'hilos', 'g'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'add') {
        $proc = trim($_POST['procedimiento'] ?? '');
        if ($proc === '') { flash('El procedimiento necesita un nombre.', 'warning'); redirect('/estetica/index?paciente_id=' . $pid); }
        $n = fn($k) => (isset($_POST[$k]) && $_POST[$k] !== '') ? $_POST[$k] : null;
        db()->prepare('INSERT INTO estetica_procedimientos
            (consultorio_id, paciente_id, fecha, procedimiento, tipo, zona, producto, cantidad, unidad,
             lote, caducidad, sesion, sesiones_total, consentimiento, resultado, efectos,
             proxima_sesion, precio, notas, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, ($_POST['fecha'] ?? '') ?: date('Y-m-d'), mb_substr($proc, 0, 200),
                in_array($_POST['tipo'] ?? '', array_keys($tipos), true) ? $_POST['tipo'] : 'no_invasivo',
                trim($_POST['zona'] ?? '') ?: null, trim($_POST['producto'] ?? '') ?: null,
                $n('cantidad'), trim($_POST['unidad'] ?? '') ?: null, trim($_POST['lote'] ?? '') ?: null,
                ($_POST['caducidad'] ?? '') ?: null, $n('sesion'), $n('sesiones_total'),
                isset($_POST['consentimiento']) ? 1 : 0, trim($_POST['resultado'] ?? '') ?: null,
                trim($_POST['efectos'] ?? '') ?: null, ($_POST['proxima_sesion'] ?? '') ?: null,
                (float) ($_POST['precio'] ?? 0), trim($_POST['notas'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'estetica', (int) db()->lastInsertId(), 'Paciente #' . $pid . ': ' . $proc);
        flash('Procedimiento registrado.');
        redirect('/estetica/index?paciente_id=' . $pid);
    }
    if (($_POST['accion'] ?? '') === 'del') {
        db()->prepare('DELETE FROM estetica_procedimientos WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $pid, tenant_id()]);
        flash('Procedimiento eliminado.');
        redirect('/estetica/index?paciente_id=' . $pid);
    }
}

$procs = db()->prepare('SELECT * FROM estetica_procedimientos WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC');
$procs->execute([$pid, tenant_id()]);
$procs = $procs->fetchAll();

$invertido = 0.0;
$proxima   = null;
foreach ($procs as $p) {
    $invertido += (float) $p['precio'];
    if ($p['proxima_sesion'] && $p['proxima_sesion'] >= date('Y-m-d')
        && ($proxima === null || $p['proxima_sesion'] < $proxima)) { $proxima = $p['proxima_sesion']; }
}
$sinConsent = array_filter($procs, fn($p) => !$p['consentimiento']);

$titulo = t('Medicina estética');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-magic text-brand"></i> <?= et('Cirugía plástica y medicina estética') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($sinConsent): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center gap-2">
    <span><i class="bi bi-exclamation-triangle"></i>
        <?= count($sinConsent) ?> <?= et('procedimiento(s) sin consentimiento firmado registrado.') ?></span>
    <a href="<?= BASE_URL ?>/consentimientos/index?paciente_id=<?= $pid ?>" class="btn btn-sm btn-light"><?= et('Consentimientos') ?></a>
</div>
<?php endif; ?>

<?php if ($procs): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Procedimientos') ?></div>
        <div class="stat-num mt-2"><?= count($procs) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Inversión del paciente') ?></div>
        <div class="stat-num mt-2"><?= fmt_money($invertido) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Próxima sesión') ?></div>
        <div class="fw-semibold mt-2"><?= $proxima ? fmt_fecha($proxima) : '—' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Último') ?></div>
        <div class="fw-semibold mt-2"><?= e($procs[0]['procedimiento']) ?></div>
        <div class="small text-muted"><?= fmt_fecha($procs[0]['fecha']) ?></div>
    </div></div></div>
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
        <div class="col-md-5"><label class="form-label"><?= et('Procedimiento') ?> *</label>
            <input name="procedimiento" class="form-control" maxlength="200" required
                   placeholder="<?= e(t('Ej. Toxina botulínica tercio superior')) ?>"></div>
        <div class="col-md-2"><label class="form-label"><?= et('Tipo') ?></label>
            <select name="tipo" class="form-select"><?php foreach ($tipos as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label"><?= et('Zona tratada') ?></label>
            <input name="zona" class="form-control" maxlength="160"></div>

        <div class="col-12"><hr class="my-1">
            <span class="small fw-semibold text-muted text-uppercase"><i class="bi bi-upc-scan"></i> <?= et('Trazabilidad del producto') ?></span>
            <div class="form-text"><?= et('Si algo sale mal meses después, este renglón es el que responde.') ?></div>
        </div>
        <div class="col-md-4"><label class="form-label"><?= et('Producto') ?></label>
            <input name="producto" class="form-control" maxlength="160" placeholder="<?= e(t('Marca y presentación')) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Cantidad') ?></label>
            <input type="number" step="0.01" name="cantidad" class="form-control" min="0"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Unidad') ?></label>
            <input name="unidad" class="form-control" list="unidadesEst" maxlength="20">
            <datalist id="unidadesEst"><?php foreach ($unidades as $un): ?><option value="<?= e($un) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Lote') ?></label>
            <input name="lote" class="form-control" maxlength="60"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('Caducidad') ?></label>
            <input type="date" name="caducidad" class="form-control"></div>

        <div class="col-6 col-md-2"><label class="form-label"><?= et('Sesión n.º') ?></label>
            <input type="number" name="sesion" class="form-control" min="1"></div>
        <div class="col-6 col-md-2"><label class="form-label"><?= et('De un total de') ?></label>
            <input type="number" name="sesiones_total" class="form-control" min="1"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Próxima sesión') ?></label>
            <input type="date" name="proxima_sesion" class="form-control"></div>
        <div class="col-md-2"><label class="form-label"><?= et('Precio') ?></label>
            <input type="number" step="0.01" name="precio" class="form-control text-end" min="0" value="0"></div>
        <div class="col-md-3 d-flex align-items-center pt-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="consentimiento" id="cons" value="1">
                <label class="form-check-label" for="cons"><?= et('Consentimiento firmado') ?></label></div>
        </div>

        <div class="col-md-6"><label class="form-label"><?= et('Resultado') ?></label>
            <textarea name="resultado" class="form-control" rows="2"></textarea></div>
        <div class="col-md-6"><label class="form-label"><?= et('Efectos adversos') ?></label>
            <textarea name="efectos" class="form-control" rows="2" placeholder="<?= e(t('Ninguno, si no los hubo')) ?>"></textarea></div>
        <div class="col-12"><label class="form-label"><?= et('Notas') ?></label>
            <textarea name="notas" class="form-control" rows="2"></textarea></div>

        <div class="col-12">
            <div class="form-text">
                <i class="bi bi-camera"></i>
                <?= et('Las fotos de antes y después se suben al expediente del paciente, donde quedan protegidas y visibles en su portal.') ?>
            </div>
        </div>
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar procedimiento') ?></button></div>
    </form>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial') ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Procedimiento') ?></th><th><?= et('Producto y lote') ?></th>
                <th><?= et('Sesión') ?></th><th class="text-end"><?= et('Precio') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($procs as $p): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($p['fecha']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($p['procedimiento']) ?></div>
                        <small class="text-muted">
                            <?= e($tipos[$p['tipo']] ?? '') ?><?php if ($p['zona']): ?> · <?= e($p['zona']) ?><?php endif; ?>
                            <?php if (!$p['consentimiento']): ?>
                                <br><span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= et('Sin consentimiento') ?></span>
                            <?php endif; ?>
                            <?php if ($p['efectos']): ?>
                                <br><span class="text-danger"><i class="bi bi-exclamation-circle"></i> <?= e($p['efectos']) ?></span>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td class="small">
                        <?= e($p['producto'] ?: '—') ?>
                        <?php if ($p['cantidad'] !== null): ?><br><?= e((string) (0 + $p['cantidad'])) ?> <?= e($p['unidad'] ?: '') ?><?php endif; ?>
                        <?php if ($p['lote']): ?><br><span class="text-muted font-monospace"><?= et('Lote') ?> <?= e($p['lote']) ?></span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($p['sesion']): ?><?= (int) $p['sesion'] ?><?= $p['sesiones_total'] ? ' / ' . (int) $p['sesiones_total'] : '' ?><?php else: ?>—<?php endif; ?>
                        <?php if ($p['proxima_sesion']): ?><br><small class="text-muted"><?= et('Sig.') ?> <?= fmt_fecha($p['proxima_sesion']) ?></small><?php endif; ?>
                    </td>
                    <td class="text-end"><?= fmt_money($p['precio']) ?></td>
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
            <?php if (!$procs): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin procedimientos todavía.') ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
