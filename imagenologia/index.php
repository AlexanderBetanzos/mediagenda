<?php
/**
 * Ultrasonido / Imagenología: bandeja de estudios, KPIs y filtros.
 * "Por informar" son los estudios ya realizados que todavía no tienen impresión
 * diagnóstica: es la lista de trabajo real del médico que reporta.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('imagenologia');

$estado  = (string) ($_GET['estado'] ?? '');
$pacFil  = (int) ($_GET['paciente_id'] ?? 0);
$estados = usg_estados();

$sql = "SELECT e.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, COALESCE(p.foto_mime, p.foto) AS pac_foto,
               u.nombre AS med_nombre,
               COALESCE(h.n, 0)       AS hallazgos,
               COALESCE(h.anormales, 0) AS anormales,
               COALESCE(ar.n, 0)      AS imagenes
        FROM img_estudios e
        JOIN pacientes p ON p.id = e.paciente_id
        LEFT JOIN usuarios u ON u.id = e.medico_id
        LEFT JOIN (SELECT estudio_id, COUNT(*) AS n, SUM(anormal = 1) AS anormales
                   FROM img_hallazgos GROUP BY estudio_id) h ON h.estudio_id = e.id
        LEFT JOIN (SELECT img_estudio_id, COUNT(*) AS n
                   FROM archivos WHERE img_estudio_id IS NOT NULL
                   GROUP BY img_estudio_id) ar ON ar.img_estudio_id = e.id
        WHERE e.consultorio_id = ?";
$params = [tenant_id()];
if (isset($estados[$estado])) { $sql .= ' AND e.estado = ?';      $params[] = $estado; }
if ($pacFil)                  { $sql .= ' AND e.paciente_id = ?'; $params[] = $pacFil; }
$sql .= ' ORDER BY e.fecha DESC, e.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$lista = $stmt->fetchAll();

/* KPIs del gabinete. */
$k = db()->prepare(
    "SELECT
        SUM(estado = 'programado')                       AS programados,
        SUM(estado = 'realizado')                        AS por_informar,
        SUM(estado = 'informado')                        AS por_entregar,
        SUM(estado <> 'cancelado'
            AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS del_mes
     FROM img_estudios WHERE consultorio_id = ?"
);
$k->execute([tenant_id()]);
$kpi = $k->fetch() ?: [];

$paciente = null;
if ($pacFil) {
    $q = db()->prepare('SELECT nombre, apellidos FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $q->execute([$pacFil, tenant_id()]);
    $paciente = $q->fetch() ?: null;
}

$titulo = t('Ultrasonidos');
$activo = 'imagenologia';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-soundwave text-brand"></i> <?= et('Ultrasonidos') ?></h1>
    <div class="d-flex gap-2">
        <?php if (has_role('admin')): ?>
        <a href="<?= BASE_URL ?>/imagenologia/plantillas" class="btn btn-outline-secondary">
            <i class="bi bi-ui-checks-grid"></i> <?= et('Protocolos') ?>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/imagenologia/estudio<?= $pacFil ? '?paciente_id=' . $pacFil : '' ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= et('Nuevo estudio') ?>
        </a>
    </div>
</div>

<?php if ($paciente): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center py-2">
    <span><i class="bi bi-person"></i> <?= et('Estudios de') ?> <strong><?= e($paciente['nombre'] . ' ' . $paciente['apellidos']) ?></strong></span>
    <a href="<?= BASE_URL ?>/imagenologia/index" class="btn btn-sm btn-light"><?= et('Ver todos') ?></a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Programados') ?></div>
        <div class="stat-num mt-2"><?= (int) ($kpi['programados'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Por informar') ?></div>
        <div class="stat-num mt-2" style="color:#38bdf8"><?= (int) ($kpi['por_informar'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Por entregar') ?></div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= (int) ($kpi['por_entregar'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Estudios del mes') ?></div>
        <div class="stat-num mt-2"><?= (int) ($kpi['del_mes'] ?? 0) ?></div>
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
                <th><?= et('Estudio') ?></th>
                <th><?= et('Informa') ?></th>
                <th class="text-end"><?= et('Precio') ?></th>
                <th><?= et('Estado') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $o): ?>
                <tr onclick="location='<?= BASE_URL ?>/imagenologia/ver?id=<?= (int) $o['id'] ?>'" style="cursor:pointer">
                    <td class="fw-semibold"><?= e($o['folio']) ?></td>
                    <td class="text-muted"><?= fmt_fecha($o['fecha']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $o['paciente_id'], $o['pac_nombre'], $o['pac_ape'], $o['pac_foto'] ?? null, 32) ?>
                            <span><?= e($o['pac_ape'] . ', ' . $o['pac_nombre']) ?></span>
                        </div>
                    </td>
                    <td>
                        <div><?= e($o['nombre']) ?></div>
                        <small class="text-muted">
                            <?php if ($o['anormales']): ?>
                                <span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i>
                                    <?= (int) $o['anormales'] ?> <?= et('hallazgos anormales') ?></span> ·
                            <?php endif; ?>
                            <i class="bi bi-images"></i> <?= (int) $o['imagenes'] ?>
                        </small>
                    </td>
                    <td class="small text-muted"><?= e($o['med_nombre'] ?: '—') ?></td>
                    <td class="text-end fw-semibold"><?= fmt_money($o['precio']) ?></td>
                    <td><span class="badge bg-<?= usg_estado_badge($o['estado']) ?>"><?= e(usg_estado_label($o['estado'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-soundwave d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    <?= et('Todavía no hay estudios de ultrasonido.') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
