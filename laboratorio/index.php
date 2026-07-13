<?php
/**
 * Laboratorio: listado de órdenes de estudio, KPIs y filtros.
 * "Por entregar" son las órdenes con resultado listo que el paciente todavía
 * no recoge: es la lista de trabajo real del mostrador.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('laboratorio');

$estado  = (string) ($_GET['estado'] ?? '');
$pacFil  = (int) ($_GET['paciente_id'] ?? 0);
$estados = lab_estados();

$sql = "SELECT o.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, COALESCE(p.foto_mime, p.foto) AS pac_foto,
               u.nombre AS med_nombre,
               COALESCE(it.n, 0)         AS items_total,
               COALESCE(it.con_res, 0)   AS items_con_resultado,
               COALESCE(ar.n, 0)         AS archivos
        FROM lab_ordenes o
        JOIN pacientes p ON p.id = o.paciente_id
        LEFT JOIN usuarios u ON u.id = o.medico_id
        LEFT JOIN (SELECT orden_id, COUNT(*) AS n,
                          SUM(resultado IS NOT NULL AND resultado <> '') AS con_res
                   FROM lab_orden_items GROUP BY orden_id) it ON it.orden_id = o.id
        LEFT JOIN (SELECT lab_orden_id, COUNT(*) AS n
                   FROM archivos WHERE lab_orden_id IS NOT NULL
                   GROUP BY lab_orden_id) ar ON ar.lab_orden_id = o.id
        WHERE o.consultorio_id = ?";
$params = [tenant_id()];
if (isset($estados[$estado])) { $sql .= ' AND o.estado = ?';      $params[] = $estado; }
if ($pacFil)                  { $sql .= ' AND o.paciente_id = ?'; $params[] = $pacFil; }
$sql .= " ORDER BY (o.prioridad = 'urgente') DESC, o.fecha DESC, o.id DESC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$lista = $stmt->fetchAll();

/* KPIs del mostrador. */
$k = db()->prepare(
    "SELECT
        SUM(estado = 'solicitada')                    AS solicitadas,
        SUM(estado = 'en_proceso')                    AS en_proceso,
        SUM(estado = 'lista')                         AS por_entregar,
        SUM(prioridad = 'urgente'
            AND estado IN ('solicitada','en_proceso')) AS urgentes
     FROM lab_ordenes WHERE consultorio_id = ?"
);
$k->execute([tenant_id()]);
$kpi = $k->fetch() ?: [];

$paciente = null;
if ($pacFil) {
    $q = db()->prepare('SELECT nombre, apellidos FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $q->execute([$pacFil, tenant_id()]);
    $paciente = $q->fetch() ?: null;
}

$titulo = t('Laboratorio');
$activo = 'laboratorio';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-eyedropper text-brand"></i> <?= et('Laboratorio') ?></h1>
    <div class="d-flex gap-2">
        <?php if (has_role('admin')): ?>
        <a href="<?= BASE_URL ?>/laboratorio/estudios" class="btn btn-outline-secondary">
            <i class="bi bi-list-check"></i> <?= et('Catálogo de estudios') ?>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/laboratorio/orden<?= $pacFil ? '?paciente_id=' . $pacFil : '' ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= et('Nueva orden') ?>
        </a>
    </div>
</div>

<?php if ($paciente): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center py-2">
    <span><i class="bi bi-person"></i> <?= et('Órdenes de') ?> <strong><?= e($paciente['nombre'] . ' ' . $paciente['apellidos']) ?></strong></span>
    <a href="<?= BASE_URL ?>/laboratorio/index" class="btn btn-sm btn-light"><?= et('Ver todas') ?></a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Solicitadas') ?></div>
        <div class="stat-num mt-2"><?= (int) ($kpi['solicitadas'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('En proceso') ?></div>
        <div class="stat-num mt-2" style="color:#38bdf8"><?= (int) ($kpi['en_proceso'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Por entregar') ?></div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= (int) ($kpi['por_entregar'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Urgentes pendientes') ?></div>
        <div class="stat-num mt-2" style="color:#ef4444"><?= (int) ($kpi['urgentes'] ?? 0) ?></div>
    </div></div></div>
</div>

<div class="btn-group btn-group-sm mb-3">
    <?php $base = $pacFil ? '&paciente_id=' . $pacFil : ''; ?>
    <a href="?estado=<?= $base ?>" class="btn <?= $estado === '' ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et('Todas') ?></a>
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
                <th><?= et('Solicita') ?></th>
                <th><?= et('Resultados') ?></th>
                <th class="text-end"><?= et('Total') ?></th>
                <th><?= et('Estado') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $o):
                $pct = $o['items_total'] ? round(100 * $o['items_con_resultado'] / $o['items_total']) : 0;
            ?>
                <tr onclick="location='<?= BASE_URL ?>/laboratorio/ver?id=<?= (int) $o['id'] ?>'" style="cursor:pointer">
                    <td class="fw-semibold">
                        <?= e($o['folio']) ?>
                        <?php if ($o['prioridad'] === 'urgente'): ?>
                            <span class="badge bg-danger ms-1"><?= et('Urgente') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= fmt_fecha($o['fecha']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $o['paciente_id'], $o['pac_nombre'], $o['pac_ape'], $o['pac_foto'] ?? null, 32) ?>
                            <span><?= e($o['pac_ape'] . ', ' . $o['pac_nombre']) ?></span>
                        </div>
                    </td>
                    <td class="small text-muted"><?= e($o['med_nombre'] ?: '—') ?></td>
                    <td style="min-width:130px" data-orden="<?= $pct ?>">
                        <div class="progress" style="height:6px">
                            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted">
                            <?= (int) $o['items_con_resultado'] ?>/<?= (int) $o['items_total'] ?> <?= et('estudios') ?>
                            <?php if ($o['archivos']): ?>
                                · <i class="bi bi-paperclip"></i> <?= (int) $o['archivos'] ?>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td class="text-end fw-semibold"><?= fmt_money($o['total']) ?></td>
                    <td><span class="badge bg-<?= lab_estado_badge($o['estado']) ?>"><?= e(lab_estado_label($o['estado'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-eyedropper d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    <?= et('Todavía no hay órdenes de laboratorio.') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
