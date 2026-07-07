<?php
/**
 * Egresos e ingresos — réplica del módulo de GymOS, adaptado a MediAgenda.
 * Registra gastos por categoría y los compara contra los ingresos (facturas
 * pagadas) del mismo rango: ingresos − egresos = balance. Categorías propias
 * con activar/desactivar. Solo administradores. Las tablas se crean solas.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
require_role('admin');

$pdo = db();
$tid = (int) tenant_id();

/* Tablas (sin migración manual). */
$pdo->exec("CREATE TABLE IF NOT EXISTS egresos (id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, fecha DATE NOT NULL, categoria VARCHAR(60) DEFAULT NULL, concepto VARCHAR(200) NOT NULL, monto DECIMAL(10,2) NOT NULL DEFAULT 0, metodo_pago VARCHAR(40) DEFAULT NULL, usuario_id INT DEFAULT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_egr_tenant (consultorio_id, fecha)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS egreso_categorias (id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, nombre VARCHAR(60) NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_egrcat_tenant (consultorio_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $pdo->exec("ALTER TABLE egreso_categorias ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}

/* Siembra categorías por defecto la primera vez. */
if ((int) $pdo->query("SELECT COUNT(*) FROM egreso_categorias WHERE consultorio_id = $tid")->fetchColumn() === 0) {
    $ins = $pdo->prepare("INSERT INTO egreso_categorias (consultorio_id, nombre) VALUES (?,?)");
    foreach (['Renta', 'Insumos y material', 'Sueldos', 'Servicios (luz, agua, internet)', 'Equipo', 'Marketing', 'Impuestos', 'Otro'] as $c) $ins->execute([$tid, $c]);
}

$METODOS = ['Efectivo', 'Tarjeta', 'Transferencia', 'Otro'];

