<?php
/** Gestión de plantillas de consulta (médico / admin). */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('plantillas');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }

// Self-healing: asegura la columna de especialidad (por si aún no se corrió la migración).
try { db()->exec("ALTER TABLE plantillas_consulta ADD COLUMN IF NOT EXISTS especialidad VARCHAR(80) DEFAULT NULL"); } catch (Throwable $e) {}

$tid = tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'borrar') {
        db()->prepare('DELETE FROM plantillas_consulta WHERE id = ? AND consultorio_id = ?')
            ->execute([(int) ($_POST['id'] ?? 0), $tid]);
        flash('Plantilla eliminada.');
        redirect('/plantillas/index');
    }

    // Carga inicial: plantillas de muchas especialidades para no partir de cero.
    if ($accion === 'sembrar') {
        $ya = db()->prepare('SELECT LOWER(nombre) FROM plantillas_consulta WHERE consultorio_id = ?');
        $ya->execute([$tid]);
        $existentes = array_flip($ya->fetchAll(PDO::FETCH_COLUMN));
        $ins = db()->prepare('INSERT INTO plantillas_consulta (consultorio_id, nombre, especialidad, tipo, motivo, exploracion, diagnostico, tratamiento, receta, notas, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $n = 0;
        foreach (plantillas_semilla() as $p) {
            [$nombre, $esp, $tipo, $motivo, $expl, $dx, $tx, $rx, $notas] = $p;
            if (isset($existentes[mb_strtolower($nombre)])) continue;
            $ins->execute([$tid, $nombre, $esp, $tipo, $motivo ?: null, $expl ?: null, $dx ?: null, $tx ?: null, $rx ?: null, $notas ?: null, current_user()['id']]);
            $n++;
        }
        flash($n > 0 ? "Se agregaron $n plantillas por especialidad." : 'Ya tenías todas las plantillas base.');
        redirect('/plantillas/index');
    }

    if ($accion === 'guardar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') { flash('Ponle un nombre a la plantilla.', 'warning'); redirect('/plantillas/index'); }
        $tipo = in_array($_POST['tipo'] ?? '', ['general','medico','dental'], true) ? $_POST['tipo'] : 'general';
        $esp  = trim($_POST['especialidad'] ?? '') ?: null;
        $vals = [
            $nombre, $esp, $tipo,
            trim($_POST['motivo'] ?? '') ?: null,
            trim($_POST['exploracion'] ?? '') ?: null,
            trim($_POST['diagnostico'] ?? '') ?: null,
            trim($_POST['tratamiento'] ?? '') ?: null,
            trim($_POST['receta'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
        ];
        if ($id) {
            db()->prepare('UPDATE plantillas_consulta SET nombre=?, especialidad=?, tipo=?, motivo=?, exploracion=?, diagnostico=?, tratamiento=?, receta=?, notas=? WHERE id=? AND consultorio_id=?')
                ->execute(array_merge($vals, [$id, $tid]));
            flash('Plantilla actualizada.');
        } else {
            db()->prepare('INSERT INTO plantillas_consulta (consultorio_id, nombre, especialidad, tipo, motivo, exploracion, diagnostico, tratamiento, receta, notas, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$tid], $vals, [current_user()['id']]));
            flash('Plantilla creada.');
        }
        redirect('/plantillas/index');
    }
}

// Para editar: precarga.
$edit = (int) ($_GET['edit'] ?? 0);
$f = ['id'=>0,'nombre'=>'','especialidad'=>'','tipo'=>'general','motivo'=>'','exploracion'=>'','diagnostico'=>'','tratamiento'=>'','receta'=>'','notas'=>''];
if ($edit) {
    $st = db()->prepare('SELECT * FROM plantillas_consulta WHERE id = ? AND consultorio_id = ?');
    $st->execute([$edit, $tid]);
    if ($r = $st->fetch()) { $f = $r; }
}

$plantillas = db()->prepare("SELECT * FROM plantillas_consulta WHERE consultorio_id = ?
                             ORDER BY (especialidad IS NULL), especialidad, nombre");
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
                    <div class="col-12"><label class="form-label"><?= et('Nombre') ?> *</label><input type="text" name="nombre" class="form-control" required maxlength="120" value="<?= $v('nombre') ?>"></div>
                    <div class="col-8"><label class="form-label"><?= et('Especialidad') ?></label>
                        <input type="text" name="especialidad" class="form-control" list="lstEsp" maxlength="80" value="<?= $v('especialidad') ?>" placeholder="<?= et('Ej. Cardiología') ?>">
                        <datalist id="lstEsp"><?php foreach (especialidades_catalogo() as $e): ?><option value="<?= e($e) ?>"><?php endforeach; ?></datalist>
                    </div>
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
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?= et('Mis plantillas') ?> (<?= count($plantillas) ?>)</span>
                <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Agregar el paquete de plantillas por especialidad? No duplica las que ya tengas.')) ?>');">
                    <?= csrf_field() ?><input type="hidden" name="accion" value="sembrar">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> <?= et('Sembrar por especialidad') ?></button>
                </form>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (!$plantillas): ?>
                    <li class="list-group-item text-muted text-center py-4"><?= et('Aún no hay plantillas. Usa «Sembrar por especialidad» para empezar con formatos de muchas áreas.') ?></li>
                <?php else: $espActual = '__none__'; foreach ($plantillas as $pl): ?>
                    <?php $espPl = $pl['especialidad'] ?? ''; if ($espPl !== $espActual): $espActual = $espPl; ?>
                        <li class="list-group-item bg-body-secondary py-1 small fw-semibold text-uppercase text-muted" style="letter-spacing:.03em"><?= e($espPl !== '' ? $espPl : t('Sin especialidad')) ?></li>
                    <?php endif; ?>
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
