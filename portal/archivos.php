<?php
/** Estudios / archivos del expediente del paciente (portal). */
require_once __DIR__ . '/../includes/functions.php';
require_paciente();

$pid = current_paciente()['id'];
$archivos = db()->prepare(
    'SELECT * FROM archivos WHERE paciente_id = ? AND consultorio_id = ? ORDER BY creado_en DESC'
);
$archivos->execute([$pid, tenant_id()]);
$archivos = $archivos->fetchAll();

$titulo = 'Mis estudios';
include __DIR__ . '/../includes/portal_header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-paperclip text-brand"></i> Mis estudios y documentos</h1>

<?php if (!$archivos): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">Tu consultorio aún no ha subido documentos a tu expediente.</div></div>
<?php else: ?>
<div class="card"><ul class="list-group list-group-flush">
    <?php foreach ($archivos as $a):
        $mime = $a['mime'] ?? '';
        $previa = strpos($mime, 'image/') === 0 || $mime === 'application/pdf'; ?>
    <li class="list-group-item d-flex align-items-center gap-3">
        <i class="bi <?= archivo_icono($a['nombre_original']) ?> fs-3 text-brand"></i>
        <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold text-truncate"><?= e($a['nombre_original']) ?></div>
            <small class="text-muted">
                <?php if ($a['descripcion']): ?><?= e($a['descripcion']) ?> · <?php endif; ?>
                <?= fmt_bytes($a['tamano']) ?> · <?= fmt_fecha($a['creado_en']) ?>
            </small>
        </div>
        <div class="text-nowrap">
            <?php if ($previa): ?>
            <a href="<?= BASE_URL ?>/portal/archivo?id=<?= $a['id'] ?>&ver=1" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/portal/archivo?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
        </div>
    </li>
    <?php endforeach; ?>
</ul></div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
