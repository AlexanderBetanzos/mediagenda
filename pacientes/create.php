<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$errores = [];
$p = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p = $_POST;

    if (trim($p['nombre'] ?? '') === '')    $errores[] = 'El nombre es obligatorio.';
    if (trim($p['apellidos'] ?? '') === '') $errores[] = 'Los apellidos son obligatorios.';

    if (!$errores) {
        $campos = paciente_post_campos($p);
        $cols   = array_keys($campos);
        $ph     = implode(',', array_fill(0, count($cols) + 1, '?'));
        $stmt = db()->prepare(
            'INSERT INTO pacientes (consultorio_id, ' . implode(', ', $cols) . ") VALUES ($ph)"
        );
        $stmt->execute(array_merge([tenant_id()], array_values($campos)));
        $nuevoId = (int) db()->lastInsertId();
        auditar('crear', 'paciente', $nuevoId, trim(($p['nombre'] ?? '') . ' ' . ($p['apellidos'] ?? '')));
        flash('Paciente registrado correctamente.');
        redirect('/pacientes/ver?id=' . $nuevoId);
    }
}

$titulo = 'Nuevo paciente';
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index">Pacientes</a></li>
        <li class="breadcrumb-item active">Nuevo</li>
    </ol>
</nav>
<h1 class="h3 mb-3">Nuevo paciente</h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>' . e($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <?php include __DIR__ . '/_form.php'; ?>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/pacientes/index" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
