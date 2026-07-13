<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('pacientes');
try { db()->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS foto VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

$errores = [];
$p = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p = $_POST;

    if (trim($p['nombre'] ?? '') === '')    $errores[] = t('El nombre es obligatorio.');
    if (trim($p['apellidos'] ?? '') === '') $errores[] = t('Los apellidos son obligatorios.');

    if (!$errores) {
        $campos = paciente_post_campos($p);
        $foto = guardar_foto_paciente($_FILES['foto'] ?? null);
        if ($foto) $campos['foto'] = $foto;
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

$titulo = t('Nuevo paciente');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index"><?= et('Pacientes') ?></a></li>
        <li class="breadcrumb-item active"><?= et('Nuevo') ?></li>
    </ol>
</nav>
<h1 class="h3 mb-3"><?= et('Nuevo paciente') ?></h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>' . e($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card" enctype="multipart/form-data">
    <div class="card-body">
        <?= csrf_field() ?>
        <?php include __DIR__ . '/_form.php'; ?>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/pacientes/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
