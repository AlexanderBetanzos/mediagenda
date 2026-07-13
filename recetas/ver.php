<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('recetas');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            u.nombre AS med_nombre, u.especialidad
     FROM recetas r
     JOIN pacientes p ON p.id = r.paciente_id
     JOIN usuarios  u ON u.id = r.medico_id
     WHERE r.id = ? AND r.consultorio_id = ?'
);
$stmt->execute([$id, tenant_id()]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); die('Receta no encontrada.'); }

$items = db()->prepare('SELECT * FROM receta_items WHERE receta_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$titulo = t('Receta') . ' #' . $id;
$activo = 'recetas';
include __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .app-navbar, .sidebar, .breadcrumb, .no-print, footer { display: none !important; }
    body, .receta-print { background: #fff !important; color: #000 !important; }
    main { width: 100% !important; max-width: 100% !important; flex: 0 0 100% !important; }
    .receta-print .card { box-shadow: none !important; border: 1px solid #999 !important; }
}
</style>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <nav aria-label="breadcrumb" class="mb-0"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/recetas/index"><?= et('Recetas') ?></a></li>
        <li class="breadcrumb-item active"><?= et('Receta') ?> #<?= $id ?></li>
    </ol></nav>
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
</div>

<div class="receta-print">
<div class="card mx-auto" style="max-width:800px">
    <div class="card-body p-4" style="color:#1f2d3d;background:#fff">
        <div class="d-flex justify-content-between border-bottom pb-3 mb-3">
            <div>
                <h2 class="h4 mb-0" style="color:#0b6fb8"><i class="bi bi-heart-pulse-fill"></i> <?= e(marca_nombre()) ?></h2>
                <small class="text-muted"><?= et('Receta médica') ?></small>
            </div>
            <div class="text-end">
                <div><strong><?= et('Folio') ?>:</strong> R-<?= str_pad((string)$id, 5, '0', STR_PAD_LEFT) ?></div>
                <div><strong><?= et('Fecha') ?>:</strong> <?= fmt_fecha($r['fecha']) ?></div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-6">
                <strong><?= et('Paciente') ?>:</strong> <?= e($r['pac_nombre'].' '.$r['pac_ape']) ?><br>
                <small class="text-muted"><?= e(edad($r['fecha_nacimiento'])) ?></small>
            </div>
            <div class="col-6 text-end">
                <strong><?= e($r['med_nombre']) ?></strong><br>
                <small class="text-muted"><?= e($r['especialidad'] ?: 'Médico') ?></small>
            </div>
        </div>

        <?php if ($r['diagnostico']): ?>
            <p><strong><?= et('Diagnóstico') ?>:</strong> <?= e($r['diagnostico']) ?></p>
        <?php endif; ?>

        <h3 class="h6 mt-4" style="color:#0b6fb8"><i class="bi bi-capsule"></i> <?= et('Medicamentos') ?></h3>
        <table class="table table-bordered">
            <thead><tr><th><?= et('Medicamento') ?></th><th><?= et('Dosis') ?></th><th><?= et('Frecuencia') ?></th><th><?= et('Duración') ?></th></tr></thead>
            <tbody>
            <?php foreach ($items as $m): ?>
                <tr>
                    <td><?= e($m['medicamento']) ?></td>
                    <td><?= e($m['dosis'] ?: '—') ?></td>
                    <td><?= e($m['frecuencia'] ?: '—') ?></td>
                    <td><?= e($m['duracion'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($r['indicaciones']): ?>
            <p class="mt-3"><strong><?= et('Indicaciones') ?>:</strong><br><?= nl2br(e($r['indicaciones'])) ?></p>
        <?php endif; ?>
        <?php if ($r['notas']): ?>
            <p class="text-muted"><small><?= nl2br(e($r['notas'])) ?></small></p>
        <?php endif; ?>

        <div class="text-center mt-5 pt-4">
            <div style="border-top:1px solid #333;width:240px;margin:0 auto"></div>
            <small><?= e($r['med_nombre']) ?> · <?= et('Firma') ?></small>
        </div>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
