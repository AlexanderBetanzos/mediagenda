<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$usr = $stmt->fetch();
if (!$usr) { http_response_code(404); die('Usuario no encontrado.'); }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $usr = array_merge($usr, $_POST);

    if (trim($usr['nombre']) === '') $errores[] = 'El nombre es obligatorio.';
    if (!filter_var($usr['email'], FILTER_VALIDATE_EMAIL)) $errores[] = 'Correo no válido.';
    if (!in_array($usr['rol'], ['admin','medico','recepcion'], true)) $errores[] = 'Rol no válido.';
    if (($usr['password'] ?? '') !== '' && strlen($usr['password']) < 6) $errores[] = 'La nueva contraseña debe tener al menos 6 caracteres.';

    if (!$errores) {
        $chk = db()->prepare('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?');
        $chk->execute([trim($usr['email']), $id]);
        if ($chk->fetch()) $errores[] = 'Otro usuario ya usa ese correo.';
    }

    if (!$errores) {
        if (($usr['password'] ?? '') !== '') {
            $stmt = db()->prepare('UPDATE usuarios SET nombre=?, email=?, rol=?, especialidad=?, telefono=?, password_hash=? WHERE id=? AND consultorio_id=?');
            $stmt->execute([trim($usr['nombre']), trim($usr['email']), $usr['rol'],
                trim($usr['especialidad'] ?? '') ?: null, trim($usr['telefono'] ?? '') ?: null,
                password_hash($usr['password'], PASSWORD_DEFAULT), $id, tenant_id()]);
        } else {
            $stmt = db()->prepare('UPDATE usuarios SET nombre=?, email=?, rol=?, especialidad=?, telefono=? WHERE id=? AND consultorio_id=?');
            $stmt->execute([trim($usr['nombre']), trim($usr['email']), $usr['rol'],
                trim($usr['especialidad'] ?? '') ?: null, trim($usr['telefono'] ?? '') ?: null, $id, tenant_id()]);
        }
        flash('Usuario actualizado.');
        redirect('/usuarios/index.php');
    }
}

$titulo = 'Editar usuario';
$activo = 'usuarios';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/usuarios/index.php">Personal</a></li>
        <li class="breadcrumb-item active">Editar</li>
    </ol>
</nav>
<h1 class="h3 mb-3">Editar usuario</h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" required value="<?= e($usr['nombre']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Correo *</label><input type="email" name="email" class="form-control" required value="<?= e($usr['email']) ?>"></div>
            <div class="col-md-4">
                <label class="form-label">Rol *</label>
                <select name="rol" class="form-select" required>
                    <?php foreach (['admin','medico','recepcion'] as $rol): ?>
                        <option value="<?= $rol ?>" <?= $usr['rol']===$rol?'selected':'' ?>><?= rol_label($rol) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label">Especialidad</label><input type="text" name="especialidad" class="form-control" value="<?= e($usr['especialidad'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= e($usr['telefono'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Nueva contraseña</label><input type="password" name="password" class="form-control" minlength="6" placeholder="Dejar en blanco para no cambiar"></div>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/usuarios/index.php" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar cambios</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
