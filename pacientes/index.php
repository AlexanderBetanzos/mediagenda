<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$q    = trim($_GET['q'] ?? '');
$tipo = $_GET['tipo'] ?? '';

$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(nombre LIKE ? OR apellidos LIKE ? OR telefono LIKE ? OR email LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
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

$titulo = 'Pacientes';
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-people text-brand"></i> Pacientes</h1>
    <a href="<?= BASE_URL ?>/pacientes/create.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> Nuevo paciente
    </a>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-md-5">
        <input type="search" name="q" class="form-control" placeholder="Buscar por nombre, teléfono o correo…" value="<?= e($q) ?>">
    </div>
    <div class="col-sm-4 col-md-3">
        <select name="tipo" class="form-select">
            <option value="">Todos los tipos</option>
            <option value="medico" <?= $tipo === 'medico' ? 'selected' : '' ?>>Médico</option>
            <option value="dental" <?= $tipo === 'dental' ? 'selected' : '' ?>>Dental</option>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Buscar</button>
        <a href="<?= BASE_URL ?>/pacientes/index.php" class="btn btn-link">Limpiar</a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Paciente</th><th>Edad</th><th>Tipo</th>
                    <th>Teléfono</th><th>Correo</th><th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pacientes): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No se encontraron pacientes.</td></tr>
            <?php else: foreach ($pacientes as $p): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($p['apellidos'] . ', ' . $p['nombre']) ?>
                        </a>
                    </td>
                    <td><?= e(edad($p['fecha_nacimiento'])) ?></td>
                    <td>
                        <span class="badge bg-<?= $p['tipo'] === 'dental' ? 'info' : 'primary' ?>">
                            <?= $p['tipo'] === 'dental' ? 'Dental' : 'Médico' ?>
                        </span>
                    </td>
                    <td><?= e($p['telefono'] ?: '—') ?></td>
                    <td><?= e($p['email'] ?: '—') ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Expediente"><i class="bi bi-folder2-open"></i></a>
                        <a href="<?= BASE_URL ?>/pacientes/edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
                        <?php if (has_role('admin', 'recepcion')): ?>
                        <form action="<?= BASE_URL ?>/pacientes/delete.php" method="post" class="d-inline"
                              onsubmit="return confirm('¿Eliminar a <?= e(addslashes($p['nombre'].' '.$p['apellidos'])) ?>? Se borrarán también sus citas y consultas.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
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
