<?php
/** Alta / edición de un servicio del catálogo. */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('presupuestos');
require_role('admin');
asegurar_servicios_publicos();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$s  = ['nombre' => '', 'codigo' => '', 'categoria' => '', 'precio' => '',
       'duracion_min' => '30', 'aplica_diente' => 0, 'descripcion' => '', 'foto' => '', 'publico' => 1, 'mostrar_precio' => 1];

if ($id) {
    $st = db()->prepare('SELECT * FROM servicios WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $s = $st->fetch();
    if (!$s) { http_response_code(404); die('Servicio no encontrado.'); }
}

$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fotoPrev = $s['foto'] ?? '';
    $s = array_merge($s, $_POST);
    $s['foto'] = $fotoPrev;
    if (trim($s['nombre']) === '') $errores[] = t('El nombre es obligatorio.');
    if ((float) ($s['precio'] ?? 0) < 0) $errores[] = t('El precio no puede ser negativo.');
    $up = subir_imagen_marca($_FILES['foto'] ?? null, 'serv');
    if ($up['error']) $errores[] = $up['error'];
    elseif ($up['ok']) $s['foto'] = $up['url'];
    elseif (!empty($_POST['quitar_foto'])) $s['foto'] = '';

    if (!$errores) {
        $vals = [
            trim($s['nombre']),
            trim($s['codigo'] ?? '') ?: null,
            trim($s['categoria'] ?? '') ?: null,
            (float) ($s['precio'] ?: 0),
            max(5, (int) ($s['duracion_min'] ?: 30)),
            !empty($s['aplica_diente']) ? 1 : 0,
        ];
        $pub = [trim($s['descripcion'] ?? '') ?: null, $s['foto'] ?: null, !empty($_POST['publico']) ? 1 : 0, !empty($_POST['mostrar_precio']) ? 1 : 0];
        if ($id) {
            db()->prepare('UPDATE servicios SET nombre=?, codigo=?, categoria=?, precio=?, duracion_min=?, aplica_diente=?
                           WHERE id=? AND consultorio_id=?')
                ->execute(array_merge($vals, [$id, tenant_id()]));
            try { db()->prepare('UPDATE servicios SET descripcion=?, foto=?, publico=?, mostrar_precio=? WHERE id=? AND consultorio_id=?')
                ->execute(array_merge($pub, [$id, tenant_id()])); } catch (Throwable $e) {}
            auditar('editar', 'servicio', $id, trim($s['nombre']));
            flash('Servicio actualizado.');
        } else {
            db()->prepare('INSERT INTO servicios (consultorio_id, nombre, codigo, categoria, precio, duracion_min, aplica_diente)
                           VALUES (?,?,?,?,?,?,?)')
                ->execute(array_merge([tenant_id()], $vals));
            $id = (int) db()->lastInsertId();
            try { db()->prepare('UPDATE servicios SET descripcion=?, foto=?, publico=?, mostrar_precio=? WHERE id=? AND consultorio_id=?')
                ->execute(array_merge($pub, [$id, tenant_id()])); } catch (Throwable $e) {}
            auditar('crear', 'servicio', $id, trim($s['nombre']));
            flash('Servicio creado.');
        }
        redirect('/servicios/index');
    }
}

$titulo = $id ? t('Editar servicio') : t('Nuevo servicio');
$activo = 'servicios';
include __DIR__ . '/../includes/header.php';
$v = fn($k) => e((string) ($s[$k] ?? ''));
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/servicios/index"><?= et('Catálogo de servicios') ?></a></li>
    <li class="breadcrumb-item active"><?= $id ? et('Editar') : et('Nuevo') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><?= $id ? et('Editar servicio') : et('Nuevo servicio') ?></h1>

<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $err) echo '<li>'.e($err).'</li>'; ?></ul></div><?php endif; ?>

<form method="post" class="card" style="max-width:760px" enctype="multipart/form-data">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-md-8">
            <label class="form-label"><?= et('Nombre') ?> *</label>
            <input type="text" name="nombre" class="form-control" required value="<?= $v('nombre') ?>" placeholder="<?= e(giro_ejemplos('servicio')) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label"><?= et('Código') ?></label>
            <input type="text" name="codigo" class="form-control" value="<?= $v('codigo') ?>">
        </div>
        <div class="col-md-5">
            <label class="form-label"><?= et('Categoría') ?></label>
            <input type="text" name="categoria" class="form-control" value="<?= $v('categoria') ?>" placeholder="<?= e(giro_ejemplos('categoria')) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label"><?= et('Precio') ?></label>
            <input type="number" step="0.01" min="0" name="precio" class="form-control" value="<?= $v('precio') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label"><?= et('Duración (min)') ?></label>
            <input type="number" min="5" step="5" name="duracion_min" class="form-control" value="<?= $v('duracion_min') ?>">
        </div>
        <div class="col-12">
            <label class="form-label"><?= et('Descripción') ?></label>
            <textarea name="descripcion" class="form-control" rows="3" maxlength="1500" placeholder="<?= e(t('Qué incluye, para quién es, cuánto dura… Así lo verán tus pacientes en tu página.')) ?>"><?= $v('descripcion') ?></textarea>
        </div>
        <div class="col-md-7">
            <label class="form-label"><?= et('Foto') ?></label>
            <div class="d-flex align-items-center gap-3">
                <?php if (!empty($s['foto'])): ?><img src="<?= e($s['foto']) ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:12px"><?php endif; ?>
                <input type="file" name="foto" class="form-control" accept="image/png,image/jpeg,image/webp">
            </div>
            <?php if (!empty($s['foto'])): ?><div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="quitarFoto" name="quitar_foto" value="1"><label class="form-check-label small" for="quitarFoto"><?= et('Quitar foto') ?></label></div><?php endif; ?>
            <div class="form-text"><?= et('Cuadrada o horizontal, hasta 2 MB.') ?></div>
        </div>
        <div class="col-md-5">
            <label class="form-label d-flex align-items-center gap-1"><?= et('En tu página pública') ?> <?= ayuda_btn('servicio.publico') ?></label>
            <div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="publico" name="publico" value="1" <?= !empty($s['publico']) ? 'checked' : '' ?>><label class="form-check-label" for="publico"><?= et('Mostrar este servicio') ?></label></div>
            <div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="mostrarPrecio" name="mostrar_precio" value="1" <?= !empty($s['mostrar_precio']) ? 'checked' : '' ?>><label class="form-check-label" for="mostrarPrecio"><?= et('Mostrar el precio') ?></label></div>
        </div>
        <?php if (giro_usa_tipo_dental() || !empty($s['aplica_diente'])): ?>
        <div class="col-12">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="aplicaDiente" name="aplica_diente" value="1" <?= !empty($s['aplica_diente']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="aplicaDiente">
                    <?= et('Se cotiza por pieza dental') ?>
                    <span class="form-text d-block"><?= et('Al agregarlo a un presupuesto se pedirá el diente y las caras tratadas.') ?></span>
                </label>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-footer text-end">
        <a href="<?= BASE_URL ?>/servicios/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
