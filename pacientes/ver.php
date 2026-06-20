<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u  = current_user();
$id = (int) ($_GET['id'] ?? $_POST['paciente_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

/* --- Alta de consulta (expediente). Solo médicos y admin. --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'consulta') {
    verify_csrf();
    if (!has_role('medico', 'admin')) { http_response_code(403); die('Sin permiso.'); }

    $stmt = db()->prepare(
        'INSERT INTO consultas
         (consultorio_id, paciente_id, medico_id, motivo, exploracion, diagnostico, tratamiento, receta,
          peso, estatura, presion, temperatura, notas)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        tenant_id(), $id, $u['id'],
        trim($_POST['motivo'] ?? '') ?: null,
        trim($_POST['exploracion'] ?? '') ?: null,
        trim($_POST['diagnostico'] ?? '') ?: null,
        trim($_POST['tratamiento'] ?? '') ?: null,
        trim($_POST['receta'] ?? '') ?: null,
        $_POST['peso'] !== '' ? $_POST['peso'] : null,
        $_POST['estatura'] !== '' ? $_POST['estatura'] : null,
        trim($_POST['presion'] ?? '') ?: null,
        $_POST['temperatura'] !== '' ? $_POST['temperatura'] : null,
        trim($_POST['notas'] ?? '') ?: null,
    ]);
    flash('Consulta agregada al expediente.');
    redirect('/pacientes/ver.php?id=' . $id);
}

// Citas del paciente
$citas = db()->prepare(
    'SELECT c.*, u.nombre AS med_nombre FROM citas c
     JOIN usuarios u ON u.id = c.medico_id
     WHERE c.paciente_id = ? AND c.consultorio_id = ? ORDER BY c.fecha DESC, c.hora DESC'
);
$citas->execute([$id, tenant_id()]);
$citas = $citas->fetchAll();

// Consultas (expediente)
$cons = db()->prepare(
    'SELECT co.*, u.nombre AS med_nombre FROM consultas co
     JOIN usuarios u ON u.id = co.medico_id
     WHERE co.paciente_id = ? AND co.consultorio_id = ? ORDER BY co.fecha DESC'
);
$cons->execute([$id, tenant_id()]);
$cons = $cons->fetchAll();

$titulo = $p['nombre'] . ' ' . $p['apellidos'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index.php">Pacientes</a></li>
        <li class="breadcrumb-item active"><?= e($p['nombre'].' '.$p['apellidos']) ?></li>
    </ol>
</nav>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h1 class="h3 mb-1">
            <?= e($p['nombre'].' '.$p['apellidos']) ?>
            <span class="badge bg-<?= $p['tipo'] === 'dental' ? 'info' : 'primary' ?> align-middle">
                <?= $p['tipo'] === 'dental' ? 'Dental' : 'Médico' ?>
            </span>
        </h1>
        <span class="text-muted">
            <?= e(edad($p['fecha_nacimiento'])) ?> ·
            <?= $p['sexo'] === 'F' ? 'Femenino' : ($p['sexo'] === 'M' ? 'Masculino' : 'Sexo n/d') ?>
        </span>
    </div>
    <div class="text-nowrap">
        <a href="<?= BASE_URL ?>/citas/create.php?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-calendar-plus"></i> Cita</a>
        <?php if (has_role('medico', 'admin')): ?>
        <a href="<?= BASE_URL ?>/recetas/create.php?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-capsule"></i> Receta</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/facturacion/create.php?paciente_id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-receipt"></i> Factura</a>
        <a href="<?= BASE_URL ?>/pacientes/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
    </div>
</div>

<div class="row g-4">
    <!-- Ficha del paciente -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-vcard text-brand"></i> Datos de contacto</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item"><i class="bi bi-telephone me-2 text-muted"></i><?= e($p['telefono'] ?: '—') ?></li>
                <li class="list-group-item"><i class="bi bi-envelope me-2 text-muted"></i><?= e($p['email'] ?: '—') ?></li>
                <li class="list-group-item"><i class="bi bi-geo-alt me-2 text-muted"></i><?= e($p['direccion'] ?: '—') ?></li>
                <li class="list-group-item"><i class="bi bi-calendar me-2 text-muted"></i><?= fmt_fecha($p['fecha_nacimiento']) ?></li>
            </ul>
        </div>
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> Información clínica</div>
            <div class="card-body">
                <p class="mb-2"><strong>Alergias:</strong><br><?= nl2br(e($p['alergias'] ?: '—')) ?></p>
                <p class="mb-2"><strong>Antecedentes:</strong><br><?= nl2br(e($p['antecedentes'] ?: '—')) ?></p>
                <p class="mb-0"><strong>Notas:</strong><br><?= nl2br(e($p['notas'] ?: '—')) ?></p>
            </div>
        </div>
    </div>

    <!-- Expediente / citas -->
    <div class="col-lg-8">
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-exp"><i class="bi bi-file-medical"></i> Expediente (<?= count($cons) ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-citas"><i class="bi bi-calendar-check"></i> Citas (<?= count($citas) ?>)</button></li>
        </ul>

        <div class="tab-content">
            <!-- Expediente -->
            <div class="tab-pane fade show active" id="tab-exp">
                <?php if (has_role('medico', 'admin')): ?>
                <div class="text-end mb-3">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formConsulta">
                        <i class="bi bi-plus-lg"></i> Nueva consulta
                    </button>
                </div>
                <div class="collapse mb-4" id="formConsulta">
                    <div class="card card-body">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="consulta">
                            <input type="hidden" name="paciente_id" value="<?= $id ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Motivo de consulta</label>
                                    <input type="text" name="motivo" class="form-control">
                                </div>
                                <div class="col-md-3"><label class="form-label">Peso (kg)</label><input type="number" step="0.01" name="peso" class="form-control"></div>
                                <div class="col-md-3"><label class="form-label">Estatura (cm)</label><input type="number" step="0.01" name="estatura" class="form-control"></div>
                                <div class="col-md-3"><label class="form-label">Presión</label><input type="text" name="presion" class="form-control" placeholder="120/80"></div>
                                <div class="col-md-3"><label class="form-label">Temp. (°C)</label><input type="number" step="0.1" name="temperatura" class="form-control"></div>
                                <div class="col-md-6"><label class="form-label">Exploración</label><textarea name="exploracion" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Diagnóstico</label><textarea name="diagnostico" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Tratamiento</label><textarea name="tratamiento" class="form-control" rows="2"></textarea></div>
                                <div class="col-md-6"><label class="form-label">Receta</label><textarea name="receta" class="form-control" rows="2"></textarea></div>
                                <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"></textarea></div>
                            </div>
                            <div class="text-end mt-3">
                                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar consulta</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$cons): ?>
                    <p class="text-muted text-center py-4">Sin consultas registradas.</p>
                <?php else: foreach ($cons as $c): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-white d-flex justify-content-between">
                            <span class="fw-semibold"><i class="bi bi-file-medical text-brand"></i> <?= fmt_fecha($c['fecha']) ?> · <?= date('H:i', strtotime($c['fecha'])) ?></span>
                            <span class="text-muted small"><?= e($c['med_nombre']) ?></span>
                        </div>
                        <div class="card-body">
                            <?php if ($c['motivo']): ?><p class="mb-2"><strong>Motivo:</strong> <?= e($c['motivo']) ?></p><?php endif; ?>
                            <?php
                            $vitales = array_filter([
                                $c['peso'] ? "Peso: {$c['peso']} kg" : null,
                                $c['estatura'] ? "Estatura: {$c['estatura']} cm" : null,
                                $c['presion'] ? "PA: {$c['presion']}" : null,
                                $c['temperatura'] ? "Temp: {$c['temperatura']} °C" : null,
                            ]);
                            if ($vitales): ?>
                                <p class="mb-2"><span class="badge bg-light text-dark border me-1"><?= implode('</span> <span class="badge bg-light text-dark border me-1">', array_map('e', $vitales)) ?></span></p>
                            <?php endif; ?>
                            <div class="row">
                                <?php foreach (['exploracion'=>'Exploración','diagnostico'=>'Diagnóstico','tratamiento'=>'Tratamiento','receta'=>'Receta'] as $k=>$lbl):
                                    if ($c[$k]): ?>
                                    <div class="col-md-6 mb-2"><strong><?= $lbl ?>:</strong><br><?= nl2br(e($c[$k])) ?></div>
                                <?php endif; endforeach; ?>
                            </div>
                            <?php if ($c['notas']): ?><p class="mb-0 text-muted"><small><?= nl2br(e($c['notas'])) ?></small></p><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <!-- Citas -->
            <div class="tab-pane fade" id="tab-citas">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Fecha</th><th>Hora</th><th>Médico</th><th>Motivo</th><th>Estado</th></tr></thead>
                            <tbody>
                            <?php if (!$citas): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Sin citas.</td></tr>
                            <?php else: foreach ($citas as $c): ?>
                                <tr>
                                    <td><?= fmt_fecha($c['fecha']) ?></td>
                                    <td><?= fmt_hora($c['hora']) ?></td>
                                    <td class="small"><?= e($c['med_nombre']) ?></td>
                                    <td><?= e($c['motivo'] ?: '—') ?></td>
                                    <td><span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
