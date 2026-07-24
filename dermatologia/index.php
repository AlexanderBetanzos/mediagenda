<?php
/**
 * Dermatología: lesiones con seguimiento foto-comparativo. Cada lesión guarda
 * fotos fechadas (en el expediente) que se muestran en galería cronológica
 * para comparar cómo evoluciona.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_derma_tables();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'add_lesion') {
        db()->prepare('INSERT INTO derma_lesiones (consultorio_id, paciente_id, region, tipo, descripcion, diagnostico, creado_por)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, trim($_POST['region'] ?? '') ?: null, trim($_POST['tipo'] ?? '') ?: null,
                trim($_POST['descripcion'] ?? '') ?: null, trim($_POST['diagnostico'] ?? '') ?: null, $u['id']]);
        auditar('crear', 'derma_lesion', (int) db()->lastInsertId(), 'Paciente #' . $pid);
        flash('Lesión registrada. Ahora súbele fotos de seguimiento.');
        redirect('/dermatologia/index?paciente_id=' . $pid);
    }

    if ($accion === 'add_foto') {
        $lid = (int) ($_POST['lesion_id'] ?? 0);
        $chk = db()->prepare('SELECT id FROM derma_lesiones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
        $chk->execute([$lid, $pid, tenant_id()]);
        if ($chk->fetch()) {
            $f = $_FILES['foto'] ?? null;
            $mime = ($f && is_uploaded_file($f['tmp_name'] ?? '')) ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
            if (strpos((string) $mime, 'image/') !== 0) {
                flash('Sube una imagen (JPG, PNG o WebP).', 'warning');
            } else {
                $r = guardar_archivo_expediente($f, $pid, $u['id'], 'Dermatología', 0);
                if ($r['estado'] === 'ok') {
                    db()->prepare('INSERT INTO derma_fotos (lesion_id, archivo_id, fecha, notas) VALUES (?,?,?,?)')
                        ->execute([$lid, (int) $r['id'], ($_POST['fecha'] ?? '') ?: date('Y-m-d'), trim($_POST['notas'] ?? '') ?: null]);
                    flash('Foto agregada a la lesión.');
                } else {
                    flash('No se pudo subir la foto: ' . $r['mensaje'], 'warning');
                }
            }
        }
        redirect('/dermatologia/index?paciente_id=' . $pid);
    }

    if ($accion === 'cerrar_lesion') {
        db()->prepare('UPDATE derma_lesiones SET activo = 0 WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['lesion_id'] ?? 0), $pid, tenant_id()]);
        flash('Lesión marcada como resuelta.');
        redirect('/dermatologia/index?paciente_id=' . $pid);
    }
}

$lesiones = db()->prepare('SELECT * FROM derma_lesiones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY activo DESC, creado_en DESC');
$lesiones->execute([$pid, tenant_id()]);
$lesiones = $lesiones->fetchAll();

// Fotos por lesión (con datos del archivo).
$fotosPor = [];
if ($lesiones) {
    $ids = implode(',', array_map(fn($l) => (int) $l['id'], $lesiones));
    $fq = db()->query("SELECT df.*, a.mime FROM derma_fotos df JOIN archivos a ON a.id = df.archivo_id
                       WHERE df.lesion_id IN ($ids) ORDER BY df.fecha ASC, df.id ASC");
    foreach ($fq->fetchAll() as $ft) { $fotosPor[(int) $ft['lesion_id']][] = $ft; }
}

$titulo = t('Dermatología');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-bandaid text-brand"></i> <?= et('Dermatología') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<!-- Nueva lesión -->
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-plus-circle text-brand"></i> <?= et('Registrar lesión') ?></h2>
    <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="add_lesion"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
        <div class="col-md-3"><label class="form-label small"><?= et('Región / zona') ?></label><input type="text" name="region" class="form-control" maxlength="120" placeholder="<?= e(t('Ej. Antebrazo derecho')) ?>"></div>
        <div class="col-md-3"><label class="form-label small"><?= et('Tipo') ?></label><input type="text" name="tipo" class="form-control" maxlength="120" placeholder="<?= e(t('Mácula, nevo, placa…')) ?>"></div>
        <div class="col-md-6"><label class="form-label small"><?= et('Diagnóstico presuntivo') ?></label><input type="text" name="diagnostico" class="form-control" maxlength="255"></div>
        <div class="col-12"><label class="form-label small"><?= et('Descripción') ?></label><input type="text" name="descripcion" class="form-control" maxlength="255"></div>
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Registrar') ?></button></div>
    </form>
</div></div>

<?php if (!$lesiones): ?>
    <p class="text-muted text-center py-4"><?= et('Sin lesiones registradas.') ?></p>
<?php else: foreach ($lesiones as $l): $act = (int) $l['activo'] === 1; $fotos = $fotosPor[(int) $l['id']] ?? []; ?>
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <span class="fw-semibold"><?= e($l['region'] ?: t('Lesión')) ?></span>
            <?php if ($l['tipo']): ?><span class="badge bg-light text-dark border ms-1"><?= e($l['tipo']) ?></span><?php endif; ?>
            <?php if (!$act): ?><span class="badge bg-secondary ms-1"><?= et('Resuelta') ?></span><?php endif; ?>
            <?php if ($l['diagnostico']): ?><div class="small text-muted"><?= e($l['diagnostico']) ?></div><?php endif; ?>
        </div>
        <?php if ($act): ?>
        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Marcar esta lesión como resuelta?')) ?>');">
            <?= csrf_field() ?><input type="hidden" name="accion" value="cerrar_lesion"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="lesion_id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all"></i> <?= et('Marcar resuelta') ?></button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($l['descripcion']): ?><p class="small text-muted"><?= e($l['descripcion']) ?></p><?php endif; ?>

        <!-- Galería comparativa -->
        <?php if ($fotos): ?>
        <div class="d-flex gap-3 overflow-auto pb-2 mb-3">
            <?php foreach ($fotos as $ft): ?>
            <div class="text-center" style="flex:0 0 auto">
                <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= (int)$ft['archivo_id'] ?>&ver=1" target="_blank" rel="noopener">
                    <img src="<?= BASE_URL ?>/pacientes/archivo?id=<?= (int)$ft['archivo_id'] ?>&ver=1" alt=""
                         style="width:150px;height:150px;object-fit:cover;border-radius:10px;border:1px solid rgba(127,127,127,.25)">
                </a>
                <div class="small text-muted mt-1"><?= e(fmt_fecha($ft['fecha'])) ?></div>
                <?php if ($ft['notas']): ?><div class="small" style="max-width:150px"><?= e($ft['notas']) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="small text-muted"><?= et('Aún no hay fotos. Sube la primera para empezar la comparación.') ?></p>
        <?php endif; ?>

        <!-- Subir foto -->
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrf_field() ?><input type="hidden" name="accion" value="add_foto"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="lesion_id" value="<?= (int)$l['id'] ?>">
            <div class="col-6 col-md-3"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label small"><?= et('Foto') ?></label><input type="file" name="foto" accept="image/*" class="form-control form-control-sm" required></div>
            <div class="col-md-3"><label class="form-label small"><?= et('Notas') ?></label><input type="text" name="notas" class="form-control form-control-sm" maxlength="255"></div>
            <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-cloud-upload"></i> <?= et('Subir') ?></button></div>
        </form>
    </div>
</div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
