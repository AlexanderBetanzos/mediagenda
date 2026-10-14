<?php
/** Vista imprimible de una receta para el paciente (portal). */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/comprobante.php';
require_paciente();

$pid = current_paciente()['id'];
$id  = (int) ($_GET['id'] ?? 0);

// Solo recetas del propio paciente y su consultorio.
$st = db()->prepare(
    'SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
            u.nombre AS med_nombre, u.especialidad
     FROM recetas r
     JOIN pacientes p ON p.id = r.paciente_id
     JOIN usuarios  u ON u.id = r.medico_id
     WHERE r.id = ? AND r.paciente_id = ? AND r.consultorio_id = ?'
);
$st->execute([$id, $pid, tenant_id()]);
$r = $st->fetch();
if (!$r) { http_response_code(404); die('Receta no encontrada.'); }

$items = db()->prepare('SELECT * FROM receta_items WHERE receta_id = ?');
$items->execute([$id]);
$items = $items->fetchAll();

$titulo = 'Receta R-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
$portalActivo = 'recetas';
include __DIR__ . '/../includes/portal_header.php';
?>
<?= comprobante_estilos() ?>
<style>@media print{.pt-top,.pt-tabbar,.pt-pie{display:none!important}}</style>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <a href="<?= BASE_URL ?>/portal/recetas" class="btn btn-link px-0"><i class="bi bi-arrow-left"></i> <?= et('Mis recetas') ?></a>
    <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir / Guardar PDF') ?></button>
</div>

<?= receta_documento($r, $items, false) ?>
<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
