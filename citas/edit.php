<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM citas WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$c = $stmt->fetch();
if (!$c) { http_response_code(404); die('Cita no encontrada.'); }

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $c = array_merge($c, $_POST);

    if (empty($c['paciente_id']) || !pertenece_al_tenant('pacientes', (int) $c['paciente_id'])) $errores[] = 'Selecciona un paciente.';
    if (empty($c['medico_id'])   || !pertenece_al_tenant('usuarios', (int) $c['medico_id']))    $errores[] = 'Selecciona un médico.';
    if (empty($c['fecha']))       $errores[] = 'Indica la fecha.';
    if (empty($c['hora']))        $errores[] = 'Indica la hora.';

    if (!$errores) {
        $stmt = db()->prepare(
            'UPDATE citas SET paciente_id=?, medico_id=?, fecha=?, hora=?, duracion=?, tipo=?, motivo=?, estado=?, notas=? WHERE id=? AND consultorio_id=?'
        );
        $stmt->execute([
            (int) $c['paciente_id'], (int) $c['medico_id'], $c['fecha'], $c['hora'],
            (int) ($c['duracion'] ?: 30), $c['tipo'] ?? 'medica',
            trim($c['motivo'] ?? '') ?: null, $c['estado'] ?? 'programada',
            trim($c['notas'] ?? '') ?: null, $id, tenant_id(),
        ]);
        flash('Cita actualizada.');
        redirect('/citas/index?desde=' . urlencode($c['fecha']));
    }
}

$titulo = 'Editar cita';
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/citas/index">Citas</a></li>
        <li class="breadcrumb-item active">Editar</li>
    </ol>
</nav>
<h1 class="h3 mb-3">Editar cita</h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php include __DIR__ . '/_form.php'; ?>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/citas/index" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
    </div>
</form>

<form action="<?= BASE_URL ?>/citas/delete" method="post" class="mt-3" onsubmit="return confirm('¿Eliminar esta cita?');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Eliminar cita</button>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
