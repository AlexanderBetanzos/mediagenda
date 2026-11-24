<?php
/**
 * Catálogo de medicamentos del consultorio y recetas favoritas.
 * El catálogo se llena solo al recetar (med_aprender); aquí se corrigen los
 * valores sugeridos, se marcan favoritos y se agregan genéricos comunes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recetas.php';
require_role('medico', 'admin');
require_modulo('recetas');
asegurar_recetas();

$tid = tenant_id();
$tab = ($_GET['tab'] ?? '') === 'favoritas' ? 'favoritas' : 'medicamentos';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);
    $txt = fn($k, $n = 120) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n) ?: null;

    if ($acc === 'guardar') {
        $nom = $txt('nombre', 200);
        if ($nom) {
            $dup = db()->prepare('SELECT id FROM medicamentos_cat WHERE consultorio_id = ? AND nombre = ? AND id <> ?');
            $dup->execute([$tid, $nom, $id]);
            if ($dup->fetchColumn()) {
                flash(t('Ese medicamento ya está en tu catálogo.'), 'warning');
            } elseif ($id) {
                db()->prepare('UPDATE medicamentos_cat SET nombre=?, dosis=?, via=?, frecuencia=?, duracion=? WHERE id=? AND consultorio_id=?')
                    ->execute([$nom, $txt('dosis'), $txt('via', 60), $txt('frecuencia'), $txt('duracion'), $id, $tid]);
                flash(t('Medicamento actualizado.'));
            } else {
                db()->prepare('INSERT INTO medicamentos_cat (consultorio_id, nombre, dosis, via, frecuencia, duracion) VALUES (?,?,?,?,?,?)')
                    ->execute([$tid, $nom, $txt('dosis'), $txt('via', 60), $txt('frecuencia'), $txt('duracion')]);
                flash(t('Medicamento agregado.'));
            }
        }
    } elseif ($acc === 'sugerido') {
        $nom = $txt('nombre', 200);
        $existe = false;
        foreach (med_catalogo() as $m) if (med_clave($m['nombre']) === med_clave((string) $nom)) $existe = true;
        if ($nom && !$existe) {
            db()->prepare('INSERT INTO medicamentos_cat (consultorio_id, nombre, via) VALUES (?,?,?)')->execute([$tid, $nom, $txt('via', 60)]);
            flash(t('Agregado a tu catálogo. Completa la dosis que sueles indicar.'));
            redirect('/recetas/medicamentos?editar=' . (int) db()->lastInsertId());
        }
    } elseif ($acc === 'favorito') {
        db()->prepare('UPDATE medicamentos_cat SET favorito = 1 - favorito WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
    } elseif ($acc === 'borrar') {
        db()->prepare('DELETE FROM medicamentos_cat WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
        flash(t('Medicamento quitado del catálogo.'));
    } elseif ($acc === 'borrar_fav') {
        db()->prepare('DELETE FROM receta_favoritas WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
        flash(t('Receta favorita eliminada.'));
        redirect('/recetas/medicamentos?tab=favoritas');
    }
    redirect('/recetas/medicamentos');
}

$cat = med_catalogo();
$editar = null;
if (!empty($_GET['editar'])) foreach ($cat as $m) if ((int) $m['id'] === (int) $_GET['editar']) $editar = $m;
$mios = [];
foreach ($cat as $m) $mios[med_clave($m['nombre'])] = true;
$sugeridos = array_values(array_filter(med_sugeridos(), fn($s) => !isset($mios[med_clave($s[0])])));
$favoritas = receta_favoritas();
$medicos = [];
foreach (db()->query('SELECT id, nombre FROM usuarios WHERE consultorio_id = ' . (int) $tid)->fetchAll() as $m) $medicos[(int) $m['id']] = $m['nombre'];

$titulo = t('Medicamentos y favoritas');
$activo = 'recetas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/recetas/index"><?= et('Recetas') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Medicamentos y favoritas') ?></li>
</ol></nav>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-journal-medical"></i> <?= et('Medicamentos y favoritas') ?></h1>
    <a href="<?= BASE_URL ?>/recetas/create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nueva receta') ?></a>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'medicamentos' ? 'active' : '' ?>" href="?tab=medicamentos"><?= et('Mis medicamentos') ?> <span class="badge bg-secondary-subtle text-body"><?= count($cat) ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'favoritas' ? 'active' : '' ?>" href="?tab=favoritas"><?= et('Recetas favoritas') ?> <span class="badge bg-secondary-subtle text-body"><?= count($favoritas) ?></span></a></li>
</ul>

<?php if ($tab === 'medicamentos'): ?>
<div class="card mb-3"><div class="card-body">
    <div class="fw-semibold mb-2"><?= $editar ? et('Editar medicamento') : et('Agregar medicamento') ?></div>
    <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
        <div class="col-md-4"><label class="form-label small"><?= et('Medicamento (genérico, concentración y presentación)') ?></label>
            <input type="text" name="nombre" class="form-control" required maxlength="200" value="<?= e($editar['nombre'] ?? '') ?>" placeholder="Paracetamol 500 mg tabletas"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Dosis') ?></label><input type="text" name="dosis" class="form-control" value="<?= e($editar['dosis'] ?? '') ?>" placeholder="1 tableta"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Vía') ?></label><input type="text" name="via" class="form-control" list="rxVias" value="<?= e($editar['via'] ?? '') ?>" placeholder="Oral"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Frecuencia') ?></label><input type="text" name="frecuencia" class="form-control" value="<?= e($editar['frecuencia'] ?? '') ?>" placeholder="Cada 8 h"></div>
        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Duración') ?></label><input type="text" name="duracion" class="form-control" value="<?= e($editar['duracion'] ?? '') ?>" placeholder="5 días"></div>
        <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $editar ? et('Guardar cambios') : et('Agregar') ?></button>
            <?php if ($editar): ?><a href="<?= BASE_URL ?>/recetas/medicamentos" class="btn btn-link"><?= et('Cancelar') ?></a><?php endif; ?>
        </div>
    </form>
    <datalist id="rxVias"><?php foreach (receta_vias() as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
    <div class="form-text mt-2"><?= et('Lo que recetas se agrega solo a este catálogo. Los valores de aquí se proponen al recetar; siempre puedes cambiarlos en cada receta.') ?></div>
</div></div>

<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th style="width:36px"></th><th><?= et('Medicamento') ?></th><th><?= et('Dosis') ?></th><th><?= et('Vía') ?></th><th><?= et('Frecuencia') ?></th><th><?= et('Duración') ?></th><th class="text-center"><?= et('Usos') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
            <tbody>
            <?php if (!$cat): ?>
                <tr><td colspan="8" class="text-center text-muted py-4"><?= et('Aún no hay medicamentos. Se agregan solos al recetar, o elige de los sugeridos de abajo.') ?></td></tr>
            <?php else: foreach ($cat as $m): ?>
                <tr>
                    <td>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="favorito"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button class="btn btn-sm btn-link p-0" title="<?= et('Favorito: sale primero al recetar') ?>"><i class="bi <?= $m['favorito'] ? 'bi-star-fill text-warning' : 'bi-star text-muted' ?>"></i></button></form>
                    </td>
                    <td class="fw-semibold"><?= e($m['nombre']) ?></td>
                    <td><?= e($m['dosis'] ?: '—') ?></td>
                    <td><?= e($m['via'] ?: '—') ?></td>
                    <td><?= e($m['frecuencia'] ?: '—') ?></td>
                    <td><?= e($m['duracion'] ?: '—') ?></td>
                    <td class="text-center"><?= (int) $m['usos'] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="?editar=<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Editar') ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('<?= e(t('¿Quitar este medicamento del catálogo?')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="<?= et('Quitar') ?>"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($sugeridos): ?>
<div class="card"><div class="card-body">
    <div class="fw-semibold mb-1"><i class="bi bi-lightbulb"></i> <?= et('Genéricos de uso común') ?></div>
    <p class="small text-muted mb-3"><?= et('Ya aparecen al recetar. Agrégalos a tu catálogo si quieres guardarles la dosis que sueles indicar.') ?></p>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($sugeridos as [$n, $v]): ?>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="sugerido">
            <input type="hidden" name="nombre" value="<?= e($n) ?>"><input type="hidden" name="via" value="<?= e($v) ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus"></i> <?= e($n) ?></button></form>
        <?php endforeach; ?>
    </div>
</div></div>
<?php endif; ?>

<?php else: ?>
<p class="text-muted small"><?= et('Para crear una, marca "Guardar también como receta favorita" al hacer una receta. Después aparece como botón en la receta nueva y la llenas con un clic.') ?></p>
<div class="row g-3">
    <?php if (!$favoritas): ?>
    <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4"><?= et('Todavía no tienes recetas favoritas.') ?></div></div></div>
    <?php endif; ?>
    <?php foreach ($favoritas as $f): ?>
    <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
                <div class="fw-semibold"><i class="bi bi-star-fill text-warning"></i> <?= e($f['nombre']) ?></div>
                <div class="small text-muted"><?= $f['medico_id'] ? e(t('Solo') . ' ' . ($medicos[(int) $f['medico_id']] ?? '')) : et('Todo el consultorio') ?> · <?= (int) $f['usos'] ?> <?= et('usos') ?></div>
            </div>
            <form method="post" onsubmit="return confirm('<?= e(t('¿Eliminar esta receta favorita?')) ?>');"><?= csrf_field() ?>
                <input type="hidden" name="accion" value="borrar_fav"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="<?= et('Eliminar') ?>"><i class="bi bi-trash"></i></button></form>
        </div>
        <?php if ($f['diagnostico']): ?><div class="small mt-2"><b><?= et('Diagnóstico') ?>:</b> <?= e($f['diagnostico']) ?></div><?php endif; ?>
        <ul class="small mt-2 mb-0 ps-3">
            <?php foreach ($f['items'] as $it): ?>
            <li><?= e($it['medicamento'] ?? '') ?><?php $det = array_filter([$it['dosis'] ?? '', $it['via'] ?? '', $it['frecuencia'] ?? '', $it['duracion'] ?? '']); if ($det): ?> <span class="text-muted">· <?= e(implode(' · ', $det)) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    </div></div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
