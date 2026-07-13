<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('recetas');

$u = current_user();
$medFiltro = $u['rol'] === 'medico' ? ' AND r.medico_id = ' . (int) $u['id'] : '';

$q = trim($_GET['q'] ?? '');
$params = [tenant_id()];
$sql = "SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, COALESCE(p.foto_mime, p.foto) AS pac_foto, u.nombre AS med_nombre,
               (SELECT COUNT(*) FROM receta_items ri WHERE ri.receta_id = r.id) AS n_items
        FROM recetas r
        JOIN pacientes p ON p.id = r.paciente_id
        JOIN usuarios  u ON u.id = r.medico_id
        WHERE r.consultorio_id = ? $medFiltro";
if ($q !== '') {
    $sql .= " AND (p.nombre LIKE ? OR p.apellidos LIKE ? OR r.diagnostico LIKE ?)";
    $like = "%$q%"; array_push($params, $like, $like, $like);
}
$sql .= " ORDER BY r.fecha DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$recetas = $stmt->fetchAll();

$titulo = t('Recetas');
$activo = 'recetas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-capsule text-info"></i> <?= et('Recetas') ?></h1>
    <?php if (has_role('medico', 'admin')): ?>
    <a href="<?= BASE_URL ?>/recetas/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nueva receta') ?></a>
    <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-md-5">
        <input type="search" name="q" class="form-control" placeholder="<?= et('Buscar por paciente o diagnóstico…') ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> <?= et('Buscar') ?></button>
        <a href="<?= BASE_URL ?>/recetas/index" class="btn btn-link"><?= et('Limpiar') ?></a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Paciente') ?></th><th><?= et('Médico') ?></th><th><?= et('Diagnóstico') ?></th><th><?= et('Medicamentos') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
            <tbody>
            <?php if (!$recetas): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('No hay recetas.') ?></td></tr>
            <?php else: foreach ($recetas as $r): ?>
                <tr>
                    <td><?= fmt_fecha($r['fecha']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $r['paciente_id'], $r['pac_nombre'], $r['pac_ape'], $r['pac_foto'] ?? null, 32) ?>
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $r['paciente_id'] ?>"><?= e($r['pac_nombre'].' '.$r['pac_ape']) ?></a>
                        </div>
                    </td>
                    <td class="small"><?= e($r['med_nombre']) ?></td>
                    <td><?= e($r['diagnostico'] ?: '—') ?></td>
                    <td><span class="badge bg-info"><?= $r['n_items'] ?></span></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/recetas/ver?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Ver/Imprimir') ?>"><i class="bi bi-printer"></i></a>
                        <?php if (has_role('medico', 'admin')): ?>
                        <form action="<?= BASE_URL ?>/recetas/delete" method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta receta?');">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
