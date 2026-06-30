<?php
/** Gestión de plantillas de consulta (médico / admin). */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('plantillas');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }

$tid    = tenant_id();
$campos = ['nombre','tipo','motivo','exploracion','diagnostico','tratamiento','receta','notas'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'borrar') {
        db()->prepare('DELETE FROM plantillas_consulta WHERE id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $tid]);
        flash('Plantilla eliminada.');
        redirect('/plantillas/index');
    }

    if ($accion === 'guardar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') { flash('Ponle un nombre a la plantilla.', 'warning'); redirect('/plantillas/index'); }
        $tipo = in_array($_POST['tipo'] ?? '', ['general','medico','dental'], true) ? $_POST['tipo'] : 'general';
        $vals = [
            $nombre, $tipo,
            trim($_POST['motivo'] ?? '') ?: null,
            trim($_POST['exploracion'] ?? '') ?: null,
            trim($_POST['diagnostico'] ?? '') ?: null,
            trim($_POST['tratamiento'] ?? '') ?: null,
            trim($_POST['receta'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
        ];
        if ($id) {
            db()->prepare('UPDATE plantillas_consulta SET nombre=?, tipo=?, motivo=?, exploracion=?, diagnostico=?, tratamiento=?, receta=?, notas=? WHERE id=? AND consultorio_id=?')
                ->execute(array_merge($vals, [$id, $tid]));
            flash('Plantilla actualizada.');
        } else {
            db()->prepare('INSERT INTO plantillas_consulta (consultorio_id, nombre, tipo, motivo, exploracion, diagnostico, tratamiento, receta, notas, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$tid], $vals, [current_user()['id']]));
            flash('Plantilla creada.');
        }
        redirect('/plantillas/index');
    }
}

// Para editar: precarga.
$edit = (int) ($_GET['edit'] ?? 0);
$f = ['id'=>0,'nombre'=>'','tipo'=>'general','motivo'=>'','exploracion'=>'','diagnostico'=>'','tratamiento'=>'','receta'=>'','notas'=>''];
if ($edit) {
    $st = db()->prepare('SELECT * FROM plantillas_consulta WHERE id = ? AND consultorio_id = ?');
    $st->execute([$edit, $tid]);
    if ($r = $st->fetch()) { $f = $r; }
}

$plantillas = db()->prepare('SELECT * FROM plantillas_consulta WHERE consultorio_id = ? ORDER BY tipo, nombre');
$plantillas->execute([$tid]);
$plantillas = $plantillas->fetchAll();

$tipoLbl = ['general'=>t('General'),'medico'=>tipo_paciente_label('medico'),'dental'=>t('Dental')];
$v = fn($k) => e($f[$k] ?? '');

$titulo = t('Plantillas de consulta');
$activo = 'plantillas';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-file-earmark-text text-brand"></i> <?= et('Plantillas de consulta') ?></h1>
<p class="text-muted"><?= et('Crea formatos reutilizables para llenar más rápido el expediente.') ?></p>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><?= $f['id'] ? et('Editar plantilla') : et('Nueva plantilla') ?></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="guardar">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <div class="col-8"><label class="form-label"><?= et('Nombre') ?> *</label><input type="text" name="nombre" class="form-control" required maxlength="120" value="<?= $v('nombre') ?>"></div>
                    <div class="col-4"><label class="form-label"><?= et('Tipo') ?></label>
                        <select name="tipo" class="form-select">
                            <?php foreach ($tipoLbl as $k=>$l): ?><option value="<?= $k ?>" <?= $f['tipo']===$k?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label"><?= et('Motivo') ?></label><input type="text" name="motivo" class="form-control" value="<?= $v('motivo') ?>"></div>
                    <div class="col-md-6"><label class="form-label"><?= et('Exploración') ?></label><textarea name="exploracion" class="form-control" rows="2"><?= $v('exploracion') ?></textarea></div>
                    <div class="col-md-6"><label class="form-label"><?= et('Diagnóstico') ?></label><textarea name="diagnostico" class="form-control" rows="2"><?= $v('diagnostico') ?></textarea></div>
                    <div class="col-md-6"><label class="form-label"><?= et('Tratamiento') ?></label><textarea name="tratamiento" class="form-control" rows="2"><?= $v('tratamiento') ?></textarea></div>
                    <div class="col-md-6"><label class="form-label"><?= et('Receta') ?></label><textarea name="receta" class="form-control" rows="2"><?= $v('receta') ?></textarea></div>
                    <div class="col-12"><label class="form-label"><?= et('Notas') ?></label><textarea name="notas" class="form-control" rows="2"><?= $v('notas') ?></textarea></div>
                    <div class="col-12 text-end">
                        <?php if ($f['id']): ?><a href="<?= BASE_URL ?>/plantillas/index" class="btn btn-light btn-sm"><?= et('Cancelar') ?></a><?php endif; ?>
                        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><?= et('Mis plantillas') ?> (<?= count($plantillas) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$plantillas): ?>
                    <li class="list-group-item text-muted text-center py-4"><?= et('Aún no hay plantillas.') ?></li>
                <?php else: foreach ($plantillas as $pl): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-semibold"><?= e($pl['nombre']) ?></span>
                        <span class="badge bg-light text-dark border ms-1"><?= $tipoLbl[$pl['tipo']] ?></span>
                        <?php if ($pl['diagnostico']): ?><div class="small text-muted text-truncate" style="max-width:380px"><?= e($pl['diagnostico']) ?></div><?php endif; ?>
                    </div>
                    <div class="text-nowrap">
                        <a href="<?= BASE_URL ?>/plantillas/index?edit=<?= $pl['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar esta plantilla?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="borrar">
                            <input type="hidden" name="id" value="<?= $pl['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
