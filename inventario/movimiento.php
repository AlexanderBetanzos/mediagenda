<?php
/**
 * Registra una entrada o salida de inventario.
 *  - Entrada: suma a un lote (lo crea si no existe).
 *  - Salida: descuenta por FEFO (primero el lote que caduca antes).
 * Toda la operación va en una transacción y deja un movimiento en la bitácora.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('farmacia');
if (!has_role('admin', 'recepcion', 'medico')) { http_response_code(403); die('Sin permiso.'); }

$tid  = tenant_id();
$tipo = ($_GET['tipo'] ?? $_POST['tipo'] ?? 'entrada') === 'salida' ? 'salida' : 'entrada';
$preProducto = (int) ($_GET['producto'] ?? 0);

$productos = db()->prepare('SELECT id, nombre, unidad FROM productos WHERE consultorio_id = ? AND activo = 1 ORDER BY nombre');
$productos->execute([$tid]);
$productos = $productos->fetchAll();

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pid      = (int) ($_POST['producto_id'] ?? 0);
    $cantidad = (int) ($_POST['cantidad'] ?? 0);
    $motivo   = trim($_POST['motivo'] ?? '') ?: null;

    if (!$pid || !pertenece_al_tenant('productos', $pid)) $errores[] = 'Selecciona un producto válido.';
    if ($cantidad <= 0) $errores[] = 'La cantidad debe ser mayor a cero.';

    if (!$errores && $tipo === 'entrada') {
        $lote = trim($_POST['lote'] ?? '') ?: null;
        $cad  = ($_POST['caducidad'] ?? '') ?: null;
        $costo = ($_POST['costo'] ?? '') !== '' ? (float) $_POST['costo'] : null;
        $prov  = trim($_POST['proveedor'] ?? '') ?: null;
        try {
            db()->beginTransaction();
            // ¿Existe ya un lote idéntico (mismo lote y caducidad)?
            $q = db()->prepare('SELECT id FROM inventario_lotes WHERE producto_id=? AND consultorio_id=?
                                AND (lote <=> ?) AND (caducidad <=> ?) LIMIT 1');
            $q->execute([$pid, $tid, $lote, $cad]);
            if ($lid = $q->fetchColumn()) {
                db()->prepare('UPDATE inventario_lotes SET cantidad = cantidad + ? WHERE id=?')->execute([$cantidad, $lid]);
            } else {
                db()->prepare('INSERT INTO inventario_lotes (consultorio_id, producto_id, lote, caducidad, cantidad) VALUES (?,?,?,?,?)')
                    ->execute([$tid, $pid, $lote, $cad, $cantidad]);
            }
            db()->prepare('INSERT INTO inventario_movimientos (consultorio_id, producto_id, tipo, cantidad, motivo, proveedor, costo, usuario_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$tid, $pid, 'entrada', $cantidad, $motivo, $prov, $costo, current_user()['id']]);
            db()->commit();
            auditar('entrada', 'producto', $pid, "+$cantidad");
            flash("Entrada registrada (+$cantidad).");
            redirect('/inventario/index');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errores[] = 'No se pudo registrar la entrada.';
        }
    }

    if (!$errores && $tipo === 'salida') {
        // Stock disponible
        $stk = db()->prepare('SELECT COALESCE(SUM(cantidad),0) FROM inventario_lotes WHERE producto_id=? AND consultorio_id=?');
        $stk->execute([$pid, $tid]);
        $disp = (int) $stk->fetchColumn();
        if ($cantidad > $disp) {
            $errores[] = "Stock insuficiente: hay $disp disponibles.";
        } else {
            try {
                db()->beginTransaction();
                // FEFO: primero los lotes que caducan antes (NULL al final).
                $lotes = db()->prepare('SELECT id, cantidad FROM inventario_lotes
                                        WHERE producto_id=? AND consultorio_id=? AND cantidad>0
                                        ORDER BY caducidad IS NULL, caducidad ASC, id ASC');
                $lotes->execute([$pid, $tid]);
                $resta = $cantidad;
                $updL  = db()->prepare('UPDATE inventario_lotes SET cantidad = cantidad - ? WHERE id=?');
                foreach ($lotes as $l) {
                    if ($resta <= 0) break;
                    $usa = min($resta, (int) $l['cantidad']);
                    $updL->execute([$usa, $l['id']]);
                    $resta -= $usa;
                }
                db()->prepare('INSERT INTO inventario_movimientos (consultorio_id, producto_id, tipo, cantidad, motivo, usuario_id) VALUES (?,?,?,?,?,?)')
                    ->execute([$tid, $pid, 'salida', $cantidad, $motivo, current_user()['id']]);
                db()->commit();
                auditar('salida', 'producto', $pid, "-$cantidad");
                flash("Salida registrada (-$cantidad).");
                redirect('/inventario/index');
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $errores[] = 'No se pudo registrar la salida.';
            }
        }
    }
}

$titulo = $tipo === 'salida' ? 'Salida de inventario' : 'Entrada de inventario';
$activo = 'inventario';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/inventario/index">Inventario</a></li>
    <li class="breadcrumb-item active"><?= $tipo === 'salida' ? 'Salida' : 'Entrada' ?></li>
</ol></nav>
<h1 class="h3 mb-3">
    <?php if ($tipo === 'salida'): ?><i class="bi bi-box-arrow-up text-secondary"></i> Salida de inventario
    <?php else: ?><i class="bi bi-box-arrow-in-down text-success"></i> Entrada de inventario<?php endif; ?>
</h1>

<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div><?php endif; ?>

<form method="post" class="card" style="max-width:680px">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="tipo" value="<?= $tipo ?>">
        <div class="col-md-8">
            <label class="form-label">Producto *</label>
            <select name="producto_id" class="form-select" required>
                <option value="">— Selecciona —</option>
                <?php foreach ($productos as $pr): ?>
                    <option value="<?= $pr['id'] ?>" <?= $preProducto === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4"><label class="form-label">Cantidad *</label><input type="number" name="cantidad" class="form-control" min="1" required value="<?= e($_POST['cantidad'] ?? '') ?>"></div>

        <?php if ($tipo === 'entrada'): ?>
        <div class="col-md-5"><label class="form-label">Lote</label><input type="text" name="lote" class="form-control" value="<?= e($_POST['lote'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label">Caducidad</label><input type="date" name="caducidad" class="form-control" value="<?= e($_POST['caducidad'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Costo unitario</label><input type="number" step="0.01" min="0" name="costo" class="form-control" value="<?= e($_POST['costo'] ?? '') ?>"></div>
        <div class="col-md-8"><label class="form-label">Proveedor</label><input type="text" name="proveedor" class="form-control" value="<?= e($_POST['proveedor'] ?? '') ?>"></div>
        <?php endif; ?>

        <div class="col-12"><label class="form-label">Motivo / nota</label><input type="text" name="motivo" class="form-control" maxlength="160" value="<?= e($_POST['motivo'] ?? '') ?>" placeholder="<?= $tipo === 'salida' ? 'Uso en consulta, venta, merma…' : 'Compra, donación…' ?>"></div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/inventario/index" class="btn btn-light">Cancelar</a>
        <button class="btn btn-<?= $tipo === 'salida' ? 'secondary' : 'success' ?>"><i class="bi bi-check-lg"></i> Registrar <?= $tipo ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
