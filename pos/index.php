<?php
/**
 * Punto de venta (POS) — cobro rápido en mostrador. Arma un carrito con
 * productos del inventario y/o conceptos libres, genera una factura PAGADA
 * al instante y descuenta el stock de los productos vendidos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');

$u   = current_user();
$pdo = db();
$tid = (int) tenant_id();

/** Paciente genérico para ventas de mostrador (se crea una vez). */
function pos_paciente_general(PDO $pdo, int $tid): int
{
    $s = $pdo->prepare("SELECT id FROM pacientes WHERE consultorio_id=? AND nombre='Público general' AND apellidos='' LIMIT 1");
    $s->execute([$tid]);
    $id = (int) $s->fetchColumn();
    if ($id) return $id;
    $pdo->prepare("INSERT INTO pacientes (consultorio_id, nombre, apellidos) VALUES (?, 'Público general', '')")->execute([$tid]);
    return (int) $pdo->lastInsertId();
}

/* ── Cobrar ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) {
        $paciente_id = pos_paciente_general($pdo, $tid);
    }
    $metodo = trim($_POST['metodo_pago'] ?? 'Efectivo') ?: 'Efectivo';
    $lineas = $_POST['item'] ?? [];

    $items = [];       // [desc, cant, precio, importe, producto_id|null]
    $subtotal = 0;
    foreach ($lineas as $l) {
        $desc = trim($l['descripcion'] ?? '');
        if ($desc === '') continue;
        $cant   = max(1, (int) ($l['cantidad'] ?? 1));
        $precio = (float) ($l['precio'] ?? 0);
        $pid    = (int) ($l['producto_id'] ?? 0) ?: null;
        $importe = $cant * $precio;
        $subtotal += $importe;
        $items[] = [$desc, $cant, $precio, $importe, $pid];
    }

    if (!$items) {
        flash('Agrega al menos un producto o concepto.', 'warning');
        redirect('/pos/index');
    }

    $pdo->beginTransaction();
    $tmp = 'TMP-' . uniqid();
    $pdo->prepare(
        'INSERT INTO facturas (consultorio_id, folio, paciente_id, medico_id, fecha, subtotal, descuento, total, estado, metodo_pago, notas)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $tid, $tmp, $paciente_id, $u['rol'] === 'medico' ? $u['id'] : null,
        date('Y-m-d'), $subtotal, 0, $subtotal, 'pagada', $metodo, 'Venta en punto de venta',
    ]);
    $fid = (int) $pdo->lastInsertId();
    $folio = 'F-' . date('Y') . '-' . str_pad((string) $fid, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE facturas SET folio = ? WHERE id = ? AND consultorio_id = ?')->execute([$folio, $fid, $tid]);

    $it  = $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,?,?,?)');
    $mov = $pdo->prepare('INSERT INTO inventario_movimientos (consultorio_id, producto_id, tipo, cantidad, motivo, usuario_id) VALUES (?,?,?,?,?,?)');
    foreach ($items as [$desc, $cant, $precio, $importe, $pid]) {
        $it->execute([$fid, $desc, $cant, $precio, $importe]);
        // Descuenta stock solo si la línea es un producto del inventario.
        if ($pid && pertenece_al_tenant('productos', $pid)) {
            $mov->execute([$tid, $pid, 'salida', $cant, 'Venta POS ' . $folio, $u['id']]);
        }
    }
    $pdo->commit();
    auditar('crear', 'venta_pos', $fid, $folio . ' · ' . fmt_money($subtotal));
    flash('Venta cobrada · ' . $folio . ' · ' . fmt_money($subtotal));
    redirect('/facturacion/ver?id=' . $fid);
}

/* ── Datos para la vista ────────────────────────────────────────────── */
$verInv = modulo_activo('farmacia');
$productos = [];
if ($verInv) {
    $st = $pdo->prepare(
        "SELECT p.id, p.nombre, p.precio, p.categoria,
                COALESCE((SELECT SUM(CASE WHEN m.tipo='salida' THEN -m.cantidad ELSE m.cantidad END)
                          FROM inventario_movimientos m WHERE m.producto_id = p.id), 0) AS stock
         FROM productos p WHERE p.consultorio_id = ? AND p.activo = 1 ORDER BY p.nombre"
    );
    $st->execute([$tid]);
    $productos = $st->fetchAll();
}

$pac = $pdo->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pac->execute([$tid]);
$pacientes = $pac->fetchAll();

$titulo = t('Punto de venta');
$activo = 'pos';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-shop text-brand"></i> <?= et('Punto de venta') ?></h1>

