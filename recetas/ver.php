<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/comprobante.php';
require_login();
require_modulo('recetas');

$id = (int) ($_GET['id'] ?? 0);
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
        <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
    </div>
</div>
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
