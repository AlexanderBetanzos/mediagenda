<?php
/** Constructor de un cuestionario previo (includes/cuestionarios.php). Solo admin. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cuestionarios.php';
require_login();
require_modulo('agenda_online');
require_role('admin');
asegurar_cuestionarios();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$q  = $id ? cuest_obtener($id) : ['nombre' => '', 'descripcion' => '', 'aplica' => 'todas', 'medico_id' => null, 'activo' => 1, 'preguntas' => []];
if ($id && !$q) { flash(t('Ese cuestionario no existe.'), 'warning'); redirect('/cuestionarios/index'); }

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $q['nombre']      = trim(mb_substr((string) ($_POST['nombre'] ?? ''), 0, 150));
    $q['descripcion'] = trim(mb_substr((string) ($_POST['descripcion'] ?? ''), 0, 500));
    $q['aplica']      = in_array($_POST['aplica'] ?? '', ['todas', 'primera', 'subsecuente'], true) ? $_POST['aplica'] : 'todas';
    $med              = (int) ($_POST['medico_id'] ?? 0);
    $q['medico_id']   = $med && pertenece_al_tenant('usuarios', $med) ? $med : null;
    $q['preguntas']   = cuest_limpiar_preguntas((string) ($_POST['preguntas'] ?? '[]'));
    if ($q['nombre'] === '') $errores[] = t('Ponle un nombre.');
    if (!$q['preguntas']) $errores[] = t('Agrega al menos una pregunta.');
    if (!$errores) {
        $json = json_encode($q['preguntas'], JSON_UNESCAPED_UNICODE);
        if ($id) {
            db()->prepare('UPDATE cuestionarios SET nombre = ?, descripcion = ?, aplica = ?, medico_id = ?, preguntas = ?, actualizado_en = NOW() WHERE id = ? AND consultorio_id = ?')
                ->execute([$q['nombre'], $q['descripcion'] ?: null, $q['aplica'], $q['medico_id'], $json, $id, tenant_id()]);
            auditar('editar', 'cuestionario', $id, $q['nombre']);
        } else {
            db()->prepare('INSERT INTO cuestionarios (consultorio_id, nombre, descripcion, aplica, medico_id, preguntas) VALUES (?,?,?,?,?,?)')
                ->execute([tenant_id(), $q['nombre'], $q['descripcion'] ?: null, $q['aplica'], $q['medico_id'], $json]);
            $id = (int) db()->lastInsertId();
            auditar('crear', 'cuestionario', $id, $q['nombre']);
        }
        flash(t('Cuestionario guardado.'));
        redirect('/cuestionarios/index');
    }
}

$medicos = db()->prepare("SELECT id, nombre FROM usuarios WHERE consultorio_id = ? AND rol = 'medico' AND activo = 1 ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$titulo = $id ? t('Editar cuestionario') : t('Nuevo cuestionario');
$activo = 'cuestionarios';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/cuestionarios/index"><?= et('Cuestionarios previos') ?></a></li>
    <li class="breadcrumb-item active"><?= e($titulo) ?></li>
</ol></nav>

<?php if ($errores): ?><div class="alert alert-danger"><?= e(implode(' ', $errores)) ?></div><?php endif; ?>

<form method="post" id="fCuest">
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= $id ?>">
<input type="hidden" name="preguntas" id="preguntasJson">
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card position-sticky" style="top:1rem">
            <div class="card-body">
                <div class="mb-3"><label class="form-label"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" required maxlength="150" value="<?= e($q['nombre']) ?>" placeholder="<?= e(t('Ej. Historia clínica — primera vez')) ?>"></div>
                <div class="mb-3"><label class="form-label"><?= et('Mensaje para el paciente') ?></label>
                    <textarea name="descripcion" class="form-control" rows="2" maxlength="500"><?= e((string) $q['descripcion']) ?></textarea></div>
                <div class="mb-3"><label class="form-label"><?= et('¿A quién se le pide?') ?></label>
                    <select name="aplica" class="form-select">
                        <option value="todas" <?= $q['aplica'] === 'todas' ? 'selected' : '' ?>><?= et('Todas las citas') ?></option>
                        <option value="primera" <?= $q['aplica'] === 'primera' ? 'selected' : '' ?>><?= et('Solo pacientes de primera vez') ?></option>
                        <option value="subsecuente" <?= $q['aplica'] === 'subsecuente' ? 'selected' : '' ?>><?= et('Solo pacientes que ya vinieron') ?></option>
                    </select></div>
                <div class="mb-3"><label class="form-label"><?= et('Médico') ?></label>
                    <select name="medico_id" class="form-select">
                        <option value=""><?= et('Todos los médicos') ?></option>
                        <?php foreach ($medicos as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $q['medico_id'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text"><?= et('Uno de un médico gana sobre uno para todos.') ?></div></div>
                <button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= et('Guardar cuestionario') ?></button>
                <a href="<?= BASE_URL ?>/cuestionarios/index" class="btn btn-light w-100 mt-2"><?= et('Cancelar') ?></a>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div id="lista"></div>
        <button type="button" class="btn btn-outline-primary w-100" id="agregar"><i class="bi bi-plus-lg"></i> <?= et('Agregar pregunta') ?></button>
    </div>
</div>
</form>

<template id="tplPregunta">
    <div class="card mb-2 preg">
        <div class="card-body">
            <div class="d-flex gap-2 mb-2">
                <span class="badge text-bg-light border align-self-center num"></span>
                <input class="form-control fw-semibold p-texto" placeholder="<?= e(t('Escribe la pregunta')) ?>" maxlength="300">
                <div class="btn-group btn-group-sm flex-shrink-0">
                    <button type="button" class="btn btn-outline-secondary p-arriba" title="<?= e(t('Subir')) ?>"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-outline-secondary p-abajo" title="<?= e(t('Bajar')) ?>"><i class="bi bi-arrow-down"></i></button>
                    <button type="button" class="btn btn-outline-danger p-quitar" title="<?= e(t('Quitar')) ?>"><i class="bi bi-trash"></i></button>
                </div>
            </div>
            <div class="row g-2 align-items-center">
                <div class="col-sm-4"><select class="form-select form-select-sm p-tipo">
                    <?php foreach (cuest_tipos() as $k => $v): ?><option value="<?= e($k) ?>"><?= e(t($v)) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-sm-5"><select class="form-select form-select-sm p-campo" title="<?= e(t('Al revisarlo, se puede pasar a este campo del expediente')) ?>">
                    <?php foreach (cuest_campos() as $k => $v): ?><option value="<?= e($k) ?>"><?= $k === '' ? e(t($v)) : e(t('Va a')) . ': ' . e(t($v)) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-sm-3"><div class="form-check"><input class="form-check-input p-req" type="checkbox"><label class="form-check-label small"><?= et('Obligatoria') ?></label></div></div>
                <div class="col-12 p-ops-wrap"><textarea class="form-control form-control-sm p-ops" rows="3" placeholder="<?= e(t('Una opción por renglón')) ?>"></textarea></div>
                <div class="col-12"><input class="form-control form-control-sm p-ayuda" maxlength="200" placeholder="<?= e(t('Ayuda para el paciente (opcional)')) ?>"></div>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    var datos = <?= json_encode(array_values($q['preguntas']), JSON_UNESCAPED_UNICODE) ?>;
    var lista = document.getElementById('lista'), tpl = document.getElementById('tplPregunta');
    function numerar() { lista.querySelectorAll('.preg').forEach(function (el, i) { el.querySelector('.num').textContent = i + 1; }); }
    function opsVisibles(el) { var t = el.querySelector('.p-tipo').value; el.querySelector('.p-ops-wrap').hidden = !(t === 'opcion' || t === 'multiple'); }
    function agregar(p) {
        p = p || { tipo: 'texto' };
        var el = tpl.content.firstElementChild.cloneNode(true);
        el.dataset.id = p.id || '';
        el.querySelector('.p-texto').value = p.texto || '';
        el.querySelector('.p-tipo').value = p.tipo || 'texto';
        el.querySelector('.p-campo').value = p.campo || '';
        el.querySelector('.p-req').checked = !!p.requerida;
        el.querySelector('.p-ops').value = (p.opciones || []).join('\n');
        el.querySelector('.p-ayuda').value = p.ayuda || '';
        el.querySelector('.p-tipo').addEventListener('change', function () { opsVisibles(el); });
        el.querySelector('.p-quitar').addEventListener('click', function () { el.remove(); numerar(); });
        el.querySelector('.p-arriba').addEventListener('click', function () { if (el.previousElementSibling) lista.insertBefore(el, el.previousElementSibling); numerar(); });
        el.querySelector('.p-abajo').addEventListener('click', function () { if (el.nextElementSibling) lista.insertBefore(el.nextElementSibling, el); numerar(); });
        opsVisibles(el);
        lista.appendChild(el);
        numerar();
        return el;
    }
    (datos.length ? datos : [null]).forEach(agregar);
    document.getElementById('agregar').addEventListener('click', function () { agregar().querySelector('.p-texto').focus(); });
    document.getElementById('fCuest').addEventListener('submit', function () {
        var out = [];
        lista.querySelectorAll('.preg').forEach(function (el) {
            out.push({ id: el.dataset.id, texto: el.querySelector('.p-texto').value, tipo: el.querySelector('.p-tipo').value,
                       campo: el.querySelector('.p-campo').value, requerida: el.querySelector('.p-req').checked,
                       opciones: el.querySelector('.p-ops').value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean),
                       ayuda: el.querySelector('.p-ayuda').value });
        });
        document.getElementById('preguntasJson').value = JSON.stringify(out);
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
