<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/comprobante.php';
require_once __DIR__ . '/../includes/ece.php';
require_login();
require_modulo('recetas');
asegurar_ece();

$id = (int) ($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'firmar') {
    verify_csrf();
    try {
        ece_firmar_receta($id, (int) current_user()['id'], (string) ($_POST['efirma_pass'] ?? ''));
        auditar('firmar', 'receta', $id);
        flash('Receta firmada con tu e.firma.');
    } catch (Throwable $e) { auditar('firmar_fallido', 'receta', $id, mb_substr($e->getMessage(), 0, 120)); flash($e->getMessage(), 'danger'); }
    redirect('/recetas/ver?id=' . $id);
}
$stmt = db()->prepare(
    'SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            COALESCE(p.foto_mime, p.foto) AS pac_foto, p.telefono AS pac_tel,
            u.nombre AS med_nombre, u.especialidad
     FROM recetas r
     JOIN pacientes p ON p.id = r.paciente_id
     JOIN usuarios  u ON u.id = r.medico_id
     WHERE r.id = ? AND r.consultorio_id = ?'
);
$stmt->execute([$id, tenant_id()]);
$r = $stmt->fetch();
if (!$r) { http_response_code(404); die('Receta no encontrada.'); }

$items = db()->prepare('SELECT * FROM receta_items WHERE receta_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

// Enlace público de la receta (el mismo que lleva el QR) y mensaje de WhatsApp.
$r['token'] = receta_token($id);
$urlRx = receta_url_publica($r['token']);
$msgWa = t('Hola') . ' ' . $r['pac_nombre'] . ', ' . t('aquí está tu receta de') . ' ' . marca_nombre() . ' (' . receta_folio($id) . '):' . "\n" . $urlRx;
$waUrl = wa_link($r['pac_tel'] ?? null, $msgWa) ?: wa_compartir($msgWa);

$titulo = t('Receta') . ' #' . $id;
$activo = 'recetas';
include __DIR__ . '/../includes/header.php';
?>
<?= comprobante_estilos() ?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <nav aria-label="breadcrumb" class="mb-0"><ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/recetas/index"><?= et('Recetas') ?></a></li>
        <li class="breadcrumb-item active"><?= et('Receta') ?> #<?= $id ?></li>
    </ol></nav>
    <div class="d-flex flex-wrap gap-2 justify-content-end">
        <?php if (has_role('medico', 'admin')): ?>
        <a href="<?= BASE_URL ?>/recetas/create?desde=<?= $id ?>" class="btn btn-outline-secondary" title="<?= e(t('Nueva receta con los mismos medicamentos')) ?>"><i class="bi bi-arrow-repeat"></i> <?= et('Repetir') ?></a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" id="rxCopiar" data-url="<?= e($urlRx) ?>"><i class="bi bi-link-45deg"></i> <span><?= et('Copiar enlace') ?></span></button>
        <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="btn btn-success"><i class="bi bi-whatsapp"></i> <?= et('Enviar por WhatsApp') ?></a>
        <?php $miEf = ece_efirma((int) current_user()['id']);
        if (empty($r['firma']) && (int) $r['medico_id'] === (int) current_user()['id']): ?>
            <?php if ($miEf): ?><button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#mFirmaRx"><i class="bi bi-pen"></i> <?= et('Firmar con e.firma') ?></button>
            <?php else: ?><a href="<?= BASE_URL ?>/usuarios/efirma" class="btn btn-outline-secondary"><i class="bi bi-pen"></i> <?= et('Configura tu e.firma') ?></a><?php endif; ?>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
    </div>
</div>
<?php if (!empty($miEf) && empty($r['firma'])): ?>
<div class="modal fade no-print" id="mFirmaRx" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><form method="post" class="modal-content" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="accion" value="firmar">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pen"></i> <?= et('Firmar receta') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="small text-muted"><?= et('Se firmará con tu e.firma, certificado') ?> <code><?= e($miEf['no_cert']) ?></code>.</p>
        <label class="form-label"><?= et('Contraseña de tu e.firma') ?></label><input type="password" name="efirma_pass" class="form-control" required autocomplete="new-password"></div>
    <div class="modal-footer"><button class="btn btn-success w-100"><?= et('Firmar') ?></button></div>
</form></div></div>
<?php endif; ?>
<script>
document.getElementById('rxCopiar').addEventListener('click', function () {
    var b = this, s = b.querySelector('span'), t = s.textContent;
    navigator.clipboard.writeText(b.dataset.url).then(function () {
        s.textContent = <?= json_encode(t('¡Copiado!'), JSON_UNESCAPED_UNICODE) ?>;
        setTimeout(function () { s.textContent = t; }, 1600);
    });
});
</script>

<?= receta_documento($r, $items) ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
