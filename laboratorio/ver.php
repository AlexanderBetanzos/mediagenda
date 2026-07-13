<?php
/**
 * Detalle de una orden de laboratorio: avanzar el estado, capturar resultados
 * y adjuntar el PDF/imagen que manda el laboratorio.
 *
 * Los archivos NO se guardan aquí: se suben al expediente del paciente
 * (guardar_archivo_expediente) y se marcan con lab_orden_id. Por eso el
 * resultado aparece solo en el expediente y en el portal del paciente.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('laboratorio');

$id = (int) ($_GET['id'] ?? 0);
$u  = current_user();

$st = db()->prepare(
    'SELECT o.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel, COALESCE(p.foto_mime, p.foto) AS pac_foto,
            p.email AS pac_email, u.nombre AS med_nombre
     FROM lab_ordenes o
     JOIN pacientes p ON p.id = o.paciente_id
     LEFT JOIN usuarios u ON u.id = o.medico_id
     WHERE o.id = ? AND o.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$o = $st->fetch();
if (!$o) { flash('Orden no encontrada.', 'warning'); redirect('/laboratorio/index'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    /* Cambio de estado. Al entregar se sella la fecha. */
    if ($accion === 'estado') {
        $nuevo = (string) ($_POST['estado'] ?? '');
        if (!isset(lab_estados()[$nuevo])) {
            flash('Estado no válido.', 'warning');
            redirect('/laboratorio/ver?id=' . $id);
        }
        db()->prepare(
            "UPDATE lab_ordenes SET estado = ?, entregada_en = IF(? = 'entregada', NOW(), entregada_en)
             WHERE id = ? AND consultorio_id = ?"
        )->execute([$nuevo, $nuevo, $id, tenant_id()]);
        auditar('lab_orden_estado', 'lab_orden', $id, $o['folio'] . ' -> ' . $nuevo);
        flash('Orden marcada como ' . mb_strtolower(lab_estado_label($nuevo)) . '.');
        redirect('/laboratorio/ver?id=' . $id);
    }

    /* Captura de resultados por estudio. */
    if ($accion === 'resultados') {
        $res   = $_POST['resultado']   ?? [];
        $fuera = $_POST['fuera_rango'] ?? [];
        $up = db()->prepare(
            'UPDATE lab_orden_items SET resultado = ?, fuera_rango = ? WHERE id = ? AND orden_id = ?'
        );
        foreach ($res as $itemId => $valor) {
            $itemId = (int) $itemId;
            $valor  = trim((string) $valor);
            $up->execute([$valor !== '' ? mb_substr($valor, 0, 160) : null,
                          isset($fuera[$itemId]) ? 1 : 0, $itemId, $id]);
        }
        // Si ya hay resultados y la orden seguía en trámite, pasa sola a "lista".
        if (in_array($o['estado'], ['solicitada', 'en_proceso'], true)) {
            $pend = db()->prepare(
                "SELECT COUNT(*) FROM lab_orden_items
                 WHERE orden_id = ? AND (resultado IS NULL OR resultado = '')"
            );
            $pend->execute([$id]);
            if ((int) $pend->fetchColumn() === 0) {
                db()->prepare("UPDATE lab_ordenes SET estado = 'lista' WHERE id = ? AND consultorio_id = ?")
                    ->execute([$id, tenant_id()]);
            }
        }
        auditar('lab_orden_resultados', 'lab_orden', $id, $o['folio']);
        flash('Resultados guardados.');
        redirect('/laboratorio/ver?id=' . $id);
    }

    /* Adjuntar el resultado que manda el laboratorio (PDF, imagen…). */
    if ($accion === 'archivo') {
        $desc = trim((string) ($_POST['descripcion'] ?? '')) ?: ('Resultado de laboratorio ' . $o['folio']);
        $r = guardar_archivo_expediente($_FILES['archivo'] ?? null, (int) $o['paciente_id'],
                                        (int) $u['id'], $desc);
        if ($r['estado'] === 'ok') {
            db()->prepare('UPDATE archivos SET lab_orden_id = ? WHERE id = ? AND consultorio_id = ?')
                ->execute([$id, (int) $r['id'], tenant_id()]);
            auditar('lab_orden_archivo', 'lab_orden', $id, $o['folio']);
            flash('Resultado adjuntado. El paciente ya puede verlo en su portal.');
        } else {
            flash($r['mensaje'], $r['estado'] === 'vacio' ? 'warning' : 'danger');
        }
        redirect('/laboratorio/ver?id=' . $id);
    }
}

