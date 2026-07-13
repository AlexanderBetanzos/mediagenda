<?php
/**
 * Ficha de un presupuesto: conceptos, avance del tratamiento y estado de cuenta.
 * Concentra las acciones (cambiar estado, marcar procedimiento realizado,
 * registrar o borrar abonos) porque todas dependen del mismo estado del documento.
 */
require_once __DIR__ . '/../includes/odontograma.php';
require_once __DIR__ . '/../includes/cobros.php';
require_login();
require_modulo('presupuestos');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$u  = current_user();

$cargar = function (int $id) {
    $st = db()->prepare(
        'SELECT pr.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel, p.foto AS pac_foto,
                m.nombre AS medico_nombre
         FROM presupuestos pr
         JOIN pacientes p ON p.id = pr.paciente_id
         LEFT JOIN usuarios m ON m.id = pr.medico_id
         WHERE pr.id = ? AND pr.consultorio_id = ?'
    );
    $st->execute([$id, tenant_id()]);
    return $st->fetch();
};

$pre = $cargar($id);
if (!$pre) { http_response_code(404); die('Presupuesto no encontrado.'); }

/* Transiciones permitidas: estado actual => estados a los que puede pasar. */
$transiciones = [
    'borrador'  => ['propuesto', 'cancelado'],
    'propuesto' => ['aceptado', 'rechazado', 'borrador'],
    'aceptado'  => ['terminado', 'cancelado'],
    'terminado' => [],
    'rechazado' => ['propuesto'],
    'cancelado' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'estado') {
        $nuevo = (string) ($_POST['estado'] ?? '');
        if (!in_array($nuevo, $transiciones[$pre['estado']] ?? [], true)) {
            flash(t('Ese cambio de estado no es válido.'), 'danger');
            redirect('/presupuestos/ver?id=' . $id);
        }
        $aceptadoEn = $nuevo === 'aceptado' ? date('Y-m-d H:i:s') : $pre['aceptado_en'];
        db()->prepare('UPDATE presupuestos SET estado = ?, aceptado_en = ? WHERE id = ? AND consultorio_id = ?')
            ->execute([$nuevo, $aceptadoEn, $id, tenant_id()]);
        auditar('presupuesto_estado', 'presupuesto', $id, $pre['estado'] . ' → ' . $nuevo);
        flash(t('Presupuesto') . ' ' . mb_strtolower(presupuesto_estado_label($nuevo)) . '.');
        redirect('/presupuestos/ver?id=' . $id);
    }

    if ($accion === 'item') {
        if (!has_role('admin', 'medico')) { http_response_code(403); die('Sin permiso.'); }
        if ($pre['estado'] !== 'aceptado') {
            flash(t('Solo se marcan procedimientos en un presupuesto aceptado.'), 'warning');
            redirect('/presupuestos/ver?id=' . $id);
        }
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $chk = db()->prepare('SELECT estado, diente, caras, tratamiento FROM presupuesto_items WHERE id = ? AND presupuesto_id = ?');
        $chk->execute([$itemId, $id]);
        $item = $chk->fetch();
        if ($item) {
            $nuevo = $item['estado'] === 'realizado' ? 'pendiente' : 'realizado';
            db()->prepare('UPDATE presupuesto_items SET estado = ?, realizado_en = ?, realizado_por = ? WHERE id = ? AND presupuesto_id = ?')
                ->execute([$nuevo, $nuevo === 'realizado' ? date('Y-m-d H:i:s') : null,
                           $nuevo === 'realizado' ? $u['id'] : null, $itemId, $id]);
            auditar('presupuesto_item', 'presupuesto', $id, $nuevo);

            // Si el concepto vino del odontograma, la cara pasa a "realizado" y su
            // hallazgo se actualiza al resultado del tratamiento. Desmarcarlo no
            // revierte el odontograma: corregirlo es una decisión clínica.
            if ($nuevo === 'realizado' && $item['diente'] && $item['tratamiento']) {
                odo_aplicar_tratamiento((int) $pre['paciente_id'], $item['diente'], $item['caras'], $item['tratamiento']);
                odo_snapshot((int) $pre['paciente_id'], t('Procedimiento realizado') . ' · ' . $pre['folio']);
            }

            // Si ya no queda nada pendiente, el plan de tratamiento terminó.
            $pend = db()->prepare("SELECT COUNT(*) FROM presupuesto_items WHERE presupuesto_id = ? AND estado = 'pendiente'");
            $pend->execute([$id]);
            if ((int) $pend->fetchColumn() === 0) {
                db()->prepare("UPDATE presupuestos SET estado = 'terminado' WHERE id = ? AND consultorio_id = ?")
                    ->execute([$id, tenant_id()]);
                flash(t('Todos los procedimientos están realizados: el tratamiento se marcó como terminado.'));
            }
        }
        redirect('/presupuestos/ver?id=' . $id);
    }

    if ($accion === 'abono') {
        if (!has_role('admin', 'recepcion')) { http_response_code(403); die('Sin permiso.'); }
        if (!presupuesto_es_cobrable($pre['estado'])) {
            flash(t('Solo se registran abonos en un presupuesto aceptado.'), 'warning');
            redirect('/presupuestos/ver?id=' . $id);
        }
        $monto = round((float) ($_POST['monto'] ?? 0), 2);
        $saldo = round((float) $pre['total'] - presupuesto_pagado($id), 2);
        if ($monto <= 0) {
            flash(t('El monto del abono debe ser mayor que cero.'), 'danger');
        } elseif ($monto > $saldo) {
            flash(t('El abono no puede ser mayor que el saldo pendiente.') . ' ' . fmt_money($saldo), 'danger');
        } else {
            db()->prepare('INSERT INTO presupuesto_pagos
                           (consultorio_id, presupuesto_id, fecha, monto, metodo, referencia, notas, usuario_id)
                           VALUES (?,?,?,?,?,?,?,?)')
                ->execute([tenant_id(), $id, ($_POST['fecha'] ?? '') ?: date('Y-m-d'), $monto,
                           trim((string) ($_POST['metodo'] ?? '')) ?: null,
                           trim((string) ($_POST['referencia'] ?? '')) ?: null,
                           trim((string) ($_POST['notas'] ?? '')) ?: null,
                           $u['id']]);
            auditar('presupuesto_abono', 'presupuesto', $id, fmt_money($monto));
            flash(t('Abono registrado.') . ' ' . fmt_money($monto));
        }
        redirect('/presupuestos/ver?id=' . $id);
    }

    if ($accion === 'cobro') {
        if (!has_role('admin', 'recepcion')) { http_response_code(403); die('Sin permiso.'); }
        if (!mp_tenant_habilitado()) {
            flash(t('Primero configura el pago en línea en Configuración.'), 'warning');
            redirect('/presupuestos/ver?id=' . $id);
        }
        $saldo = round((float) $pre['total'] - presupuesto_pagado($id), 2);
        $monto = round((float) ($_POST['monto'] ?? 0), 2) ?: $saldo;
        if (!presupuesto_es_cobrable($pre['estado']) || $saldo <= 0) {
            flash(t('No hay saldo por cobrar en este presupuesto.'), 'warning');
        } elseif ($monto <= 0 || $monto > $saldo) {
            flash(t('El monto a cobrar debe estar entre cero y el saldo pendiente.'), 'danger');
        } else {
            $cid = cobro_crear((int) $pre['paciente_id'], $monto,
                               t('Presupuesto') . ' ' . $pre['folio'], $id);
            auditar('cobro_crear', 'presupuesto', $id, fmt_money($monto));
            flash(t('Link de pago generado. Compártelo con el paciente.'));
        }
        redirect('/presupuestos/ver?id=' . $id);
    }

    if ($accion === 'cancelar_cobro') {
        if (!has_role('admin', 'recepcion')) { http_response_code(403); die('Sin permiso.'); }
        db()->prepare("UPDATE cobros SET estado = 'cancelado'
                       WHERE id = ? AND presupuesto_id = ? AND consultorio_id = ? AND estado = 'pendiente'")
            ->execute([(int) ($_POST['cobro_id'] ?? 0), $id, tenant_id()]);
        auditar('cobro_cancelar', 'presupuesto', $id);
        flash(t('Link de pago cancelado.'), 'warning');
        redirect('/presupuestos/ver?id=' . $id);
    }

    if ($accion === 'borrar_abono') {
        require_role('admin');
        $pagoId = (int) ($_POST['pago_id'] ?? 0);
        db()->prepare('DELETE FROM presupuesto_pagos WHERE id = ? AND presupuesto_id = ? AND consultorio_id = ?')
            ->execute([$pagoId, $id, tenant_id()]);
        auditar('presupuesto_abono_borrado', 'presupuesto', $id, '#' . $pagoId);
        flash(t('Abono eliminado.'), 'warning');
        redirect('/presupuestos/ver?id=' . $id);
    }
}

$items = db()->prepare(
    'SELECT i.*, m.nombre AS realizado_por_nombre
     FROM presupuesto_items i
     LEFT JOIN usuarios m ON m.id = i.realizado_por
     WHERE i.presupuesto_id = ? ORDER BY i.orden, i.id'
);
$items->execute([$id]);
$items = $items->fetchAll();

$pagos = db()->prepare(
    'SELECT pg.*, us.nombre AS usuario_nombre
     FROM presupuesto_pagos pg
     LEFT JOIN usuarios us ON us.id = pg.usuario_id
     WHERE pg.presupuesto_id = ? AND pg.consultorio_id = ? ORDER BY pg.fecha, pg.id'
);
$pagos->execute([$id, tenant_id()]);
$pagos = $pagos->fetchAll();

$cobros = db()->prepare(
    "SELECT * FROM cobros
     WHERE presupuesto_id = ? AND consultorio_id = ? AND estado = 'pendiente'
     ORDER BY creado_en DESC"
);
$cobros->execute([$id, tenant_id()]);
$cobros = $cobros->fetchAll();

$pagado   = array_sum(array_column($pagos, 'monto'));
$saldo    = (float) $pre['total'] - $pagado;
$cobrable = presupuesto_es_cobrable($pre['estado']);
$hechos   = count(array_filter($items, fn($i) => $i['estado'] === 'realizado'));
$pct      = $items ? round(100 * $hechos / count($items)) : 0;
$editable = in_array($pre['estado'], ['borrador', 'propuesto'], true);

$titulo = t('Presupuesto') . ' ' . $pre['folio'];
$activo = 'presupuestos';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/presupuestos/index"><?= et('Presupuestos') ?></a></li>
    <li class="breadcrumb-item active"><?= e($pre['folio']) ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-3">
        <?= avatar_paciente((int) $pre['paciente_id'], $pre['pac_nombre'], $pre['pac_ape'], $pre['pac_foto'] ?? null, 56) ?>
        <div>
        <h1 class="h3 mb-1"><?= e($pre['folio']) ?>
            <span class="badge bg-<?= presupuesto_estado_badge($pre['estado']) ?> align-middle"><?= e(presupuesto_estado_label($pre['estado'])) ?></span>
        </h1>
        <div class="text-muted">
            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $pre['paciente_id'] ?>"><?= e($pre['pac_nombre'] . ' ' . $pre['pac_ape']) ?></a>
            · <?= fmt_fecha($pre['fecha']) ?>
            <?php if ($pre['medico_nombre']): ?> · <?= e($pre['medico_nombre']) ?><?php endif; ?>
        </div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/presupuestos/imprimir?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-printer"></i> <?= et('Imprimir / PDF') ?>
        </a>
        <?php if ($editable): ?>
        <a href="<?= BASE_URL ?>/presupuestos/edit?id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-pencil"></i> <?= et('Editar') ?></a>
        <?php endif; ?>
        <?php foreach ($transiciones[$pre['estado']] as $destino):
            [$lbl, $color] = presupuesto_estados()[$destino]; ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="accion" value="estado">
            <input type="hidden" name="estado" value="<?= e($destino) ?>">
            <button class="btn btn-<?= $destino === 'aceptado' ? 'success' : ($destino === 'rechazado' || $destino === 'cancelado' ? 'outline-danger' : 'outline-secondary') ?>">
                <?= et('Marcar como') ?> <?= et($lbl) ?>
            </button>
        </form>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-check"></i> <?= et('Procedimientos') ?></span>
                <span class="text-muted small"><?= $hechos ?>/<?= count($items) ?> <?= et('realizados') ?></span>
            </div>
            <div class="progress rounded-0" style="height:4px">
                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th><?= et('Procedimiento') ?></th>
                        <th><?= et('Diente') ?></th>
                        <th class="text-end"><?= et('Cant.') ?></th>
                        <th class="text-end"><?= et('Importe') ?></th>
                        <th class="text-end"><?= et('Estado') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($items as $it): $hecho = $it['estado'] === 'realizado'; ?>
                        <tr class="<?= $hecho ? 'table-success' : '' ?>">
                            <td>
                                <span class="<?= $hecho ? 'text-decoration-line-through text-muted' : 'fw-semibold' ?>"><?= e($it['descripcion']) ?></span>
                                <?php if ($hecho && $it['realizado_por_nombre']): ?>
                                <div class="small text-muted"><?= et('Realizado') ?> <?= fmt_fecha($it['realizado_en']) ?> · <?= e($it['realizado_por_nombre']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($it['diente']): ?>
                                    <span class="badge bg-secondary"><?= e($it['diente']) ?></span>
                                    <?php if ($it['caras']): ?><small class="text-muted ms-1"><?= e($it['caras']) ?></small><?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="text-end"><?= (int) $it['cantidad'] ?></td>
                            <td class="text-end"><?= fmt_money($it['importe']) ?></td>
                            <td class="text-end">
                                <?php if ($pre['estado'] === 'aceptado' && has_role('admin', 'medico')): ?>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="accion" value="item">
                                    <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                    <button class="btn btn-sm btn-<?= $hecho ? 'success' : 'outline-secondary' ?>"
                                            title="<?= $hecho ? et('Marcar como pendiente') : et('Marcar como realizado') ?>">
                                        <i class="bi bi-<?= $hecho ? 'check-circle-fill' : 'circle' ?>"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <span class="badge bg-<?= $hecho ? 'success' : 'secondary' ?>"><?= $hecho ? et('Realizado') : et('Pendiente') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-top">
                        <tr><td colspan="3" class="text-end text-muted"><?= et('Subtotal') ?></td><td class="text-end"><?= fmt_money($pre['subtotal']) ?></td><td></td></tr>
                        <?php if ((float) $pre['descuento'] > 0): ?>
                        <tr><td colspan="3" class="text-end text-muted"><?= et('Descuento') ?></td><td class="text-end text-danger">−<?= fmt_money($pre['descuento']) ?></td><td></td></tr>
                        <?php endif; ?>
                        <tr class="fs-5"><td colspan="3" class="text-end"><?= et('Total') ?></td><td class="text-end fw-bold"><?= fmt_money($pre['total']) ?></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php if ($pre['notas']): ?>
        <div class="card mb-3"><div class="card-body">
            <h6 class="text-muted"><i class="bi bi-card-text"></i> <?= et('Notas') ?></h6>
            <p class="mb-0" style="white-space:pre-line"><?= e($pre['notas']) ?></p>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-cash-coin"></i> <?= et('Estado de cuenta') ?></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span class="text-muted"><?= et('Total') ?></span><strong><?= fmt_money($pre['total']) ?></strong></div>
                <div class="d-flex justify-content-between mb-2"><span class="text-muted"><?= et('Abonado') ?></span><strong class="text-success"><?= fmt_money($pagado) ?></strong></div>
                <hr>
                <div class="d-flex justify-content-between fs-5">
                    <span><?= et('Saldo') ?></span>
                    <strong class="<?= $saldo > 0 ? 'text-warning' : 'text-success' ?>"><?= fmt_money($saldo) ?></strong>
                </div>
                <?php if (!$cobrable): ?>
                <div class="form-text mt-2"><i class="bi bi-info-circle"></i> <?= et('Los abonos se habilitan cuando el paciente acepta el presupuesto.') ?></div>
                <?php elseif ($saldo <= 0): ?>
                <div class="alert alert-success mt-3 mb-0 py-2 text-center"><i class="bi bi-check-circle"></i> <?= et('Liquidado') ?></div>
                <?php endif; ?>
            </div>

            <?php if ($cobrable && $saldo > 0 && has_role('admin', 'recepcion')): ?>
            <form method="post" class="card-body border-top">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="abono">
                <h6 class="mb-3"><?= et('Registrar abono') ?></h6>
                <div class="row g-2">
                    <div class="col-7">
                        <label class="form-label small"><?= et('Monto') ?> *</label>
                        <input type="number" step="0.01" min="0.01" max="<?= $saldo ?>" name="monto" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label small"><?= et('Fecha') ?></label>
                        <input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-7">
                        <label class="form-label small"><?= et('Método') ?></label>
                        <input type="text" name="metodo" class="form-control form-control-sm" list="metodosPago" placeholder="<?= et('Efectivo') ?>">
                        <datalist id="metodosPago">
                            <option value="<?= et('Efectivo') ?>"><option value="<?= et('Tarjeta') ?>">
                            <option value="<?= et('Transferencia') ?>"><option value="<?= et('Cheque') ?>">
                        </datalist>
                    </div>
                    <div class="col-5">
                        <label class="form-label small"><?= et('Referencia') ?></label>
                        <input type="text" name="referencia" class="form-control form-control-sm">
                    </div>
                </div>
                <button class="btn btn-primary btn-sm w-100 mt-3"><i class="bi bi-plus-lg"></i> <?= et('Agregar abono') ?></button>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($cobrable && $saldo > 0 && has_role('admin', 'recepcion')): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-link-45deg"></i> <?= et('Cobro en línea') ?></div>
            <div class="card-body">
                <?php if (!mp_tenant_habilitado()): ?>
                    <p class="small text-muted mb-2">
                        <?= et('Genera un link para que el paciente pague con tarjeta desde su celular.') ?>
                    </p>
                    <a href="<?= BASE_URL ?>/configuracion/index" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-gear"></i> <?= et('Configurar pago en línea') ?>
                    </a>
                <?php else: ?>
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="accion" value="cobro">
                        <div class="col-7">
                            <label class="form-label small"><?= et('Monto a cobrar') ?></label>
                            <input type="number" step="0.01" min="0.01" max="<?= $saldo ?>" name="monto"
                                   class="form-control form-control-sm" value="<?= $saldo ?>">
                        </div>
                        <div class="col-5">
                            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-link-45deg"></i> <?= et('Generar link') ?></button>
                        </div>
                    </form>
                    <?php if (mp_tenant_es_sandbox()): ?>
                    <div class="form-text text-warning mt-2"><i class="bi bi-cone-striped"></i> <?= et('Credenciales de prueba: no se cobra dinero real.') ?></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($cobros): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($cobros as $c): $url = cobro_url($c);
                    $wa = modulo_activo('whatsapp')
                        ? wa_link($pre['pac_tel'], t('Hola') . ' ' . $pre['pac_nombre'] . ', '
                            . t('puedes pagar aquí tu tratamiento') . ': ' . $url)
                        : ''; ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong><?= fmt_money($c['monto']) ?></strong>
                        <span class="badge bg-warning text-dark"><?= et('Esperando pago') ?></span>
                    </div>
                    <input type="text" class="form-control form-control-sm font-monospace mb-2"
                           value="<?= e($url) ?>" readonly onclick="this.select()">
                    <div class="d-flex gap-1">
                        <?php if ($wa): ?>
                        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-whatsapp"></i> <?= et('Enviar') ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-box-arrow-up-right"></i> <?= et('Abrir') ?>
                        </a>
                        <form method="post" class="ms-auto" onsubmit="return confirm('<?= et('¿Cancelar este link de pago?') ?>')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="accion" value="cancelar_cobro">
                            <input type="hidden" name="cobro_id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($pagos): ?>
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history"></i> <?= et('Abonos') ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($pagos as $pg): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= fmt_money($pg['monto']) ?></strong>
                        <div class="small text-muted">
                            <?= fmt_fecha($pg['fecha']) ?>
                            <?php if ($pg['metodo']): ?> · <?= e($pg['metodo']) ?><?php endif; ?>
                            <?php if ($pg['referencia']): ?> · <?= e($pg['referencia']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if (has_role('admin')): ?>
                    <form method="post" onsubmit="return confirm('<?= et('¿Eliminar este abono?') ?>')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="accion" value="borrar_abono">
                        <input type="hidden" name="pago_id" value="<?= $pg['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (has_role('admin') && !$pagos && $pre['estado'] !== 'terminado'): ?>
        <form method="post" action="<?= BASE_URL ?>/presupuestos/delete" class="mt-3 text-end"
              onsubmit="return confirm('<?= et('¿Eliminar este presupuesto? No se puede deshacer.') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> <?= et('Eliminar presupuesto') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
