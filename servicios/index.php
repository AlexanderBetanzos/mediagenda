<?php
/**
 * Catálogo de servicios / procedimientos: la lista de precios del consultorio.
 * Es la base de los presupuestos (y de la duración por defecto de las citas).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('presupuestos');

$categoria = trim((string) ($_GET['categoria'] ?? ''));
$ver       = $_GET['ver'] ?? 'activos';

$sql    = 'SELECT * FROM servicios WHERE consultorio_id = ?';
$params = [tenant_id()];
if ($ver === 'activos')        { $sql .= ' AND activo = 1'; }
elseif ($ver === 'inactivos')  { $sql .= ' AND activo = 0'; }
if ($categoria !== '') { $sql .= ' AND categoria = ?'; $params[] = $categoria; }
$sql .= ' ORDER BY categoria IS NULL, categoria, nombre';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$servicios = $stmt->fetchAll();

$cats = db()->prepare(
    'SELECT DISTINCT categoria FROM servicios
     WHERE consultorio_id = ? AND categoria IS NOT NULL AND categoria <> "" ORDER BY categoria'
);
$cats->execute([tenant_id()]);
$cats = $cats->fetchAll(PDO::FETCH_COLUMN);

$puedeEditar = has_role('admin');

$titulo = t('Catálogo de servicios');
$activo = 'servicios';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-tags text-brand"></i> <?= et('Catálogo de servicios') ?></h1>
    <?php if ($puedeEditar): ?>
    <a href="<?= BASE_URL ?>/servicios/servicio" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nuevo servicio') ?></a>
    <?php endif; ?>
</div>

<?php if (!$servicios && $categoria === '' && $ver === 'activos'): ?>
<div class="card"><div class="card-body text-center py-5">
    <i class="bi bi-tags" style="font-size:2.5rem;opacity:.4"></i>
    <p class="mt-3 mb-1 fw-semibold"><?= et('Aún no tienes servicios en el catálogo.') ?></p>
    <p class="text-muted"><?= et('Da de alta tus procedimientos con su precio para poder armar presupuestos.') ?></p>
    <?php if ($puedeEditar): ?>
    <a href="<?= BASE_URL ?>/servicios/servicio" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nuevo servicio') ?></a>
    <?php endif; ?>
</div></div>
<?php else: ?>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-4">
        <select name="categoria" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value=""><?= et('Todas las categorías') ?></option>
            <?php foreach ($cats as $c): ?>
            <option value="<?= e($c) ?>" <?= $categoria === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-auto">
        <div class="btn-group btn-group-sm">
            <?php foreach (['activos' => 'Activos', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $val => $lbl): ?>
            <a href="?ver=<?= $val ?>&categoria=<?= urlencode($categoria) ?>"
               class="btn <?= $ver === $val ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et($lbl) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th><?= et('Servicio') ?></th>
                <th><?= et('Categoría') ?></th>
                <th><?= et('Código') ?></th>
                <th class="text-end"><?= et('Precio') ?></th>
                <th class="text-end"><?= et('Duración') ?></th>
                <?php if ($puedeEditar): ?><th class="text-end"><?= et('Acciones') ?></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($servicios as $s): ?>
                <tr class="<?= $s['activo'] ? '' : 'opacity-50' ?>">
                    <td>
                        <span class="fw-semibold"><?= e($s['nombre']) ?></span>
                        <?php if ($s['aplica_diente']): ?>
                            <span class="badge bg-info-subtle text-info-emphasis ms-1" title="<?= et('Se cotiza por pieza dental') ?>">
                                <i class="bi bi-emoji-smile"></i> <?= et('Por diente') ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!$s['activo']): ?><span class="badge bg-secondary ms-1"><?= et('Inactivo') ?></span><?php endif; ?>
                    </td>
                    <td class="text-muted"><?= $s['categoria'] ? e($s['categoria']) : '—' ?></td>
                    <td class="text-muted"><?= $s['codigo'] ? e($s['codigo']) : '—' ?></td>
                    <td class="text-end fw-semibold"><?= fmt_money($s['precio']) ?></td>
                    <td class="text-end text-muted"><?= (int) $s['duracion_min'] ?> min</td>
                    <?php if ($puedeEditar): ?>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/servicios/servicio?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= et('Editar') ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="<?= BASE_URL ?>/servicios/toggle" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button class="btn btn-sm btn-outline-<?= $s['activo'] ? 'warning' : 'success' ?>"
                                    title="<?= $s['activo'] ? et('Desactivar') : et('Activar') ?>">
                                <i class="bi bi-<?= $s['activo'] ? 'pause' : 'play' ?>"></i>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$servicios): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin resultados con este filtro.') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
