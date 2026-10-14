<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/comprobante.php';
require_login();
require_modulo('recetas');

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            COALESCE(p.foto_mime, p.foto) AS pac_foto,
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
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
</div>

<?= receta_documento($r, $items) ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
