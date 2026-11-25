<?php
/**
 * Punto de venta (POS) — cobro rápido en mostrador. Arma un carrito con
 * productos del inventario y/o conceptos libres, genera una factura PAGADA
 * al instante y descuenta el stock de los productos vendidos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventario.php';
require_login();
require_modulo('farmacia');
inv_asegurar();

$u   = current_user();
$pdo = db();
$tid = (int) tenant_id();

// Con sucursales, la venta sale de una sede: la que se está viendo o la primera.
$porSuc = inv_por_sucursal();
$sucs   = $porSuc ? sucursales() : [];
$sucPos = $porSuc ? (sucursal_actual() ?: (int) $sucs[0]['id']) : null;

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

    $suc = $porSuc ? (inv_sucursal_valida($_POST['sucursal_id'] ?? 0) ?: $sucPos) : null;
    $it  = $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,?,?,?)');
    $faltan = [];
    foreach ($items as [$desc, $cant, $precio, $importe, $pid]) {
        $it->execute([$fid, $desc, $cant, $precio, $importe]);
        // Descuenta stock (lotes por FEFO) solo si la línea es un producto del inventario.
        // No frena la venta: lo que ya se entregó en mostrador se vende; si no
        // alcanzaba la existencia registrada, se avisa para corregir el inventario.
        if ($pid && pertenece_al_tenant('productos', $pid)) {
            if (inv_stock($pid, $suc) < $cant) $faltan[] = $desc;
            inv_salida($pid, $cant, $suc, 'Venta POS ' . $folio, false);
        }
    }
    $pdo->commit();
    require_once __DIR__ . '/../includes/cortes.php';
    registrar_cobro($fid);   // entra en el cierre de caja de quien vendió
    auditar('crear', 'venta_pos', $fid, $folio . ' · ' . fmt_money($subtotal));
    flash('Venta cobrada · ' . $folio . ' · ' . fmt_money($subtotal));
    if ($faltan) flash(sprintf(t('Se vendió más de lo que había registrado en inventario: %s. Registra la entrada que falta para que cuadre.'), implode(', ', $faltan)), 'warning');
    redirect('/facturacion/ver?id=' . $fid . '&ticket=1');   // ticket y cajón, si esta PC los tiene
}

/* ── Datos para la vista ────────────────────────────────────────────── */
$verInv = modulo_activo('farmacia');
$productos = [];
if ($verInv) {
    $st = $pdo->prepare('SELECT p.id, p.nombre, p.precio, p.categoria, p.sku,
                                COALESCE((SELECT SUM(l.cantidad) FROM inventario_lotes l WHERE l.producto_id = p.id AND l.consultorio_id = p.consultorio_id'
                                . ($sucPos ? ' AND l.sucursal_id = ' . (int) $sucPos : '') . '), 0) AS stock
                         FROM productos p WHERE p.consultorio_id = ? AND p.activo = 1 ORDER BY p.nombre');
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
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-shop text-brand"></i> <?= et('Punto de venta') ?></h1>
    <?php if ($porSuc): ?>
    <form class="d-inline-flex align-items-center gap-1"><span class="small text-muted"><i class="bi bi-geo-alt"></i> <?= et('Vendes desde') ?></span>
        <select name="sucursal" class="form-select form-select-sm" style="width:auto;min-width:11rem" onchange="this.form.submit()">
            <?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sucPos === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<form method="post" id="posForm">
<?= csrf_field() ?>
<?php if ($sucPos): ?><input type="hidden" name="sucursal_id" value="<?= (int) $sucPos ?>"><?php endif; ?>
<div class="row g-3">
    <!-- Catálogo -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-boxes text-brand"></i> <?= et('Productos') ?></span>
                <input type="search" id="buscar" class="form-control form-control-sm" style="max-width:220px" placeholder="<?= e(t('Buscar…')) ?>">
            </div>
            <div class="card-body">
                <?php if ($productos): ?>
                <div class="pos-scan mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" id="scan" class="form-control" data-escaner autocomplete="off" autofocus
                               placeholder="<?= e(t('Escanea el código de barras o escríbelo y pulsa Enter')) ?>">
                        <button type="button" class="btn btn-outline-secondary" data-disp-camara title="<?= e(t('Escanear con la cámara')) ?>"><i class="bi bi-camera"></i></button>
                    </div>
                    <div id="scanMsg" class="small mt-1"></div>
                    <div id="linkBox" class="border rounded-3 p-2 mt-2" hidden>
                        <div class="small mb-2"><i class="bi bi-upc-scan"></i> <?= et('El código') ?> <b id="linkCode"></b> <?= et('no está registrado. ¿De qué producto es?') ?></div>
                        <div class="d-flex gap-2">
                            <select id="linkProd" class="form-select form-select-sm">
                                <option value=""><?= et('— Elige el producto —') ?></option>
                                <?php foreach ($productos as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?><?= $p['sku'] ? ' (' . e($p['sku']) . ')' : '' ?></option><?php endforeach; ?>
                            </select>
                            <button type="button" id="linkOk" class="btn btn-sm btn-primary text-nowrap"><?= et('Vincular y agregar') ?></button>
                            <button type="button" id="linkNo" class="btn btn-sm btn-link text-muted"><?= et('Cancelar') ?></button>
                        </div>
                        <div class="form-text"><?= et('La próxima vez que escanees este código se reconocerá solo.') ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$productos): ?>
                    <p class="text-muted mb-0"><?= $verInv ? et('No hay productos activos. Agrega productos en Inventario o usa un concepto libre.') : et('Inventario no disponible en tu plan. Usa un concepto libre.') ?></p>
                <?php else: ?>
                <div class="row g-2" id="catalogo">
                    <?php foreach ($productos as $p): ?>
                        <div class="col-6 col-md-4 prod" data-nombre="<?= e(mb_strtolower($p['nombre'])) ?>">
                            <button type="button" class="btn btn-light w-100 h-100 text-start p-2 add-prod" data-sku="<?= e((string) $p['sku']) ?>"
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
    /* Lector de código de barras: el campo de arriba recibe la lectura (o lo
       que se teclee + Enter); si el foco está en otro lado, llega como evento. */
    var porSku = {};
    document.querySelectorAll('.add-prod').forEach(function (b) { if (b.dataset.sku) porSku[b.dataset.sku.trim().toLowerCase()] = b; });
    var $scan = document.getElementById('scan'), $msg = document.getElementById('scanMsg'), $link = document.getElementById('linkBox'), pendiente = '';
    function msg(t, ok) { if (!$msg) return; $msg.className = 'small mt-1 ' + (ok ? 'text-success' : 'text-danger'); $msg.textContent = t; clearTimeout(msg._t); msg._t = setTimeout(function () { $msg.textContent = ''; }, 2500); }
    function porCodigo(cod) {
        cod = String(cod || '').trim(); if (!cod) return;
        var b = porSku[cod.toLowerCase()];
        if (b) { add(b.dataset.id, b.dataset.nombre, b.dataset.precio); msg('+1 ' + b.dataset.nombre, true); if ($scan) $scan.focus(); return; }
        if (!$link) return;
        pendiente = cod; document.getElementById('linkCode').textContent = cod; $link.hidden = false;
        document.getElementById('linkProd').focus();
    }
    if ($scan) $scan.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault(); var c = $scan.value; $scan.value = ''; porCodigo(c);
    });
    document.addEventListener('medios:escaneo', function (e) { e.preventDefault(); porCodigo(e.detail.codigo); });
    if ($link) {
        document.getElementById('linkNo').onclick = function () { $link.hidden = true; pendiente = ''; $scan.focus(); };
        document.getElementById('linkOk').onclick = function () {
            var pid = document.getElementById('linkProd').value, cod = pendiente;
            if (!pid || !cod) return;
            var body = new URLSearchParams({ accion: 'vincular', codigo: cod, producto_id: pid, csrf: <?= json_encode(csrf_token()) ?> });
            fetch(<?= json_encode(BASE_URL . '/dispositivos/codigo') ?>, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (!r.ok) { msg(r.error || 'No se pudo vincular.'); return; }
                    var b = document.querySelector('.add-prod[data-id="' + pid + '"]');
                    if (b) { b.dataset.sku = cod; porSku[cod.toLowerCase()] = b; }
                    $link.hidden = true; pendiente = '';
                    porCodigo(cod);
                }).catch(function () { msg('No se pudo vincular.'); });
        };
    }
    var inicial = new URLSearchParams(location.search).get('codigo');
    if (inicial) porCodigo(inicial);

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
