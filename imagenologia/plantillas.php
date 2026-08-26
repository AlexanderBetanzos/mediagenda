<?php
/**
 * Catálogo de protocolos de ultrasonido del consultorio: qué se mide y qué se
 * describe en cada región. Un protocolo es la plantilla del informe; al crear
 * un estudio sus renglones se COPIAN al estudio, así que editar el protocolo
 * nunca altera un informe ya emitido.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('imagenologia');

$editar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? 'guardar';

    /* Carga inicial: protocolos comunes para que el catálogo no nazca vacío. */
    if ($accion === 'sembrar') {
        $ya = db()->prepare('SELECT COUNT(*) FROM img_plantillas WHERE consultorio_id = ?');
        $ya->execute([tenant_id()]);
        if ((int) $ya->fetchColumn() > 0) {
            flash('El catálogo ya tiene protocolos; la carga inicial solo aplica cuando está vacío.', 'warning');
            redirect('/imagenologia/plantillas');
        }
        $ins = db()->prepare(
            'INSERT INTO img_plantillas (consultorio_id, nombre, region, campos, tecnica, preparacion, precio)
             VALUES (?,?,?,?,?,?,0)'
        );
        foreach (usg_protocolos() as $region => $p) {
            $campos = [];
            foreach ($p['campos'] as [$clave, $etiqueta, $tipo, $unidad, $ref, $opciones]) {
                $campos[] = compact('clave', 'etiqueta', 'tipo', 'unidad', 'opciones')
                          + ['referencia' => $ref];
            }
            $ins->execute([tenant_id(), $p['nombre'], $region,
                           json_encode($campos, JSON_UNESCAPED_UNICODE),
                           $p['tecnica'], $p['preparacion']]);
        }
        auditar('img_plantillas_carga_inicial', 'img_plantilla');
        flash('Protocolos cargados. Ajusta los precios y la redacción a tu forma de reportar.');
        redirect('/imagenologia/plantillas');
    }

    if ($accion === 'toggle') {
        db()->prepare('UPDATE img_plantillas SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')
            ->execute([(int) $_POST['id'], tenant_id()]);
        redirect('/imagenologia/plantillas');
    }

    /* Alta / edición. Los renglones llegan como arrays paralelos del editor. */
    $id     = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));

    $etiquetas = $_POST['campo_etiqueta'] ?? [];
    $campos    = [];
    foreach ($etiquetas as $i => $etq) {
        $etq = trim((string) $etq);
        if ($etq === '') continue;                       // renglón vacío del editor
        $campos[] = [
            'clave'      => 'c' . count($campos),
            'etiqueta'   => $etq,
            'tipo'       => (string) ($_POST['campo_tipo'][$i] ?? 'texto'),
            'unidad'     => trim((string) ($_POST['campo_unidad'][$i] ?? '')),
            'referencia' => trim((string) ($_POST['campo_ref'][$i] ?? '')),
            'opciones'   => trim((string) ($_POST['campo_opciones'][$i] ?? '')),
        ];
    }
    // usg_campos() recorta longitudes y descarta tipos inválidos: se pasa por
    // ahí antes de guardar para que en la base solo entren renglones sanos.
    $campos = usg_campos(json_encode($campos, JSON_UNESCAPED_UNICODE));

    $datos = [
        mb_substr($nombre, 0, 160),
        trim((string) ($_POST['region'] ?? ''))      ?: null,
        $campos ? json_encode($campos, JSON_UNESCAPED_UNICODE) : null,
        trim((string) ($_POST['tecnica'] ?? ''))     ?: null,
        trim((string) ($_POST['preparacion'] ?? '')) ?: null,
        (float) ($_POST['precio'] ?? 0),
    ];

    if ($nombre === '') {
        flash('El protocolo necesita un nombre.', 'warning');
    } elseif ($id) {
        db()->prepare(
            'UPDATE img_plantillas SET nombre = ?, region = ?, campos = ?, tecnica = ?,
                    preparacion = ?, precio = ?
             WHERE id = ? AND consultorio_id = ?'
        )->execute(array_merge($datos, [$id, tenant_id()]));
        auditar('img_plantilla_editar', 'img_plantilla', $id, $nombre);
        flash('Protocolo actualizado.');
    } else {
        db()->prepare(
            'INSERT INTO img_plantillas (consultorio_id, nombre, region, campos, tecnica, preparacion, precio)
             VALUES (?,?,?,?,?,?,?)'
        )->execute(array_merge([tenant_id()], $datos));
        auditar('img_plantilla_crear', 'img_plantilla', (int) db()->lastInsertId(), $nombre);
        flash('Protocolo agregado al catálogo.');
    }
    redirect('/imagenologia/plantillas');
}

