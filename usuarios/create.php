<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$errores = [];
$usr = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $usr = $_POST;

    if (trim($usr['nombre'] ?? '') === '') $errores[] = 'El nombre es obligatorio.';
    if (!filter_var($usr['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errores[] = 'Correo no válido.';
    if (strlen($usr['password'] ?? '') < 6) $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    if (!in_array($usr['rol'] ?? '', ['admin','medico','recepcion'], true)) $errores[] = 'Rol no válido.';

    // ¿correo duplicado?
    if (!$errores) {
        $chk = db()->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $chk->execute([trim($usr['email'])]);
        if ($chk->fetch()) $errores[] = 'Ya existe un usuario con ese correo.';
    }

    if (!$errores) {
        $stmt = db()->prepare(
            'INSERT INTO usuarios (consultorio_id, nombre, email, password_hash, rol, especialidad, telefono)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            tenant_id(),
            trim($usr['nombre']), trim($usr['email']),
            password_hash($usr['password'], PASSWORD_DEFAULT),
            $usr['rol'],
            trim($usr['especialidad'] ?? '') ?: null,
            trim($usr['telefono'] ?? '') ?: null,
        ]);
        auditar('crear', 'usuario', (int) db()->lastInsertId(), trim($usr['nombre']) . ' · ' . $usr['rol']);
        flash('Usuario creado correctamente.');
        redirect('/usuarios/index');
    }
}

$titulo = 'Nuevo usuario';
$activo = 'usuarios';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/usuarios/index">Personal</a></li>
        <li class="breadcrumb-item active">Nuevo</li>
    </ol>
</nav>
<h1 class="h3 mb-3">Nuevo usuario</h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" required value="<?= e($usr['nombre'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Correo *</label><input type="email" name="email" class="form-control" required value="<?= e($usr['email'] ?? '') ?>"></div>
            <div class="col-md-4">
                <label class="form-label">Rol *</label>
                <select name="rol" class="form-select" required>
                    <?php $r = $usr['rol'] ?? ''; foreach (['admin','medico','recepcion'] as $rol): ?>
                        <option value="<?= $rol ?>" <?= $r===$rol?'selected':'' ?>><?= rol_label($rol) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><label class="form-label">Especialidad</label><input type="text" name="especialidad" class="form-control" value="<?= e($usr['especialidad'] ?? '') ?>" placeholder="Solo médicos/dentistas"></div>
            <div class="col-md-4"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= e($usr['telefono'] ?? '') ?>"></div>
            <div class="col-md-6"><label class="form-label">Contraseña *</label><input type="password" name="password" class="form-control" required minlength="6"></div>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/usuarios/index" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Crear usuario</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
