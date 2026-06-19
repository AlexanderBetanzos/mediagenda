<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$usuarios = db()->query('SELECT * FROM usuarios ORDER BY rol, nombre')->fetchAll();

$titulo = 'Personal';
$activo = 'usuarios';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-person-badge text-brand"></i> Personal</h1>
    <a href="<?= BASE_URL ?>/usuarios/create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nuevo usuario</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Especialidad</th><th>Teléfono</th><th>Estado</th><th class="text-end">Acciones</th></tr>
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
                            <span class="badge bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/usuarios/edit.php?id=<?= $usr['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <?php if ($usr['id'] != current_user()['id']): ?>
                        <form action="<?= BASE_URL ?>/usuarios/toggle.php" method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $usr['id'] ?>">
                            <button class="btn btn-sm btn-outline-<?= $usr['activo'] ? 'warning' : 'success' ?>" title="<?= $usr['activo'] ? 'Desactivar' : 'Activar' ?>">
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