/* ── Acciones (POST) ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'guardar';

    if ($action === 'guardar') {
        $eid       = (int) ($_POST['id'] ?? 0);
        $fecha     = $_POST['fecha'] ?: date('Y-m-d');
        $categoria = trim($_POST['categoria'] ?? '');
        $concepto  = trim($_POST['concepto'] ?? '');
        $monto     = (float) ($_POST['monto'] ?? 0);
        $metodo    = trim($_POST['metodo_pago'] ?? '');
        if ($concepto === '' || $monto <= 0) {
            flash('Escribe un concepto y un monto mayor a cero.', 'warning');
            redirect('/egresos/index' . ($eid ? '?edit=' . $eid : ''));
        }
        if ($eid > 0) {
            $pdo->prepare('UPDATE egresos SET fecha=?, categoria=?, concepto=?, monto=?, metodo_pago=? WHERE id=? AND consultorio_id=?')
                ->execute([$fecha, $categoria ?: null, $concepto, $monto, $metodo ?: null, $eid, $tid]);
            auditar('editar', 'egreso', $eid, $concepto . ' · ' . fmt_money($monto));
            flash('Egreso actualizado.');
        } else {
            $pdo->prepare('INSERT INTO egresos (consultorio_id, fecha, categoria, concepto, monto, metodo_pago, usuario_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$tid, $fecha, $categoria ?: null, $concepto, $monto, $metodo ?: null, current_user()['id']]);
            auditar('crear', 'egreso', (int) $pdo->lastInsertId(), $concepto . ' · ' . fmt_money($monto));
            flash('Egreso registrado.');
        }
        redirect('/egresos/index');
    }

    if ($action === 'delete') {
        $del = $pdo->prepare('DELETE FROM egresos WHERE id=? AND consultorio_id=?');
        $del->execute([(int) ($_POST['id'] ?? 0), $tid]);
        if ($del->rowCount()) { auditar('eliminar', 'egreso', (int) $_POST['id']); flash('Egreso eliminado.'); }
        redirect('/egresos/index');
    }

    // Crear / renombrar / activar categoría (un solo action, estilo GymOS)
    if ($action === 'cat_save') {
        $cid    = (int) ($_POST['cat_id'] ?? 0);
        $nombre = trim($_POST['cat_name'] ?? '');
        $activo = isset($_POST['cat_active']) ? 1 : 0;
        if ($nombre === '') {
            flash('El nombre de la categoría es obligatorio.', 'warning');
        } elseif ($cid > 0) {
            $prev = $pdo->prepare("SELECT nombre FROM egreso_categorias WHERE id=? AND consultorio_id=?");
            $prev->execute([$cid, $tid]);
            $prevNombre = $prev->fetchColumn();
            $pdo->prepare("UPDATE egreso_categorias SET nombre=?, activo=? WHERE id=? AND consultorio_id=?")->execute([$nombre, $activo, $cid, $tid]);
            // Si se renombró, propaga el nombre a los egresos que la usaban.
            if ($prevNombre !== false && $prevNombre !== $nombre) {
                $pdo->prepare("UPDATE egresos SET categoria=? WHERE consultorio_id=? AND categoria=?")->execute([$nombre, $tid, $prevNombre]);
            }
            flash('Categoría actualizada.');
        } else {
            $ex = $pdo->prepare("SELECT 1 FROM egreso_categorias WHERE consultorio_id=? AND nombre=?");
            $ex->execute([$tid, $nombre]);
            if ($ex->fetchColumn()) flash('Esa categoría ya existe.', 'warning');
            else { $pdo->prepare("INSERT INTO egreso_categorias (consultorio_id, nombre) VALUES (?,?)")->execute([$tid, $nombre]); flash('Categoría agregada.'); }
        }
        redirect('/egresos/index?' . http_build_query(array_filter(['from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'cat' => $_GET['cat'] ?? ''])));
    }

    if ($action === 'cat_del') {
        $cid = (int) ($_POST['cat_id'] ?? 0);
        $row = $pdo->prepare("SELECT nombre FROM egreso_categorias WHERE id=? AND consultorio_id=?");
        $row->execute([$cid, $tid]);
        $nombre = $row->fetchColumn();
        if ($nombre !== false) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM egresos WHERE consultorio_id=? AND categoria=?");
            $cnt->execute([$tid, $nombre]);
            if ((int) $cnt->fetchColumn() > 0) {
                flash('No se puede eliminar: hay egresos con esta categoría. Desactívala en su lugar.', 'warning');
            } else {
                $pdo->prepare("DELETE FROM egreso_categorias WHERE id=? AND consultorio_id=?")->execute([$cid, $tid]);
                flash('Categoría eliminada.');
            }
        }
        redirect('/egresos/index');
    }
}

/* ── Filtros ────────────────────────────────────────────────────────── */
$rx   = '/^\d{4}-\d{2}-\d{2}$/';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match($rx, $from)) $from = date('Y-m-01');
if (!preg_match($rx, $to))   $to   = date('Y-m-d');
if ($to < $from) { $t = $from; $from = $to; $to = $t; }
$cat = trim($_GET['cat'] ?? '');

/* Categorías (activas para el select; todas para el gestor y el filtro). */
$catAll = $pdo->prepare("SELECT id, nombre, activo FROM egreso_categorias WHERE consultorio_id=? ORDER BY activo DESC, nombre");
$catAll->execute([$tid]);
$catAll = $catAll->fetchAll();
$catActivas = array_values(array_filter(array_map(fn($c) => $c['activo'] ? $c['nombre'] : null, $catAll)));
$catNombres = array_column($catAll, 'nombre');

/* Egresos del periodo (+ filtro por categoría). */
$where = "consultorio_id=? AND fecha BETWEEN ? AND ?"; $params = [$tid, $from, $to];
if ($cat !== '' && in_array($cat, $catNombres, true)) { $where .= " AND categoria=?"; $params[] = $cat; }
$eq = $pdo->prepare("SELECT * FROM egresos WHERE $where ORDER BY fecha DESC, id DESC");
$eq->execute($params);
$egresos = $eq->fetchAll();

$totalEgresos = 0.0; $porCat = [];
foreach ($egresos as $r) { $totalEgresos += (float) $r['monto']; $k = $r['categoria'] ?: '—'; $porCat[$k] = ($porCat[$k] ?? 0) + (float) $r['monto']; }
arsort($porCat);

