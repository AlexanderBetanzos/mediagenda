<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$usuarios = db()->prepare('SELECT * FROM usuarios WHERE consultorio_id = ? ORDER BY rol, nombre');
$usuarios->execute([tenant_id()]);
$usuarios = $usuarios->fetchAll();

$titulo = t('Personal');
$activo = 'usuarios';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-person-badge text-brand"></i> <?= et('Personal') ?></h1>
    <a href="<?= BASE_URL ?>/usuarios/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nuevo usuario') ?></a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th><?= et('Nombre') ?></th><th><?= et('Correo') ?></th><th><?= et('Rol') ?></th><th><?= et('Especialidad') ?></th><th><?= et('Teléfono') ?></th><th><?= et('Estado') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $usr): ?>
                <tr class="<?= $usr['activo'] ? '' : 'opacity-50' ?>">
                    <td class="fw-semibold"><?= e($usr['nombre']) ?></td>
                    <td><?= e($usr['email']) ?></td>
                    <td><span class="badge bg-secondary"><?= e(rol_label($usr['rol'])) ?></span></td>
                    <td><?= e($usr['especialidad'] ?: '—') ?></td>
                    <td><?= e($usr['telefono'] ?: '—') ?></td>
                    <td>
                        <?php if ($usr['activo']): ?>
                            <span class="badge bg-success"><?= et('Activo') ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?= et('Inactivo') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/usuarios/edit?id=<?= $usr['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <?php if ($usr['id'] != current_user()['id']): ?>
                        <form action="<?= BASE_URL ?>/usuarios/toggle" method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                            <button class="btn btn-sm btn-outline-<?= $usr['activo'] ? 'warning' : 'success' ?>" title="<?= $usr['activo'] ? et('Desactivar') : et('Activar') ?>">
                                <i class="bi bi-<?= $usr['activo'] ? 'pause' : 'play' ?>-fill"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