$it = db()->prepare('SELECT * FROM lab_orden_items WHERE orden_id = ? ORDER BY id');
$it->execute([$id]);
$items = $it->fetchAll();

$ar = db()->prepare('SELECT * FROM archivos WHERE lab_orden_id = ? AND consultorio_id = ? ORDER BY creado_en DESC');
$ar->execute([$id, tenant_id()]);
$archivos = $ar->fetchAll();

$pendientes = 0;
foreach ($items as $i) { if (trim((string) $i['resultado']) === '') $pendientes++; }

$paciente = $o['pac_nombre'] . ' ' . $o['pac_ape'];
$wa = (modulo_activo('whatsapp') && $o['pac_tel'] && in_array($o['estado'], ['lista', 'entregada'], true))
    ? wa_link($o['pac_tel'], t('Hola') . ' ' . $o['pac_nombre'] . ', '
        . t('los resultados de tus estudios ya están listos') . ' (' . $o['folio'] . ').')
    : '';

$titulo = $o['folio'];
$activo = 'laboratorio';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/laboratorio/index"><?= et('Laboratorio') ?></a></li>
    <li class="breadcrumb-item active"><?= e($o['folio']) ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-3">
        <?= avatar_paciente((int) $o['paciente_id'], $o['pac_nombre'], $o['pac_ape'], $o['pac_foto'] ?? null, 56) ?>
        <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-eyedropper text-brand"></i> <?= e($o['folio']) ?>
            <span class="badge bg-<?= lab_estado_badge($o['estado']) ?> align-middle"><?= e(lab_estado_label($o['estado'])) ?></span>
            <?php if ($o['prioridad'] === 'urgente'): ?>
                <span class="badge bg-danger align-middle"><?= et('Urgente') ?></span>
            <?php endif; ?>
        </h1>
        <div class="text-muted small">
            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $o['paciente_id'] ?>" class="text-decoration-none">
                <i class="bi bi-person"></i> <?= e($paciente) ?>
            </a>
            · <?= fmt_fecha($o['fecha']) ?>
            <?php if ($o['med_nombre']): ?> · <?= et('Solicita') ?>: <?= e($o['med_nombre']) ?><?php endif; ?>
            <?php if ($o['proveedor']): ?> · <?= et('Laboratorio') ?>: <?= e($o['proveedor']) ?><?php endif; ?>
        </div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($wa): ?>
            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-success">
                <i class="bi bi-whatsapp"></i> <?= et('Avisar al paciente') ?>
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/laboratorio/imprimir?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-printer"></i> <?= et('Imprimir orden') ?>
        </a>
        <a href="<?= BASE_URL ?>/laboratorio/orden?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil"></i> <?= et('Editar') ?>
        </a>
    </div>
</div>

