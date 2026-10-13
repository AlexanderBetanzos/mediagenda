<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('pacientes');

$q    = trim($_GET['q'] ?? '');
$tipo = $_GET['tipo'] ?? '';

$where  = ['consultorio_id = ?'];
$params = [tenant_id()];
$vet = es_veterinaria();
if ($vet) asegurar_campos_veterinaria();
if ($q !== '') {
    $like = "%$q%";
    if ($vet) {   // en veterinaria también se busca por dueño, especie, raza y microchip
        $where[] = '(nombre LIKE ? OR apellidos LIKE ? OR telefono LIKE ? OR email LIKE ? OR propietario LIKE ? OR especie LIKE ? OR raza LIKE ? OR microchip LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    } else {
        $where[] = '(nombre LIKE ? OR apellidos LIKE ? OR telefono LIKE ? OR email LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
}
if (in_array($tipo, ['medico', 'dental'], true)) {
    $where[] = 'tipo = ?';
    $params[] = $tipo;
}
$sql = 'SELECT * FROM pacientes';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY apellidos, nombre';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$pacientes = $stmt->fetchAll();

$titulo = t('Pacientes');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-people text-brand"></i> <?= et('Pacientes') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/create" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> <?= et('Nuevo paciente') ?>
    </a>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-md-5">
        <input type="search" name="q" class="form-control" placeholder="<?= $vet ? et('Buscar por mascota, propietario, especie, raza o teléfono…') : et('Buscar por nombre, teléfono o correo…') ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-sm-4 col-md-3<?= $vet ? ' d-none' : '' ?>">
        <select name="tipo" class="form-select">
            <option value=""><?= et('Todos los tipos') ?></option>
            <option value="medico" <?= $tipo === 'medico' ? 'selected' : '' ?>><?= e(tipo_paciente_label('medico')) ?></option>
            <option value="dental" <?= $tipo === 'dental' ? 'selected' : '' ?>><?= e(tipo_paciente_label('dental')) ?></option>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> <?= et('Buscar') ?></button>
        <a href="<?= BASE_URL ?>/pacientes/index" class="btn btn-link"><?= et('Limpiar') ?></a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= et('Paciente') ?></th><th><?= et('Edad') ?></th><th><?= $vet ? et('Especie') : et('Tipo') ?></th>
                    <th><?= et('Teléfono') ?></th><th><?= et('Correo') ?></th><th class="text-end"><?= et('Acciones') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pacientes): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('No se encontraron pacientes.') ?></td></tr>
            <?php else: foreach ($pacientes as $p): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $p['id'], $p['nombre'], $p['apellidos'], ($p['foto_mime'] ?? null) ?: ($p['foto'] ?? null)) ?>
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= e($vet ? $p['nombre'] : $p['apellidos'] . ', ' . $p['nombre']) ?>
                            </a>
                            <?php if ($vet && ($dueno = mascota_propietario($p)) !== ''): ?>
                                <div class="small text-muted"><i class="bi bi-person"></i> <?= e($dueno) ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= e(edad($p['fecha_nacimiento'])) ?></td>
                    <td>
                        <?php if ($vet): ?>
                            <span class="small"><?= e(mascota_resumen($p) ?: '—') ?></span>
                        <?php else: ?>
                        <span class="badge bg-<?= $p['tipo'] === 'dental' ? 'info' : 'primary' ?>">
                            <?= e(tipo_paciente_label($p['tipo'])) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['telefono'] ?: '—') ?></td>
                    <td><?= e($p['email'] ?: '—') ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Expediente') ?>"><i class="bi bi-folder2-open"></i></a>
                        <a href="<?= BASE_URL ?>/pacientes/edit?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= et('Editar') ?>"><i class="bi bi-pencil"></i></a>
                        <?php if (has_role('admin', 'recepcion')): ?>
                        <form action="<?= BASE_URL ?>/pacientes/delete" method="post" class="d-inline"
                              onsubmit="return confirm('¿Eliminar a <?= e(addslashes($p['nombre'].' '.$p['apellidos'])) ?>? Se borrarán también sus citas y consultas.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="<?= et('Eliminar') ?>"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
