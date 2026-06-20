<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$q   = trim($_GET['q'] ?? '');
$con = $_GET['con'] ?? '';   // '' = todos, '1' = solo con expediente

$where  = ['p.consultorio_id = ?'];
$params = [tenant_id()];
if ($q !== '') {
    $where[] = '(p.nombre LIKE ? OR p.apellidos LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like);
}

// Por cada paciente: nº de consultas y datos de la última (fecha y diagnóstico).
$sql = 'SELECT p.id, p.nombre, p.apellidos, p.tipo,
               (SELECT COUNT(*) FROM consultas c WHERE c.paciente_id = p.id) AS num,
               (SELECT c.fecha FROM consultas c WHERE c.paciente_id = p.id
                  ORDER BY c.fecha DESC LIMIT 1) AS ultima,
               (SELECT c.diagnostico FROM consultas c WHERE c.paciente_id = p.id
                  ORDER BY c.fecha DESC LIMIT 1) AS dx
        FROM pacientes p';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
if ($con === '1') {
    // Solo pacientes con al menos una consulta registrada.
    $sql .= ($where ? ' AND' : ' WHERE') . ' EXISTS (SELECT 1 FROM consultas c WHERE c.paciente_id = p.id)';
}
// NULL (sin consultas) ordena al final con DESC; recientes primero.
$sql .= ' ORDER BY ultima DESC, p.apellidos, p.nombre';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$pacientes = $stmt->fetchAll();

$titulo = 'Expediente';
$activo = 'expediente';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-folder2-open text-brand"></i> Expediente clínico</h1>
</div>
<p class="text-muted">Pacientes ordenados por su consulta más reciente. Abre un expediente para ver el historial completo o registrar una nueva consulta.</p>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-md-5">
        <input type="search" name="q" class="form-control" placeholder="Buscar paciente por nombre…" value="<?= e($q) ?>">
    </div>
    <div class="col-sm-4 col-md-3">
        <select name="con" class="form-select">
            <option value="">Todos los pacientes</option>
            <option value="1" <?= $con === '1' ? 'selected' : '' ?>>Solo con expediente</option>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Buscar</button>
        <a href="<?= BASE_URL ?>/expediente/index" class="btn btn-link">Limpiar</a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Paciente</th><th>Tipo</th><th>Última consulta</th>
                    <th>Diagnóstico</th><th class="text-center">Consultas</th><th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$pacientes): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No se encontraron pacientes.</td></tr>
            <?php else: foreach ($pacientes as $p): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($p['apellidos'] . ', ' . $p['nombre']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="badge bg-<?= $p['tipo'] === 'dental' ? 'info' : 'primary' ?>">
                            <?= $p['tipo'] === 'dental' ? 'Dental' : 'Médico' ?>
                        </span>
                    </td>
                    <td><?= $p['ultima'] ? fmt_fecha($p['ultima']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= e($p['dx'] ?: '—') ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $p['num'] > 0 ? 'success' : 'light text-dark border' ?>"><?= (int) $p['num'] ?></span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver expediente">
                            <i class="bi bi-folder2-open"></i> Ver
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
