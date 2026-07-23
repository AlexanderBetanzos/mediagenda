<?php
/**
 * Plataforma — plan y módulos de un consultorio: asigna el plan y activa/
 * desactiva módulos puntuales (add-ons / cortesías) sobre lo que incluye el plan.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_platform();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
require_platform_consultorio($id);   // socios: solo sus consultorios asignados
$c  = db()->prepare('SELECT * FROM consultorios WHERE id = ?');
$c->execute([$id]);
$c = $c->fetch();
if (!$c) { http_response_code(404); die('Consultorio no encontrado.'); }

$planes  = planes_mp();
$modulos = db()->query('SELECT clave, nombre, fase FROM modulos ORDER BY orden')->fetchAll();

/** Módulos incluidos en un plan. */
function plan_incluye(string $plan): array
{
    $st = db()->prepare('SELECT modulo_clave FROM plan_modulos WHERE plan_clave = ?');
    $st->execute([$plan]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'datos') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $tel    = trim($_POST['telefono'] ?? '');
        $slug   = strtolower(trim($_POST['slug'] ?? ''));
        $slug   = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $estado = $_POST['estado'] ?? $c['estado'];
        if (!in_array($estado, ['trial', 'activa', 'suspendida', 'expirada'], true)) $estado = $c['estado'];
        if ($id === 1 && $estado !== 'activa') $estado = 'activa'; // el principal no se suspende

        if ($nombre === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $slug === '') {
            flash('Nombre, correo válido y slug son obligatorios.', 'warning');
            redirect('/platform/consultorio?id=' . $id);
        }
        $ex = db()->prepare('SELECT id FROM consultorios WHERE slug = ? AND id <> ?');
        $ex->execute([$slug, $id]);
        if ($ex->fetchColumn()) {
            flash('Ese slug ya está en uso por otro consultorio.', 'warning');
            redirect('/platform/consultorio?id=' . $id);
        }
        db()->prepare('UPDATE consultorios SET nombre=?, email=?, telefono=?, slug=?, estado=? WHERE id=?')
            ->execute([$nombre, $email, $tel ?: null, $slug, $estado, $id]);
        auditar('consultorio_editar', 'consultorio', $id, $nombre, $id);
        flash('Datos del consultorio actualizados.');
        redirect('/platform/consultorio?id=' . $id);
    }

    if ($accion === 'plan') {
        $nuevo = $_POST['plan'] ?? '';
        if (isset($planes[$nuevo])) {
            // Asignar un plan activa la membresía (deja de estar en prueba/expirada/suspendida).
            db()->prepare("UPDATE consultorios SET plan = ?, estado = 'activa' WHERE id = ?")->execute([$nuevo, $id]);
            auditar('plan_cambiar', 'consultorio', $id, $nuevo, $id);
            flash('Plan actualizado a ' . $planes[$nuevo]['nombre'] . ' y membresía activada.');
        } else {
            flash('Plan no válido.', 'danger');
        }
        redirect('/platform/consultorio?id=' . $id);
    }

    if ($accion === 'modulos') {
        $enPlan   = plan_incluye($c['plan']);
        $marcados = $_POST['mod'] ?? [];
        $up = db()->prepare(
            'INSERT INTO consultorio_modulos (consultorio_id, modulo_clave, activo) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE activo = VALUES(activo)'
        );
        $del = db()->prepare('DELETE FROM consultorio_modulos WHERE consultorio_id = ? AND modulo_clave = ?');
        foreach ($modulos as $m) {
            $clave    = $m['clave'];
            $deseado  = isset($marcados[$clave]) ? 1 : 0;
            $incluido = in_array($clave, $enPlan, true) ? 1 : 0;
            if ($deseado === $incluido) { $del->execute([$id, $clave]); }
            else { $up->execute([$id, $clave, $deseado]); }
        }
        auditar('modulos_editar', 'consultorio', $id, null, $id);
        flash('Módulos del consultorio actualizados.');
        redirect('/platform/consultorio?id=' . $id);
    }
}

$enPlan = plan_incluye($c['plan']);
$ov = db()->prepare('SELECT modulo_clave, activo FROM consultorio_modulos WHERE consultorio_id = ?');
$ov->execute([$id]);
$overrides = $ov->fetchAll(PDO::FETCH_KEY_PAIR);

$fases = [1 => 'Núcleo / Fase 1', 2 => 'Fase 2 — Crecer', 3 => 'Fase 3 — IA', 4 => 'Fase 4 — Hospital'];

$titulo = 'Plan · ' . $c['nombre'];
include __DIR__ . '/_head.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/platform/index"><?= et('Consultorios') ?></a></li>
        <li class="breadcrumb-item active"><?= e($c['nombre']) ?></li>
    </ol>
