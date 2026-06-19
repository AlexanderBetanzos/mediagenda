<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM pacientes WHERE id = ?');
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p = array_merge($p, $_POST);

    if (trim($p['nombre']) === '')    $errores[] = 'El nombre es obligatorio.';
    if (trim($p['apellidos']) === '') $errores[] = 'Los apellidos son obligatorios.';

    if (!$errores) {
        $stmt = db()->prepare(
            'UPDATE pacientes SET
             nombre=?, apellidos=?, fecha_nacimiento=?, sexo=?, telefono=?, email=?,
             direccion=?, tipo=?, alergias=?, antecedentes=?, notas=?
             WHERE id=?'
        );
        $stmt->execute([
            trim($p['nombre']), trim($p['apellidos']),
            $p['fecha_nacimiento'] ?: null, $p['sexo'] ?: null,
            trim($p['telefono'] ?? '') ?: null, trim($p['email'] ?? '') ?: null,
            trim($p['direccion'] ?? '') ?: null, $p['tipo'] ?? 'medico',
            trim($p['alergias'] ?? '') ?: null, trim($p['antecedentes'] ?? '') ?: null,
            trim($p['notas'] ?? '') ?: null, $id,
        ]);
        flash('Datos del paciente actualizados.');
        redirect('/pacientes/ver.php?id=' . $id);
    }
}

$titulo = 'Editar paciente';
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index.php">Pacientes</a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $id ?>"><?= e($p['nombre'].' '.$p['apellidos']) ?></a></li>
        <li class="breadcrumb-item active">Editar</li>
    </ol>
</nav>
<h1 class="h3 mb-3">Editar paciente</h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>' . e($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php include __DIR__ . '/_form.php'; ?>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $id ?>" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar cambios</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
