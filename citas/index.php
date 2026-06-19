<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();

// Filtros
$desde  = $_GET['desde']  ?? date('Y-m-d');
$hasta  = $_GET['hasta']  ?? '';
$estado = $_GET['estado'] ?? '';
$medico = $_GET['medico'] ?? ($u['rol'] === 'medico' ? (string) $u['id'] : '');

$where  = ['c.fecha >= ?'];
$params = [$desde];
if ($hasta !== '')  { $where[] = 'c.fecha <= ?';  $params[] = $hasta; }
if ($estado !== '' && array_key_exists($estado, ['programada'=>1,'confirmada'=>1,'atendida'=>1,'cancelada'=>1,'no_asistio'=>1])) {
    $where[] = 'c.estado = ?'; $params[] = $estado;
}
if ($medico !== '' && ctype_digit($medico)) { $where[] = 'c.medico_id = ?'; $params[] = (int) $medico; }

$sql = "SELECT c.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, u.nombre AS med_nombre
        FROM citas c
        JOIN pacientes p ON p.id = c.paciente_id
        JOIN usuarios  u ON u.id = c.medico_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.fecha, c.hora";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$citas = $stmt->fetchAll();

// Médicos para el filtro
$medicos = db()->query("SELECT id, nombre FROM usuarios WHERE rol='medico' AND activo=1 ORDER BY nombre")->fetchAll();

$titulo = 'Citas';
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-calendar-check text-brand"></i> Agenda de citas</h1>
    <a href="<?= BASE_URL ?>/citas/create.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nueva cita</a>
</div>

<form class="row g-2 mb-3 align-items-end" method="get">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Desde</label>
        <input type="date" name="desde" class="form-control" value="<?= e($desde) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Hasta</label>
        <input type="date" name="hasta" class="form-control" value="<?= e($hasta) ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Médico</label>
        <select name="medico" class="form-select" <?= $u['rol']==='medico' ? 'disabled' : '' ?>>
            <option value="">Todos</option>
            <?php foreach ($medicos as $m): ?>
                <option value="<?= $m['id'] ?>" <?= (string)$m['id']===$medico ? 'selected':'' ?>><?= e($m['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Estado</label>
        <select name="estado" class="form-select">
            <option value="">Todos</option>
            <?php foreach (['programada','confirmada','atendida','cancelada','no_asistio'] as $es): ?>
                <option value="<?= $es ?>" <?= $estado===$es?'selected':'' ?>><?= estado_label($es) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filtrar</button>
        <a href="<?= BASE_URL ?>/citas/index.php" class="btn btn-link">Limpiar</a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Fecha</th><th>Hora</th><th>Paciente</th><th>Médico</th><th>Tipo</th><th>Motivo</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php if (!$citas): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No hay citas con esos filtros.</td></tr>
            <?php else: foreach ($citas as $c): ?>
                <tr>
                    <td><?= fmt_fecha($c['fecha']) ?><?= $c['fecha']===date('Y-m-d') ? ' <span class="badge bg-success">Hoy</span>' : '' ?></td>
                    <td><?= fmt_hora($c['hora']) ?></td>
                    <td><a href="<?= BASE_URL ?>/pacientes/ver.php?id=<?= $c['paciente_id'] ?>"><?= e($c['pac_nombre'].' '.$c['pac_ape']) ?></a></td>
                    <td class="small"><?= e($c['med_nombre']) ?></td>
                    <td><span class="badge bg-<?= $c['tipo']==='dental'?'info':'primary' ?>"><?= $c['tipo']==='dental'?'Dental':'Médica' ?></span></td>
                    <td><?= e($c['motivo'] ?: '—') ?></td>
                    <td><span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span></td>
                    <td class="text-end text-nowrap">
                        <!-- Cambio rápido de estado -->
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">Estado</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php foreach (['confirmada','atendida','cancelada','no_asistio','programada'] as $es): ?>
                                <li>
                                    <form action="<?= BASE_URL ?>/citas/estado.php" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <input type="hidden" name="estado" value="<?= $es ?>">
                                        <button class="dropdown-item"><?= estado_label($es) ?></button>
                                    </form>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <a href="<?= BASE_URL ?>/citas/edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
