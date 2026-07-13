<?php
/**
 * Óptica: tablero de órdenes de trabajo.
 *
 * El KPI que manda es "atrasados": el cliente que viene por sus lentes y no
 * están es el que no vuelve. Por eso los trabajos vencidos se muestran primero
 * y en rojo, aunque el resto de la tabla se pueda reordenar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('optica');

$estado  = (string) ($_GET['estado'] ?? '');
$pacFil  = (int) ($_GET['paciente_id'] ?? 0);
$estados = optica_estados();

$sql = "SELECT t.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel,
               COALESCE(p.foto_mime, p.foto) AS pac_foto,
               g.od_esfera, g.od_cilindro, g.od_eje, g.oi_esfera, g.oi_cilindro, g.oi_eje,
               g.od_adicion, g.oi_adicion, g.dip
        FROM optica_trabajos t
        JOIN pacientes p ON p.id = t.paciente_id
        LEFT JOIN optica_graduaciones g ON g.id = t.graduacion_id
        WHERE t.consultorio_id = ?";
$params = [tenant_id()];
if (isset($estados[$estado])) { $sql .= ' AND t.estado = ?';      $params[] = $estado; }
if ($pacFil)                  { $sql .= ' AND t.paciente_id = ?'; $params[] = $pacFil; }
// Los atrasados primero: es la lista de trabajo del mostrador.
$sql .= " ORDER BY (t.estado NOT IN ('entregado','cancelado')
                    AND t.fecha_promesa IS NOT NULL AND t.fecha_promesa < CURDATE()) DESC,
          t.fecha_promesa IS NULL, t.fecha_promesa ASC, t.id DESC";

$st = db()->prepare($sql);
$st->execute($params);
$lista = $st->fetchAll();

$k = db()->prepare(
    "SELECT
        SUM(estado = 'pedido')          AS pedidos,
        SUM(estado = 'en_laboratorio')  AS en_lab,
        SUM(estado = 'recibido')        AS por_entregar,
        SUM(estado NOT IN ('entregado','cancelado')
            AND fecha_promesa IS NOT NULL AND fecha_promesa < CURDATE()) AS atrasados,
        COALESCE(SUM(CASE WHEN estado <> 'cancelado' THEN total - anticipo END), 0) AS por_cobrar
     FROM optica_trabajos WHERE consultorio_id = ?"
);
$k->execute([tenant_id()]);
$kpi = $k->fetch() ?: [];

$titulo = t('Óptica');
$activo = 'optica';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-eyeglasses text-brand"></i> <?= et('Óptica') ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <?php if (has_role('admin')): ?>
        <a href="<?= BASE_URL ?>/optica/micas" class="btn btn-outline-secondary">
            <i class="bi bi-circle-half"></i> <?= et('Catálogo de micas') ?>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/optica/trabajo" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= et('Nueva orden') ?>
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Atrasados') ?></div>
        <div class="stat-num mt-2" style="color:#ef4444"><?= (int) ($kpi['atrasados'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('En laboratorio') ?></div>
        <div class="stat-num mt-2" style="color:#38bdf8"><?= (int) ($kpi['en_lab'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Por entregar') ?></div>
        <div class="stat-num mt-2" style="color:#22c55e"><?= (int) ($kpi['por_entregar'] ?? 0) ?></div>
    </div></div></div>
    <div class="col-sm-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Saldo por cobrar') ?></div>
        <div class="stat-num mt-2" style="color:#f59e0b"><?= fmt_money($kpi['por_cobrar'] ?? 0) ?></div>
    </div></div></div>
</div>

<div class="btn-group btn-group-sm mb-3">
    <a href="?" class="btn <?= $estado === '' ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et('Todas') ?></a>
    <?php foreach ($estados as $clave => [$lbl, $color]): ?>
    <a href="?estado=<?= $clave ?>" class="btn <?= $estado === $clave ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= et($lbl) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th><?= et('Folio') ?></th>
                <th><?= et('Paciente') ?></th>
                <th><?= et('Graduación') ?></th>
                <th><?= et('Armazón / Mica') ?></th>
                <th><?= et('Entrega') ?></th>
                <th class="text-end"><?= et('Total') ?></th>
                <th class="text-end"><?= et('Saldo') ?></th>
                <th><?= et('Estado') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $t):
                $atrasado = optica_trabajo_atrasado($t);
                $saldo    = (float) $t['total'] - (float) $t['anticipo'];
                $tieneGrad = $t['od_esfera'] !== null || $t['oi_esfera'] !== null;
            ?>
                <tr onclick="location='<?= BASE_URL ?>/optica/ver?id=<?= (int) $t['id'] ?>'" style="cursor:pointer"
                    class="<?= $atrasado ? 'table-danger' : '' ?>">
                    <td class="fw-semibold"><?= e($t['folio']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $t['paciente_id'], $t['pac_nombre'], $t['pac_ape'], $t['pac_foto'] ?? null, 32) ?>
                            <span><?= e($t['pac_ape'] . ', ' . $t['pac_nombre']) ?></span>
                        </div>
                    </td>
                    <td class="small font-monospace text-muted">
                        <?= $tieneGrad ? e(optica_graduacion_resumen($t)) : '—' ?>
                    </td>
                    <td class="small">
                        <?= e($t['armazon_desc'] ?: '—') ?><br>
                        <span class="text-muted"><?= e($t['mica_desc'] ?: '—') ?></span>
                    </td>
                    <td class="small" data-orden="<?= e((string) ($t['fecha_promesa'] ?? '9999-12-31')) ?>">
                        <?php if ($t['fecha_promesa']): ?>
                            <?= fmt_fecha($t['fecha_promesa']) ?>
                            <?php if ($atrasado): ?>
                                <br><span class="badge bg-danger"><?= et('Atrasado') ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= fmt_money($t['total']) ?></td>
                    <td class="text-end <?= $saldo > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
                        <?= fmt_money($saldo) ?>
                    </td>
                    <td><span class="badge bg-<?= optica_estado_badge($t['estado']) ?>"><?= e(optica_estado_label($t['estado'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">
                    <i class="bi bi-eyeglasses d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    <?= et('Todavía no hay órdenes de trabajo.') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
