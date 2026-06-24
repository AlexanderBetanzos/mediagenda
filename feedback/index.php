<?php
/**
 * Feedback de los usuarios: dejar comentarios/sugerencias para mejorar el sistema.
 * Cualquier usuario con sesión puede enviar y ver sus propios envíos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mensaje = trim($_POST['mensaje'] ?? '');
    $tipo    = in_array($_POST['tipo'] ?? '', ['sugerencia','problema','otro'], true) ? $_POST['tipo'] : 'sugerencia';
    $url     = mb_substr(trim($_POST['url'] ?? ''), 0, 255) ?: null;
    if ($mensaje === '') {
        flash(t('Escribe tu comentario.'), 'warning');
    } else {
        db()->prepare(
            'INSERT INTO feedback (consultorio_id, usuario_id, usuario_nombre, tipo, mensaje, url)
             VALUES (?,?,?,?,?,?)'
        )->execute([tenant_id(), $u['id'], $u['nombre'], $tipo, mb_substr($mensaje, 0, 2000), $url]);
        auditar('feedback', null, null, $tipo);
        flash(t('¡Gracias! Tu comentario fue enviado.'));
    }
    redirect('/feedback/index');
}

$mios = db()->prepare('SELECT * FROM feedback WHERE usuario_id = ? AND consultorio_id = ? ORDER BY id DESC LIMIT 20');
$mios->execute([$u['id'], tenant_id()]);
$mios = $mios->fetchAll();

$tipoLbl = ['sugerencia' => t('Sugerencia'), 'problema' => t('Problema'), 'otro' => t('Otro')];
$estLbl  = ['nuevo' => t('Nuevo'), 'visto' => t('Visto'), 'resuelto' => t('Resuelto')];
$estBadge = ['nuevo' => 'secondary', 'visto' => 'info', 'resuelto' => 'success'];

$titulo = t('Comentarios');
$activo = '';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-chat-left-dots text-brand"></i> <?= et('Comentarios y sugerencias') ?></h1>
<p class="text-muted"><?= et('¿Algo que mejorar o un problema? Cuéntanos, lo leemos todo.') ?></p>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="url" id="fbUrl">
                    <div class="col-sm-5">
                        <label class="form-label"><?= et('Tipo') ?></label>
                        <select name="tipo" class="form-select">
                            <?php foreach ($tipoLbl as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= et('Tu comentario') ?></label>
                        <textarea name="mensaje" class="form-control" rows="5" maxlength="2000" required
                                  placeholder="<?= et('Describe tu sugerencia o el problema con el mayor detalle posible…') ?>"></textarea>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary"><i class="bi bi-send"></i> <?= et('Enviar comentario') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white fw-semibold"><?= et('Mis comentarios') ?></div>
            <ul class="list-group list-group-flush">
                <?php if (!$mios): ?>
                    <li class="list-group-item text-muted text-center py-4"><?= et('Aún no has enviado comentarios.') ?></li>
                <?php else: foreach ($mios as $f): ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <span class="badge bg-light text-dark border"><?= e($tipoLbl[$f['tipo']]) ?></span>
                        <span class="badge bg-<?= $estBadge[$f['estado']] ?>"><?= e($estLbl[$f['estado']]) ?></span>
                    </div>
                    <div class="mt-1"><?= nl2br(e($f['mensaje'])) ?></div>
                    <div class="small text-muted mt-1"><?= fmt_fecha($f['creado_en']) ?></div>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<script>document.getElementById('fbUrl').value = document.referrer || '';</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
