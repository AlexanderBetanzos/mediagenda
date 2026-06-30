<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');

$u = current_user();

// Filtros
$desde  = $_GET['desde']  ?? date('Y-m-d');
$hasta  = $_GET['hasta']  ?? '';
$estado = $_GET['estado'] ?? '';
$medico = $_GET['medico'] ?? ($u['rol'] === 'medico' ? (string) $u['id'] : '');

$where  = ['c.consultorio_id = ?', 'c.fecha >= ?'];
$params = [tenant_id(), $desde];
if ($hasta !== '')  { $where[] = 'c.fecha <= ?';  $params[] = $hasta; }
if ($estado !== '' && array_key_exists($estado, ['programada'=>1,'confirmada'=>1,'atendida'=>1,'cancelada'=>1,'no_asistio'=>1])) {
    $where[] = 'c.estado = ?'; $params[] = $estado;
}
if ($medico !== '' && ctype_digit($medico)) { $where[] = 'c.medico_id = ?'; $params[] = (int) $medico; }

$sql = "SELECT c.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel, u.nombre AS med_nombre
        FROM citas c
        JOIN pacientes p ON p.id = c.paciente_id
        JOIN usuarios  u ON u.id = c.medico_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.fecha, c.hora";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$citas = $stmt->fetchAll();

// Resumen por estado de los resultados filtrados (sin consultas extra).
$resumen = ['programada'=>0,'confirmada'=>0,'atendida'=>0,'no_asistio'=>0,'cancelada'=>0];
foreach ($citas as $c) { $resumen[$c['estado']] = ($resumen[$c['estado']] ?? 0) + 1; }

// Médicos para el filtro
$medicos = db()->prepare("SELECT id, nombre FROM usuarios WHERE rol='medico' AND activo=1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$titulo = t('Citas');
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-calendar-check text-brand"></i> <?= et('Agenda de citas') ?></h1>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/citas/sala" class="btn btn-outline-secondary"><i class="bi bi-hourglass-split"></i> <?= et('Sala') ?></a>
        <a href="<?= BASE_URL ?>/citas/calendario" class="btn btn-outline-secondary"><i class="bi bi-calendar3"></i> <?= et('Calendario') ?></a>
        <?php if (has_role('admin', 'medico')): ?>
        <a href="<?= BASE_URL ?>/citas/horarios" class="btn btn-outline-secondary"><i class="bi bi-clock-history"></i> <?= et('Horarios') ?></a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/citas/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nueva cita') ?></a>
    </div>
</div>

<form class="row g-2 mb-3 align-items-end" method="get">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1"><?= et('Desde') ?></label>
        <input type="date" name="desde" class="form-control" value="<?= e($desde) ?>">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1"><?= et('Hasta') ?></label>
        <input type="date" name="hasta" class="form-control" value="<?= e($hasta) ?>">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1"><?= et('Médico') ?></label>
        <select name="medico" class="form-select" <?= $u['rol']==='medico' ? 'disabled' : '' ?>>
            <option value=""><?= et('Todos') ?></option>
            <?php foreach ($medicos as $m): ?>
                <option value="<?= $m['id'] ?>" <?= (string)$m['id']===$medico ? 'selected':'' ?>><?= e($m['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1"><?= et('Estado') ?></label>
        <select name="estado" class="form-select">
            <option value=""><?= et('Todos') ?></option>
            <?php foreach (['programada','confirmada','atendida','cancelada','no_asistio'] as $es): ?>
                <option value="<?= $es ?>" <?= $estado===$es?'selected':'' ?>><?= estado_label($es) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> <?= et('Filtrar') ?></button>
        <a href="<?= BASE_URL ?>/citas/index" class="btn btn-link"><?= et('Limpiar') ?></a>
    </div>
</form>

<?php if ($citas): ?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <span class="text-muted small me-1"><?= count($citas) ?> cita<?= count($citas) === 1 ? '' : 's' ?>:</span>
    <?php foreach ($resumen as $es => $n): if ($n): ?>
        <span class="badge rounded-pill bg-<?= estado_badge($es) ?>"><?= estado_label($es) ?> · <?= $n ?></span>
    <?php endif; endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th><?= et('Fecha') ?></th><th><?= et('Hora') ?></th><th><?= et('Paciente') ?></th><th><?= et('Médico') ?></th><th><?= et('Tipo') ?></th><th><?= et('Motivo') ?></th><th><?= et('Estado') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr>
            </thead>
            <tbody>
            <?php if (!$citas): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= et('No hay citas con esos filtros.') ?></td></tr>
            <?php else: foreach ($citas as $c): ?>
                <tr>
                    <td><?= fmt_fecha($c['fecha']) ?><?= $c['fecha']===date('Y-m-d') ? ' <span class="badge bg-success">'.et('Hoy').'</span>' : '' ?></td>
                    <td><?= fmt_hora($c['hora']) ?></td>
                    <td><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $c['paciente_id'] ?>"><?= e($c['pac_nombre'].' '.$c['pac_ape']) ?></a></td>
                    <td class="small"><?= e($c['med_nombre']) ?></td>
                    <td><span class="badge bg-<?= $c['tipo']==='dental'?'info':'primary' ?>"><?= $c['tipo']==='dental'?et('Dental'):et('Médica') ?></span></td>
                    <td><?= e($c['motivo'] ?: '—') ?></td>
                    <td><span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span></td>
                    <td class="text-end text-nowrap">
                        <!-- Cambio rápido de estado -->
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><?= et('Estado') ?></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php foreach (['confirmada','atendida','cancelada','no_asistio','programada'] as $es): ?>
                                <li>
                                    <form action="<?= BASE_URL ?>/citas/estado" method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <input type="hidden" name="estado" value="<?= $es ?>">
                                        <button class="dropdown-item"><?= estado_label($es) ?></button>
                                    </form>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php if (modulo_activo('whatsapp')):
                            $wa = wa_link($c['pac_tel'], mensaje_recordatorio($c['pac_nombre'].' '.$c['pac_ape'], fmt_fecha($c['fecha']), fmt_hora($c['hora'])));
                            if ($wa): ?>
                        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Recordatorio por WhatsApp"><i class="bi bi-whatsapp"></i></a>
                        <?php endif; endif; ?>
                        <a href="<?= BASE_URL ?>/citas/edit?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
