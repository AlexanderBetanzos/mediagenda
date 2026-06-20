<?php
/**
 * Gestión de un consultorio (súper-admin): asignar plan y activar/desactivar
 * módulos puntuales (add-ons / cortesías) sobre lo que incluye su plan.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_superadmin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
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

    if ($accion === 'plan') {
        $nuevo = $_POST['plan'] ?? '';
        if (isset($planes[$nuevo])) {
            db()->prepare('UPDATE consultorios SET plan = ? WHERE id = ?')->execute([$nuevo, $id]);
            auditar('plan_cambiar', 'consultorio', $id, $nuevo, $id);
            flash('Plan actualizado a ' . $planes[$nuevo]['nombre'] . '.');
        } else {
            flash('Plan no válido.', 'danger');
        }
        redirect('/admin/consultorio?id=' . $id);
    }

    if ($accion === 'modulos') {
        $enPlan  = plan_incluye($c['plan']);
        $marcados = $_POST['mod'] ?? [];   // claves marcadas (activas)
        $up = db()->prepare(
            'INSERT INTO consultorio_modulos (consultorio_id, modulo_clave, activo) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE activo = VALUES(activo)'
        );
        $del = db()->prepare('DELETE FROM consultorio_modulos WHERE consultorio_id = ? AND modulo_clave = ?');
        foreach ($modulos as $m) {
            $clave   = $m['clave'];
            $deseado = isset($marcados[$clave]) ? 1 : 0;
            $incluido = in_array($clave, $enPlan, true) ? 1 : 0;
            // Solo guardamos override cuando difiere del plan; si coincide, lo quitamos.
            if ($deseado === $incluido) { $del->execute([$id, $clave]); }
            else { $up->execute([$id, $clave, $deseado]); }
        }
        auditar('modulos_editar', 'consultorio', $id, null, $id);
        flash('Módulos del consultorio actualizados.');
        redirect('/admin/consultorio?id=' . $id);
    }
}

// Estado efectivo de cada módulo para este consultorio.
$enPlan = plan_incluye($c['plan']);
$ov = db()->prepare('SELECT modulo_clave, activo FROM consultorio_modulos WHERE consultorio_id = ?');
$ov->execute([$id]);
$overrides = $ov->fetchAll(PDO::FETCH_KEY_PAIR);

$fases = [1 => 'Núcleo / Fase 1', 2 => 'Fase 2 — Crecer', 3 => 'Fase 3 — IA', 4 => 'Fase 4 — Hospital'];

$titulo = 'Plan · ' . $c['nombre'];
$activo = 'admin';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/admin/index">Consultorios</a></li>
        <li class="breadcrumb-item active"><?= e($c['nombre']) ?></li>
    </ol>
</nav>

<h1 class="h3 mb-1"><i class="bi bi-shield-lock text-brand"></i> <?= e($c['nombre']) ?></h1>
<p class="text-muted"><i class="bi bi-envelope"></i> <?= e($c['email']) ?> · Estado: <strong><?= ucfirst($c['estado']) ?></strong></p>

<div class="row g-4">
    <!-- Plan -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-stars text-brand"></i> Plan contratado</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="accion" value="plan">
                    <?php foreach ($planes as $k => $pl): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="plan" id="plan_<?= e($k) ?>" value="<?= e($k) ?>"
                               <?= $c['plan'] === $k ? 'checked' : '' ?>>
                        <label class="form-check-label d-flex justify-content-between" for="plan_<?= e($k) ?>" style="width:100%">
                            <span><?= e($pl['nombre']) ?></span>
                            <span class="text-brand fw-semibold">$<?= number_format($pl['precio'], 0) ?>/mes</span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php if (!isset($planes[$c['plan']])): ?>
                        <div class="alert alert-warning py-2 small mt-2">Plan actual «<?= e($c['plan']) ?>» sin definir: el consultorio tiene acceso total (fail-open). Asigna uno de la lista.</div>
                    <?php endif; ?>
                    <button class="btn btn-primary mt-2"><i class="bi bi-check-lg"></i> Guardar plan</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Módulos -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-puzzle text-brand"></i> Módulos</span>
                <span class="small text-muted">marcado = activo · fuera del plan = add-on</span>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="accion" value="modulos">
                    <?php foreach ($fases as $nf => $lf):
                        $deFase = array_filter($modulos, fn($m) => (int) $m['fase'] === $nf);
                        if (!$deFase) continue; ?>
                        <div class="fw-semibold small text-muted text-uppercase mt-2 mb-1"><?= e($lf) ?></div>
                        <?php foreach ($deFase as $m):
                            $clave    = $m['clave'];
                            $incluido = in_array($clave, $enPlan, true);
                            $efectivo = array_key_exists($clave, $overrides) ? (bool) $overrides[$clave] : $incluido;
                            $esAddon  = !$incluido && $efectivo;
                            $bloqueado = $incluido && !$efectivo; ?>
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input" type="checkbox" name="mod[<?= e($clave) ?>]" id="m_<?= e($clave) ?>" value="1" <?= $efectivo ? 'checked' : '' ?>>
                            <label class="form-check-label" for="m_<?= e($clave) ?>"><?= e($m['nombre']) ?></label>
                            <?php if ($incluido): ?><span class="badge bg-light text-success border">en el plan</span><?php endif; ?>
                            <?php if ($esAddon): ?><span class="badge bg-info-subtle text-info border">add-on</span><?php endif; ?>
                            <?php if ($bloqueado): ?><span class="badge bg-warning-subtle text-warning border">desactivado</span><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> Guardar módulos</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