<?php /* Avance de estado: solo se ofrece el siguiente paso lógico. */ ?>
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted small me-2"><?= et('Avanzar la orden:') ?></span>
        <?php
        $siguiente = [
            'solicitada' => [['en_proceso', 'En proceso', 'info'], ['cancelada', 'Cancelar', 'outline-secondary']],
            'en_proceso' => [['lista', 'Marcar resultados listos', 'primary'], ['cancelada', 'Cancelar', 'outline-secondary']],
            'lista'      => [['entregada', 'Entregar al paciente', 'success']],
            'entregada'  => [],
            'cancelada'  => [['solicitada', 'Reabrir', 'outline-secondary']],
        ][$o['estado']] ?? [];
        ?>
        <?php foreach ($siguiente as [$clave, $lbl, $color]): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="estado">
            <input type="hidden" name="estado" value="<?= $clave ?>">
            <button class="btn btn-sm btn-<?= $color ?>"><?= et($lbl) ?></button>
        </form>
        <?php endforeach; ?>
        <?php if (!$siguiente): ?>
            <span class="text-muted small"><i class="bi bi-check-circle text-success"></i> <?= et('Orden cerrada.') ?></span>
        <?php endif; ?>
        <?php if ($o['entregada_en']): ?>
            <span class="ms-auto small text-muted"><?= et('Entregada el') ?> <?= fmt_fecha($o['entregada_en']) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="post" class="card mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="resultados">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Resultados') ?></span>
                <?php if ($pendientes): ?>
                    <span class="badge bg-warning text-dark"><?= $pendientes ?> <?= et('sin capturar') ?></span>
                <?php else: ?>
                    <span class="badge bg-success"><?= et('Completos') ?></span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr>
                        <th><?= et('Estudio') ?></th>
                        <th style="width:170px"><?= et('Resultado') ?></th>
                        <th style="width:100px"><?= et('Unidad') ?></th>
                        <th style="width:130px"><?= et('Referencia') ?></th>
                        <th style="width:110px" class="text-center"><?= et('Fuera de rango') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr class="<?= $i['fuera_rango'] ? 'table-danger' : '' ?>">
                            <td class="fw-semibold"><?= e($i['nombre']) ?></td>
                            <td>
                                <input name="resultado[<?= (int) $i['id'] ?>]" class="form-control form-control-sm"
                                       maxlength="160" value="<?= e($i['resultado'] ?? '') ?>">
                            </td>
                            <td class="small text-muted"><?= e($i['unidad'] ?: '—') ?></td>
                            <td class="small text-muted"><?= e($i['referencia'] ?: '—') ?></td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="fuera_rango[<?= (int) $i['id'] ?>]"
                                       value="1" <?= $i['fuera_rango'] ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Esta orden no tiene estudios.') ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($items): ?>
            <div class="card-body border-top d-flex justify-content-between align-items-center">
                <small class="text-muted"><?= et('Al capturar todos los resultados, la orden pasa sola a "Lista".') ?></small>
                <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= et('Guardar resultados') ?></button>
            </div>
            <?php endif; ?>
        </form>

        <?php if ($o['diagnostico'] || $o['notas']): ?>
        <div class="card mb-3">
            <div class="card-body">
                <?php if ($o['diagnostico']): ?>
                    <div class="mb-2"><span class="text-muted small"><?= et('Diagnóstico presuntivo') ?>:</span>
                        <?= e($o['diagnostico']) ?></div>
                <?php endif; ?>
                <?php if ($o['notas']): ?>
                    <div><span class="text-muted small"><?= et('Notas') ?>:</span> <?= nl2br(e($o['notas'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-paperclip text-brand"></i> <?= et('Archivos del resultado') ?></div>
            <form method="post" enctype="multipart/form-data" class="card-body border-bottom">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="archivo">
                <input type="file" name="archivo" class="form-control form-control-sm mb-2" required>
                <input name="descripcion" class="form-control form-control-sm mb-2" maxlength="255"
                       placeholder="<?= e(t('Descripción (opcional)')) ?>">
                <button class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-upload"></i> <?= et('Subir resultado') ?>
                </button>
                <div class="form-text">
                    <?= et('Máximo') ?> <?= fmt_bytes(archivo_max_bytes()) ?>.
                    <?= et('Se guarda en el expediente y el paciente lo ve en su portal.') ?>
                </div>
            </form>
            <ul class="list-group list-group-flush">
                <?php foreach ($archivos as $a): ?>
                <li class="list-group-item d-flex align-items-center gap-2">
                    <i class="bi <?= archivo_icono($a['nombre_original']) ?> text-brand fs-5"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="small fw-semibold text-truncate"><?= e($a['descripcion'] ?: $a['nombre_original']) ?></div>
                        <div class="small text-muted"><?= fmt_bytes($a['tamano']) ?> · <?= fmt_fecha($a['creado_en']) ?></div>
                    </div>
                    <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= (int) $a['id'] ?>&ver=1" target="_blank"
                       class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Ver')) ?>">
                        <i class="bi bi-eye"></i>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if (!$archivos): ?>
                    <li class="list-group-item text-muted small text-center py-3">
                        <?= et('Sin archivos todavía.') ?>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="card">
            <div class="card-body d-flex justify-content-between align-items-center">
                <span class="text-muted"><?= et('Total de la orden') ?></span>
                <span class="h5 mb-0 fw-bold"><?= fmt_money($o['total']) ?></span>
            </div>
        </div>

        <?php if (has_role('admin')): ?>
        <form method="post" action="<?= BASE_URL ?>/laboratorio/delete" class="mt-3"
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
