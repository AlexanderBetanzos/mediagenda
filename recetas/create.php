<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('medico', 'admin');

$u = current_user();
$errores = [];
$presel = (int) ($_GET['paciente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    $medico_id   = (int) ($_POST['medico_id'] ?? $u['id']);
    $meds        = $_POST['med'] ?? [];

    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) $errores[] = t('Selecciona un paciente.');
    if (!pertenece_al_tenant('usuarios', $medico_id)) $medico_id = (int) $u['id'];
    // Al menos un medicamento con nombre
    $items = [];
    foreach ($meds as $m) {
        if (trim($m['medicamento'] ?? '') !== '') {
            $items[] = $m;
        }
    }
    if (!$items) $errores[] = t('Agrega al menos un medicamento.');

    if (!$errores) {
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO recetas (consultorio_id, paciente_id, medico_id, diagnostico, indicaciones, notas) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            tenant_id(), $paciente_id, $medico_id,
            trim($_POST['diagnostico'] ?? '') ?: null,
            trim($_POST['indicaciones'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
        ]);
        $rid = $pdo->lastInsertId();
        $it = $pdo->prepare('INSERT INTO receta_items (receta_id, medicamento, dosis, frecuencia, duracion) VALUES (?,?,?,?,?)');
        foreach ($items as $m) {
            $it->execute([
                $rid, trim($m['medicamento']),
                trim($m['dosis'] ?? '') ?: null,
                trim($m['frecuencia'] ?? '') ?: null,
                trim($m['duracion'] ?? '') ?: null,
            ]);
        }
        $pdo->commit();
        auditar('crear', 'receta', (int) $rid, 'Paciente #' . $paciente_id);
        flash('Receta creada correctamente.');
        redirect('/recetas/ver?id=' . $rid);
    }
}

$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();
$medicos   = db()->prepare("SELECT id, nombre FROM usuarios WHERE rol='medico' AND activo=1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$titulo = t('Nueva receta');
$activo = 'recetas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/recetas/index"><?= et('Recetas') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Nueva') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><?= et('Nueva receta') ?></h1>

<?php if ($errores): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label"><?= et('Paciente') ?> *</label>
                <select name="paciente_id" class="form-select" required>
                    <option value=""><?= et('— Selecciona —') ?></option>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $presel===$p['id']?'selected':'' ?>><?= e($p['apellidos'].', '.$p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Médico') ?> *</label>
                <select name="medico_id" class="form-select" required>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= (int)$u['id']===$m['id']?'selected':'' ?>><?= e($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label"><?= et('Diagnóstico') ?></label>
                <input type="text" name="diagnostico" class="form-control">
            </div>
        </div>

        <label class="form-label"><?= et('Medicamentos') ?> *</label>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="tablaMeds">
                <thead><tr><th><?= et('Medicamento') ?></th><th><?= et('Dosis') ?></th><th><?= et('Frecuencia') ?></th><th><?= et('Duración') ?></th><th></th></tr></thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="med[0][medicamento]" class="form-control" placeholder="Paracetamol 500mg"></td>
                        <td><input type="text" name="med[0][dosis]" class="form-control" placeholder="1 tableta"></td>
                        <td><input type="text" name="med[0][frecuencia]" class="form-control" placeholder="Cada 8 h"></td>
                        <td><input type="text" name="med[0][duracion]" class="form-control" placeholder="5 días"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="agregarMed"><i class="bi bi-plus"></i> <?= et('Agregar medicamento') ?></button>

        <div class="row g-3 mt-1">
            <div class="col-md-6"><label class="form-label"><?= et('Indicaciones generales') ?></label><textarea name="indicaciones" class="form-control" rows="3"></textarea></div>
            <div class="col-md-6"><label class="form-label"><?= et('Notas') ?></label><textarea name="notas" class="form-control" rows="3"></textarea></div>
        </div>
    </div>
    <div class="card-footer text-end">
        <a href="<?= BASE_URL ?>/recetas/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar receta') ?></button>
    </div>
</form>

<script>
let idx = 1;
document.getElementById('agregarMed').addEventListener('click', () => {
    const tb = document.querySelector('#tablaMeds tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="med[${idx}][medicamento]" class="form-control"></td>
        <td><input type="text" name="med[${idx}][dosis]" class="form-control"></td>
        <td><input type="text" name="med[${idx}][frecuencia]" class="form-control"></td>
        <td><input type="text" name="med[${idx}][duracion]" class="form-control"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>`;
    tb.appendChild(tr);
    idx++;
});
document.addEventListener('click', e => {
    if (e.target.closest('.quitar')) {
        const rows = document.querySelectorAll('#tablaMeds tbody tr');
        if (rows.length > 1) e.target.closest('tr').remove();
    }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