/* Ingresos del mismo periodo (facturas pagadas). */
$in = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id=? AND estado='pagada' AND fecha BETWEEN ? AND ?");
$in->execute([$tid, $from, $to]);
$totalIngresos = (float) $in->fetchColumn();
$balance = $totalIngresos - $totalEgresos;

/* Egreso en edición (?edit=id). */
$editing = null;
if (!empty($_GET['edit'])) {
    $ed = $pdo->prepare("SELECT * FROM egresos WHERE id=? AND consultorio_id=?");
    $ed->execute([(int) $_GET['edit'], $tid]);
    $editing = $ed->fetch() ?: null;
}
$catsSelect = $catActivas;
if ($editing && $editing['categoria'] && !in_array($editing['categoria'], $catsSelect, true)) array_unshift($catsSelect, $editing['categoria']);

$titulo = t('Egresos e ingresos');
$activo = 'egresos';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-arrow-left-right text-brand"></i> <?= et('Egresos e ingresos') ?></h1>

<!-- Filtros -->
<form method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">
    <div><label class="form-label small mb-0"><?= et('Desde') ?></label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div><label class="form-label small mb-0"><?= et('Hasta') ?></label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div><label class="form-label small mb-0"><?= et('Categoría') ?></label>
        <select name="cat" class="form-select form-select-sm">
            <option value=""><?= et('Todas') ?></option>
            <?php foreach ($catNombres as $n): ?><option value="<?= e($n) ?>" <?= $cat === $n ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> <?= et('Filtrar') ?></button>
</form>

