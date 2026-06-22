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
        // Citas recurrentes: genera una serie a partir de la primera fecha.
        $intervalos = ['semanal' => '+1 week', 'quincenal' => '+2 weeks', 'mensual' => '+1 month'];
        $repetir = $_POST['repetir'] ?? 'no';
        $reps    = max(1, min(52, (int) ($_POST['repeticiones'] ?? 1)));
        if (!isset($intervalos[$repetir])) { $reps = 1; }

        $stmt = db()->prepare(
            'INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, tipo, motivo, estado, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $fecha = $c['fecha'];
        $creadas = 0;
        $primera = null;
        for ($i = 0; $i < $reps; $i++) {
            $stmt->execute([
                tenant_id(),
                (int) $c['paciente_id'], (int) $c['medico_id'], $fecha, $c['hora'],
                (int) ($c['duracion'] ?: 30), $c['tipo'] ?? 'medica',
                trim($c['motivo'] ?? '') ?: null, $c['estado'] ?? 'programada',
                trim($c['notas'] ?? '') ?: null,
            ]);
            if ($i === 0) { $primera = (int) db()->lastInsertId(); }
            $creadas++;
            $fecha = date('Y-m-d', strtotime($fecha . ' ' . $intervalos[$repetir]));
        }
        auditar('crear', 'cita', $primera, $creadas > 1 ? "serie de $creadas citas" : null);
        flash($creadas > 1 ? "Se agendaron $creadas citas." : 'Cita agendada correctamente.');
        redirect('/citas/index?desde=' . urlencode($c['fecha']));
    }
}

$mostrar_recurrencia = true;
$titulo = t('Nueva cita');
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/citas/index"><?= et('Citas') ?></a></li>
        <li class="breadcrumb-item active"><?= et('Nueva') ?></li>
    </ol>
</nav>
<h1 class="h3 mb-3"><?= et('Agendar cita') ?></h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body"><?= csrf_field() ?><?php include __DIR__ . '/_form.php'; ?></div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/citas/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Agendar') ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