</nav>

<?php foreach (get_flash() as $f): ?>
    <div class="alert alert-<?= e($f['tipo']) ?> alert-dismissible fade show"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>

<h1 class="h3 mb-1"><i class="bi bi-pencil-square text-brand"></i> <?= et('Editar consultorio') ?></h1>
<p class="text-muted"><?= e($c['nombre']) ?> · <?= et('Estado:') ?> <strong><?= et(ucfirst($c['estado'])) ?></strong></p>

<!-- Datos generales -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-building text-brand"></i> <?= et('Datos generales') ?></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="accion" value="datos">
            <div class="col-md-6">
                <label class="form-label"><?= et('Nombre del consultorio') ?></label>
                <input type="text" name="nombre" class="form-control" value="<?= e($c['nombre']) ?>" maxlength="120" required>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Correo de contacto') ?></label>
                <input type="email" name="email" class="form-control" value="<?= e($c['email']) ?>" maxlength="150" required>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Teléfono') ?></label>
                <input type="text" name="telefono" class="form-control" value="<?= e($c['telefono'] ?? '') ?>" maxlength="40">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Slug') ?></label>
                <input type="text" name="slug" class="form-control" value="<?= e($c['slug']) ?>" maxlength="60" required <?= $id === 1 ? 'readonly' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Estado') ?></label>
                <select name="estado" class="form-select" <?= $id === 1 ? 'disabled' : '' ?>>
                    <?php foreach (['trial' => 'Trial', 'activa' => 'Activa', 'suspendida' => 'Suspendida', 'expirada' => 'Expirada'] as $ev => $el): ?>
                        <option value="<?= $ev ?>" <?= $c['estado'] === $ev ? 'selected' : '' ?>><?= et($el) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($id === 1): ?><input type="hidden" name="estado" value="activa"><div class="form-text"><?= et('El consultorio principal no se puede suspender.') ?></div><?php endif; ?>
            </div>
            <div class="col-12">
                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar datos') ?></button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <!-- Plan -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-stars text-brand"></i> <?= et('Plan contratado') ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="accion" value="plan">
                    <?php foreach ($planes as $k => $pl): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="plan" id="plan_<?= e($k) ?>" value="<?= e($k) ?>" <?= $c['plan'] === $k ? 'checked' : '' ?>>
                        <label class="form-check-label d-flex justify-content-between" for="plan_<?= e($k) ?>" style="width:100%">
                            <span><?= e($pl['nombre']) ?></span>
                            <span class="text-brand fw-semibold">$<?= number_format($pl['precio'], 0) ?><?= et('/mes') ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!isset($planes[$c['plan']])): ?>
                        <div class="alert alert-warning py-2 small mt-2">Plan actual «<?= e($c['plan']) ?>» sin definir: acceso total (fail-open). Asigna uno.</div>
                    <?php endif; ?>
                    <button class="btn btn-primary mt-2"><i class="bi bi-check-lg"></i> <?= et('Guardar plan') ?></button>
                </form>
            </div>
        </div>
    </div>

    <!-- Módulos -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-puzzle text-brand"></i> <?= et('Módulos') ?></span>
                <span class="small text-muted"><?= et('marcado = activo · fuera del plan = add-on') ?></span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="accion" value="modulos">
                    <?php foreach ($fases as $nf => $lf):
                        $deFase = array_filter($modulos, fn($m) => (int) $m['fase'] === $nf);
                        if (!$deFase) continue; ?>
                        <div class="fw-semibold small text-muted text-uppercase mt-2 mb-1"><?= et($lf) ?></div>
                        <?php foreach ($deFase as $m):
                            $clave    = $m['clave'];
                            $incluido = in_array($clave, $enPlan, true);
                            $efectivo = array_key_exists($clave, $overrides) ? (bool) $overrides[$clave] : $incluido;
                            $esAddon  = !$incluido && $efectivo;
                            $bloqueado = $incluido && !$efectivo; ?>
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input" type="checkbox" name="mod[<?= e($clave) ?>]" id="m_<?= e($clave) ?>" value="1" <?= $efectivo ? 'checked' : '' ?>>
                            <label class="form-check-label" for="m_<?= e($clave) ?>"><?= et($m['nombre']) ?></label>
                            <?php if ($incluido): ?><span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25"><?= et('en el plan') ?></span><?php endif; ?>
                            <?php if ($esAddon): ?><span class="badge bg-info bg-opacity-25 text-info">add-on</span><?php endif; ?>
                            <?php if ($bloqueado): ?><span class="badge bg-warning bg-opacity-25 text-warning"><?= et('desactivado') ?></span><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> <?= et('Guardar módulos') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_foot.php'; ?>