<!-- Ingresos / Egresos / Balance -->
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="color:#22c55e"><?= fmt_money($totalIngresos) ?></div><div class="stat-label mt-1"><?= et('Ingresos del periodo') ?></div></div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="color:#ef4444"><?= fmt_money($totalEgresos) ?></div><div class="stat-label mt-1"><?= et('Egresos del periodo') ?></div></div></div></div>
    <div class="col-md-4"><div class="card stat-card h-100"><div class="card-body"><div class="stat-num" style="color:<?= $balance >= 0 ? '#22c55e' : '#ef4444' ?>"><?= fmt_money($balance) ?></div><div class="stat-label mt-1"><?= et('Balance (utilidad)') ?></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <!-- Registrar / editar egreso -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><?= $editing ? '<i class="bi bi-pencil-square text-brand"></i> ' . et('Editar egreso') : '<i class="bi bi-plus-circle text-brand"></i> ' . et('Registrar egreso') ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="guardar">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <div class="mb-2"><label class="form-label"><?= et('Monto') ?></label><input type="number" step="0.01" min="0.01" name="monto" class="form-control" value="<?= $editing ? e($editing['monto']) : '' ?>" required></div>
                    <div class="mb-2"><label class="form-label"><?= et('Categoría') ?></label>
                        <select name="categoria" class="form-select">
                            <?php foreach ($catsSelect as $n): ?><option value="<?= e($n) ?>" <?= ($editing && $editing['categoria'] === $n) ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                            <?php if (!$catsSelect): ?><option value=""><?= et('Crea una categoría abajo') ?></option><?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-2"><label class="form-label"><?= et('Concepto') ?></label><input type="text" name="concepto" class="form-control" maxlength="200" value="<?= e($editing['concepto'] ?? '') ?>" placeholder="<?= e(t('Ej. Pago de renta')) ?>" required></div>
                    <div class="row g-2">
                        <div class="col-6 mb-2"><label class="form-label"><?= et('Método') ?></label>
                            <select name="metodo_pago" class="form-select"><?php foreach ($METODOS as $m): ?><option value="<?= e($m) ?>" <?= ($editing && $editing['metodo_pago'] === $m) ? 'selected' : '' ?>><?= et($m) ?></option><?php endforeach; ?></select></div>
                        <div class="col-6 mb-2"><label class="form-label"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= e($editing['fecha'] ?? date('Y-m-d')) ?>" required></div>
                    </div>
                    <div class="d-flex gap-2 mt-1">
                        <button class="btn btn-primary flex-grow-1"><i class="bi bi-save"></i> <?= $editing ? et('Actualizar egreso') : et('Registrar egreso') ?></button>
                        <?php if ($editing): ?><a href="<?= BASE_URL ?>/egresos/index" class="btn btn-outline-secondary"><?= et('Cancelar') ?></a><?php endif; ?>
                    </div>
                </form>
                <?php if ($porCat): ?>
                    <hr>
                    <div class="stat-label mb-2"><?= et('Egresos por categoría') ?></div>
                    <?php foreach ($porCat as $k => $v): $pct = $totalEgresos > 0 ? round($v / $totalEgresos * 100) : 0; ?>
                        <div class="d-flex justify-content-between small"><span><?= e($k) ?></span><strong><?= fmt_money($v) ?></strong></div>
                        <div class="progress my-1" style="height:5px"><div class="progress-bar bg-danger" style="width:<?= $pct ?>%"></div></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Administrar categorías -->
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-tags text-brand"></i> <?= et('Categorías') ?></span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#catManager"><i class="bi bi-gear"></i> <?= et('Administrar') ?></button>
            </div>
            <div class="collapse" id="catManager"><div class="card-body">
                <form method="post" class="d-flex gap-2 mb-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="cat_save">
                    <input name="cat_name" class="form-control form-control-sm" placeholder="<?= e(t('Nueva categoría')) ?>" maxlength="60" required>
                    <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> <?= et('Agregar') ?></button>
                </form>
                <?php foreach ($catAll as $c): ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <form method="post" class="d-flex align-items-center gap-2 flex-grow-1 m-0">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cat_save"><input type="hidden" name="cat_id" value="<?= (int) $c['id'] ?>">
                            <input name="cat_name" class="form-control form-control-sm" value="<?= e($c['nombre']) ?>" maxlength="60" required>
                            <div class="form-check form-switch m-0" title="<?= e(t('Activa (aparece al registrar)')) ?>">
                                <input class="form-check-input" type="checkbox" name="cat_active" onchange="this.form.requestSubmit()" <?= $c['activo'] ? 'checked' : '' ?>>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary" title="<?= e(t('Guardar')) ?>"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta categoría?')) ?>')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cat_del"><input type="hidden" name="cat_id" value="<?= (int) $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="<?= e(t('Eliminar')) ?>"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <div class="form-text mt-2"><?= et('Desactiva una categoría para ocultarla al registrar, sin perder su historial. Solo puedes eliminar categorías sin egresos.') ?></div>
            </div></div>
        </div>
    </div>

    <!-- Lista de egresos -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul text-brand"></i> <?= et('Egresos del periodo') ?></span>
                <input type="search" id="buscarEg" class="form-control form-control-sm" style="max-width:240px" placeholder="<?= e(t('Buscar…')) ?>">
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="tblEgresos">
                    <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Categoría') ?></th><th><?= et('Concepto') ?></th><th><?= et('Método') ?></th><th class="text-end"><?= et('Monto') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($egresos as $r): ?>
                        <tr data-buscar="<?= e(mb_strtolower(($r['concepto'] ?? '') . ' ' . ($r['categoria'] ?? '') . ' ' . ($r['metodo_pago'] ?? ''))) ?>">
                            <td class="small text-muted"><?= fmt_fecha($r['fecha']) ?></td>
                            <td class="small"><?= e($r['categoria'] ?: '—') ?></td>
                            <td class="small fw-semibold"><?= e($r['concepto']) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-50 text-capitalize"><?= e($r['metodo_pago'] ?: '—') ?></span></td>
                            <td class="text-end fw-bold" style="color:#ef4444"><?= fmt_money($r['monto']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= BASE_URL ?>/egresos/index?edit=<?= (int) $r['id'] ?>" class="btn btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                                    <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar este egreso?')) ?>')">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button class="btn btn-outline-secondary py-0" title="<?= e(t('Eliminar')) ?>"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$egresos): ?><tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin egresos en el periodo.') ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('buscarEg').oninput = function () {
    var q = this.value.toLowerCase();
    document.querySelectorAll('#tblEgresos tbody tr[data-buscar]').forEach(function (tr) {
        tr.style.display = tr.dataset.buscar.indexOf(q) !== -1 ? '' : 'none';
    });
};
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
