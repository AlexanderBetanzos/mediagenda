<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();
$errores = [];
$presel = (int) ($_GET['paciente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    $descuento   = (float) ($_POST['descuento'] ?? 0);
    $lineas      = $_POST['item'] ?? [];

    if (!$paciente_id) $errores[] = 'Selecciona un paciente.';

    $items = [];
    $subtotal = 0;
    foreach ($lineas as $l) {
        $desc = trim($l['descripcion'] ?? '');
        if ($desc === '') continue;
        $cant = max(1, (int) ($l['cantidad'] ?? 1));
        $precio = (float) ($l['precio'] ?? 0);
        $importe = $cant * $precio;
        $subtotal += $importe;
        $items[] = [$desc, $cant, $precio, $importe];
    }
    if (!$items) $errores[] = 'Agrega al menos un concepto.';

    if (!$errores) {
        $total = max(0, $subtotal - $descuento);
        $pdo = db();
        $pdo->beginTransaction();
        // folio temporal único, luego se actualiza con el id
        $tmp = 'TMP-' . uniqid();
        $stmt = $pdo->prepare(
            'INSERT INTO facturas (folio, paciente_id, medico_id, fecha, subtotal, descuento, total, estado, metodo_pago, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $tmp, $paciente_id, $u['rol'] === 'medico' ? $u['id'] : null,
            $_POST['fecha'] ?: date('Y-m-d'),
            $subtotal, $descuento, $total,
            $_POST['estado'] ?? 'pendiente',
            trim($_POST['metodo_pago'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
        ]);
        $fid = $pdo->lastInsertId();
        $folio = 'F-' . date('Y') . '-' . str_pad((string)$fid, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE facturas SET folio = ? WHERE id = ?')->execute([$folio, $fid]);

        $it = $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,?,?,?)');
        foreach ($items as $row) {
            $it->execute([$fid, $row[0], $row[1], $row[2], $row[3]]);
        }
        $pdo->commit();
        flash('Factura ' . $folio . ' creada.');
        redirect('/facturacion/ver.php?id=' . $fid);
    }
}

$pacientes = db()->query('SELECT id, nombre, apellidos FROM pacientes ORDER BY apellidos, nombre')->fetchAll();

$titulo = 'Nueva factura';
$activo = 'facturacion';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/facturacion/index.php">Facturación</a></li>
    <li class="breadcrumb-item active">Nueva</li>
</ol></nav>
<h1 class="h3 mb-3">Nueva factura</h1>

<?php if ($errores): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <label class="form-label">Paciente *</label>
                <select name="paciente_id" class="form-select" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($pacientes as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $presel===$p['id']?'selected':'' ?>><?= e($p['apellidos'].', '.$p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3"><label class="form-label">Fecha</label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4">
                <label class="form-label">Estado</label>
                <select name="estado" class="form-select">
                    <option value="pendiente">Pendiente</option>
                    <option value="pagada">Pagada</option>
                </select>
            </div>
        </div>

        <label class="form-label">Conceptos *</label>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="tablaItems">
                <thead><tr><th style="width:45%">Descripción</th><th>Cant.</th><th>Precio</th><th class="text-end">Importe</th><th></th></tr></thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="item[0][descripcion]" class="form-control" placeholder="Consulta médica"></td>
                        <td><input type="number" name="item[0][cantidad]" class="form-control cant" value="1" min="1"></td>
                        <td><input type="number" step="0.01" name="item[0][precio]" class="form-control precio" value="0"></td>
                        <td class="text-end importe">$0.00</td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="agregarItem"><i class="bi bi-plus"></i> Agregar concepto</button>

        <div class="row g-3 justify-content-end">
            <div class="col-md-4">
                <div class="d-flex justify-content-between"><span>Subtotal</span><strong id="subtotalTxt">$0.00</strong></div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span>Descuento</span>
                    <input type="number" step="0.01" name="descuento" id="descuento" class="form-control form-control-sm w-50 text-end" value="0">
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-5"><span>Total</span><strong id="totalTxt" class="text-info">$0.00</strong></div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-4"><label class="form-label">Método de pago</label><input type="text" name="metodo_pago" class="form-control" placeholder="Efectivo, tarjeta…"></div>
            <div class="col-md-8"><label class="form-label">Notas</label><input type="text" name="notas" class="form-control"></div>
        </div>
    </div>
    <div class="card-footer text-end">
        <a href="<?= BASE_URL ?>/facturacion/index.php" class="btn btn-light">Cancelar</a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar factura</button>
    </div>
</form>

<script>
let idx = 1;
const money = n => '$' + (Number(n)||0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});

function recalc() {
    let subtotal = 0;
    document.querySelectorAll('#tablaItems tbody tr').forEach(tr => {
        const cant = Number(tr.querySelector('.cant').value) || 0;
        const precio = Number(tr.querySelector('.precio').value) || 0;
        const imp = cant * precio;
        tr.querySelector('.importe').textContent = money(imp);
        subtotal += imp;
    });
    const desc = Number(document.getElementById('descuento').value) || 0;
    document.getElementById('subtotalTxt').textContent = money(subtotal);
    document.getElementById('totalTxt').textContent = money(Math.max(0, subtotal - desc));
}

document.getElementById('agregarItem').addEventListener('click', () => {
    const tb = document.querySelector('#tablaItems tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="item[${idx}][descripcion]" class="form-control"></td>
        <td><input type="number" name="item[${idx}][cantidad]" class="form-control cant" value="1" min="1"></td>
        <td><input type="number" step="0.01" name="item[${idx}][precio]" class="form-control precio" value="0"></td>
        <td class="text-end importe">$0.00</td>
        <td><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>`;
    tb.appendChild(tr);
    idx++;
});
document.addEventListener('input', e => {
    if (e.target.matches('.cant, .precio, #descuento')) recalc();
});
document.addEventListener('click', e => {
    if (e.target.closest('.quitar')) {
        const rows = document.querySelectorAll('#tablaItems tbody tr');
        if (rows.length > 1) { e.target.closest('tr').remove(); recalc(); }
    }
});
recalc();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
