<?php
/**
 * Sucursales y recursos (consultorios, cuartos, sillones, equipos).
 * Solo administradores; plan Clínica o prueba.
 */
require_once __DIR__ . '/../includes/sucursales.php';
require_role('admin');
asegurar_sucursales();
$tid = (int) tenant_id();
$permitido = sucursales_permitidas();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $permitido) {
    verify_csrf();
    $ac = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = mb_substr(trim((string) ($_POST['nombre'] ?? '')), 0, 120);
    if ($ac === 'sucursal' && $nombre !== '') {
        if ($id) db()->prepare('UPDATE sucursales SET nombre = ?, direccion = ?, telefono = ? WHERE id = ? AND consultorio_id = ?')
            ->execute([$nombre, trim((string) ($_POST['direccion'] ?? '')) ?: null, trim((string) ($_POST['telefono'] ?? '')) ?: null, $id, $tid]);
        else db()->prepare('INSERT INTO sucursales (consultorio_id, nombre, direccion, telefono) VALUES (?,?,?,?)')
            ->execute([$tid, $nombre, trim((string) ($_POST['direccion'] ?? '')) ?: null, trim((string) ($_POST['telefono'] ?? '')) ?: null]);
        auditar($id ? 'editar' : 'crear', 'sucursal', $id ?: (int) db()->lastInsertId(), $nombre);
        flash('Sucursal guardada.');
    } elseif ($ac === 'recurso' && $nombre !== '') {
        $suc = (int) ($_POST['sucursal_id'] ?? 0) ?: null;
        $tipo = isset(recurso_tipos()[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'consultorio';
        $enl = !empty($_POST['en_linea']) ? 1 : 0;
        if ($id) db()->prepare('UPDATE recursos SET nombre = ?, sucursal_id = ?, tipo = ?, en_linea = ? WHERE id = ? AND consultorio_id = ?')->execute([$nombre, $suc, $tipo, $enl, $id, $tid]);
        else db()->prepare('INSERT INTO recursos (consultorio_id, sucursal_id, nombre, tipo, en_linea) VALUES (?,?,?,?,?)')->execute([$tid, $suc, $nombre, $tipo, $enl]);
        auditar($id ? 'editar' : 'crear', 'recurso', $id ?: (int) db()->lastInsertId(), $nombre);
        flash('Recurso guardado.');
    } elseif ($ac === 'baja_sucursal') {
        db()->prepare('UPDATE sucursales SET activa = 0 WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
        auditar('borrar', 'sucursal', $id);
        flash('Sucursal dada de baja. Sus citas y horarios se conservan.', 'warning');
    } elseif ($ac === 'baja_recurso') {
        db()->prepare('UPDATE recursos SET activo = 0 WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
        auditar('borrar', 'recurso', $id);
        flash('Recurso dado de baja.', 'warning');
    }
    redirect('/sucursales/index');
}

$sucs = sucursales();
$recs = recursos();
$titulo = t('Sucursales y recursos');
$activo = 'sucursales';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-building text-brand"></i> <?= et('Sucursales y recursos') ?></h1>
<p class="text-muted"><?= et('Organiza tu agenda por sede y por consultorio, cuarto, sillón o equipo: nadie puede ocupar el mismo recurso dos veces a la misma hora.') ?></p>
<?php if (!$permitido): ?>
<div class="alert alert-info"><i class="bi bi-stars"></i> <?= et('Las sucursales y la agenda por recurso vienen en el plan Clínica.') ?> <a href="<?= BASE_URL ?>/pagos/index"><?= et('Ver planes') ?></a></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-5">
    <div class="card mb-3"><div class="card-header fw-semibold"><i class="bi bi-geo-alt"></i> <?= et('Sucursales') ?></div>
        <ul class="list-group list-group-flush">
        <?php if (!$sucs): ?><li class="list-group-item small text-muted"><?= et('Sin sucursales: todo se agenda en una sola sede.') ?></li><?php endif; ?>
        <?php foreach ($sucs as $s): ?>
            <li class="list-group-item">
                <form method="post" class="row g-1 align-items-center"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <div class="col-12"><input name="nombre" class="form-control form-control-sm fw-semibold" value="<?= e($s['nombre']) ?>" required></div>
                    <div class="col-7"><input name="direccion" class="form-control form-control-sm" value="<?= e($s['direccion'] ?? '') ?>" placeholder="<?= e(t('Dirección')) ?>"></div>
                    <div class="col-5"><input name="telefono" class="form-control form-control-sm" value="<?= e($s['telefono'] ?? '') ?>" placeholder="<?= e(t('Teléfono')) ?>"></div>
                    <div class="col-12 d-flex gap-1 mt-1"><button name="accion" value="sucursal" class="btn btn-sm btn-outline-primary" <?= $permitido ? '' : 'disabled' ?>><?= et('Guardar') ?></button>
                        <button name="accion" value="baja_sucursal" class="btn btn-sm btn-link text-danger" onclick="return confirm('<?= e(t('¿Dar de baja esta sucursal?')) ?>')" <?= $permitido ? '' : 'disabled' ?>><?= et('Dar de baja') ?></button></div>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
        <div class="card-body border-top">
            <form method="post" class="row g-1"><?= csrf_field() ?><input type="hidden" name="accion" value="sucursal">
                <div class="col-12"><input name="nombre" class="form-control form-control-sm" placeholder="<?= e(t('Nueva sucursal, p. ej. Sucursal Centro')) ?>" required></div>
                <div class="col-7"><input name="direccion" class="form-control form-control-sm" placeholder="<?= e(t('Dirección')) ?>"></div>
                <div class="col-5"><input name="telefono" class="form-control form-control-sm" placeholder="<?= e(t('Teléfono')) ?>"></div>
                <div class="col-12"><button class="btn btn-sm btn-primary mt-1" <?= $permitido ? '' : 'disabled' ?>><i class="bi bi-plus-lg"></i> <?= et('Agregar sucursal') ?></button></div>
            </form>
        </div>
    </div>
</div>
<div class="col-lg-7">
    <?php foreach ($recs as $r): ?><form id="rf<?= (int) $r['id'] ?>" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"></form><?php endforeach; ?>
    <form id="rfNuevo" method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="recurso"></form>
    <div class="card"><div class="card-header fw-semibold"><i class="bi bi-door-open"></i> <?= et('Recursos') ?></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th><?= et('Nombre') ?></th><th><?= et('Tipo') ?></th><th><?= et('Sucursal') ?></th><th class="text-center" title="<?= e(t('Se asigna en la agenda en línea')) ?>"><?= et('En línea') ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recs as $r): $f = 'rf' . (int) $r['id']; ?>
            <tr>
                <td><input form="<?= $f ?>" name="nombre" class="form-control form-control-sm" value="<?= e($r['nombre']) ?>" required></td>
                <td><select form="<?= $f ?>" name="tipo" class="form-select form-select-sm"><?php foreach (recurso_tipos() as $k => $l): ?><option value="<?= $k ?>" <?= $r['tipo'] === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></td>
                <td><select form="<?= $f ?>" name="sucursal_id" class="form-select form-select-sm"><option value=""><?= et('Todas') ?></option><?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $r['sucursal_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?></select></td>
                <td class="text-center"><input form="<?= $f ?>" type="checkbox" name="en_linea" value="1" class="form-check-input" <?= $r['en_linea'] ? 'checked' : '' ?>></td>
                <td class="text-nowrap"><button form="<?= $f ?>" name="accion" value="recurso" class="btn btn-sm btn-outline-primary" <?= $permitido ? '' : 'disabled' ?>><i class="bi bi-check-lg"></i></button>
                    <button form="<?= $f ?>" name="accion" value="baja_recurso" class="btn btn-sm btn-link text-danger" <?= $permitido ? '' : 'disabled' ?>><i class="bi bi-trash"></i></button></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-light">
                <td><input form="rfNuevo" name="nombre" class="form-control form-control-sm" placeholder="<?= e(t('Consultorio 1, Sillón 2…')) ?>" required></td>
                <td><select form="rfNuevo" name="tipo" class="form-select form-select-sm"><?php foreach (recurso_tipos() as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></td>
                <td><select form="rfNuevo" name="sucursal_id" class="form-select form-select-sm"><option value=""><?= et('Todas') ?></option><?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?></select></td>
                <td class="text-center"><input form="rfNuevo" type="checkbox" name="en_linea" value="1" class="form-check-input" checked></td>
                <td><button form="rfNuevo" class="btn btn-sm btn-primary" <?= $permitido ? '' : 'disabled' ?>><i class="bi bi-plus-lg"></i></button></td>
            </tr>
            </tbody>
        </table></div>
        <div class="card-body small text-muted border-top">
            <?= et('Cómo funciona: en Horarios indicas en qué sucursal atiende cada médico. Al agendar eliges el recurso (o se asigna solo en la agenda en línea) y el sistema no deja empalmar dos citas en el mismo recurso.') ?>
        </div>
    </div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
