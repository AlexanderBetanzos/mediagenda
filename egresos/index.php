<?php
/**
 * Egresos e ingresos — registra gastos del consultorio para calcular la
 * utilidad real del mes (ingresos de facturas pagadas − egresos).
 * Solo administradores. La tabla se crea sola la primera vez.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('facturacion');
require_role('admin');

$pdo = db();
$tid = (int) tenant_id();

/* Crea la tabla de egresos la primera vez (sin migración manual). */
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

$CATEGORIAS = ['Renta', 'Insumos y material', 'Sueldos', 'Servicios (luz, agua, internet)', 'Equipo', 'Marketing', 'Impuestos', 'Otro'];

/* Alta de egreso. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $fecha     = $_POST['fecha'] ?: date('Y-m-d');
    $categoria = trim($_POST['categoria'] ?? '');
    $concepto  = trim($_POST['concepto'] ?? '');
    $monto     = (float) ($_POST['monto'] ?? 0);
    $metodo    = trim($_POST['metodo_pago'] ?? '');

    if ($concepto === '' || $monto <= 0) {
        flash('Escribe un concepto y un monto mayor a cero.', 'warning');
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO egresos (consultorio_id, fecha, categoria, concepto, monto, metodo_pago, usuario_id)
             VALUES (?,?,?,?,?,?,?)'
        );
        $ins->execute([$tid, $fecha, $categoria ?: null, $concepto, $monto, $metodo ?: null, current_user()['id']]);
        auditar('crear', 'egreso', (int) $pdo->lastInsertId(), $concepto . ' · ' . fmt_money($monto));
        flash('Egreso registrado.');
    }
    redirect('/egresos/index?mes=' . substr($_POST['fecha'] ?: date('Y-m-d'), 0, 7));
}

/* Mes en curso (por defecto el actual). */
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');
$ini = $mes . '-01';
$fin = date('Y-m-t', strtotime($ini));

/* Egresos del mes. */
$eg = $pdo->prepare("SELECT * FROM egresos WHERE consultorio_id = ? AND fecha BETWEEN ? AND ? ORDER BY fecha DESC, id DESC");
$eg->execute([$tid, $ini, $fin]);
$egresos = $eg->fetchAll();
$totalEgresos = 0.0;
foreach ($egresos as $r) $totalEgresos += (float) $r['monto'];

/* Ingresos del mes (facturas pagadas). */
$in = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM facturas WHERE consultorio_id = ? AND estado='pagada' AND fecha BETWEEN ? AND ?");
$in->execute([$tid, $ini, $fin]);
$totalIngresos = (float) $in->fetchColumn();
$utilidad = $totalIngresos - $totalEgresos;

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
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-plus-circle text-brand"></i> <?= et('Registrar egreso') ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="mb-2">
                        <label class="form-label"><?= et('Fecha') ?></label>
                        <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><?= et('Categoría') ?></label>
                        <select name="categoria" class="form-select">
                            <?php foreach ($CATEGORIAS as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label"><?= et('Concepto') ?></label>
                        <input type="text" name="concepto" class="form-control" maxlength="200" placeholder="<?= e(t('Ej. Pago de renta')) ?>" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-2">
                            <label class="form-label"><?= et('Monto') ?></label>
                            <input type="number" name="monto" step="0.01" min="0.01" class="form-control" required>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label"><?= et('Método') ?></label>
                            <select name="metodo_pago" class="form-select">
                                <option value="Efectivo"><?= et('Efectivo') ?></option>
                                <option value="Tarjeta"><?= et('Tarjeta') ?></option>
                                <option value="Transferencia"><?= et('Transferencia') ?></option>
                                <option value="Otro"><?= et('Otro') ?></option>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100 mt-2"><i class="bi bi-save"></i> <?= et('Guardar egreso') ?></button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-list-ul text-brand"></i> <?= et('Egresos del mes') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Categoría') ?></th><th><?= et('Concepto') ?></th><th><?= et('Método') ?></th><th class="text-end"><?= et('Monto') ?></th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$egresos): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4"><?= et('Sin egresos este mes.') ?></td></tr>
                    <?php else: foreach ($egresos as $r): ?>
                        <tr>
                            <td class="small text-muted"><?= fmt_fecha($r['fecha']) ?></td>
                            <td class="small"><?= e($r['categoria'] ?: '—') ?></td>
                            <td class="small fw-semibold"><?= e($r['concepto']) ?></td>
                            <td><span class="badge bg-secondary bg-opacity-50 text-capitalize"><?= e($r['metodo_pago'] ?: '—') ?></span></td>
                            <td class="text-end fw-bold" style="color:#ef4444"><?= fmt_money($r['monto']) ?></td>
                            <td class="text-end">
                                <form method="post" action="<?= BASE_URL ?>/egresos/delete" onsubmit="return confirm('<?= e(t('¿Eliminar este egreso?')) ?>')" class="m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="mes" value="<?= e($mes) ?>">
                                    <button class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Eliminar')) ?>"><i class="bi bi-trash"></i></button>
                                </form>
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
