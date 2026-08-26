<?php
/**
 * Videoconsulta, lado del médico. Entra con su sesión del panel.
 *
 * Abrir la sala sella `sala_abierta_en`, que es lo que el paciente ve en su
 * pantalla de espera ("tu médico ya está en la sala"): sin eso, el paciente no
 * distingue entre llegar temprano y que nadie lo vaya a atender.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('telemedicina');

$id = (int) ($_GET['id'] ?? 0);

$st = db()->prepare(
    'SELECT c.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel,
            u.nombre AS med_nombre
     FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     JOIN usuarios  u ON u.id = c.medico_id
     WHERE c.id = ? AND c.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$c = $st->fetch();
if (!$c) { flash('Cita no encontrada.', 'warning'); redirect('/citas/index'); }

if ($c['modalidad'] !== 'en_linea') {
    flash('Esta cita es presencial. Cámbiala a videoconsulta para abrir la sala.', 'warning');
    redirect('/citas/edit?id=' . $id);
}

$sala   = cita_sala($id);
$enlace = cita_sala_enlace($id);

// El médico puede abrir antes de la ventana (para probar cámara), pero se le
// avisa; al paciente sí se le respeta la ventana.
$ventana = cita_ventana_sala($c['fecha'], $c['hora'], (int) ($c['duracion'] ?: 30));

if (!$c['sala_abierta_en']) {
    db()->prepare('UPDATE citas SET sala_abierta_en = NOW() WHERE id = ? AND consultorio_id = ?')
        ->execute([$id, tenant_id()]);
    auditar('videoconsulta_abrir', 'cita', $id);
}

$paciente  = $c['pac_nombre'] . ' ' . $c['pac_ape'];
$nombre    = $c['med_nombre'];
$asunto    = t('Consulta') . ' · ' . $paciente;
$volver    = BASE_URL . '/pacientes/ver?id=' . (int) $c['paciente_id'];
$moderador = true;

$wa = modulo_activo('whatsapp')
    ? wa_link($c['pac_tel'], t('Hola') . ' ' . $c['pac_nombre'] . ', '
        . t('entra aquí a tu videoconsulta') . ': ' . $enlace)
    : '';

$titulo = t('Videoconsulta');
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><i class="bi bi-camera-video text-brand"></i> <?= et('Videoconsulta') ?></h1>
        <div class="text-muted small">
            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $c['paciente_id'] ?>" class="text-decoration-none">
                <i class="bi bi-person"></i> <?= e($paciente) ?>
            </a>
            · <?= fmt_fecha($c['fecha']) ?> <?= fmt_hora($c['hora']) ?>
            · <?= e(cita_folio($id)) ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($wa): ?>
        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
            <i class="bi bi-whatsapp"></i> <?= et('Mandar el enlace') ?>
        </a>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm" id="copiar" data-url="<?= e($enlace) ?>">
            <i class="bi bi-clipboard"></i> <?= et('Copiar enlace del paciente') ?>
        </button>
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $c['paciente_id'] ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-journal-medical"></i> <?= et('Abrir expediente') ?>
        </a>
    </div>
</div>

<?php if ($ventana === 'antes'): ?>
<div class="alert alert-info py-2">
    <i class="bi bi-clock"></i>
    <?= et('Abriste la sala antes de la hora. El paciente podrá entrar desde 15 minutos antes de') ?>
    <strong><?= fmt_hora($c['hora']) ?></strong>.
</div>
<?php elseif ($ventana === 'cerrada'): ?>
<div class="alert alert-warning py-2">
    <i class="bi bi-exclamation-triangle"></i>
    <?= et('El horario de esta cita ya pasó: al paciente su enlace ya no le abre. Reagenda si necesitan verse otra vez.') ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_jitsi.php'; ?>

<script>
document.getElementById('copiar').addEventListener('click', function (ev) {
    var b = ev.currentTarget;
    navigator.clipboard.writeText(b.dataset.url).then(function () {
        b.innerHTML = '<i class="bi bi-check-lg"></i> <?= e(t('Copiado')) ?>';
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
