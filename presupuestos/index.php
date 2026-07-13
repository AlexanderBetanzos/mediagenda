<?php
/**
 * Presupuestos / planes de tratamiento: listado, KPIs y filtros.
 * El saldo por cobrar solo cuenta presupuestos ya aceptados por el paciente.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('presupuestos');

$estado  = (string) ($_GET['estado'] ?? '');
$pacFil  = (int) ($_GET['paciente_id'] ?? 0);
$estados = presupuesto_estados();

$sql = "SELECT pr.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.foto AS pac_foto,
               COALESCE(pg.pagado, 0)  AS pagado,
               COALESCE(it.n, 0)       AS items_total,
               COALESCE(it.hechos, 0)  AS items_hechos
        FROM presupuestos pr
        JOIN pacientes p ON p.id = pr.paciente_id
        LEFT JOIN (SELECT presupuesto_id, SUM(monto) AS pagado
                   FROM presupuesto_pagos GROUP BY presupuesto_id) pg ON pg.presupuesto_id = pr.id
        LEFT JOIN (SELECT presupuesto_id, COUNT(*) AS n,
                          SUM(estado = 'realizado') AS hechos
                   FROM presupuesto_items GROUP BY presupuesto_id) it ON it.presupuesto_id = pr.id
        WHERE pr.consultorio_id = ?";
$params = [tenant_id()];
if (isset($estados[$estado])) { $sql .= ' AND pr.estado = ?';      $params[] = $estado; }
if ($pacFil)                  { $sql .= ' AND pr.paciente_id = ?'; $params[] = $pacFil; }
$sql .= ' ORDER BY pr.fecha DESC, pr.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$lista = $stmt->fetchAll();

// KPIs: solo lo aceptado/terminado es dinero comprometido.
$k = db()->prepare(
    "SELECT COALESCE(SUM(pr.total), 0) AS comprometido,
            COALESCE(SUM(pg.pagado), 0) AS cobrado
     FROM presupuestos pr
     LEFT JOIN (SELECT presupuesto_id, SUM(monto) AS pagado
                FROM presupuesto_pagos GROUP BY presupuesto_id) pg ON pg.presupuesto_id = pr.id
     WHERE pr.consultorio_id = ? AND pr.estado IN ('aceptado','terminado')"
);
$k->execute([tenant_id()]);
$kpi = $k->fetch();
$comprometido = (float) $kpi['comprometido'];
$cobrado      = (float) $kpi['cobrado'];
$porCobrar    = max(0, $comprometido - $cobrado);

$pp = db()->prepare("SELECT COALESCE(SUM(total),0) FROM presupuestos WHERE consultorio_id = ? AND estado = 'propuesto'");
$pp->execute([tenant_id()]);
$enPropuesta = (float) $pp->fetchColumn();

$paciente = null;
if ($pacFil) {
    $q = db()->prepare('SELECT nombre, apellidos FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $q->execute([$pacFil, tenant_id()]);
    $paciente = $q->fetch() ?: null;
}

$titulo = t('Presupuestos');
$activo = 'presupuestos';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-clipboard2-check text-brand"></i> <?= et('Presupuestos') ?></h1>
    <a href="<?= BASE_URL ?>/presupuestos/edit<?= $pacFil ? '?paciente_id=' . $pacFil : '' ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> <?= et('Nuevo presupuesto') ?>
    </a>
</div>

<?php if ($paciente): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center py-2">
    <span><i class="bi bi-person"></i> <?= et('Presupuestos de') ?> <strong><?= e($paciente['nombre'] . ' ' . $paciente['apellidos']) ?></strong></span>
    <a href="<?= BASE_URL ?>/presupuestos/index" class="btn btn-sm btn-light"><?= et('Ver todos') ?></a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Tratamiento aceptado') ?></div>
        <div class="stat-num mt-2"><?= fmt_money($comprometido) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Cobrado (abonos)') ?></div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= fmt_money($cobrado) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Por cobrar') ?></div>
        <div class="stat-num mt-2" style="color:#f59e0b"><?= fmt_money($porCobrar) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('En espera de respuesta') ?></div>
        <div class="stat-num mt-2" style="color:#38bdf8"><?= fmt_money($enPropuesta) ?></div>
    </div></div></div>
</div>

<div class="btn-group btn-group-sm mb-3">
    <?php $base = $pacFil ? '&paciente_id=' . $pacFil : ''; ?>
    <a href="?estado=<?= $base ?>" class="btn <?= $estado === '' ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et('Todos') ?></a>
    <?php foreach ($estados as $clave => [$lbl, $color]): ?>
    <a href="?estado=<?= $clave . $base ?>" class="btn <?= $estado === $clave ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et($lbl) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th><?= et('Folio') ?></th>
                <th><?= et('Fecha') ?></th>
                <th><?= et('Paciente') ?></th>
                <th><?= et('Avance') ?></th>
                <th class="text-end"><?= et('Total') ?></th>
                <th class="text-end"><?= et('Abonado') ?></th>
                <th class="text-end"><?= et('Saldo') ?></th>
                <th><?= et('Estado') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $p):
                $saldo    = (float) $p['total'] - (float) $p['pagado'];
                $cobrable = presupuesto_es_cobrable($p['estado']);
                $pct      = $p['items_total'] ? round(100 * $p['items_hechos'] / $p['items_total']) : 0;
            ?>
                <tr onclick="location='<?= BASE_URL ?>/presupuestos/ver?id=<?= $p['id'] ?>'" style="cursor:pointer">
                    <td class="fw-semibold"><?= e($p['folio']) ?></td>
                    <td class="text-muted"><?= fmt_fecha($p['fecha']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $p['paciente_id'], $p['pac_nombre'], $p['pac_ape'], $p['pac_foto'] ?? null, 32) ?>
                            <span><?= e($p['pac_ape'] . ', ' . $p['pac_nombre']) ?></span>
                        </div>
                    </td>
                    <td style="min-width:120px" data-orden="<?= $pct ?>">
                        <div class="progress" style="height:6px">
                            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted"><?= (int) $p['items_hechos'] ?>/<?= (int) $p['items_total'] ?> <?= et('procedimientos') ?></small>
                    </td>
                    <td class="text-end fw-semibold"><?= fmt_money($p['total']) ?></td>
                    <td class="text-end text-success"><?= fmt_money($p['pagado']) ?></td>
                    <td class="text-end <?= $cobrable && $saldo > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
                        <?= $cobrable ? fmt_money($saldo) : '—' ?>
                    </td>
                    <td><span class="badge bg-<?= presupuesto_estado_badge($p['estado']) ?>"><?= e(presupuesto_estado_label($p['estado'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">
                    <i class="bi bi-clipboard2 d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    <?= et('Todavía no hay presupuestos.') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
