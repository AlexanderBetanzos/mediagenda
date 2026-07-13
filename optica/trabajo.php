<?php
/**
 * Alta / edición de una orden de trabajo (el par de lentes).
 *
 * Lo que hace útil esta pantalla no es el formulario, es el filtro: al elegir la
 * graduación, el catálogo de micas se recorta a las que REALMENTE se pueden
 * fabricar con esa receta (por rango de esfera y cilindro), y cada una trae su
 * precio y sus días de entrega. Así el vendedor no cotiza una mica imposible ni
 * promete una fecha que el laboratorio no va a cumplir.
 *
 * El armazón puede salir del inventario (y entonces descuenta stock al pedirlo)
 * o ser el que trajo el cliente.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('optica');

$id  = (int) ($_GET['id'] ?? 0);
$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? 0);
$gid = (int) ($_GET['graduacion_id'] ?? 0);

$t = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM optica_trabajos WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $t = $st->fetch();
    if (!$t) { flash('Orden no encontrada.', 'warning'); redirect('/optica/index'); }
    $pid = (int) $t['paciente_id'];
    $gid = (int) ($t['graduacion_id'] ?: 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) {
        flash('Selecciona un paciente válido.', 'warning');
        redirect('/optica/index');
    }

    $armazonProd = ((int) ($_POST['armazon_producto_id'] ?? 0)) ?: null;
    if ($armazonProd && !pertenece_al_tenant('productos', $armazonProd)) $armazonProd = null;

    $micaId = ((int) ($_POST['mica_id'] ?? 0)) ?: null;

    $armazonPrecio = round((float) ($_POST['armazon_precio'] ?? 0), 2);
    $micaPrecio    = round((float) ($_POST['mica_precio'] ?? 0), 2);
    $descuento     = round((float) ($_POST['descuento'] ?? 0), 2);
    $total         = max(0, $armazonPrecio + $micaPrecio - $descuento);
    $anticipo      = min($total, max(0, round((float) ($_POST['anticipo'] ?? 0), 2)));

    $datos = [
        'paciente_id'         => $paciente_id,
        'graduacion_id'       => ((int) ($_POST['graduacion_id'] ?? 0)) ?: null,
        'vendedor_id'         => (int) $u['id'],
        'fecha'               => ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
        'fecha_promesa'       => ($_POST['fecha_promesa'] ?? '') ?: null,
        'armazon_producto_id' => $armazonProd,
        'armazon_desc'        => trim((string) ($_POST['armazon_desc'] ?? '')) ?: null,
        'armazon_precio'      => $armazonPrecio,
        'mica_id'             => $micaId,
        'mica_desc'           => trim((string) ($_POST['mica_desc'] ?? '')) ?: null,
        'mica_precio'         => $micaPrecio,
        'tratamientos'        => trim((string) ($_POST['tratamientos'] ?? '')) ?: null,
        'laboratorio'         => trim((string) ($_POST['laboratorio'] ?? '')) ?: null,
        'descuento'           => $descuento,
        'total'               => $total,
        'anticipo'            => $anticipo,
        'notas'               => trim((string) ($_POST['notas'] ?? '')) ?: null,
    ];

    if ($id) {
        $set = implode(' = ?, ', array_keys($datos)) . ' = ?';
        db()->prepare("UPDATE optica_trabajos SET $set WHERE id = ? AND consultorio_id = ?")
            ->execute(array_merge(array_values($datos), [$id, tenant_id()]));
        auditar('optica_trabajo_editar', 'optica_trabajo', $id, $t['folio']);
    } else {
        $folio = optica_siguiente_folio();
        $cols  = array_merge(['consultorio_id', 'folio'], array_keys($datos));
        $ph    = implode(',', array_fill(0, count($cols), '?'));
        db()->prepare('INSERT INTO optica_trabajos (' . implode(', ', $cols) . ") VALUES ($ph)")
            ->execute(array_merge([tenant_id(), $folio], array_values($datos)));
        $id = (int) db()->lastInsertId();
        auditar('optica_trabajo_crear', 'optica_trabajo', $id, $folio);
    }

    flash('Orden de trabajo guardada.');
    redirect('/optica/ver?id=' . $id);
}

/* ── Datos para la pantalla ─────────────────────────────────────────────── */
$pac = null;
if ($pid) {
    $st = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $st->execute([$pid, tenant_id()]);
    $pac = $st->fetch() ?: null;
}

