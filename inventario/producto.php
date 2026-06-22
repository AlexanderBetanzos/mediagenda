<?php
/** Alta / edición de producto del inventario. */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('farmacia');
if (!has_role('admin', 'recepcion')) { http_response_code(403); die('Sin permiso.'); }

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$p  = ['nombre' => '', 'sku' => '', 'categoria' => '', 'unidad' => 'pieza', 'precio' => '', 'stock_minimo' => '0'];

if ($id) {
    $st = db()->prepare('SELECT * FROM productos WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $p = $st->fetch();
    if (!$p) { http_response_code(404); die('Producto no encontrado.'); }
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $p = array_merge($p, $_POST);
    if (trim($p['nombre']) === '') $errores[] = t('El nombre es obligatorio.');

    if (!$errores) {
        $vals = [
            trim($p['nombre']),
            trim($p['sku'] ?? '') ?: null,
            trim($p['categoria'] ?? '') ?: null,
            trim($p['unidad'] ?? '') ?: 'pieza',
            (float) ($p['precio'] ?: 0),
            (int) ($p['stock_minimo'] ?: 0),
        ];
        if ($id) {
            db()->prepare('UPDATE productos SET nombre=?, sku=?, categoria=?, unidad=?, precio=?, stock_minimo=? WHERE id=? AND consultorio_id=?')
                ->execute(array_merge($vals, [$id, tenant_id()]));
            auditar('editar', 'producto', $id, trim($p['nombre']));
            flash('Producto actualizado.');
        } else {
            db()->prepare('INSERT INTO productos (consultorio_id, nombre, sku, categoria, unidad, precio, stock_minimo) VALUES (?,?,?,?,?,?,?)')
                ->execute(array_merge([tenant_id()], $vals));
            $id = (int) db()->lastInsertId();
            auditar('crear', 'producto', $id, trim($p['nombre']));
            flash('Producto creado. Registra una entrada para darle stock.');
        }
        redirect('/inventario/index');
    }
}

$titulo = $id ? t('Editar producto') : t('Nuevo producto');
$activo = 'inventario';
include __DIR__ . '/../includes/header.php';
$v = fn($k) => e($p[$k] ?? '');
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/inventario/index"><?= et('Inventario') ?></a></li>
    <li class="breadcrumb-item active"><?= $id ? et('Editar') : et('Nuevo') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><?= $id ? et('Editar producto') : et('Nuevo producto') ?></h1>

<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div><?php endif; ?>

<form method="post" class="card" style="max-width:720px">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-md-8"><label class="form-label"><?= et('Nombre') ?> *</label><input type="text" name="nombre" class="form-control" required value="<?= $v('nombre') ?>"></div>
        <div class="col-md-4"><label class="form-label"><?= et('Código / SKU') ?></label><input type="text" name="sku" class="form-control" value="<?= $v('sku') ?>"></div>
        <div class="col-md-4"><label class="form-label"><?= et('Categoría') ?></label><input type="text" name="categoria" class="form-control" value="<?= $v('categoria') ?>" placeholder="Medicamento, insumo…"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Unidad') ?></label><input type="text" name="unidad" class="form-control" value="<?= $v('unidad') ?>" placeholder="pieza, caja, ml"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Precio de venta') ?></label><input type="number" step="0.01" min="0" name="precio" class="form-control" value="<?= $v('precio') ?>"></div>
        <div class="col-md-2"><label class="form-label"><?= et('Stock mínimo') ?></label><input type="number" min="0" name="stock_minimo" class="form-control" value="<?= $v('stock_minimo') ?>"></div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/inventario/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
