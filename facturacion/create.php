<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');

$u = current_user();
$errores = [];
$presel = (int) ($_GET['paciente_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    $descuento   = (float) ($_POST['descuento'] ?? 0);
    $lineas      = $_POST['item'] ?? [];

    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) $errores[] = t('Selecciona un paciente.');

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
    if (!$items) $errores[] = t('Agrega al menos un concepto.');

    if (!$errores) {
        $total = max(0, $subtotal - $descuento);
        $pdo = db();
        $pdo->beginTransaction();
        // folio temporal único, luego se actualiza con el id
        $tmp = 'TMP-' . uniqid();
        $stmt = $pdo->prepare(
            'INSERT INTO facturas (consultorio_id, folio, paciente_id, medico_id, fecha, subtotal, descuento, total, estado, metodo_pago, notas)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            tenant_id(), $tmp, $paciente_id, $u['rol'] === 'medico' ? $u['id'] : null,
            $_POST['fecha'] ?: date('Y-m-d'),
            $subtotal, $descuento, $total,
            $_POST['estado'] ?? 'pendiente',
            trim($_POST['metodo_pago'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
        ]);
        $fid = $pdo->lastInsertId();
        $folio = 'F-' . date('Y') . '-' . str_pad((string)$fid, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE facturas SET folio = ? WHERE id = ? AND consultorio_id = ?')->execute([$folio, $fid, tenant_id()]);

        $it = $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,?,?,?)');
        foreach ($items as $row) {
            $it->execute([$fid, $row[0], $row[1], $row[2], $row[3]]);
        }
        $pdo->commit();
        // Quién cobró y cuándo, para su cierre de caja (fuera de la transacción:
        // asegurar_cortes() puede hacer un ALTER, que en MySQL confirma solo).
        require_once __DIR__ . '/../includes/cortes.php';
        registrar_cobro((int) $fid);
        auditar('crear', 'factura', (int) $fid, $folio);
        flash('Factura ' . $folio . ' creada.');
        redirect('/facturacion/ver?id=' . $fid);
    }
}

$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();

$titulo = t('Nueva factura');
$activo = 'facturacion';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/facturacion/index"><?= et('Facturación') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Nueva') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi bi-receipt"></i> <?= et('Nueva factura') ?></h1>

<?php if ($errores): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<?php
/* Atajos del catálogo: un clic agrega el servicio con su precio. */
$catalogo = [];
try {
    $cq = db()->prepare("SELECT nombre, precio FROM servicios WHERE consultorio_id = ? AND activo = 1 ORDER BY categoria, nombre LIMIT 40");
    $cq->execute([tenant_id()]);
    $catalogo = $cq->fetchAll();
} catch (Throwable $e) {}
$metodosPago = [['Efectivo', 'bi-cash'], ['Tarjeta', 'bi-credit-card'], ['Transferencia', 'bi-bank'], ['Otro', 'bi-three-dots']];
?>
<form method="post" class="cobro">
    <?= csrf_field() ?>
    <div class="cobro-grid">
        <div class="cobro-main">
            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-person"></i> <?= et('Paciente y fecha') ?></div>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label"><?= et('Paciente') ?> *</label>
                        <select name="paciente_id" class="form-select" required>
                            <option value=""><?= et('— Selecciona —') ?></option>
                            <?php foreach ($pacientes as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $presel===$p['id']?'selected':'' ?>><?= e($p['apellidos'].', '.$p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                </div>
            </section>

            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-list-check"></i> <?= et('Conceptos') ?> *</div>
                <?php if ($catalogo): ?>
                <div class="cobro-cat">
                    <span class="small text-muted me-1"><?= et('Agregar del catálogo') ?>:</span>
                    <?php foreach ($catalogo as $sv): ?>
                    <button type="button" class="cobro-chip" data-desc="<?= e($sv['nombre']) ?>" data-precio="<?= e((string) (float) $sv['precio']) ?>">
                        <i class="bi bi-plus"></i> <?= e($sv['nombre']) ?><?= (float) $sv['precio'] > 0 ? ' <b>' . fmt_money($sv['precio']) . '</b>' : '' ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2" id="tablaItems">
                        <thead><tr><th style="width:48%"><?= et('Descripción') ?></th><th style="width:14%"><?= et('Cant.') ?></th><th style="width:18%"><?= et('Precio') ?></th><th class="text-end"><?= et('Importe') ?></th><th></th></tr></thead>
                        <tbody>
                            <tr>
                                <td><input type="text" name="item[0][descripcion]" class="form-control" placeholder="<?= et('Consulta médica') ?>"></td>
                                <td><input type="number" name="item[0][cantidad]" class="form-control cant" value="1" min="1"></td>
                                <td><input type="number" step="0.01" name="item[0][precio]" class="form-control precio" value="0"></td>
                                <td class="text-end importe fw-semibold">$0.00</td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar" aria-label="<?= e(t('Quitar')) ?>"><i class="bi bi-x"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="agregarItem"><i class="bi bi-plus"></i> <?= et('Agregar concepto') ?></button>
            </section>

            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-chat-left-text"></i> <?= et('Notas') ?> <span class="pf-opc"><?= et('Opcional') ?></span></div>
                <input type="text" name="notas" class="form-control" placeholder="<?= e(t('Ej. pago en dos exhibiciones')) ?>">
            </section>
        </div>

        <aside class="cobro-side">
            <div class="pf-card cobro-total">
                <div class="d-flex justify-content-between small"><span><?= et('Subtotal') ?></span><strong id="subtotalTxt">$0.00</strong></div>
                <div class="d-flex justify-content-between align-items-center mt-2 small">
                    <span><?= et('Descuento') ?></span>
                    <input type="number" step="0.01" name="descuento" id="descuento" class="form-control form-control-sm text-end" style="max-width:120px" value="0">
                </div>
                <div class="cobro-tot-lbl mt-3"><?= et('Total') ?></div>
                <div class="cobro-tot" id="totalTxt">$0.00</div>

                <div class="form-label mt-3"><?= et('Estado') ?></div>
                <div class="seg">
                    <input type="radio" class="btn-check" name="estado" id="estPag" value="pagada" checked>
                    <label for="estPag"><i class="bi bi-check-circle"></i> <?= et('Pagada') ?></label>
                    <input type="radio" class="btn-check" name="estado" id="estPen" value="pendiente">
                    <label for="estPen"><i class="bi bi-hourglass-split"></i> <?= et('Pendiente') ?></label>
                </div>

                <div id="metodoBox">
                    <div class="form-label mt-3"><?= et('Método de pago') ?></div>
                    <?php /* Opciones fijas: el texto libre ("efe", "Tarj."…) desordenaba el corte de caja. */ ?>
                    <div class="metodos">
                        <?php foreach ($metodosPago as $mi => [$mv, $mic]): ?>
                        <input type="radio" class="btn-check" name="metodo_pago" id="mp<?= $mi ?>" value="<?= e($mv) ?>" <?= $mi === 0 ? 'checked' : '' ?>>
                        <label for="mp<?= $mi ?>"><i class="bi <?= $mic ?>"></i><span><?= et($mv) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg w-100 mt-4"><i class="bi bi-check-lg"></i> <?= et('Guardar factura') ?></button>
                <a href="<?= BASE_URL ?>/facturacion/index" class="btn btn-link w-100 mt-1 text-muted"><?= et('Cancelar') ?></a>
            </div>
        </aside>
    </div>
</form>

<style>
.cobro-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1.25rem; align-items: start; }
@media (max-width: 991.98px) { .cobro-grid { grid-template-columns: 1fr; } }
.cobro-side { position: sticky; top: 90px; }
.pf-card, .pf-sec { background: var(--bs-body-bg); border-radius: 18px; padding: 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 10px 28px rgba(15,30,60,.06); }
body.ui-glass .pf-card, body.ui-glass .pf-sec { background: var(--g-glass-2, #fff); box-shadow: var(--g-shadow-sm); }
.pf-sec + .pf-sec { margin-top: 1rem; }
.pf-sec-h { display: flex; align-items: center; gap: .55rem; font-weight: 650; margin-bottom: 1rem; }
.pf-sec-h .bi { width: 34px; height: 34px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, transparent); }
.pf-opc { margin-left: auto; font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; opacity: .55; border: 1px solid rgba(127,127,127,.3); border-radius: 999px; padding: .15rem .55rem; }
.cobro-cat { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; margin-bottom: 1rem; }
.cobro-chip { border: 1px solid rgba(127,127,127,.25); background: transparent; border-radius: 999px; padding: .32rem .75rem; font-size: .82rem; color: inherit; transition: all .15s ease; }
.cobro-chip:hover { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 8%, transparent); }
.cobro-chip b { color: var(--brand); font-weight: 700; }
.cobro-tot-lbl { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .6; }
.cobro-tot { font-size: 2.3rem; font-weight: 800; letter-spacing: -.04em; line-height: 1.05; color: var(--brand); font-variant-numeric: tabular-nums; }
.seg { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; border-radius: 999px; background: rgba(127,127,127,.12); }
.seg label { text-align: center; padding: .45rem .5rem; border-radius: 999px; font-weight: 600; font-size: .86rem; cursor: pointer; margin: 0; }
.seg .btn-check:checked + label { background: var(--bs-body-bg); box-shadow: 0 2px 8px rgba(0,0,0,.12); color: var(--brand); }
.metodos { display: grid; grid-template-columns: 1fr 1fr; gap: .45rem; }
.metodos label { display: flex; flex-direction: column; align-items: center; gap: .2rem; padding: .6rem .3rem; border-radius: 14px; cursor: pointer; margin: 0;
                 border: 1px solid rgba(127,127,127,.22); font-size: .8rem; font-weight: 600; transition: all .15s ease; }
.metodos label .bi { font-size: 1.25rem; color: var(--brand); }
.metodos .btn-check:checked + label { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, transparent); box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 25%, transparent); }
</style>

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
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>`;
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
/* Atajo del catálogo: usa el primer renglón vacío o agrega uno nuevo. */
document.querySelectorAll('.cobro-chip').forEach(ch => ch.addEventListener('click', () => {
    let tr = [...document.querySelectorAll('#tablaItems tbody tr')].find(r => !r.querySelector('input[name$="[descripcion]"]').value.trim());
    if (!tr) { document.getElementById('agregarItem').click(); tr = document.querySelector('#tablaItems tbody tr:last-child'); }
    tr.querySelector('input[name$="[descripcion]"]').value = ch.dataset.desc;
    tr.querySelector('.precio').value = ch.dataset.precio;
    recalc();
}));
/* El método de pago solo aplica a lo que ya se pagó. */
function metodoVis() {
    const pag = document.getElementById('estPag').checked;
    document.getElementById('metodoBox').style.display = pag ? '' : 'none';
    document.querySelectorAll('input[name="metodo_pago"]').forEach(r => r.disabled = !pag);   // pendiente: sin método
}
document.querySelectorAll('input[name="estado"]').forEach(r => r.addEventListener('change', metodoVis));
metodoVis();
recalc();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