$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();

// Graduaciones del paciente (la más reciente primero).
$grads = [];
if ($pid) {
    $st = db()->prepare('SELECT * FROM optica_graduaciones WHERE paciente_id = ? AND consultorio_id = ?
                         ORDER BY fecha DESC, id DESC');
    $st->execute([$pid, tenant_id()]);
    $grads = $st->fetchAll();
}
$grad = null;
foreach ($grads as $g) { if ((int) $g['id'] === $gid) { $grad = $g; break; } }
if (!$grad && $grads) { $grad = $grads[0]; $gid = (int) $grad['id']; }

// Micas que SÍ se pueden fabricar con esa graduación (por rango).
$micas = $grad ? optica_micas_para($grad) : [];

// Armazones = productos del inventario. Se ofrecen todos: la óptica decide
// cómo categorizarlos.
$armazones = db()->prepare('SELECT id, nombre, sku, precio FROM productos
                            WHERE consultorio_id = ? AND activo = 1 ORDER BY nombre');
$armazones->execute([tenant_id()]);
$armazones = $armazones->fetchAll();

$titulo = $t ? t('Editar orden') : t('Nueva orden de trabajo');
$activo = 'optica';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/optica/index"><?= et('Óptica') ?></a></li>
    <li class="breadcrumb-item active"><?= $t ? e($t['folio']) : et('Nueva orden') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-eyeglasses text-brand"></i> <?= $t ? et('Editar orden de trabajo') : et('Nueva orden de trabajo') ?></h1>

<?php if (!$pid): ?>
<div class="card mb-3">
    <form class="card-body row g-2 align-items-end" method="get">
        <div class="col-md-6">
            <label class="form-label"><?= et('Paciente') ?></label>
            <select name="paciente_id" class="form-select" required>
                <option value=""><?= et('Selecciona…') ?></option>
                <?php foreach ($pacientes as $p): ?>
                    <option value="<?= (int) $p['id'] ?>"><?= e($p['apellidos'] . ', ' . $p['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-primary"><?= et('Continuar') ?></button></div>
    </form>
</div>
<?php else: ?>

<?php if (!$grads): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <span><i class="bi bi-exclamation-triangle"></i>
        <?= et('Este paciente no tiene ninguna graduación capturada. Sin ella no se puede elegir la mica.') ?></span>
    <a href="<?= BASE_URL ?>/optica/graduacion?paciente_id=<?= $pid ?>" class="btn btn-sm btn-primary">
        <i class="bi bi-eye"></i> <?= et('Capturar graduación') ?>
    </a>
</div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">

    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <?= avatar_paciente((int) $pac['id'], $pac['nombre'], $pac['apellidos'], ($pac['foto_mime'] ?? null) ?: ($pac['foto'] ?? null), 48) ?>
                <div>
                    <div class="fw-semibold"><?= e($pac['nombre'] . ' ' . $pac['apellidos']) ?></div>
                    <div class="small text-muted"><?= e(edad($pac['fecha_nacimiento'])) ?></div>
                </div>
                <a href="<?= BASE_URL ?>/optica/graduacion?paciente_id=<?= $pid ?>" class="btn btn-sm btn-outline-secondary ms-auto">
                    <i class="bi bi-plus-lg"></i> <?= et('Nueva graduación') ?>
                </a>
            </div>

            <?php if ($grads): ?>
            <label class="form-label"><?= et('Graduación') ?></label>
            <select name="graduacion_id" class="form-select" id="selGrad"
                    onchange="location='<?= BASE_URL ?>/optica/trabajo?paciente_id=<?= $pid ?><?= $id ? '&id=' . $id : '' ?>&graduacion_id=' + this.value">
                <?php foreach ($grads as $g): ?>
                    <option value="<?= (int) $g['id'] ?>" <?= $gid === (int) $g['id'] ? 'selected' : '' ?>>
                        <?= fmt_fecha($g['fecha']) ?> · <?= e(optica_graduacion_resumen($g)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">
                <?= et('Al cambiar de graduación se recalculan las micas que se pueden fabricar.') ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <!-- Armazón -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold"><i class="bi bi-eyeglasses text-brand"></i> <?= et('Armazón') ?></div>
                <div class="card-body">
                    <label class="form-label"><?= et('Del inventario') ?></label>
                    <select name="armazon_producto_id" id="selArmazon" class="form-select mb-2">
                        <option value="" data-precio="0"><?= et('— El cliente trae el suyo / sin inventario —') ?></option>
                        <?php foreach ($armazones as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" data-precio="<?= e((string) $a['precio']) ?>"
                                    data-nombre="<?= e($a['nombre']) ?>"
                                    <?= (int) ($t['armazon_producto_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>>
                                <?= e($a['nombre']) ?><?= $a['sku'] ? ' · ' . e($a['sku']) : '' ?> — <?= fmt_money($a['precio']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="form-label"><?= et('Descripción') ?></label>
                    <input name="armazon_desc" id="armazonDesc" class="form-control mb-2" maxlength="160"
                           placeholder="<?= e(t('Marca, modelo, color')) ?>"
                           value="<?= e($t['armazon_desc'] ?? '') ?>">

                    <label class="form-label"><?= et('Precio') ?></label>
                    <input type="number" step="0.01" min="0" name="armazon_precio" id="armazonPrecio"
                           class="form-control precio" value="<?= e((string) ($t['armazon_precio'] ?? '0')) ?>">
                </div>
            </div>
        </div>

        <!-- Micas -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-circle-half text-brand"></i> <?= et('Micas') ?></span>
                    <?php if (has_role('admin')): ?>
                    <a href="<?= BASE_URL ?>/optica/micas" class="small"><?= et('Catálogo →') ?></a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$grad): ?>
                        <p class="text-muted small mb-0"><?= et('Captura primero la graduación para ver qué micas se pueden fabricar.') ?></p>
                    <?php elseif (!$micas): ?>
                        <div class="alert alert-warning py-2 small mb-2">
                            <?= et('Ninguna mica de tu catálogo cubre esta graduación.') ?>
                            <?php if (has_role('admin')): ?>
                                <a href="<?= BASE_URL ?>/optica/micas"><?= et('Ajusta los rangos del catálogo') ?></a>
                            <?php endif; ?>
                            <?= et('o captura la mica a mano.') ?>
                        </div>
                    <?php else: ?>
                        <label class="form-label"><?= et('Mica que cubre esta graduación') ?></label>
                        <select name="mica_id" id="selMica" class="form-select mb-2">
                            <option value="" data-precio="0" data-dias="0"><?= et('— A mano —') ?></option>
                            <?php foreach ($micas as $m): ?>
                                <option value="<?= (int) $m['id'] ?>"
                                        data-precio="<?= e((string) $m['precio']) ?>"
                                        data-dias="<?= (int) $m['dias_entrega'] ?>"
                                        data-nombre="<?= e($m['nombre']) ?>"
                                        data-trat="<?= e($m['tratamientos'] ?? '') ?>"
                                        <?= (int) ($t['mica_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                    <?= e($m['nombre']) ?> — <?= fmt_money($m['precio']) ?> · <?= (int) $m['dias_entrega'] ?> <?= et('días') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <label class="form-label"><?= et('Descripción') ?></label>
                    <input name="mica_desc" id="micaDesc" class="form-control mb-2" maxlength="160"
                           value="<?= e($t['mica_desc'] ?? '') ?>">

                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label"><?= et('Tratamientos') ?></label>
                            <input name="tratamientos" id="micaTrat" class="form-control" maxlength="255"
                                   value="<?= e($t['tratamientos'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= et('Precio del par') ?></label>
                            <input type="number" step="0.01" min="0" name="mica_precio" id="micaPrecio"
                                   class="form-control precio" value="<?= e((string) ($t['mica_precio'] ?? '0')) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body row g-3">
            <div class="col-md-3">
                <label class="form-label"><?= et('Laboratorio') ?></label>
                <input name="laboratorio" class="form-control" maxlength="120"
                       placeholder="<?= e(t('A dónde se manda a tallar')) ?>"
                       value="<?= e($t['laboratorio'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= et('Fecha') ?></label>
                <input type="date" name="fecha" class="form-control" value="<?= e($t['fecha'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Fecha prometida') ?></label>
                <input type="date" name="fecha_promesa" id="fechaPromesa" class="form-control"
                       value="<?= e($t['fecha_promesa'] ?? '') ?>">
                <div class="form-text"><?= et('Se calcula sola con los días de la mica.') ?></div>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Notas') ?></label>
                <input name="notas" class="form-control" maxlength="500" value="<?= e($t['notas'] ?? '') ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label"><?= et('Descuento') ?></label>
                <input type="number" step="0.01" min="0" name="descuento" id="descuento"
                       class="form-control precio" value="<?= e((string) ($t['descuento'] ?? '0')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Anticipo') ?></label>
                <input type="number" step="0.01" min="0" name="anticipo" id="anticipo"
                       class="form-control precio" value="<?= e((string) ($t['anticipo'] ?? '0')) ?>">
            </div>
            <div class="col-md-6 d-flex align-items-end justify-content-end gap-4">
                <div class="text-end">
                    <div class="text-muted small"><?= et('Total') ?></div>
                    <div class="h4 mb-0 fw-bold" id="total"><?= fmt_money($t['total'] ?? 0) ?></div>
                </div>
                <div class="text-end">
                    <div class="text-muted small"><?= et('Saldo') ?></div>
                    <div class="h4 mb-0 fw-bold text-warning" id="saldo">
                        <?= fmt_money(($t['total'] ?? 0) - ($t['anticipo'] ?? 0)) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?= BASE_URL ?>/optica/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar orden') ?></button>
    </div>
</form>

<script>
(function () {
    var armazon = document.getElementById('selArmazon');
    var mica    = document.getElementById('selMica');

    function money(n) {
        return '$' + Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function totales() {
        var a = parseFloat(document.getElementById('armazonPrecio').value) || 0;
        var m = parseFloat(document.getElementById('micaPrecio').value) || 0;
        var d = parseFloat(document.getElementById('descuento').value) || 0;
        var ant = parseFloat(document.getElementById('anticipo').value) || 0;
        var total = Math.max(0, a + m - d);
        document.getElementById('total').textContent = money(total);
        document.getElementById('saldo').textContent = money(Math.max(0, total - ant));
    }

    /* Al elegir del catálogo se copian precio y descripción, pero quedan
       editables: el vendedor a veces respeta un precio pactado. */
    if (armazon) armazon.addEventListener('change', function () {
        var o = armazon.options[armazon.selectedIndex];
        if (armazon.value) {
            document.getElementById('armazonPrecio').value = o.dataset.precio || 0;
            document.getElementById('armazonDesc').value   = o.dataset.nombre || '';
        }
        totales();
    });

    if (mica) mica.addEventListener('change', function () {
        var o = mica.options[mica.selectedIndex];
        if (mica.value) {
            document.getElementById('micaPrecio').value = o.dataset.precio || 0;
            document.getElementById('micaDesc').value   = o.dataset.nombre || '';
            document.getElementById('micaTrat').value   = o.dataset.trat || '';

            // La fecha prometida sale de los días de entrega de la mica: prometer
            // a ojo es la causa número uno de que un trabajo llegue tarde.
            var dias = parseInt(o.dataset.dias, 10) || 0;
            if (dias > 0) {
                var f = new Date();
                f.setDate(f.getDate() + dias);
                document.getElementById('fechaPromesa').value = f.toISOString().slice(0, 10);
            }
        }
        totales();
    });

    document.querySelectorAll('.precio').forEach(function (i) {
        i.addEventListener('input', totales);
    });

    totales();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
