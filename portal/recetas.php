<?php
/** Recetas del paciente (portal). */
require_once __DIR__ . '/../includes/functions.php';
require_paciente();

$pid = current_paciente()['id'];
$recetas = db()->prepare(
    "SELECT r.*, u.nombre AS med_nombre,
            (SELECT COUNT(*) FROM receta_items i WHERE i.receta_id = r.id) AS n_items
     FROM recetas r JOIN usuarios u ON u.id = r.medico_id
     WHERE r.paciente_id = ? AND r.consultorio_id = ?
     ORDER BY r.fecha DESC"
);
$recetas->execute([$pid, tenant_id()]);
$recetas = $recetas->fetchAll();

$titulo = t('Mis recetas');
include __DIR__ . '/../includes/portal_header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-capsule text-brand"></i> <?= et('Mis recetas') ?></h1>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th><?= et('Folio') ?></th><th><?= et('Fecha') ?></th><th><?= et('Médico') ?></th><th><?= et('Diagnóstico') ?></th><th class="text-center"><?= et('Medicamentos') ?></th><th class="text-end"><?= et('Ver') ?></th></tr></thead>
            <tbody>
            <?php if (!$recetas): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Aún no tienes recetas.') ?></td></tr>
            <?php else: foreach ($recetas as $r): ?>
                <tr>
                    <td>R-<?= str_pad((string) $r['id'], 5, '0', STR_PAD_LEFT) ?></td>
                    <td><?= fmt_fecha($r['fecha']) ?></td>
                    <td class="small"><?= e($r['med_nombre']) ?></td>
                    <td><?= e($r['diagnostico'] ?: '—') ?></td>
                    <td class="text-center"><span class="badge bg-secondary"><?= (int) $r['n_items'] ?></span></td>
                    <td class="text-end">
                        <a href="<?= BASE_URL ?>/portal/receta?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> <?= et('Ver') ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
