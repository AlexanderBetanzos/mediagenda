<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$errores = [];
$c = ['paciente_id' => $_GET['paciente_id'] ?? ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $c = $_POST;

    if (empty($c['paciente_id']) || !pertenece_al_tenant('pacientes', (int) $c['paciente_id'])) $errores[] = 'Selecciona un paciente.';
    if (empty($c['medico_id'])   || !pertenece_al_tenant('usuarios', (int) $c['medico_id']))    $errores[] = 'Selecciona un médico.';
    if (empty($c['fecha']))       $errores[] = 'Indica la fecha.';
    if (empty($c['hora']))        $errores[] = 'Indica la hora.';

    if (!$errores) {
        $stmt = db()->prepare(
            'INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, tipo, motivo, estado, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            tenant_id(),
            (int) $c['paciente_id'], (int) $c['medico_id'], $c['fecha'], $c['hora'],
            (int) ($c['duracion'] ?: 30), $c['tipo'] ?? 'medica',
            trim($c['motivo'] ?? '') ?: null, $c['estado'] ?? 'programada',
            trim($c['notas'] ?? '') ?: null,
        ]);
        flash('Cita agendada correctamente.');
        redirect('/citas/index.php?desde=' . urlencode($c['fecha']));
    }
}

$titulo = 'Nueva cita';
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/citas/index.php">Citas</a></li>
        <li class="breadcrumb-item active">Nueva</li>
    </ol>
</nav>
<h1 class="h3 mb-3">Agendar cita</h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body"><?= csrf_field() ?><?php include __DIR__ . '/_form.php'; ?></div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/citas/index.php" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Agendar</button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
