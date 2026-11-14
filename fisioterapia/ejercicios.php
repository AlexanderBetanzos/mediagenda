<?php
/**
 * Biblioteca de ejercicios del consultorio: la base clásica de rehabilitación
 * viene sembrada y el fisioterapeuta la ajusta o agrega los suyos, con enlace
 * a video (YouTube, Drive…) e imagen para la hoja del paciente.
 */
$fisioSinPaciente = true;
require __DIR__ . '/_comun.php';

$tid = tenant_id();
fisio_sembrar_biblioteca($tid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($accion === 'guardar') {
        $txt = fn ($k, $max) => ($s = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($s, 0, $max);
        $url = fn ($k) => ($s = trim((string) ($_POST[$k] ?? ''))) !== '' && preg_match('~^https?://~i', $s) ? mb_substr($s, 0, 500) : null;
        $nombre = $txt('nombre', 160);
        if (!$nombre) { flash('El nombre es obligatorio.', 'danger'); redirect('/fisioterapia/ejercicios'); }
        $d = [$nombre, $txt('region', 40), $txt('tipo', 40), $txt('instrucciones', 5000),
              ($_POST['series'] ?? '') !== '' ? max(0, min(99, (int) $_POST['series'])) : null, $txt('reps', 20),
              ($_POST['segundos'] ?? '') !== '' ? max(0, min(7200, (int) $_POST['segundos'])) : null, $url('imagen_url'), $url('video_url')];
        if ($id) {
            db()->prepare('UPDATE fisio_ejercicios SET nombre=?, region=?, tipo=?, instrucciones=?, series=?, reps=?, segundos=?, imagen_url=?, video_url=? WHERE id = ? AND consultorio_id = ?')
                ->execute([...$d, $id, $tid]);
            flash('Ejercicio actualizado.');
        } else {
            db()->prepare('INSERT INTO fisio_ejercicios (consultorio_id, nombre, region, tipo, instrucciones, series, reps, segundos, imagen_url, video_url) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid, ...$d]);
            flash('Ejercicio agregado a la biblioteca.');
        }
    } elseif ($accion === 'toggle') {
        db()->prepare('UPDATE fisio_ejercicios SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
        flash('Ejercicio actualizado.');
    }
    redirect('/fisioterapia/ejercicios' . (isset($_POST['r']) && $_POST['r'] !== '' ? '?r=' . rawurlencode($_POST['r']) : ''));
}

$todos = db()->prepare('SELECT * FROM fisio_ejercicios WHERE consultorio_id = ? ORDER BY activo DESC, region, nombre');
$todos->execute([$tid]);
$todos = $todos->fetchAll();
$regiones = array_values(array_unique(array_merge(fisio_regiones(), array_filter(array_column($todos, 'region')))));
$filtro = (string) ($_GET['r'] ?? '');
$lista = $filtro === '' ? $todos : array_values(array_filter($todos, fn ($x) => $x['region'] === $filtro));
$edit = null;
if ($eid = (int) ($_GET['editar'] ?? 0)) foreach ($todos as $x) if ((int) $x['id'] === $eid) $edit = $x;

$titulo = t('Biblioteca de ejercicios');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
$ev = fn ($k) => e((string) ($edit[$k] ?? ''));
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-collection-play text-brand"></i> <?= et('Biblioteca de ejercicios') ?></h1>
    <a href="javascript:history.back()" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver') ?></a>
</div>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<div class="row g-3">
<div class="col-12 col-lg-5">
    <form method="post" class="card" id="frm"><div class="card-body row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="r" value="<?= e($filtro) ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <h2 class="h6 col-12 mb-1"><?= $edit ? et('Editar ejercicio') : et('Nuevo ejercicio') ?></h2>
        <div class="col-12"><label class="form-label small"><?= et('Nombre') ?></label><input type="text" name="nombre" class="form-control" maxlength="160" required value="<?= $ev('nombre') ?>"></div>
        <div class="col-6"><label class="form-label small"><?= et('Región') ?></label><select name="region" class="form-select"><?php foreach ($regiones as $r): ?><option <?= ($edit['region'] ?? $filtro) === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small"><?= et('Tipo') ?></label><select name="tipo" class="form-select"><?php foreach (fisio_tipos_ejercicio() as $t): ?><option <?= ($edit['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><label class="form-label small"><?= et('Series') ?></label><input type="number" min="0" name="series" class="form-control" value="<?= $ev('series') ?>"></div>
        <div class="col-4"><label class="form-label small"><?= et('Repeticiones') ?></label><input type="text" name="reps" class="form-control" maxlength="20" value="<?= $ev('reps') ?>"></div>
        <div class="col-4"><label class="form-label small"><?= et('Segundos') ?></label><input type="number" min="0" name="segundos" class="form-control" value="<?= $ev('segundos') ?>"></div>
        <div class="col-12"><label class="form-label small"><?= et('Instrucciones para el paciente') ?></label><textarea name="instrucciones" class="form-control" rows="4"><?= $ev('instrucciones') ?></textarea></div>
        <div class="col-12"><label class="form-label small"><?= et('Video (enlace de YouTube, Drive…)') ?></label><input type="url" name="video_url" class="form-control" placeholder="https://" value="<?= $ev('video_url') ?>"></div>
        <div class="col-12"><label class="form-label small"><?= et('Imagen (enlace)') ?></label><input type="url" name="imagen_url" class="form-control" placeholder="https://" value="<?= $ev('imagen_url') ?>">
            <div class="form-text"><?= et('Si pones un video de YouTube y no hay imagen, la hoja usa su miniatura.') ?></div></div>
        <div class="col-12 d-flex justify-content-end gap-2">
            <?php if ($edit): ?><a href="<?= BASE_URL ?>/fisioterapia/ejercicios<?= $filtro !== '' ? '?r=' . rawurlencode($filtro) : '' ?>" class="btn btn-light"><?= et('Cancelar') ?></a><?php endif; ?>
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
        </div>
    </div></form>
</div>
<div class="col-12 col-lg-7">
    <div class="d-flex flex-wrap gap-1 mb-2">
        <a href="<?= BASE_URL ?>/fisioterapia/ejercicios" class="btn btn-sm <?= $filtro === '' ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et('Todas') ?> (<?= count($todos) ?>)</a>
        <?php foreach (array_count_values(array_filter(array_column($todos, 'region'))) as $r => $c): ?>
        <a href="?r=<?= rawurlencode($r) ?>" class="btn btn-sm <?= $filtro === $r ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($r) ?> (<?= $c ?>)</a>
        <?php endforeach; ?>
    </div>
    <div class="card"><ul class="list-group list-group-flush">
        <?php if (!$lista): ?><li class="list-group-item text-muted text-center py-4"><?= et('Sin ejercicios en esta región.') ?></li><?php endif; ?>
        <?php foreach ($lista as $x): $yt = fisio_youtube_id($x['video_url']); $img = $x['imagen_url'] ?: ($yt ? 'https://img.youtube.com/vi/' . $yt . '/mqdefault.jpg' : ''); ?>
        <li class="list-group-item d-flex gap-3 align-items-start <?= $x['activo'] ? '' : 'opacity-50' ?>">
            <?php if ($img): ?><img src="<?= e($img) ?>" alt="" style="width:72px;height:54px;object-fit:cover;border-radius:.4rem" loading="lazy"><?php else: ?><div class="bg-body-tertiary rounded d-flex align-items-center justify-content-center text-muted" style="width:72px;height:54px;flex:none"><i class="bi bi-person-arms-up fs-4"></i></div><?php endif; ?>
            <div class="flex-grow-1">
                <div class="fw-semibold"><?= e($x['nombre']) ?> <?php if (!$x['activo']): ?><span class="badge bg-secondary"><?= et('Oculto') ?></span><?php endif; ?></div>
                <div class="small text-muted"><?= e($x['region']) ?> · <?= e($x['tipo']) ?><?= fisio_dosis($x) ? ' · ' . e(fisio_dosis($x)) : '' ?>
                    <?php if ($x['video_url']): ?> · <a href="<?= e($x['video_url']) ?>" target="_blank" rel="noopener"><i class="bi bi-play-btn"></i> <?= et('video') ?></a><?php endif; ?></div>
                <?php if ($x['instrucciones']): ?><div class="small mt-1"><?= e(mb_strimwidth($x['instrucciones'], 0, 180, '…')) ?></div><?php endif; ?>
            </div>
            <div class="d-flex gap-1">
                <a href="?editar=<?= (int) $x['id'] ?><?= $filtro !== '' ? '&r=' . rawurlencode($filtro) : '' ?>#frm" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-pencil"></i></a>
                <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>"><input type="hidden" name="r" value="<?= e($filtro) ?>">
                    <button class="btn btn-sm btn-outline-secondary py-0" title="<?= $x['activo'] ? e(t('Ocultar')) : e(t('Mostrar')) ?>"><i class="bi <?= $x['activo'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i></button></form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul></div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
