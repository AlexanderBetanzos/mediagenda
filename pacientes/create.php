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
        $stmt = db()->prepare(
            'INSERT INTO pacientes
             (consultorio_id, nombre, apellidos, fecha_nacimiento, sexo, telefono, email, direccion, tipo, alergias, antecedentes, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            tenant_id(),
            trim($p['nombre']), trim($p['apellidos']),
            $p['fecha_nacimiento'] ?: null, $p['sexo'] ?: null,
            trim($p['telefono'] ?? '') ?: null, trim($p['email'] ?? '') ?: null,
            trim($p['direccion'] ?? '') ?: null, $p['tipo'] ?? 'medico',
            trim($p['alergias'] ?? '') ?: null, trim($p['antecedentes'] ?? '') ?: null,
            trim($p['notas'] ?? '') ?: null,
        ]);
        flash('Paciente registrado correctamente.');
        redirect('/pacientes/ver?id=' . db()->lastInsertId());
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