if ($id = (int) ($_GET['editar'] ?? 0)) {
    $st = db()->prepare('SELECT * FROM img_plantillas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $editar = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT * FROM img_plantillas WHERE consultorio_id = ? ORDER BY activo DESC, region, nombre');
$st->execute([tenant_id()]);
$plantillas = $st->fetchAll();

$camposEditar = usg_campos($editar['campos'] ?? null);
$tipos        = usg_tipos_campo();

$titulo = t('Protocolos de ultrasonido');
$activo = 'imagenologia';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/imagenologia/index"><?= et('Ultrasonidos') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Protocolos') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-ui-checks-grid text-brand"></i> <?= et('Protocolos de ultrasonido') ?></h1>

<?php if (!$plantillas): ?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <div class="fw-semibold"><?= et('Tu catálogo de protocolos está vacío.') ?></div>
        <div class="text-muted small">
            <?= et('Carga los protocolos comunes (abdominal, obstétrico, pélvico, renal, tiroides, mama, testicular, partes blandas y Doppler venoso) y ajústalos a tu forma de reportar.') ?>
        </div>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="sembrar">
        <button class="btn btn-primary"><i class="bi bi-download"></i> <?= et('Cargar protocolos comunes') ?></button>
    </form>
</div></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th><?= et('Protocolo') ?></th>
                        <th><?= et('Renglones') ?></th>
                        <th class="text-end"><?= et('Precio') ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($plantillas as $p): ?>
                        <tr class="<?= $p['activo'] ? '' : 'opacity-50' ?>">
                            <td>
                                <div class="fw-semibold"><?= e($p['nombre']) ?></div>
                                <small class="text-muted">
                                    <?= e($p['region'] ?: '—') ?>
                                    <?php if ($p['preparacion']): ?> · <?= e($p['preparacion']) ?><?php endif; ?>
                                </small>
                            </td>
                            <td class="small text-muted"><?= count(usg_campos($p['campos'])) ?></td>
                            <td class="text-end"><?= fmt_money($p['precio']) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?editar=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"
                                   title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="accion" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary py-0"
                                            title="<?= e($p['activo'] ? t('Desactivar') : t('Activar')) ?>">
                                        <i class="bi bi-<?= $p['activo'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$plantillas): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><?= et('Sin protocolos todavía.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <form method="post" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
            <div class="card-header fw-semibold">
                <i class="bi bi-<?= $editar ? 'pencil' : 'plus-lg' ?> text-brand"></i>
                <?= $editar ? et('Editar protocolo') : et('Nuevo protocolo') ?>
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" maxlength="160" required
                           value="<?= e($editar['nombre'] ?? '') ?>">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-7">
                        <label class="form-label"><?= et('Región') ?></label>
                        <input name="region" class="form-control" maxlength="60" list="regiones"
                               value="<?= e($editar['region'] ?? '') ?>">
                        <datalist id="regiones">
                            <?php foreach (array_keys(usg_protocolos()) as $r): ?>
                                <option value="<?= e($r) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-5">
                        <label class="form-label"><?= et('Precio') ?></label>
                        <input name="precio" type="number" step="0.01" min="0" class="form-control text-end"
                               value="<?= e((string) ($editar['precio'] ?? '0')) ?>">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label"><?= et('Preparación del paciente') ?></label>
                    <input name="preparacion" class="form-control" maxlength="255"
                           placeholder="<?= e(t('Ej. Ayuno de 6 h')) ?>"
                           value="<?= e($editar['preparacion'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= et('Técnica') ?></label>
                    <textarea name="tecnica" class="form-control" rows="3" maxlength="1000"><?= e($editar['tecnica'] ?? '') ?></textarea>
                    <div class="form-text"><?= et('Se copia al informe y ahí se puede ajustar.') ?></div>
                </div>

                <label class="form-label"><?= et('Renglones del informe') ?></label>
                <div id="campos" class="vstack gap-2 mb-2">
                    <?php foreach ($camposEditar as $c): ?>
                    <div class="border rounded p-2 campo">
                        <div class="d-flex gap-2 mb-2">
                            <input name="campo_etiqueta[]" class="form-control form-control-sm" maxlength="120"
                                   placeholder="<?= e(t('Etiqueta')) ?>" value="<?= e($c['etiqueta']) ?>">
                            <select name="campo_tipo[]" class="form-select form-select-sm" style="max-width:150px">
                                <?php foreach ($tipos as $k => $lbl): ?>
                                    <option value="<?= e($k) ?>" <?= $c['tipo'] === $k ? 'selected' : '' ?>><?= et($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="d-flex gap-2">
                            <input name="campo_unidad[]" class="form-control form-control-sm" maxlength="30"
                                   placeholder="<?= e(t('Unidad')) ?>" value="<?= e($c['unidad']) ?>">
                            <input name="campo_ref[]" class="form-control form-control-sm" maxlength="60"
                                   placeholder="<?= e(t('Referencia')) ?>" value="<?= e($c['referencia']) ?>">
                            <input name="campo_opciones[]" class="form-control form-control-sm" maxlength="255"
                                   placeholder="<?= e(t('Opciones separadas por |')) ?>" value="<?= e($c['opciones']) ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="btnCampo">
                    <i class="bi bi-plus-lg"></i> <?= et('Agregar renglón') ?>
                </button>
            </div>
            <div class="card-body border-top">
                <button class="btn btn-primary w-100"><i class="bi bi-check-lg"></i> <?= et('Guardar protocolo') ?></button>
                <?php if ($editar): ?>
                <a href="<?= BASE_URL ?>/imagenologia/plantillas" class="btn btn-link w-100 mt-1"><?= et('Cancelar') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<template id="tplCampo">
    <div class="border rounded p-2 campo">
        <div class="d-flex gap-2 mb-2">
            <input name="campo_etiqueta[]" class="form-control form-control-sm" maxlength="120"
                   placeholder="<?= e(t('Etiqueta')) ?>">
            <select name="campo_tipo[]" class="form-select form-select-sm" style="max-width:150px">
                <?php foreach ($tipos as $k => $lbl): ?>
                    <option value="<?= e($k) ?>"><?= et($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="d-flex gap-2">
            <input name="campo_unidad[]" class="form-control form-control-sm" maxlength="30" placeholder="<?= e(t('Unidad')) ?>">
            <input name="campo_ref[]" class="form-control form-control-sm" maxlength="60" placeholder="<?= e(t('Referencia')) ?>">
            <input name="campo_opciones[]" class="form-control form-control-sm" maxlength="255" placeholder="<?= e(t('Opciones separadas por |')) ?>">
        </div>
    </div>
</template>

<script>
(function () {
    /* El renglón vacío se clona desde una plantilla <template>: así el HTML del
       editor vive una sola vez y no se arma concatenando cadenas. */
    var cont = document.getElementById('campos');
    var tpl  = document.getElementById('tplCampo');

    document.getElementById('btnCampo').addEventListener('click', function () {
        cont.appendChild(tpl.content.cloneNode(true));
    });
    cont.addEventListener('click', function (ev) {
        var b = ev.target.closest('.quitar');
        if (b) b.closest('.campo').remove();
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
