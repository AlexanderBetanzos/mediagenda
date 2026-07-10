<?php
/**
 * Alta / edición de un presupuesto (plan de tratamiento).
 * Los conceptos solo se pueden editar mientras el presupuesto está en
 * borrador o propuesto: una vez aceptado, el trabajo cotizado queda fijo y
 * únicamente se marca como realizado desde la ficha.
 */
require_once __DIR__ . '/../includes/odontograma.php';
require_login();
require_modulo('presupuestos');

$u  = current_user();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$pre   = ['folio' => '', 'paciente_id' => (int) ($_GET['paciente_id'] ?? 0), 'medico_id' => null,
          'fecha' => date('Y-m-d'), 'vigencia' => '', 'estado' => 'borrador',
          'descuento' => '0', 'notas' => ''];
$items = [];

if ($id) {
    $st = db()->prepare('SELECT * FROM presupuestos WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $pre = $st->fetch();
    if (!$pre) { http_response_code(404); die('Presupuesto no encontrado.'); }
    if (!in_array($pre['estado'], ['borrador', 'propuesto'], true)) {
        flash(t('Un presupuesto aceptado ya no se puede editar.'), 'warning');
        redirect('/presupuestos/ver?id=' . $id);
    }
    $it = db()->prepare('SELECT * FROM presupuesto_items WHERE presupuesto_id = ? ORDER BY orden, id');
    $it->execute([$id]);
    $items = $it->fetchAll();
}

$catalogo = db()->prepare('SELECT id, nombre, precio, aplica_diente FROM servicios
                           WHERE consultorio_id = ? AND activo = 1 ORDER BY nombre');
$catalogo->execute([tenant_id()]);
$catalogo = $catalogo->fetchAll();

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    $medico_id   = (int) ($_POST['medico_id'] ?? 0);
    $descuento   = max(0, (float) ($_POST['descuento'] ?? 0));
    $estadoNuevo = in_array($_POST['estado'] ?? '', ['borrador', 'propuesto'], true) ? $_POST['estado'] : 'borrador';
    $fecha       = ($_POST['fecha'] ?? '') ?: date('Y-m-d');
    $vigencia    = ($_POST['vigencia'] ?? '') ?: null;
    $notas       = trim((string) ($_POST['notas'] ?? '')) ?: null;

    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) {
        $errores[] = t('Selecciona un paciente.');
    }
    if ($medico_id && !pertenece_al_tenant('usuarios', $medico_id)) $medico_id = 0;

    // Servicios válidos del consultorio, para no aceptar ids ajenos.
    $validos  = array_column($catalogo, null, 'id');
    $dientes  = dientes_fdi();
    $trats    = odo_tratamientos();
    $limpios  = [];
    $subtotal = 0.0;

    foreach (($_POST['item'] ?? []) as $linea) {
        $servicio_id = (int) ($linea['servicio_id'] ?? 0);
        $servicio    = $validos[$servicio_id] ?? null;
        $descripcion = trim((string) ($linea['descripcion'] ?? ''));
        if ($descripcion === '' && $servicio) $descripcion = $servicio['nombre'];
        if ($descripcion === '') continue;

        $diente = (string) ($linea['diente'] ?? '');
        $diente = in_array((int) $diente, $dientes, true) ? $diente : null;

        $cantidad = max(1, (int) ($linea['cantidad'] ?? 1));
        $precio   = max(0, (float) ($linea['precio'] ?? 0));
        $importe  = $cantidad * $precio;
        $subtotal += $importe;

        // Se conserva el tratamiento de origen (odontograma) para poder marcar
        // la cara como realizada cuando el procedimiento se ejecute.
        $tratamiento = (string) ($linea['tratamiento'] ?? '');
        $tratamiento = ($diente && isset($trats[$tratamiento])) ? $tratamiento : null;

        $limpios[] = [
            $servicio ? $servicio_id : null,
            mb_substr($descripcion, 0, 200),
            $diente,
            $diente ? caras_normalizar($linea['caras'] ?? []) : null,
            $cantidad, $precio, $importe, $tratamiento,
        ];
    }
    if (!$limpios) $errores[] = t('Agrega al menos un procedimiento.');
    if ($descuento > $subtotal) $errores[] = t('El descuento no puede ser mayor que el subtotal.');

    if (!$errores) {
        $total = max(0, $subtotal - $descuento);
        $pdo   = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE presupuestos SET paciente_id=?, medico_id=?, fecha=?, vigencia=?, estado=?,
                                                       subtotal=?, descuento=?, total=?, notas=?
                               WHERE id=? AND consultorio_id=?')
                    ->execute([$paciente_id, $medico_id ?: null, $fecha, $vigencia, $estadoNuevo,
                               $subtotal, $descuento, $total, $notas, $id, tenant_id()]);
                // Los conceptos se reescriben: en borrador/propuesto ninguno puede
                // estar marcado como realizado, así que no se pierde histórico.
                $pdo->prepare('DELETE FROM presupuesto_items WHERE presupuesto_id = ?')->execute([$id]);
                auditar('editar', 'presupuesto', $id, $pre['folio']);
            } else {
                $folio = presupuesto_siguiente_folio();
                $pdo->prepare('INSERT INTO presupuestos
                               (consultorio_id, folio, paciente_id, medico_id, fecha, vigencia, estado,
                                subtotal, descuento, total, notas, creado_por)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([tenant_id(), $folio, $paciente_id, $medico_id ?: null, $fecha, $vigencia,
                               $estadoNuevo, $subtotal, $descuento, $total, $notas, $u['id']]);
                $id  = (int) $pdo->lastInsertId();
                $pre['folio'] = $folio;
                auditar('crear', 'presupuesto', $id, $folio);
            }

            $ins = $pdo->prepare('INSERT INTO presupuesto_items
                                  (presupuesto_id, servicio_id, descripcion, diente, caras, cantidad, precio, importe, tratamiento, orden)
                                  VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($limpios as $i => $row) {
                $ins->execute(array_merge([$id], $row, [$i]));
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        flash(t('Presupuesto guardado.') . ' ' . $pre['folio']);
        redirect('/presupuestos/ver?id=' . $id);
    }

    // Repinta lo capturado tras un error de validación.
    $pre   = array_merge($pre, $_POST);
    $items = [];
    foreach (($_POST['item'] ?? []) as $l) {
        $items[] = [
            'servicio_id' => (int) ($l['servicio_id'] ?? 0) ?: null,
            'descripcion' => (string) ($l['descripcion'] ?? ''),
            'diente'      => (string) ($l['diente'] ?? ''),
            'caras'       => caras_normalizar($l['caras'] ?? []) ?? '',
            'cantidad'    => (int) ($l['cantidad'] ?? 1),
            'precio'      => (float) ($l['precio'] ?? 0),
            'tratamiento' => (string) ($l['tratamiento'] ?? ''),
        ];
    }
}

$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();

$medicos = db()->prepare("SELECT id, nombre FROM usuarios WHERE consultorio_id = ? AND rol = 'medico' AND activo = 1 ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

if (!$items) $items = [['servicio_id' => null, 'descripcion' => '', 'diente' => '', 'caras' => '', 'cantidad' => 1, 'precio' => 0, 'tratamiento' => '']];

$titulo = $id ? t('Editar presupuesto') : t('Nuevo presupuesto');
$activo = 'presupuestos';
include __DIR__ . '/../includes/header.php';

/** Pinta la celda de caras (checkboxes O/M/D/V/L) de una fila. */
function celda_caras(int $i, string $seleccionadas): string
{
    $sel = array_filter(explode(',', $seleccionadas));
    $h   = '<div class="caras-box d-flex gap-1">';
    foreach (caras_dentales() as $k => $lbl) {
        $cid = "cara_{$i}_{$k}";
        $h .= '<div class="form-check form-check-inline m-0">'
            . '<input class="form-check-input visually-hidden" type="checkbox" id="' . $cid . '"'
            . ' name="item[' . $i . '][caras][]" value="' . $k . '"' . (in_array($k, $sel, true) ? ' checked' : '') . '>'
            . '<label class="cara-lbl" for="' . $cid . '" title="' . e(t($lbl)) . '">' . $k . '</label>'
            . '</div>';
    }
    return $h . '</div>';
}
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/presupuestos/index"><?= et('Presupuestos') ?></a></li>
    <li class="breadcrumb-item active"><?= $id ? e($pre['folio']) : et('Nuevo') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><?= $id ? et('Editar presupuesto') : et('Nuevo presupuesto') ?></h1>

<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $err) echo '<li>'.e($err).'</li>'; ?></ul></div><?php endif; ?>

<?php if (!$catalogo): ?>
<div class="alert alert-warning">
    <i class="bi bi-info-circle"></i>
    <?= et('Tu catálogo de servicios está vacío. Puedes capturar conceptos libres, pero cargarlo te ahorra tiempo.') ?>
    <?php if (has_role('admin')): ?>
    <a href="<?= BASE_URL ?>/servicios/servicio" class="alert-link"><?= et('Agregar servicios') ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label"><?= et('Paciente') ?> *</label>
                <select name="paciente_id" class="form-select" required>
                    <option value=""><?= et('— Selecciona —') ?></option>
                    <?php foreach ($pacientes as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= (int) $pre['paciente_id'] === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= e($p['apellidos'] . ', ' . $p['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Médico / Dentista') ?></label>
                <select name="medico_id" class="form-select">
                    <option value=""><?= et('— Sin asignar —') ?></option>
                    <?php foreach ($medicos as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= (int) ($pre['medico_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= et('Fecha') ?></label>
                <input type="date" name="fecha" class="form-control" value="<?= e($pre['fecha']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><?= et('Vigencia') ?></label>
                <input type="date" name="vigencia" class="form-control" value="<?= e((string) ($pre['vigencia'] ?? '')) ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label"><?= et('Estado') ?></label>
                <select name="estado" class="form-select">
                    <option value="borrador"  <?= $pre['estado'] === 'borrador'  ? 'selected' : '' ?>><?= et('Borrador') ?></option>
                    <option value="propuesto" <?= $pre['estado'] === 'propuesto' ? 'selected' : '' ?>><?= et('Propuesto') ?></option>
                </select>
            </div>
        </div>

        <label class="form-label"><?= et('Procedimientos') ?> *</label>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="tablaItems">
                <thead><tr>
                    <th style="min-width:180px"><?= et('Servicio') ?></th>
                    <th style="min-width:180px"><?= et('Descripción') ?></th>
                    <th style="width:90px"><?= et('Diente') ?></th>
                    <th style="width:150px"><?= et('Caras') ?></th>
                    <th style="width:80px"><?= et('Cant.') ?></th>
                    <th style="width:110px"><?= et('Precio') ?></th>
                    <th class="text-end" style="width:110px"><?= et('Importe') ?></th>
                    <th style="width:40px"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $i => $it): ?>
                    <tr>
                        <td>
                            <input type="hidden" name="item[<?= $i ?>][tratamiento]" value="<?= e((string) ($it['tratamiento'] ?? '')) ?>">
                            <select name="item[<?= $i ?>][servicio_id]" class="form-select form-select-sm servicio">
                                <option value=""><?= et('Concepto libre') ?></option>
                                <?php foreach ($catalogo as $s): ?>
                                <option value="<?= $s['id'] ?>" data-precio="<?= $s['precio'] ?>" data-diente="<?= $s['aplica_diente'] ?>"
                                        data-nombre="<?= e($s['nombre']) ?>" <?= (int) ($it['servicio_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['nombre']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="item[<?= $i ?>][descripcion]" class="form-control form-control-sm descripcion" value="<?= e((string) $it['descripcion']) ?>"></td>
                        <td>
                            <select name="item[<?= $i ?>][diente]" class="form-select form-select-sm diente">
                                <option value="">—</option>
                                <?php foreach (dientes_fdi() as $d): ?>
                                <option value="<?= $d ?>" <?= (string) ($it['diente'] ?? '') === (string) $d ? 'selected' : '' ?>><?= $d ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><?= celda_caras($i, (string) ($it['caras'] ?? '')) ?></td>
                        <td><input type="number" min="1" name="item[<?= $i ?>][cantidad]" class="form-control form-control-sm cant" value="<?= (int) $it['cantidad'] ?>"></td>
                        <td><input type="number" step="0.01" min="0" name="item[<?= $i ?>][precio]" class="form-control form-control-sm precio" value="<?= e((string) $it['precio']) ?>"></td>
                        <td class="text-end importe">$0.00</td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="agregarItem">
            <i class="bi bi-plus"></i> <?= et('Agregar procedimiento') ?>
        </button>

        <div class="row g-3 justify-content-end">
            <div class="col-md-4">
                <div class="d-flex justify-content-between"><span><?= et('Subtotal') ?></span><strong id="subtotalTxt">$0.00</strong></div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span><?= et('Descuento') ?></span>
                    <input type="number" step="0.01" min="0" name="descuento" id="descuento" class="form-control form-control-sm w-50 text-end" value="<?= e((string) $pre['descuento']) ?>">
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-5"><span><?= et('Total') ?></span><strong id="totalTxt" class="text-brand">$0.00</strong></div>
            </div>
        </div>

        <div class="mt-3">
            <label class="form-label"><?= et('Notas para el paciente') ?></label>
            <textarea name="notas" class="form-control" rows="2"><?= e((string) ($pre['notas'] ?? '')) ?></textarea>
        </div>
    </div>
    <div class="card-footer text-end">
        <a href="<?= BASE_URL ?>/presupuestos/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar presupuesto') ?></button>
    </div>
</form>

<script>
(function () {
    var idx = <?= count($items) ?>;
    var dientes = <?= json_encode(dientes_fdi()) ?>;
    var caras   = <?= json_encode(array_keys(caras_dentales())) ?>;
    var T = {
        libre: <?= json_encode(t('Concepto libre')) ?>,
        servicios: <?= json_encode(array_map(fn($s) => [
            'id' => (int) $s['id'], 'nombre' => $s['nombre'],
            'precio' => (float) $s['precio'], 'diente' => (int) $s['aplica_diente'],
        ], $catalogo), JSON_UNESCAPED_UNICODE) ?>
    };
    var money = n => '$' + (Number(n) || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    function recalc() {
        var subtotal = 0;
        document.querySelectorAll('#tablaItems tbody tr').forEach(function (tr) {
            var imp = (Number(tr.querySelector('.cant').value) || 0) * (Number(tr.querySelector('.precio').value) || 0);
            tr.querySelector('.importe').textContent = money(imp);
            subtotal += imp;
        });
        var desc = Number(document.getElementById('descuento').value) || 0;
        document.getElementById('subtotalTxt').textContent = money(subtotal);
        document.getElementById('totalTxt').textContent = money(Math.max(0, subtotal - desc));
    }

    /* Al elegir un servicio del catálogo se copia su nombre y precio, y se
       resalta la columna del diente si el servicio se cotiza por pieza. */
    function aplicarServicio(tr) {
        var opt = tr.querySelector('.servicio').selectedOptions[0];
        if (!opt || !opt.value) { tr.classList.remove('necesita-diente'); return; }
        var desc = tr.querySelector('.descripcion');
        if (!desc.value.trim()) desc.value = opt.dataset.nombre;
        var precio = tr.querySelector('.precio');
        if (!Number(precio.value)) precio.value = opt.dataset.precio;
        tr.classList.toggle('necesita-diente', opt.dataset.diente === '1');
        recalc();
    }

    function filaHTML(i) {
        var opciones = '<option value="">' + T.libre + '</option>' + T.servicios.map(function (s) {
            return '<option value="' + s.id + '" data-precio="' + s.precio + '" data-diente="' + s.diente +
                   '" data-nombre="' + s.nombre.replace(/"/g, '&quot;') + '">' + s.nombre.replace(/</g, '&lt;') + '</option>';
        }).join('');
        var opcDientes = '<option value="">—</option>' + dientes.map(d => '<option value="' + d + '">' + d + '</option>').join('');
        var opcCaras = caras.map(function (c) {
            var cid = 'cara_' + i + '_' + c;
            return '<div class="form-check form-check-inline m-0">' +
                   '<input class="form-check-input visually-hidden" type="checkbox" id="' + cid + '" name="item[' + i + '][caras][]" value="' + c + '">' +
                   '<label class="cara-lbl" for="' + cid + '">' + c + '</label></div>';
        }).join('');
        return '<td><input type="hidden" name="item[' + i + '][tratamiento]" value="">' +
               '<select name="item[' + i + '][servicio_id]" class="form-select form-select-sm servicio">' + opciones + '</select></td>' +
               '<td><input type="text" name="item[' + i + '][descripcion]" class="form-control form-control-sm descripcion"></td>' +
               '<td><select name="item[' + i + '][diente]" class="form-select form-select-sm diente">' + opcDientes + '</select></td>' +
               '<td><div class="caras-box d-flex gap-1">' + opcCaras + '</div></td>' +
               '<td><input type="number" min="1" name="item[' + i + '][cantidad]" class="form-control form-control-sm cant" value="1"></td>' +
               '<td><input type="number" step="0.01" min="0" name="item[' + i + '][precio]" class="form-control form-control-sm precio" value="0"></td>' +
               '<td class="text-end importe">$0.00</td>' +
               '<td><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>';
    }

    document.getElementById('agregarItem').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML = filaHTML(idx++);
        document.querySelector('#tablaItems tbody').appendChild(tr);
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('.cant, .precio, #descuento')) recalc();
    });
    document.addEventListener('change', function (e) {
        if (e.target.matches('.servicio')) aplicarServicio(e.target.closest('tr'));
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest('.quitar')) {
            var filas = document.querySelectorAll('#tablaItems tbody tr');
            if (filas.length > 1) { e.target.closest('tr').remove(); recalc(); }
        }
    });

    document.querySelectorAll('#tablaItems tbody tr').forEach(function (tr) {
        var opt = tr.querySelector('.servicio').selectedOptions[0];
        if (opt && opt.dataset.diente === '1') tr.classList.add('necesita-diente');
    });
    recalc();
})();
</script>
<style>
/* Caras del diente: chips O/M/D/V/L en lugar de casillas sueltas. */
.cara-lbl{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;
          border:1px solid var(--bs-border-color);border-radius:6px;font-size:.7rem;font-weight:700;
          cursor:pointer;user-select:none;opacity:.55}
.form-check-input:checked + .cara-lbl{background:var(--brand);border-color:var(--brand);color:#fff;opacity:1}
.form-check-input:focus-visible + .cara-lbl{outline:2px solid var(--brand);outline-offset:1px}
tr.necesita-diente .diente{border-color:var(--brand)}
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
