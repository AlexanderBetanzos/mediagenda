<?php
/**
 * Egresos e ingresos — registra gastos del consultorio para calcular la
 * utilidad real del mes (ingresos de facturas pagadas − egresos).
 * Permite CRUD de egresos y gestión de categorías propias. Solo admin.
 * Las tablas se crean solas la primera vez.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
require_role('admin');

$pdo = db();
$tid = (int) tenant_id();

/* Tablas (sin migración manual). */
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS egresos (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL DEFAULT 1,
        fecha          DATE NOT NULL,
        categoria      VARCHAR(60) DEFAULT NULL,
        concepto       VARCHAR(200) NOT NULL,
        monto          DECIMAL(10,2) NOT NULL DEFAULT 0,
        metodo_pago    VARCHAR(40) DEFAULT NULL,
        usuario_id     INT DEFAULT NULL,
        creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_egr_tenant (consultorio_id, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS egreso_categorias (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL DEFAULT 1,
        nombre         VARCHAR(60) NOT NULL,
        creado_en      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_egrcat_tenant (consultorio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

/* Siembra las categorías por defecto la primera vez. */
$hayCat = (int) $pdo->query("SELECT COUNT(*) FROM egreso_categorias WHERE consultorio_id = $tid")->fetchColumn();
if ($hayCat === 0) {
    $def = ['Renta', 'Insumos y material', 'Sueldos', 'Servicios (luz, agua, internet)', 'Equipo', 'Marketing', 'Impuestos', 'Otro'];
    $ins = $pdo->prepare("INSERT INTO egreso_categorias (consultorio_id, nombre) VALUES (?,?)");
    foreach ($def as $c) $ins->execute([$tid, $c]);
}

$mesRedir = fn($f) => '/egresos/index?mes=' . substr($f ?: date('Y-m-d'), 0, 7);

/* ── Acciones (POST) ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'guardar';

    if ($action === 'guardar') {
        $id        = (int) ($_POST['id'] ?? 0);
        $fecha     = $_POST['fecha'] ?: date('Y-m-d');
        $categoria = trim($_POST['categoria'] ?? '');
        $concepto  = trim($_POST['concepto'] ?? '');
        $monto     = (float) ($_POST['monto'] ?? 0);
        $metodo    = trim($_POST['metodo_pago'] ?? '');

        if ($concepto === '' || $monto <= 0) {
            flash('Escribe un concepto y un monto mayor a cero.', 'warning');
            redirect($mesRedir($fecha) . ($id ? '&edit=' . $id : ''));
        }
        if ($id > 0) {
            $up = $pdo->prepare('UPDATE egresos SET fecha=?, categoria=?, concepto=?, monto=?, metodo_pago=? WHERE id=? AND consultorio_id=?');
            $up->execute([$fecha, $categoria ?: null, $concepto, $monto, $metodo ?: null, $id, $tid]);
            auditar('editar', 'egreso', $id, $concepto . ' · ' . fmt_money($monto));
            flash('Egreso actualizado.');
        } else {
            $in = $pdo->prepare('INSERT INTO egresos (consultorio_id, fecha, categoria, concepto, monto, metodo_pago, usuario_id) VALUES (?,?,?,?,?,?,?)');
            $in->execute([$tid, $fecha, $categoria ?: null, $concepto, $monto, $metodo ?: null, current_user()['id']]);
            auditar('crear', 'egreso', (int) $pdo->lastInsertId(), $concepto . ' · ' . fmt_money($monto));
            flash('Egreso registrado.');
        }
        redirect($mesRedir($fecha));
    }

    if ($action === 'cat_add') {
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre !== '') {
            $ex = $pdo->prepare("SELECT 1 FROM egreso_categorias WHERE consultorio_id=? AND nombre=?");
            $ex->execute([$tid, $nombre]);
            if (!$ex->fetchColumn()) {
                $pdo->prepare("INSERT INTO egreso_categorias (consultorio_id, nombre) VALUES (?,?)")->execute([$tid, $nombre]);
                flash('Categoría agregada.');
            } else {
                flash('Esa categoría ya existe.', 'warning');
            }
        }
        redirect('/egresos/index?mes=' . ($_POST['mes'] ?? date('Y-m')));
    }

    if ($action === 'cat_rename') {
        $cid = (int) ($_POST['cat_id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        if ($cid > 0 && $nombre !== '') {
            $pdo->prepare("UPDATE egreso_categorias SET nombre=? WHERE id=? AND consultorio_id=?")->execute([$nombre, $cid, $tid]);
            flash('Categoría actualizada.');
        }
        redirect('/egresos/index?mes=' . ($_POST['mes'] ?? date('Y-m')));
    }

    if ($action === 'cat_del') {
        $cid = (int) ($_POST['cat_id'] ?? 0);
        if ($cid > 0) {
            $pdo->prepare("DELETE FROM egreso_categorias WHERE id=? AND consultorio_id=?")->execute([$cid, $tid]);
            flash('Categoría eliminada. (Los egresos ya registrados conservan su categoría.)');
        }
        redirect('/egresos/index?mes=' . ($_POST['mes'] ?? date('Y-m')));
    }
}

/* ── Datos para la vista ────────────────────────────────────────────── */
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$ini = $mes . '-01';
$fin = date('Y-m-t', strtotime($ini));

/* Categorías (para el <select> y el gestor). */
$catRows = $pdo->prepare("SELECT id, nombre FROM egreso_categorias WHERE consultorio_id=? ORDER BY nombre");
$catRows->execute([$tid]);
$catRows = $catRows->fetchAll();
$cats = array_column($catRows, 'nombre');

/* Egreso en edición (?edit=id). */
$editing = null;
if (!empty($_GET['edit'])) {
    $ed = $pdo->prepare("SELECT * FROM egresos WHERE id=? AND consultorio_id=?");
    $ed->execute([(int) $_GET['edit'], $tid]);
    $editing = $ed->fetch() ?: null;
}
/* Si la categoría del egreso ya no está en el catálogo, la agregamos como opción. */
$catsSelect = $cats;
if ($editing && $editing['categoria'] && !in_array($editing['categoria'], $catsSelect, true)) {
    array_unshift($catsSelect, $editing['categoria']);
}

/* Egresos del mes. */
$eg = $pdo->prepare("SELECT * FROM egresos WHERE consultorio_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC, id DESC");
$eg->execute([$tid, $ini, $fin]);
$egresos = $eg->fetchAll();
$totalEgresos = 0.0;
foreach ($egresos as $r) $totalEgresos += (float) $r['monto'];

/* Ingresos del mes (facturas pagadas). */
$in = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id=? AND estado='pagada' AND fecha BETWEEN ? AND ?");
$in->execute([$tid, $ini, $fin]);
$totalIngresos = (float) $in->fetchColumn();
$utilidad = $totalIngresos - $totalEgresos;

$METODOS = ['Efectivo', 'Tarjeta', 'Transferencia', 'Otro'];

$titulo = t('Egresos e ingresos');
$activo = 'egresos';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-arrow-left-right text-brand"></i> <?= et('Egresos e ingresos') ?></h1>
    <form class="d-flex align-items-center gap-2" method="get">
        <input type="month" name="mes" value="<?= e($mes) ?>" class="form-control form-control-sm" style="width:auto" onchange="this.form.submit()">
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Ingresos del mes') ?></div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= fmt_money($totalIngresos) ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Egresos del mes') ?></div>
        <div class="stat-num mt-2" style="color:#ef4444"><?= fmt_money($totalEgresos) ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label"><?= et('Utilidad') ?></div>
        <div class="stat-num mt-2" style="color:<?= $utilidad >= 0 ? '#22c55e' : '#ef4444' ?>"><?= fmt_money($utilidad) ?></div>
    </div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <!-- Formulario de alta / edición -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">
                <?php if ($editing): ?><i class="bi bi-pencil-square text-brand"></i> <?= et('Editar egreso') ?>
                <?php else: ?><i class="bi bi-plus-circle text-brand"></i> <?= et('Registrar egreso') ?><?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="guardar">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label"><?= et('Fecha') ?></label>
                        <input type="date" name="fecha" value="<?= e($editing['fecha'] ?? date('Y-m-d')) ?>" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><?= et('Categoría') ?></label>
                        <select name="categoria" class="form-select">
                            <?php foreach ($catsSelect as $c): ?>
                                <option value="<?= e($c) ?>" <?= ($editing && $editing['categoria'] === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><?= et('Concepto') ?></label>
                        <input type="text" name="concepto" class="form-control" maxlength="200" placeholder="<?= e(t('Ej. Pago de renta')) ?>" value="<?= e($editing['concepto'] ?? '') ?>" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-2">
                            <label class="form-label"><?= et('Monto') ?></label>
                            <input type="number" name="monto" step="0.01" min="0.01" class="form-control" value="<?= $editing ? e($editing['monto']) : '' ?>" required>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label"><?= et('Método') ?></label>
                            <select name="metodo_pago" class="form-select">
                                <?php foreach ($METODOS as $m): ?>
                                    <option value="<?= e($m) ?>" <?= ($editing && $editing['metodo_pago'] === $m) ? 'selected' : '' ?>><?= et($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button class="btn btn-primary flex-grow-1"><i class="bi bi-save"></i> <?= $editing ? et('Actualizar egreso') : et('Guardar egreso') ?></button>
                        <?php if ($editing): ?><a href="<?= BASE_URL ?>/egresos/index?mes=<?= e($mes) ?>" class="btn btn-outline-secondary"><?= et('Cancelar') ?></a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Gestor de categorías -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-tags text-brand"></i> <?= et('Categorías') ?></div>
            <div class="card-body">
                <form method="post" class="d-flex gap-2 mb-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cat_add">
                    <input type="hidden" name="mes" value="<?= e($mes) ?>">
                    <input type="text" name="nombre" class="form-control form-control-sm" maxlength="60" placeholder="<?= e(t('Nueva categoría')) ?>" required>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i></button>
                </form>
                <?php if (!$catRows): ?>
                    <p class="text-muted small mb-0"><?= et('Sin categorías.') ?></p>
                <?php else: foreach ($catRows as $c): ?>
                    <form method="post" class="d-flex gap-1 align-items-center mb-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="cat_id" value="<?= (int) $c['id'] ?>">
                        <input type="hidden" name="mes" value="<?= e($mes) ?>">
                        <input type="text" name="nombre" value="<?= e($c['nombre']) ?>" maxlength="60" class="form-control form-control-sm">
                        <button name="action" value="cat_rename" class="btn btn-sm btn-outline-secondary py-1" title="<?= e(t('Guardar')) ?>"><i class="bi bi-check-lg"></i></button>
                        <button name="action" value="cat_del" class="btn btn-sm btn-outline-secondary py-1" title="<?= e(t('Eliminar')) ?>" onclick="return confirm('<?= e(t('¿Eliminar esta categoría?')) ?>')"><i class="bi bi-trash"></i></button>
                    </form>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-list-ul text-brand"></i> <?= et('Egresos del mes') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Categoría') ?></th><th><?= et('Concepto') ?></th><th><?= et('Método') ?></th><th class="text-end"><?= et('Monto') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
                    <tbody>
                    <?php if (!$egresos): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin egresos este mes.') ?></td></tr>
                    <?php else: foreach ($egresos as $r): ?>
                        <tr <?= ($editing && (int) $editing['id'] === (int) $r['id']) ? 'class="table-active"' : '' ?>>
                            <td class="small text-muted"><?= fmt_fecha($r['fecha']) ?></td>
                            <td class="small"><?= e($r['categoria'] ?: '—') ?></td>
                            <td class="small fw-semibold"><?= e($r['concepto']) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-50 text-capitalize"><?= e($r['metodo_pago'] ?: '—') ?></span></td>
                            <td class="text-end fw-bold" style="color:#ef4444"><?= fmt_money($r['monto']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= BASE_URL ?>/egresos/index?mes=<?= e($mes) ?>&edit=<?= (int) $r['id'] ?>" class="btn btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                                    <form method="post" action="<?= BASE_URL ?>/egresos/delete" onsubmit="return confirm('<?= e(t('¿Eliminar este egreso?')) ?>')" class="m-0">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="mes" value="<?= e($mes) ?>">
                                        <button class="btn btn-outline-secondary py-0" title="<?= e(t('Eliminar')) ?>"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
