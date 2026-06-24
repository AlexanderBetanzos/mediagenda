<?php
/** Bandeja de feedback (súper-admin): todos los comentarios de todos los consultorios. */
require_once __DIR__ . '/../includes/functions.php';
require_superadmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    if ($id && in_array($accion, ['visto', 'resuelto', 'nuevo'], true)) {
        db()->prepare('UPDATE feedback SET estado = ? WHERE id = ?')->execute([$accion, $id]);
    } elseif ($id && $accion === 'borrar') {
        db()->prepare('DELETE FROM feedback WHERE id = ?')->execute([$id]);
    }
    redirect('/admin/feedback' . (!empty($_POST['estado_f']) ? '?estado=' . urlencode($_POST['estado_f']) : ''));
}

$estado = $_GET['estado'] ?? '';
$where  = [];
$params = [];
if (in_array($estado, ['nuevo','visto','resuelto'], true)) { $where[] = 'f.estado = ?'; $params[] = $estado; }
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$filas = db()->prepare(
    "SELECT f.*, c.nombre AS consultorio FROM feedback f
     LEFT JOIN consultorios c ON c.id = f.consultorio_id
     $sqlWhere ORDER BY (f.estado='nuevo') DESC, f.id DESC LIMIT 200"
);
$filas->execute($params);
$filas = $filas->fetchAll();

$nNuevos = (int) db()->query("SELECT COUNT(*) FROM feedback WHERE estado='nuevo'")->fetchColumn();
$tipoLbl = ['sugerencia' => t('Sugerencia'), 'problema' => t('Problema'), 'otro' => t('Otro')];
$estLbl  = ['nuevo' => t('Nuevo'), 'visto' => t('Visto'), 'resuelto' => t('Resuelto')];
$estBadge = ['nuevo' => 'secondary', 'visto' => 'info', 'resuelto' => 'success'];
$tipoBadge = ['sugerencia' => 'primary', 'problema' => 'danger', 'otro' => 'secondary'];

$titulo = t('Comentarios');
$activo = 'admin';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-chat-left-dots text-brand"></i> <?= et('Comentarios') ?>
        <?php if ($nNuevos): ?><span class="badge bg-danger align-middle"><?= $nNuevos ?> <?= et('nuevos') ?></span><?php endif; ?>
    </h1>
    <div class="btn-group">
        <?php foreach (['' => t('Todos'), 'nuevo' => t('Nuevo'), 'visto' => t('Visto'), 'resuelto' => t('Resuelto')] as $v => $l): ?>
            <a href="?estado=<?= $v ?>" class="btn btn-sm <?= $estado === $v ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= e($l) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <ul class="list-group list-group-flush">
        <?php if (!$filas): ?>
            <li class="list-group-item text-muted text-center py-4"><?= et('Sin comentarios.') ?></li>
        <?php else: foreach ($filas as $f): ?>
        <li class="list-group-item">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <span class="badge bg-<?= $tipoBadge[$f['tipo']] ?>"><?= e($tipoLbl[$f['tipo']]) ?></span>
                    <span class="badge bg-<?= $estBadge[$f['estado']] ?>"><?= e($estLbl[$f['estado']]) ?></span>
                    <span class="small text-muted ms-1"><?= e($f['usuario_nombre'] ?: '—') ?> · <?= e($f['consultorio'] ?? ('#'.$f['consultorio_id'])) ?> · <?= fmt_fecha($f['creado_en']) ?></span>
                </div>
                <form method="post" class="d-flex gap-1 m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                    <input type="hidden" name="estado_f" value="<?= e($estado) ?>">
                    <?php if ($f['estado'] !== 'visto'): ?><button name="accion" value="visto" class="btn btn-sm btn-outline-info"><?= et('Visto') ?></button><?php endif; ?>
                    <?php if ($f['estado'] !== 'resuelto'): ?><button name="accion" value="resuelto" class="btn btn-sm btn-outline-success"><?= et('Resuelto') ?></button><?php endif; ?>
                    <button name="accion" value="borrar" class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este comentario?');"><i class="bi bi-trash"></i></button>
                </form>
            </div>
            <div class="mt-2"><?= nl2br(e($f['mensaje'])) ?></div>
            <?php if ($f['url']): ?><div class="small text-muted mt-1"><i class="bi bi-link-45deg"></i> <?= e($f['url']) ?></div><?php endif; ?>
        </li>
        <?php endforeach; endif; ?>
    </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
