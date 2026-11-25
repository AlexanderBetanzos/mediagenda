<?php
/**
 * Registra una entrada, salida o traspaso de inventario (includes/inventario.php).
 *  - Entrada: suma a un lote de la sucursal (lo crea si no existe).
 *  - Salida: descuenta por FEFO (primero el lote que caduca antes).
 *  - Traspaso: pasa piezas de una sucursal a otra con su lote y caducidad.
 * Toda la operación va en una transacción y deja un movimiento en la bitácora.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventario.php';
require_login();
require_modulo('farmacia');
if (!has_role('admin', 'recepcion', 'medico')) { http_response_code(403); die('Sin permiso.'); }
inv_asegurar();

$tid  = tenant_id();
$tipo = $_GET['tipo'] ?? $_POST['tipo'] ?? 'entrada';
$tipo = in_array($tipo, ['entrada', 'salida', 'traspaso'], true) ? $tipo : 'entrada';
$preProducto = (int) ($_GET['producto'] ?? 0);

$porSuc = inv_por_sucursal();
$sucs   = $porSuc ? sucursales() : [];
if ($tipo === 'traspaso' && count($sucs) < 2) redirect('/inventario/index');
$sucDef = $porSuc ? (sucursal_actual() ?: (int) $sucs[0]['id']) : 0;

$productos = db()->prepare('SELECT id, nombre, unidad FROM productos WHERE consultorio_id = ? AND activo = 1 ORDER BY nombre');
$productos->execute([$tid]);
$productos = $productos->fetchAll();

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pid      = (int) ($_POST['producto_id'] ?? 0);
    $cantidad = (int) ($_POST['cantidad'] ?? 0);
    $motivo   = trim($_POST['motivo'] ?? '') ?: null;
    $suc      = $porSuc ? inv_sucursal_valida($_POST['sucursal_id'] ?? 0) : null;
    $destino  = $porSuc ? inv_sucursal_valida($_POST['destino_id'] ?? 0) : null;

    if (!$pid || !pertenece_al_tenant('productos', $pid)) $errores[] = t('Selecciona un producto válido.');
    if ($cantidad <= 0) $errores[] = t('La cantidad debe ser mayor a cero.');
    if ($porSuc && !$suc) $errores[] = t('Elige la sucursal.');
    if ($tipo === 'traspaso' && (!$destino || $destino === $suc)) $errores[] = t('Elige una sucursal de destino distinta al origen.');

    if (!$errores) {
        try {
            db()->beginTransaction();
            if ($tipo === 'entrada') {
                $costo = ($_POST['costo'] ?? '') !== '' ? (float) $_POST['costo'] : null;
                inv_entrada($pid, $cantidad, $suc, trim($_POST['lote'] ?? '') ?: null, ($_POST['caducidad'] ?? '') ?: null,
                            $motivo, trim($_POST['proveedor'] ?? '') ?: null, $costo);
            } elseif ($tipo === 'salida') {
                inv_salida($pid, $cantidad, $suc, $motivo);
            } else {
                inv_traspaso($pid, $cantidad, $suc, $destino, $motivo);
            }
            db()->commit();
            $donde = $porSuc ? ' · ' . inv_sucursal_nombre($suc) . ($tipo === 'traspaso' ? ' → ' . inv_sucursal_nombre($destino) : '') : '';
            auditar($tipo, 'producto', $pid, ($tipo === 'entrada' ? '+' : ($tipo === 'salida' ? '-' : '')) . $cantidad . $donde);
            flash(($tipo === 'entrada' ? t('Entrada registrada') : ($tipo === 'salida' ? t('Salida registrada') : t('Traspaso registrado')))
                  . ' (' . ($tipo === 'entrada' ? '+' : ($tipo === 'salida' ? '-' : '')) . $cantidad . ')' . $donde . '.');
            redirect('/inventario/index');
        } catch (RuntimeException $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errores[] = $e->getMessage();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('inventario/movimiento: ' . $e->getMessage());
            $errores[] = t('No se pudo registrar el movimiento.');
        }
    }
}

$titulo = ['entrada' => t('Entrada de inventario'), 'salida' => t('Salida de inventario'), 'traspaso' => t('Traspaso entre sucursales')][$tipo];
$activo = 'inventario';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/inventario/index"><?= et('Inventario') ?></a></li>
    <li class="breadcrumb-item active"><?= e($titulo) ?></li>
</ol></nav>
<h1 class="h3 mb-3">
    <i class="bi <?= ['entrada' => 'bi-box-arrow-in-down text-success', 'salida' => 'bi-box-arrow-up text-secondary', 'traspaso' => 'bi-arrow-left-right text-primary'][$tipo] ?>"></i> <?= e($titulo) ?>
</h1>

<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div><?php endif; ?>

<form method="post" class="card" style="max-width:680px">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="tipo" value="<?= $tipo ?>">
        <div class="col-md-8">
            <label class="form-label"><?= et('Producto') ?> *</label>
            <select name="producto_id" class="form-select" required>
                <option value=""><?= et('— Selecciona —') ?></option>
                <?php foreach ($productos as $pr): ?>
                    <option value="<?= $pr['id'] ?>" <?= $preProducto === (int) $pr['id'] ? 'selected' : '' ?>><?= e($pr['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4"><label class="form-label"><?= et('Cantidad') ?> *</label><input type="number" name="cantidad" class="form-control" min="1" required value="<?= e($_POST['cantidad'] ?? '') ?>"></div>

        <?php if ($porSuc): $selSuc = (int) ($_POST['sucursal_id'] ?? $sucDef); ?>
        <div class="col-md-6">
            <label class="form-label"><?= $tipo === 'traspaso' ? et('Sale de') : et('Sucursal') ?> *</label>
            <select name="sucursal_id" class="form-select" required>
                <?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $selSuc === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if ($tipo === 'traspaso'): $selDest = (int) ($_POST['destino_id'] ?? 0); ?>
        <div class="col-md-6">
            <label class="form-label"><?= et('Llega a') ?> *</label>
            <select name="destino_id" class="form-select" required>
                <option value=""><?= et('— Selecciona —') ?></option>
                <?php foreach ($sucs as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $selDest === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($tipo === 'entrada'): ?>
        <div class="col-md-5"><label class="form-label"><?= et('Lote') ?></label><input type="text" name="lote" class="form-control" value="<?= e($_POST['lote'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Caducidad') ?></label><input type="date" name="caducidad" class="form-control" value="<?= e($_POST['caducidad'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label"><?= et('Costo unitario') ?></label><input type="number" step="0.01" min="0" name="costo" class="form-control" value="<?= e($_POST['costo'] ?? '') ?>"></div>
        <div class="col-md-8"><label class="form-label"><?= et('Proveedor') ?></label><input type="text" name="proveedor" class="form-control" value="<?= e($_POST['proveedor'] ?? '') ?>"></div>
        <?php endif; ?>

        <div class="col-12"><label class="form-label"><?= et('Motivo / nota') ?></label><input type="text" name="motivo" class="form-control" maxlength="160" value="<?= e($_POST['motivo'] ?? '') ?>" placeholder="<?= ['entrada' => et('Compra, donación…'), 'salida' => et('Uso en consulta, venta, merma…'), 'traspaso' => et('Reabasto de la sucursal…')][$tipo] ?>"></div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/inventario/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-<?= ['entrada' => 'success', 'salida' => 'secondary', 'traspaso' => 'primary'][$tipo] ?>"><i class="bi bi-check-lg"></i> <?= et('Registrar') ?> <?= ['entrada' => et('Entrada'), 'salida' => et('Salida'), 'traspaso' => et('Traspaso')][$tipo] ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