<form method="post" id="posForm">
<?= csrf_field() ?>
<div class="row g-3">
    <!-- Catálogo -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-box-seam text-brand"></i> <?= et('Productos') ?></span>
                <input type="search" id="buscar" class="form-control form-control-sm" style="max-width:220px" placeholder="<?= e(t('Buscar…')) ?>">
            </div>
            <div class="card-body">
                <?php if (!$productos): ?>
                    <p class="text-muted mb-0"><?= $verInv ? et('No hay productos activos. Agrega productos en Inventario o usa un concepto libre.') : et('Inventario no disponible en tu plan. Usa un concepto libre.') ?></p>
                <?php else: ?>
                <div class="row g-2" id="catalogo">
                    <?php foreach ($productos as $p): ?>
                        <div class="col-6 col-md-4 prod" data-nombre="<?= e(mb_strtolower($p['nombre'])) ?>">
                            <button type="button" class="btn btn-light w-100 h-100 text-start p-2 add-prod"
                                    data-id="<?= (int) $p['id'] ?>" data-nombre="<?= e($p['nombre']) ?>" data-precio="<?= (float) $p['precio'] ?>">
                                <div class="fw-semibold small text-truncate"><?= e($p['nombre']) ?></div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="text-brand fw-bold"><?= fmt_money($p['precio']) ?></span>
                                    <span class="badge bg-secondary bg-opacity-25 text-muted"><?= (int) $p['stock'] ?></span>
                                </div>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <hr>
                <div class="row g-2 align-items-end">
                    <div class="col-6"><label class="form-label small"><?= et('Concepto libre') ?></label><input type="text" id="freeDesc" class="form-control form-control-sm" placeholder="<?= e(t('Ej. Consulta general')) ?>"></div>
                    <div class="col-3"><label class="form-label small"><?= et('Precio') ?></label><input type="number" id="freePrecio" step="0.01" min="0" class="form-control form-control-sm"></div>
                    <div class="col-3"><button type="button" id="addFree" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus-lg"></i> <?= et('Agregar') ?></button></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ticket -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-cart3 text-brand"></i> <?= et('Venta') ?></div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small"><?= et('Paciente') ?></label>
                    <select name="paciente_id" class="form-select form-select-sm">
                        <option value=""><?= et('Público general (mostrador)') ?></option>
                        <?php foreach ($pacientes as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['apellidos'] . ', ' . $p['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2">
                        <thead><tr><th><?= et('Concepto') ?></th><th class="text-center" style="width:90px"><?= et('Cant.') ?></th><th class="text-end"><?= et('Importe') ?></th><th></th></tr></thead>
                        <tbody id="cart"><tr id="cartEmpty"><td colspan="4" class="text-center text-muted py-3"><?= et('Carrito vacío') ?></td></tr></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center border-top pt-2">
                    <span class="fw-semibold"><?= et('Total') ?></span>
                    <span class="h4 mb-0 fw-bold text-brand" id="total"><?= fmt_money(0) ?></span>
                </div>
                <div class="mt-3">
                    <label class="form-label small"><?= et('Método de pago') ?></label>
                    <select name="metodo_pago" class="form-select form-select-sm">
                        <option value="Efectivo"><?= et('Efectivo') ?></option>
                        <option value="Tarjeta"><?= et('Tarjeta') ?></option>
                        <option value="Transferencia"><?= et('Transferencia') ?></option>
                        <option value="Otro"><?= et('Otro') ?></option>
                    </select>
                </div>
                <button type="submit" id="cobrar" class="btn btn-primary w-100 mt-3" disabled><i class="bi bi-cash-coin"></i> <?= et('Cobrar') ?></button>
            </div>
        </div>
    </div>
</div>
</form>

<script>
(function () {
    var cart = [];   // {id, desc, precio, cant}
    var moneda = function (v) { return '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var $cart = document.getElementById('cart'), $empty = document.getElementById('cartEmpty');
    var $total = document.getElementById('total'), $cobrar = document.getElementById('cobrar'), $form = document.getElementById('posForm');

    function add(id, desc, precio) {
        var key = id || ('free:' + desc + ':' + precio);
        var found = cart.find(function (x) { return x.key === key; });
        if (found) found.cant++;
        else cart.push({ key: key, id: id || '', desc: desc, precio: Number(precio), cant: 1 });
        render();
    }
    function render() {
        $cart.querySelectorAll('tr.line').forEach(function (n) { n.remove(); });
        var total = 0;
        cart.forEach(function (x, i) {
            total += x.precio * x.cant;
            var tr = document.createElement('tr');
            tr.className = 'line';
            tr.innerHTML = '<td class="small">' + x.desc + '<div class="text-muted">' + moneda(x.precio) + '</div></td>' +
                '<td class="text-center"><div class="input-group input-group-sm" style="max-width:90px">' +
                '<button type="button" class="btn btn-outline-secondary py-0 dec">−</button>' +
                '<input type="text" class="form-control text-center py-0 qty" value="' + x.cant + '" readonly>' +
                '<button type="button" class="btn btn-outline-secondary py-0 inc">+</button></div></td>' +
                '<td class="text-end fw-semibold">' + moneda(x.precio * x.cant) + '</td>' +
                '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-secondary py-0 del"><i class="bi bi-x"></i></button></td>';
            tr.querySelector('.inc').onclick = function () { x.cant++; render(); };
            tr.querySelector('.dec').onclick = function () { x.cant = Math.max(1, x.cant - 1); render(); };
            tr.querySelector('.del').onclick = function () { cart.splice(i, 1); render(); };
            $cart.appendChild(tr);
        });
        $empty.style.display = cart.length ? 'none' : '';
        $total.textContent = moneda(total);
        $cobrar.disabled = cart.length === 0;
    }

    document.querySelectorAll('.add-prod').forEach(function (b) {
        b.onclick = function () { add(b.dataset.id, b.dataset.nombre, b.dataset.precio); };
    });
    document.getElementById('addFree').onclick = function () {
        var d = document.getElementById('freeDesc').value.trim(), p = document.getElementById('freePrecio').value;
        if (!d || !(Number(p) >= 0)) return;
        add('', d, p);
        document.getElementById('freeDesc').value = ''; document.getElementById('freePrecio').value = '';
    };
    document.getElementById('buscar').oninput = function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#catalogo .prod').forEach(function (n) {
            n.style.display = n.dataset.nombre.indexOf(q) !== -1 ? '' : 'none';
        });
    };
    // Serializa el carrito en inputs ocultos al enviar.
    $form.addEventListener('submit', function () {
        cart.forEach(function (x, i) {
            var mk = function (name, val) { var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'item[' + i + '][' + name + ']'; inp.value = val; $form.appendChild(inp); };
            mk('descripcion', x.desc); mk('cantidad', x.cant); mk('precio', x.precio); mk('producto_id', x.id);
        });
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
