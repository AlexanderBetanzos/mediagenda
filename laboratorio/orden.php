<?php
/**
 * Alta / edición de una orden de laboratorio.
 * Los estudios se copian del catálogo al item (nombre, precio, unidad,
 * referencia): si mañana cambia el catálogo, la orden ya emitida no se altera.
 * Al guardar, los items se reescriben completos (borrar + insertar), igual que
 * en presupuestos, pero conservando los resultados ya capturados por nombre.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('laboratorio');

$id     = (int) ($_GET['id'] ?? 0);
$pacSel = (int) ($_GET['paciente_id'] ?? 0);
$u      = current_user();
$orden  = null;
$items  = [];

if ($id) {
    $st = db()->prepare('SELECT * FROM lab_ordenes WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $orden = $st->fetch();
    if (!$orden) { flash('Orden no encontrada.', 'warning'); redirect('/laboratorio/index'); }

    $it = db()->prepare('SELECT * FROM lab_orden_items WHERE orden_id = ? ORDER BY id');
    $it->execute([$id]);
    $items = $it->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // El paciente y el médico deben ser de ESTE consultorio: los ids llegan del
    // POST y no basta con que el select solo ofreciera los propios.
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    if ($paciente_id && !pertenece_al_tenant('pacientes', $paciente_id)) { $paciente_id = 0; }
    $medico_id = (int) ($_POST['medico_id'] ?? 0);
    if ($medico_id && !pertenece_al_tenant('usuarios', $medico_id)) { $medico_id = 0; }

    $nombres     = $_POST['item_nombre']  ?? [];
    $precios     = $_POST['item_precio']  ?? [];
    $estudioIds  = $_POST['item_estudio'] ?? [];
    $unidades    = $_POST['item_unidad']  ?? [];
    $refs        = $_POST['item_ref']     ?? [];

    // Filtra filas vacías: el formulario permite dejar renglones sin llenar.
    $filas = [];
    foreach ($nombres as $i => $n) {
        $n = trim((string) $n);
        if ($n === '') continue;
        $filas[] = [
            'nombre'     => mb_substr($n, 0, 160),
            'precio'     => (float) ($precios[$i] ?? 0),
            'estudio_id' => ((int) ($estudioIds[$i] ?? 0)) ?: null,
            'unidad'     => (trim((string) ($unidades[$i] ?? '')) ?: null),
            'referencia' => (trim((string) ($refs[$i] ?? '')) ?: null),
        ];
    }

    if (!$paciente_id || !$filas) {
        flash('La orden necesita un paciente y al menos un estudio.', 'warning');
    } else {
        $total = array_sum(array_column($filas, 'precio'));
        $datos = [
            'paciente_id' => $paciente_id,
            'medico_id'   => $medico_id ?: null,
            'fecha'       => ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
            'prioridad'   => ($_POST['prioridad'] ?? '') === 'urgente' ? 'urgente' : 'normal',
            'proveedor'   => (trim((string) ($_POST['proveedor'] ?? '')) ?: null),
            'diagnostico' => (trim((string) ($_POST['diagnostico'] ?? '')) ?: null),
            'notas'       => (trim((string) ($_POST['notas'] ?? '')) ?: null),
            'total'       => $total,
        ];

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare(
                    'UPDATE lab_ordenes SET paciente_id = ?, medico_id = ?, fecha = ?, prioridad = ?,
                            proveedor = ?, diagnostico = ?, notas = ?, total = ?
                     WHERE id = ? AND consultorio_id = ?'
                )->execute(array_merge(array_values($datos), [$id, tenant_id()]));

                // Conserva los resultados ya capturados, emparejando por nombre.
                // Se guarda una cola por nombre y cada resultado se consume una
                // sola vez: si la orden repite un estudio (glucosa basal y
                // postprandial), cada renglón recupera el suyo, no el del otro.
                $prev = $pdo->prepare('SELECT nombre, resultado, fuera_rango FROM lab_orden_items WHERE orden_id = ? ORDER BY id');
                $prev->execute([$id]);
                $resultados = [];
                foreach ($prev->fetchAll() as $p) {
                    $resultados[$p['nombre']][] = [$p['resultado'], (int) $p['fuera_rango']];
                }
                $pdo->prepare('DELETE FROM lab_orden_items WHERE orden_id = ?')->execute([$id]);
                auditar('lab_orden_editar', 'lab_orden', $id, $orden['folio']);
            } else {
                $folio = lab_siguiente_folio();
                $pdo->prepare(
                    'INSERT INTO lab_ordenes (consultorio_id, folio, paciente_id, medico_id, fecha,
                                              prioridad, proveedor, diagnostico, notas, total, creado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute(array_merge([tenant_id(), $folio], array_values($datos), [(int) $u['id']]));
                $id = (int) $pdo->lastInsertId();
                $resultados = [];
                auditar('lab_orden_crear', 'lab_orden', $id, $folio);
            }

            $ins = $pdo->prepare(
                'INSERT INTO lab_orden_items (orden_id, estudio_id, nombre, precio, unidad, referencia, resultado, fuera_rango)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($filas as $f) {
                [$res, $fuera] = !empty($resultados[$f['nombre']])
                    ? array_shift($resultados[$f['nombre']])
                    : [null, 0];
                $ins->execute([$id, $f['estudio_id'], $f['nombre'], $f['precio'],
                               $f['unidad'], $f['referencia'], $res, $fuera]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('No se pudo guardar la orden. Inténtalo de nuevo.', 'danger');
            redirect('/laboratorio/index');
        }

        flash('Orden guardada.');
        redirect('/laboratorio/ver?id=' . $id);
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

$catalogo = db()->prepare('SELECT * FROM lab_estudios WHERE consultorio_id = ? AND activo = 1 ORDER BY categoria, nombre');
$catalogo->execute([tenant_id()]);
$catalogo = $catalogo->fetchAll();

$titulo = $orden ? t('Editar orden') : t('Nueva orden');
$activo = 'laboratorio';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/laboratorio/index"><?= et('Laboratorio') ?></a></li>
    <li class="breadcrumb-item active"><?= $orden ? e($orden['folio']) : et('Nueva orden') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-eyedropper text-brand"></i> <?= $orden ? et('Editar orden') : et('Nueva orden de laboratorio') ?></h1>

<?php if (!$catalogo): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span><i class="bi bi-info-circle"></i> <?= et('Tu catálogo de estudios está vacío. Puedes escribir los estudios a mano, o cargarlo una vez y reutilizarlo siempre.') ?></span>
    <?php if (has_role('admin')): ?>
    <a href="<?= BASE_URL ?>/laboratorio/estudios" class="btn btn-sm btn-primary"><?= et('Ir al catálogo') ?></a>
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
                    <?php $pacActual = (int) ($orden['paciente_id'] ?? $pacSel); ?>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $pacActual === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e($p['apellidos'] . ', ' . $p['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Médico que solicita') ?></label>
                <select name="medico_id" class="form-select">
                    <option value=""><?= et('Sin especificar') ?></option>
                    <?php $medActual = (int) ($orden['medico_id'] ?? ($u['rol'] === 'medico' ? $u['id'] : 0)); ?>
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
                       value="<?= e($orden['fecha'] ?? date('Y-m-d')) ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label"><?= et('Prioridad') ?></label>
                <select name="prioridad" class="form-select">
                    <option value="normal"  <?= ($orden['prioridad'] ?? '') !== 'urgente' ? 'selected' : '' ?>><?= et('Normal') ?></option>
                    <option value="urgente" <?= ($orden['prioridad'] ?? '') === 'urgente' ? 'selected' : '' ?>><?= et('Urgente') ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Laboratorio externo') ?></label>
                <input name="proveedor" class="form-control" maxlength="120"
                       placeholder="<?= e(t('Si se envía fuera')) ?>"
                       value="<?= e($orden['proveedor'] ?? '') ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label"><?= et('Diagnóstico presuntivo') ?></label>
                <input name="diagnostico" class="form-control" maxlength="255"
                       value="<?= e($orden['diagnostico'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul text-brand"></i> <?= et('Estudios solicitados') ?></span>
            <div class="d-flex gap-2">
                <?php if ($catalogo): ?>
                <select id="selCatalogo" class="form-select form-select-sm" style="min-width:260px">
                    <option value=""><?= et('Agregar del catálogo…') ?></option>
                    <?php foreach ($catalogo as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"
                                data-nombre="<?= e($c['nombre']) ?>"
                                data-precio="<?= e((string) $c['precio']) ?>"
                                data-unidad="<?= e($c['unidad'] ?? '') ?>"
                                data-ref="<?= e($c['referencia'] ?? '') ?>">
                            <?= e($c['nombre']) ?><?= $c['categoria'] ? ' · ' . e($c['categoria']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnLibre">
                    <i class="bi bi-plus-lg"></i> <?= et('Renglón libre') ?>
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr>
                    <th><?= et('Estudio') ?></th>
                    <th style="width:120px"><?= et('Unidad') ?></th>
                    <th style="width:140px"><?= et('Referencia') ?></th>
                    <th style="width:130px" class="text-end"><?= et('Precio') ?></th>
                    <th style="width:50px"></th>
                </tr></thead>
                <tbody id="items">
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <input type="hidden" name="item_estudio[]" value="<?= (int) ($it['estudio_id'] ?? 0) ?>">
                            <input name="item_nombre[]" class="form-control form-control-sm" required
                                   value="<?= e($it['nombre']) ?>">
                        </td>
                        <td><input name="item_unidad[]" class="form-control form-control-sm" value="<?= e($it['unidad'] ?? '') ?>"></td>
                        <td><input name="item_ref[]" class="form-control form-control-sm" value="<?= e($it['referencia'] ?? '') ?>"></td>
                        <td><input name="item_precio[]" type="number" step="0.01" min="0"
                                   class="form-control form-control-sm text-end precio" value="<?= e((string) $it['precio']) ?>"></td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 quitar"><i class="bi bi-x-lg"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td colspan="3" class="text-end fw-semibold"><?= et('Total') ?></td>
                    <td class="text-end fw-bold" id="total"><?= fmt_money($orden['total'] ?? 0) ?></td>
                    <td></td>
                </tr></tfoot>
            </table>
        </div>
        <div class="card-body border-top">
            <label class="form-label"><?= et('Notas para el laboratorio') ?></label>
            <textarea name="notas" class="form-control" rows="2" maxlength="1000"><?= e($orden['notas'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?= BASE_URL ?>/laboratorio/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar orden') ?></button>
    </div>
</form>

<script>
(function () {
    var tbody  = document.getElementById('items');
    var sel    = document.getElementById('selCatalogo');
    var moneda = '$';   // igual que fmt_money() en el servidor

    /* Los valores vienen del catálogo (texto libre que teclea el consultorio),
       así que se asignan por .value y nunca se interpolan en HTML: si un
       estudio se llamara `" onfocus=…`, concatenarlo rompería el atributo. */
    function input(name, valor, clase) {
        var i = document.createElement('input');
        i.name = name;
        i.className = 'form-control form-control-sm' + (clase ? ' ' + clase : '');
        i.value = valor == null ? '' : valor;
        return i;
    }

    function celda(tr, hijo) {
        var td = document.createElement('td');
        td.appendChild(hijo);
        tr.appendChild(td);
        return td;
    }

    function fila(nombre, precio, unidad, ref, estudioId) {
        var tr = document.createElement('tr');

        var oculto = input('item_estudio[]', estudioId || 0);
        oculto.type = 'hidden';
        var nom = input('item_nombre[]', nombre);
        nom.required = true;
        var tdNombre = celda(tr, oculto);
        tdNombre.appendChild(nom);

        celda(tr, input('item_unidad[]', unidad));
        celda(tr, input('item_ref[]', ref));

        var pre = input('item_precio[]', precio || 0, 'text-end precio');
        pre.type = 'number'; pre.step = '0.01'; pre.min = '0';
        celda(tr, pre);

        var td = document.createElement('td');
        td.className = 'text-end';
        td.innerHTML = '<button type="button" class="btn btn-sm btn-outline-danger py-0 quitar"><i class="bi bi-x-lg"></i></button>';
        tr.appendChild(td);

        tbody.appendChild(tr);
        total();
    }

    function total() {
        var suma = 0;
        tbody.querySelectorAll('.precio').forEach(function (i) { suma += parseFloat(i.value) || 0; });
        document.getElementById('total').textContent =
            moneda + suma.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    if (sel) sel.addEventListener('change', function () {
        var o = sel.options[sel.selectedIndex];
        if (!sel.value) return;
        fila(o.dataset.nombre, o.dataset.precio, o.dataset.unidad, o.dataset.ref, sel.value);
        sel.value = '';
    });

    document.getElementById('btnLibre').addEventListener('click', function () { fila('', 0, '', '', 0); });

    tbody.addEventListener('click', function (ev) {
        var b = ev.target.closest('.quitar');
        if (b) { b.closest('tr').remove(); total(); }
    });
    tbody.addEventListener('input', function (ev) {
        if (ev.target.classList.contains('precio')) total();
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
