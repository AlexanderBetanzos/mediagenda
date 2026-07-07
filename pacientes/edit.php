<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
try { db()->exec("ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS foto VARCHAR(255) DEFAULT NULL"); } catch (Throwable $e) {}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p = array_merge($p, $_POST);

    if (trim($p['nombre']) === '')    $errores[] = t('El nombre es obligatorio.');
    if (trim($p['apellidos']) === '') $errores[] = t('Los apellidos son obligatorios.');

    if (!$errores) {
        $campos = paciente_post_campos($p);
        $foto = guardar_foto_paciente($_FILES['foto'] ?? null);
        if ($foto) { eliminar_foto_paciente($p['foto'] ?? null); $campos['foto'] = $foto; }
        $set    = implode(' = ?, ', array_keys($campos)) . ' = ?';
        $stmt = db()->prepare("UPDATE pacientes SET $set WHERE id = ? AND consultorio_id = ?");
        $stmt->execute(array_merge(array_values($campos), [$id, tenant_id()]));
        auditar('editar', 'paciente', $id, trim(($p['nombre'] ?? '') . ' ' . ($p['apellidos'] ?? '')));
        flash('Datos del paciente actualizados.');
        redirect('/pacientes/ver?id=' . $id);
    }
}

$titulo = t('Editar paciente');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index"><?= et('Pacientes') ?></a></li>
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $id ?>"><?= e($p['nombre'].' '.$p['apellidos']) ?></a></li>
        <li class="breadcrumb-item active"><?= et('Editar') ?></li>
    </ol>
</nav>
<h1 class="h3 mb-3"><?= et('Editar paciente') ?></h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>' . e($e) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card" enctype="multipart/form-data">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php include __DIR__ . '/_form.php'; ?>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $id ?>" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar cambios') ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
