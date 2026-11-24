<?php
/**
 * Cuartos y tarifas del internamiento: suite, habitación compartida,
 * internamiento de día… Cada uno con su capacidad de camas y cómo se cobra
 * (por noche, por día de asistencia o por hora). La tarifa se copia a cada
 * estancia al ingresar, así cambiar precios no altera lo ya pactado.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/hospitalizacion.php';
require_login();
require_modulo('expediente');
require_role('admin');
asegurar_hosp();

$tid = (int) tenant_id();
$mods = hosp_modalidades();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);
    if ($acc === 'guardar') {
        $nombre = mb_substr(trim((string) ($_POST['nombre'] ?? '')), 0, 80);
        if ($nombre === '') { flash(t('Ponle nombre al cuarto.'), 'warning'); redirect('/hospitalizacion/cuartos'); }
        $vals = [$nombre, mb_substr(trim((string) ($_POST['tipo'] ?? '')), 0, 60) ?: null,
                 array_key_exists($_POST['modalidad'] ?? '', $mods) ? $_POST['modalidad'] : 'noche',
                 max(0, (float) ($_POST['tarifa'] ?? 0)), max(1, min(50, (int) ($_POST['camas'] ?? 1))),
                 mb_substr(trim((string) ($_POST['descripcion'] ?? '')), 0, 255) ?: null];
        if ($id) {
            db()->prepare('UPDATE hosp_cuartos SET nombre=?, tipo=?, modalidad=?, tarifa=?, camas=?, descripcion=? WHERE id=? AND consultorio_id=?')
                ->execute([...$vals, $id, $tid]);
            flash(t('Cuarto actualizado. Los internados actuales conservan su tarifa pactada.'));
        } else {
            db()->prepare('INSERT INTO hosp_cuartos (consultorio_id, nombre, tipo, modalidad, tarifa, camas, descripcion) VALUES (?,?,?,?,?,?,?)')
                ->execute([$tid, ...$vals]);
            flash(t('Cuarto agregado.'));
        }
        auditar('editar', 'hosp_cuarto', $id, $nombre);
    } elseif ($acc === 'toggle' && $id) {
        db()->prepare('UPDATE hosp_cuartos SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
    } elseif ($acc === 'ejemplo') {
        // Arranque rápido con la estructura típica de un programa residencial
        // (precio en 0: cada clínica pone el suyo).
        $ins = db()->prepare('INSERT INTO hosp_cuartos (consultorio_id, nombre, tipo, modalidad, tarifa, camas, orden) VALUES (?,?,?,?,0,?,?)');
        foreach ([['Suite 1', 'Habitación individual', 'noche', 1], ['Suite 2', 'Habitación individual', 'noche', 1],
                  ['Habitación 3', 'Habitación compartida', 'noche', 2], ['Habitación 4', 'Habitación compartida', 'noche', 2],
                  ['Internamiento de día', 'Programa de día (2 a 10 h)', 'dia', 10]] as $k => [$n, $tp, $m, $cm]) {
            $ins->execute([$tid, $n, $tp, $m, $cm, $k]);
        }
        flash(t('Cuartos de ejemplo creados: ponles su precio.'));
    }
    redirect('/hospitalizacion/cuartos');
}

$cuartos = hosp_cuartos(false);
$ocup = hosp_ocupacion();
$edit = null;
foreach ($cuartos as $c) if ((int) $c['id'] === (int) ($_GET['editar'] ?? 0)) $edit = $c;

$titulo = t('Cuartos y tarifas');
$activo = 'hospitalizacion';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/hospitalizacion/index"><?= et('Hospitalización') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Cuartos y tarifas') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi bi-door-closed"></i> <?= et('Cuartos y tarifas') ?></h1>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <?php if (!$cuartos): ?>
            <div class="card-body text-center py-5">
                <i class="bi bi-door-closed fs-1 text-brand d-block mb-2"></i>
                <p class="text-muted mb-3"><?= et('Aún no tienes cuartos. Agrégalos a la derecha o empieza con una estructura de ejemplo.') ?></p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="ejemplo">
                    <button class="btn btn-outline-primary"><i class="bi bi-magic"></i> <?= et('Crear suites, habitaciones e internamiento de día') ?></button></form>
            </div>
            <?php else: ?>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th><?= et('Cuarto') ?></th><th><?= et('Cobro') ?></th><th class="text-end"><?= et('Tarifa') ?></th><th class="text-center"><?= et('Camas') ?></th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php foreach ($cuartos as $c): ?>
                <tr class="<?= $c['activo'] ? '' : 'opacity-50' ?>">
                    <td><b><?= e($c['nombre']) ?></b><?php if ($c['tipo']): ?><div class="small text-muted"><?= e($c['tipo']) ?></div><?php endif; ?></td>
                    <td class="small"><?= e(t($mods[$c['modalidad']] ?? $c['modalidad'])) ?></td>
                    <td class="text-end fw-semibold"><?= (float) $c['tarifa'] > 0 ? fmt_money($c['tarifa']) : '<span class="text-warning">' . et('Sin precio') . '</span>' ?></td>
                    <td class="text-center"><?= (int) ($ocup[(int) $c['id']] ?? 0) ?> / <?= (int) $c['camas'] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="?editar=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="<?= e($c['activo'] ? t('Desactivar') : t('Activar')) ?>"><i class="bi <?= $c['activo'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
        <p class="small text-muted mt-2"><i class="bi bi-info-circle"></i>
            <?= et('Por noche: el día de ingreso cuenta y el de alta no (23 → 25 = 2 noches). Por día: se cobra cada día del ingreso al alta. Por hora: se carga a mano desde la cuenta del paciente.') ?></p>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <h2 class="h6 mb-3"><?= $edit ? et('Editar cuarto') : et('Nuevo cuarto') ?></h2>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <div class="col-7"><label class="form-label"><?= et('Nombre') ?> *</label><input name="nombre" class="form-control" required maxlength="80" value="<?= e($edit['nombre'] ?? '') ?>" placeholder="<?= e(t('Suite 1, Habitación 3…')) ?>"></div>
                <div class="col-5"><label class="form-label"><?= et('Camas') ?></label><input type="number" min="1" max="50" name="camas" class="form-control" value="<?= (int) ($edit['camas'] ?? 1) ?>"></div>
                <div class="col-12"><label class="form-label"><?= et('Tipo') ?></label><input name="tipo" class="form-control" maxlength="60" value="<?= e($edit['tipo'] ?? '') ?>" placeholder="<?= e(t('Habitación individual, compartida…')) ?>"></div>
                <div class="col-7"><label class="form-label"><?= et('Cómo se cobra') ?></label>
                    <select name="modalidad" class="form-select"><?php foreach ($mods as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['modalidad'] ?? 'noche') === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-5"><label class="form-label"><?= et('Tarifa') ?></label><input type="number" step="0.01" min="0" name="tarifa" class="form-control" value="<?= e((string) ($edit['tarifa'] ?? '')) ?>"></div>
                <div class="col-12"><label class="form-label"><?= et('Incluye') ?></label><input name="descripcion" class="form-control" maxlength="255" value="<?= e($edit['descripcion'] ?? '') ?>" placeholder="<?= e(t('Enfermería 24 h, alimentos, actividades…')) ?>"></div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                    <?php if ($edit): ?><a href="<?= BASE_URL ?>/hospitalizacion/cuartos" class="btn btn-light"><?= et('Cancelar') ?></a><?php endif; ?>
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
                </div>
            </form>
        </div></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
