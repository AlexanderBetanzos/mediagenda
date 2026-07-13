<?php
/**
 * Detalle de una orden de trabajo: avanzar el estado, registrar el anticipo o
 * el saldo, y entregar.
 *
 * Al pasar a "en laboratorio" se descuenta del inventario el armazón, si salió
 * de ahí: ese es el momento real en que la pieza deja el aparador, no antes.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('optica');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$u  = current_user();

$cargar = function (int $id) {
    $st = db()->prepare(
        'SELECT t.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel,
                COALESCE(p.foto_mime, p.foto) AS pac_foto, p.fecha_nacimiento,
                v.nombre AS vendedor_nombre
         FROM optica_trabajos t
         JOIN pacientes p ON p.id = t.paciente_id
         LEFT JOIN usuarios v ON v.id = t.vendedor_id
         WHERE t.id = ? AND t.consultorio_id = ?'
    );
    $st->execute([$id, tenant_id()]);
    return $st->fetch();
};

$t = $cargar($id);
if (!$t) { flash('Orden no encontrada.', 'warning'); redirect('/optica/index'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'estado') {
        $nuevo = (string) ($_POST['estado'] ?? '');
        if (!isset(optica_estados()[$nuevo])) {
            flash('Estado no válido.', 'warning');
            redirect('/optica/ver?id=' . $id);
        }

        // El armazón sale del aparador cuando el trabajo se manda a tallar: ese
        // es el momento real en que la pieza deja la tienda, no cuando se cotiza.
        // El movimiento se registra igual que en el POS (inventario_movimientos).
        if ($nuevo === 'en_laboratorio' && $t['estado'] === 'pedido'
            && $t['armazon_producto_id'] && pertenece_al_tenant('productos', (int) $t['armazon_producto_id'])) {
            db()->prepare(
                'INSERT INTO inventario_movimientos
                 (consultorio_id, producto_id, tipo, cantidad, motivo, usuario_id)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                tenant_id(), (int) $t['armazon_producto_id'], 'salida', 1,
                'Orden de óptica ' . $t['folio'], (int) $u['id'],
            ]);
        }

        db()->prepare(
            "UPDATE optica_trabajos
             SET estado = ?, entregado_en = IF(? = 'entregado', NOW(), entregado_en)
             WHERE id = ? AND consultorio_id = ?"
        )->execute([$nuevo, $nuevo, $id, tenant_id()]);

        auditar('optica_trabajo_estado', 'optica_trabajo', $id, $t['folio'] . ' -> ' . $nuevo);
        flash('Orden marcada como ' . mb_strtolower(optica_estado_label($nuevo)) . '.');
        redirect('/optica/ver?id=' . $id);
    }

    if ($accion === 'abono') {
        $monto = round((float) ($_POST['monto'] ?? 0), 2);
        $saldo = round((float) $t['total'] - (float) $t['anticipo'], 2);
        if ($monto <= 0) {
            flash('El abono debe ser mayor que cero.', 'warning');
        } elseif ($monto > $saldo) {
            flash('El abono no puede ser mayor que el saldo: ' . fmt_money($saldo), 'danger');
        } else {
            db()->prepare('UPDATE optica_trabajos SET anticipo = anticipo + ? WHERE id = ? AND consultorio_id = ?')
                ->execute([$monto, $id, tenant_id()]);
            auditar('optica_trabajo_abono', 'optica_trabajo', $id, fmt_money($monto));
            flash('Abono registrado: ' . fmt_money($monto));
        }
        redirect('/optica/ver?id=' . $id);
    }
}

$grad = null;
if ($t['graduacion_id']) {
    $st = db()->prepare('SELECT * FROM optica_graduaciones WHERE id = ? AND consultorio_id = ?');
    $st->execute([(int) $t['graduacion_id'], tenant_id()]);
    $grad = $st->fetch() ?: null;
}

$saldo    = (float) $t['total'] - (float) $t['anticipo'];
$atrasado = optica_trabajo_atrasado($t);

$wa = (modulo_activo('whatsapp') && $t['pac_tel'] && $t['estado'] === 'recibido')
    ? wa_link($t['pac_tel'], t('Hola') . ' ' . $t['pac_nombre'] . ', '
        . t('tus lentes ya están listos para recoger') . ' (' . $t['folio'] . ').')
    : '';

$titulo = $t['folio'];
$activo = 'optica';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/optica/index"><?= et('Óptica') ?></a></li>
    <li class="breadcrumb-item active"><?= e($t['folio']) ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-3">
        <?= avatar_paciente((int) $t['paciente_id'], $t['pac_nombre'], $t['pac_ape'], $t['pac_foto'] ?? null, 56) ?>
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-eyeglasses text-brand"></i> <?= e($t['folio']) ?>
                <span class="badge bg-<?= optica_estado_badge($t['estado']) ?> align-middle"><?= e(optica_estado_label($t['estado'])) ?></span>
                <?php if ($atrasado): ?><span class="badge bg-danger align-middle"><?= et('Atrasado') ?></span><?php endif; ?>
            </h1>
            <div class="text-muted small">
                <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $t['paciente_id'] ?>" class="text-decoration-none">
                    <i class="bi bi-person"></i> <?= e($t['pac_nombre'] . ' ' . $t['pac_ape']) ?>
                </a>
                · <?= fmt_fecha($t['fecha']) ?>
                <?php if ($t['laboratorio']): ?> · <?= et('Laboratorio') ?>: <?= e($t['laboratorio']) ?><?php endif; ?>
                <?php if ($t['vendedor_nombre']): ?> · <?= et('Vendió') ?>: <?= e($t['vendedor_nombre']) ?><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($wa): ?>
            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-success">
                <i class="bi bi-whatsapp"></i> <?= et('Avisar que están listos') ?>
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/optica/imprimir?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-printer"></i> <?= et('Imprimir orden') ?>
        </a>
        <a href="<?= BASE_URL ?>/optica/trabajo?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil"></i> <?= et('Editar') ?>
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted small me-2"><?= et('Avanzar el trabajo:') ?></span>
        <?php
        $siguiente = [
            'pedido'         => [['en_laboratorio', 'Mandar a laboratorio', 'info'], ['cancelado', 'Cancelar', 'outline-secondary']],
            'en_laboratorio' => [['recibido', 'Llegó a la óptica', 'primary'], ['cancelado', 'Cancelar', 'outline-secondary']],
            'recibido'       => [['entregado', 'Entregar al cliente', 'success']],
            'entregado'      => [],
            'cancelado'      => [['pedido', 'Reabrir', 'outline-secondary']],
        ][$t['estado']] ?? [];
        ?>
        <?php foreach ($siguiente as [$clave, $lbl, $color]): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="accion" value="estado">
            <input type="hidden" name="estado" value="<?= $clave ?>">
            <button class="btn btn-sm btn-<?= $color ?>"><?= et($lbl) ?></button>
        </form>
        <?php endforeach; ?>
        <?php if (!$siguiente): ?>
            <span class="text-muted small"><i class="bi bi-check-circle text-success"></i> <?= et('Trabajo cerrado.') ?></span>
        <?php endif; ?>
        <?php if ($t['entregado_en']): ?>
            <span class="ms-auto small text-muted"><?= et('Entregado el') ?> <?= fmt_fecha($t['entregado_en']) ?></span>
        <?php elseif ($t['fecha_promesa']): ?>
            <span class="ms-auto small <?= $atrasado ? 'text-danger fw-semibold' : 'text-muted' ?>">
                <?= et('Prometido para el') ?> <?= fmt_fecha($t['fecha_promesa']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php if ($grad): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-eye text-brand"></i> <?= et('Graduación') ?></span>
                <span class="small text-muted"><?= fmt_fecha($grad['fecha']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 text-center" data-no-sort>
                    <thead><tr>
                        <th style="width:60px"></th>
                        <th><?= et('Esfera') ?></th><th><?= et('Cilindro') ?></th><th><?= et('Eje') ?></th>
                        <th><?= et('Adición') ?></th><th><?= et('Agudeza') ?></th>
                    </tr></thead>
                    <tbody class="font-monospace">
                        <tr>
                            <th class="text-start">OD</th>
                            <td><?= fmt_dioptria($grad['od_esfera']) ?></td>
                            <td><?= fmt_dioptria($grad['od_cilindro']) ?></td>
                            <td><?= fmt_eje($grad['od_eje']) ?></td>
                            <td><?= fmt_dioptria($grad['od_adicion']) ?></td>
                            <td><?= e($grad['od_av'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th class="text-start">OI</th>
                            <td><?= fmt_dioptria($grad['oi_esfera']) ?></td>
                            <td><?= fmt_dioptria($grad['oi_cilindro']) ?></td>
                            <td><?= fmt_eje($grad['oi_eje']) ?></td>
                            <td><?= fmt_dioptria($grad['oi_adicion']) ?></td>
                            <td><?= e($grad['oi_av'] ?: '—') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top small text-muted">
                <?php if ($grad['dip']): ?><?= et('DIP') ?>: <strong><?= e((string) $grad['dip']) ?></strong> · <?php endif; ?>
                <?php if ($grad['tipo_lente']): ?><?= et(optica_tipos_lente()[$grad['tipo_lente']]) ?> · <?php endif; ?>
                <?= e($grad['diagnostico'] ?: '') ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-bag text-brand"></i> <?= et('Lo que se vendió') ?></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0" data-no-sort>
                    <tbody>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= et('Armazón') ?></div>
                                <div class="small text-muted"><?= e($t['armazon_desc'] ?: t('El cliente trajo el suyo')) ?></div>
                            </td>
                            <td class="text-end fw-semibold"><?= fmt_money($t['armazon_precio']) ?></td>
                        </tr>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= et('Micas') ?></div>
                                <div class="small text-muted">
                                    <?= e($t['mica_desc'] ?: '—') ?>
                                    <?php if ($t['tratamientos']): ?><br><?= e($t['tratamientos']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td class="text-end fw-semibold"><?= fmt_money($t['mica_precio']) ?></td>
                        </tr>
                        <?php if ((float) $t['descuento'] > 0): ?>
                        <tr>
                            <td class="text-muted"><?= et('Descuento') ?></td>
                            <td class="text-end text-danger">− <?= fmt_money($t['descuento']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-top">
                            <td class="fw-bold"><?= et('Total') ?></td>
                            <td class="text-end fw-bold h5 mb-0"><?= fmt_money($t['total']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($t['notas']): ?>
            <div class="card-body border-top small text-muted"><?= nl2br(e($t['notas'])) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-cash-coin text-brand"></i> <?= et('Estado de cuenta') ?></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted"><?= et('Total') ?></span>
                    <span class="fw-semibold"><?= fmt_money($t['total']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted"><?= et('Anticipos') ?></span>
                    <span class="text-success"><?= fmt_money($t['anticipo']) ?></span>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                    <span class="fw-semibold"><?= et('Saldo') ?></span>
                    <span class="h5 mb-0 fw-bold <?= $saldo > 0 ? 'text-warning' : 'text-success' ?>">
                        <?= fmt_money($saldo) ?>
                    </span>
                </div>
            </div>
            <?php if ($saldo > 0 && $t['estado'] !== 'cancelado'): ?>
            <form method="post" class="card-body border-top">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="abono">
                <label class="form-label"><?= et('Registrar abono') ?></label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" step="0.01" min="0.01" max="<?= e((string) $saldo) ?>"
                           name="monto" class="form-control" value="<?= e(number_format($saldo, 2, '.', '')) ?>" required>
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Abonar') ?></button>
                </div>
                <div class="form-text"><?= et('Se sugiere el saldo completo (liquidar al entregar).') ?></div>
            </form>
            <?php endif; ?>
        </div>

        <?php if (has_role('admin')): ?>
        <form method="post" action="<?= BASE_URL ?>/optica/delete"
              onsubmit="return confirm('<?= e(t('¿Eliminar esta orden? No se puede deshacer.')) ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-trash"></i> <?= et('Eliminar orden') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
