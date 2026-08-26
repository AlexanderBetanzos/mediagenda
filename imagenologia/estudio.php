<?php
/**
 * Alta / edición de un estudio de ultrasonido.
 * Al crearlo se COPIAN los renglones del protocolo a `img_hallazgos`: si mañana
 * cambia el protocolo, el informe ya emitido no se altera. La captura de esos
 * renglones y el informe se hacen en ver.php.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('imagenologia');

$id      = (int) ($_GET['id'] ?? 0);
$pacSel  = (int) ($_GET['paciente_id'] ?? 0);
$u       = current_user();
$estudio = null;

if ($id) {
    $st = db()->prepare('SELECT * FROM img_estudios WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $estudio = $st->fetch();
    if (!$estudio) { flash('Estudio no encontrado.', 'warning'); redirect('/imagenologia/index'); }
}

/* Protocolos disponibles: se necesitan tanto para el select como para copiar
   sus renglones al guardar, así que se cargan antes del POST. */
$cat = db()->prepare('SELECT * FROM img_plantillas WHERE consultorio_id = ? AND activo = 1 ORDER BY region, nombre');
$cat->execute([tenant_id()]);
$catalogo = [];
foreach ($cat->fetchAll() as $p) { $catalogo[(int) $p['id']] = $p; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // El paciente y el médico deben ser de ESTE consultorio: los ids llegan del
    // POST y no basta con que el select solo ofreciera los propios.
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    if ($paciente_id && !pertenece_al_tenant('pacientes', $paciente_id)) { $paciente_id = 0; }
    $medico_id = (int) ($_POST['medico_id'] ?? 0);
    if ($medico_id && !pertenece_al_tenant('usuarios', $medico_id)) { $medico_id = 0; }

    // El protocolo se valida contra el catálogo ya cargado (mismo consultorio).
    $plantilla_id = (int) ($_POST['plantilla_id'] ?? 0);
    $plantilla    = $catalogo[$plantilla_id] ?? null;
    if (!$plantilla) { $plantilla_id = 0; }

    // Sin protocolo el estudio es libre y el nombre lo teclea el usuario.
    $nombre = trim((string) ($_POST['nombre'] ?? '')) ?: (string) ($plantilla['nombre'] ?? '');

    if (!$paciente_id || $nombre === '') {
        flash('El estudio necesita un paciente y un nombre.', 'warning');
    } else {
        $datos = [
            'paciente_id'  => $paciente_id,
            'plantilla_id' => $plantilla_id ?: null,
            'medico_id'    => $medico_id ?: null,
            'nombre'       => mb_substr($nombre, 0, 160),
            'region'       => ($plantilla['region'] ?? null) ?: null,   // el estudio libre no tiene región
            'fecha'        => ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
            'referente'    => (trim((string) ($_POST['referente'] ?? ''))   ?: null),
            'indicacion'   => (trim((string) ($_POST['indicacion'] ?? ''))  ?: null),
            'equipo'       => (trim((string) ($_POST['equipo'] ?? ''))      ?: null),
            'transductor'  => (trim((string) ($_POST['transductor'] ?? '')) ?: null),
            'precio'       => (float) ($_POST['precio'] ?? 0),
        ];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare(
                    'UPDATE img_estudios SET paciente_id = ?, plantilla_id = ?, medico_id = ?, nombre = ?,
                            region = ?, fecha = ?, referente = ?, indicacion = ?, equipo = ?,
                            transductor = ?, precio = ?
                     WHERE id = ? AND consultorio_id = ?'
                )->execute(array_merge(array_values($datos), [$id, tenant_id()]));
                auditar('img_estudio_editar', 'img_estudio', $id, $estudio['folio']);

                // Cambiar de protocolo cambia los renglones del informe: se
                // rehacen solo en ese caso, para no borrar lo ya capturado.
                $cambio = (int) ($estudio['plantilla_id'] ?? 0) !== $plantilla_id;
            } else {
                $folio = usg_siguiente_folio();
                $pdo->prepare(
                    'INSERT INTO img_estudios (consultorio_id, folio, paciente_id, plantilla_id, medico_id,
                                               nombre, region, fecha, referente, indicacion, equipo,
                                               transductor, precio, tecnica, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute(array_merge([tenant_id(), $folio], array_values($datos),
                                       [$plantilla['tecnica'] ?? null, (int) $u['id']]));
                $id     = (int) $pdo->lastInsertId();
                $cambio = true;
                auditar('img_estudio_crear', 'img_estudio', $id, $folio);
            }

            if ($cambio) {
                $pdo->prepare('DELETE FROM img_hallazgos WHERE estudio_id = ?')->execute([$id]);
                if ($plantilla) {
                    $ins = $pdo->prepare(
                        'INSERT INTO img_hallazgos (estudio_id, clave, etiqueta, tipo, unidad, referencia, opciones, orden)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );
                    foreach (usg_campos($plantilla['campos']) as $n => $c) {
                        $ins->execute([$id, $c['clave'], $c['etiqueta'], $c['tipo'],
                                       $c['unidad'] ?: null, $c['referencia'] ?: null,
                                       $c['opciones'] ?: null, $n]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('No se pudo guardar el estudio. Inténtalo de nuevo.', 'danger');
            redirect('/imagenologia/index');
        }

        flash('Estudio guardado.');
        redirect('/imagenologia/ver?id=' . $id);
    }
}

/* Datos para los selectores. */
$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();

$medicos = db()->prepare(
    "SELECT id, nombre FROM usuarios WHERE consultorio_id = ? AND rol IN ('medico','admin') AND activo = 1 ORDER BY nombre"
);
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

/* El equipo y el transductor casi nunca cambian entre estudios: se proponen los
   del último estudio del consultorio para no volver a teclearlos cada vez. */
$ultimo = db()->prepare(
    'SELECT equipo, transductor FROM img_estudios
     WHERE consultorio_id = ? AND equipo IS NOT NULL ORDER BY id DESC LIMIT 1'
);
$ultimo->execute([tenant_id()]);
$ultimo = $ultimo->fetch() ?: ['equipo' => '', 'transductor' => ''];

$titulo = $estudio ? t('Editar estudio') : t('Nuevo estudio');
$activo = 'imagenologia';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/imagenologia/index"><?= et('Ultrasonidos') ?></a></li>
    <li class="breadcrumb-item active"><?= $estudio ? e($estudio['folio']) : et('Nuevo estudio') ?></li>
</ol></nav>

<h1 class="h3 mb-3">
    <i class="bi bi-soundwave text-brand"></i>
    <?= $estudio ? et('Editar estudio') : et('Nuevo estudio de ultrasonido') ?>
</h1>

<?php if (!$catalogo): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span><i class="bi bi-info-circle"></i> <?= et('No tienes protocolos cargados. Puedes hacer un estudio libre (solo narrativa), o cargar los protocolos una vez y reutilizarlos siempre.') ?></span>
    <?php if (has_role('admin')): ?>
    <a href="<?= BASE_URL ?>/imagenologia/plantillas" class="btn btn-sm btn-primary"><?= et('Ir a protocolos') ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-5">
                <label class="form-label"><?= et('Paciente') ?> *</label>
                <select name="paciente_id" class="form-select" required>
                    <option value=""><?= et('Selecciona…') ?></option>
                    <?php $pacActual = (int) ($estudio['paciente_id'] ?? $pacSel); ?>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $pacActual === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['apellidos'] . ', ' . $p['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Médico que informa') ?></label>
                <select name="medico_id" class="form-select">
                    <option value=""><?= et('Sin especificar') ?></option>
                    <?php $medActual = (int) ($estudio['medico_id'] ?? ($u['rol'] === 'medico' ? $u['id'] : 0)); ?>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $medActual === (int) $m['id'] ? 'selected' : '' ?>>
                            <?= e($m['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Fecha') ?></label>
                <input type="date" name="fecha" class="form-control"
                       value="<?= e($estudio['fecha'] ?? date('Y-m-d')) ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label"><?= et('Protocolo') ?></label>
                <select name="plantilla_id" id="selProtocolo" class="form-select">
                    <option value="" data-nombre="" data-precio="0"><?= et('Estudio libre (sin protocolo)') ?></option>
                    <?php $plaActual = (int) ($estudio['plantilla_id'] ?? 0); ?>
                    <?php foreach ($catalogo as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $plaActual === (int) $c['id'] ? 'selected' : '' ?>
                                data-nombre="<?= e($c['nombre']) ?>"
                                data-precio="<?= e((string) $c['precio']) ?>">
                            <?= e($c['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($estudio): ?>
                <div class="form-text text-warning-emphasis">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?= et('Cambiar el protocolo rehace los renglones del informe y borra lo capturado en ellos.') ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Nombre del estudio') ?> *</label>
                <input name="nombre" id="inpNombre" class="form-control" maxlength="160" required
                       value="<?= e($estudio['nombre'] ?? '') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label"><?= et('Médico que solicita') ?></label>
                <input name="referente" class="form-control" maxlength="120"
                       placeholder="<?= e(t('Puede ser externo al consultorio')) ?>"
                       value="<?= e($estudio['referente'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Indicación del estudio') ?></label>
                <input name="indicacion" class="form-control" maxlength="255"
                       placeholder="<?= e(t('Motivo o diagnóstico presuntivo')) ?>"
                       value="<?= e($estudio['indicacion'] ?? '') ?>">
            </div>

            <div class="col-md-5">
                <label class="form-label"><?= et('Equipo') ?></label>
                <input name="equipo" class="form-control" maxlength="120"
                       value="<?= e($estudio['equipo'] ?? $ultimo['equipo'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Transductor') ?></label>
                <input name="transductor" class="form-control" maxlength="60"
                       placeholder="<?= e(t('Convexo 3.5 MHz')) ?>"
                       value="<?= e($estudio['transductor'] ?? $ultimo['transductor'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Precio') ?></label>
                <input name="precio" id="inpPrecio" type="number" step="0.01" min="0"
                       class="form-control text-end" value="<?= e((string) ($estudio['precio'] ?? '0')) ?>">
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?= BASE_URL ?>/imagenologia/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar estudio') ?></button>
    </div>
</form>

<script>
(function () {
    /* Elegir protocolo propone su nombre y su precio, sin pisar lo que el
       usuario ya haya escrito a mano. */
    var sel    = document.getElementById('selProtocolo');
    var nombre = document.getElementById('inpNombre');
    var precio = document.getElementById('inpPrecio');

    sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex];
        if (!o.dataset.nombre) return;
        if (!nombre.value.trim()) nombre.value = o.dataset.nombre;
        if (!parseFloat(precio.value)) precio.value = o.dataset.precio;
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
