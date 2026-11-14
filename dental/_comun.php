<?php
/**
 * Arranque común del expediente dental: sesión, plan, rol, tablas y el
 * paciente del consultorio activo ($pid, $pac, $pacNombre).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dental.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo odontólogo o admin.'); }
ensure_dental_tables();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));
$P = '?paciente_id=' . $pid;

/** Encabezado común de las pantallas del expediente dental. */
function dental_encabezado(string $titulo, string $icono, array $pac, int $pid, string $activa = ''): void
{
    $tabs = [
        'index'     => ['/dental/index', 'bi-grid-1x2', 'Resumen'],
        'carta'     => ['/dental/carta', 'bi-emoji-smile', 'Carta dental'],
        'historia'  => ['/dental/historia', 'bi-clipboard2-pulse', 'Historia clínica'],
        'fotos'     => ['/dental/fotos', 'bi-images', 'Fotos'],
        'perio'     => ['/dental/perio', 'bi-rulers', 'Periodontograma'],
        'evolucion' => ['/dental/evolucion', 'bi-journal-medical', 'Evolución'],
    ];
    $nom = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));
    ?>
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <?= avatar_paciente($pid, $pac['nombre'], $pac['apellidos'] ?? '', $pac['foto'] ?? null, 40) ?>
            <div>
                <h1 class="h5 mb-0"><i class="bi <?= $icono ?> text-brand"></i> <?= et($titulo) ?></h1>
                <div class="small text-muted"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="text-reset"><?= e($nom) ?></a>
                    <?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
    </div>
    <ul class="nav nav-pills dx-tabs mb-3 flex-nowrap overflow-auto">
        <?php foreach ($tabs as $k => [$url, $ic, $lbl]): ?>
        <li class="nav-item"><a class="nav-link <?= $activa === $k ? 'active' : '' ?>" href="<?= BASE_URL . $url ?>?paciente_id=<?= $pid ?>"><i class="bi <?= $ic ?>"></i> <?= et($lbl) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <style>.dx-tabs .nav-link{white-space:nowrap;font-size:.88rem;padding:.4rem .8rem}</style>
    <?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach;
}
