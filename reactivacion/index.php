<?php
/**
 * Reactivación de pacientes — lista de pacientes que ya asistieron pero no
 * tienen visita (cita atendida o consulta) en los últimos N meses y no tienen
 * cita futura agendada. Incluye acciones para contactarlos y reagendar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('crm');

$u        = current_user();
$esMedico = $u['rol'] === 'medico';
$tid      = (int) tenant_id();
$medCitas = $esMedico ? ' AND estado=\'atendida\' AND medico_id = ' . (int) $u['id'] : ' AND estado=\'atendida\'';
$medCons  = $esMedico ? ' AND medico_id = ' . (int) $u['id'] : '';

/* Ventana de inactividad (meses). */
$meses = (int) ($_GET['meses'] ?? 6);
if (!in_array($meses, [3, 6, 12], true)) $meses = 6;
$corte = date('Y-m-d', strtotime("-$meses months"));

$pdo = db();
$st = $pdo->prepare(
    "SELECT p.id, p.nombre, p.apellidos, p.telefono, p.email, p.foto,
            GREATEST(COALESCE(uc.f,'1900-01-01'), COALESCE(uco.f,'1900-01-01')) AS ultima
     FROM pacientes p
     LEFT JOIN (SELECT paciente_id, MAX(fecha) f FROM citas WHERE consultorio_id = ? $medCitas GROUP BY paciente_id) uc ON uc.paciente_id = p.id
     LEFT JOIN (SELECT paciente_id, MAX(DATE(fecha)) f FROM consultas WHERE consultorio_id = ? $medCons GROUP BY paciente_id) uco ON uco.paciente_id = p.id
     WHERE p.consultorio_id = ?
       AND (uc.f IS NOT NULL OR uco.f IS NOT NULL)
       AND GREATEST(COALESCE(uc.f,'1900-01-01'), COALESCE(uco.f,'1900-01-01')) < ?
       AND NOT EXISTS (
           SELECT 1 FROM citas cf WHERE cf.paciente_id = p.id AND cf.consultorio_id = ?
             AND cf.fecha >= CURDATE() AND cf.estado IN ('programada','confirmada')
       )
     ORDER BY ultima ASC
     LIMIT 300"
);
$st->execute([$tid, $tid, $tid, $corte, $tid]);
$pacientes = $st->fetchAll();

$titulo = t('Reactivación');
$activo = 'reactivacion';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-arrow-repeat text-brand"></i> <?= et('Reactivación de pacientes') ?></h1>
    <div class="btn-group btn-group-sm" role="group">
        <?php foreach ([3, 6, 12] as $m): ?>
            <a href="<?= BASE_URL ?>/reactivacion/index?meses=<?= $m ?>" class="btn <?= $meses === $m ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $m ?> <?= et('meses') ?></a>
        <?php endforeach; ?>
    </div>
</div>

<p class="text-muted mb-3">
    <i class="bi bi-info-circle"></i>
    <?= et('Pacientes con última visita hace más de') ?> <strong><?= $meses ?> <?= et('meses') ?></strong>
    <?= et('y sin cita futura.') ?> · <strong><?= count($pacientes) ?></strong> <?= et('encontrados') ?>
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Paciente') ?></th><th><?= et('Última visita') ?></th><th><?= et('Inactivo') ?></th><th><?= et('Teléfono') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
            <tbody>
            <?php if (!$pacientes): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-4 d-block mb-1"></i><?= et('Ningún paciente inactivo en este periodo. ¡Bien!') ?></td></tr>
            <?php else: foreach ($pacientes as $p):
                $dias  = (int) floor((time() - strtotime($p['ultima'])) / 86400);
                $mesesInact = (int) floor($dias / 30);
                $msg   = t('Hola') . ' ' . $p['nombre'] . ', ' . t('te escribimos de') . ' ' . marca_nombre() . '. ' . t('Queremos saber cómo sigues y agendar tu próxima consulta.');
                $wa    = modulo_activo('whatsapp') ? wa_link($p['telefono'], $msg) : '';
            ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $p['id'], $p['nombre'], $p['apellidos'], $p['foto'] ?? null, 36) ?>
                            <div>
                                <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $p['id'] ?>" class="fw-semibold text-decoration-none"><?= e($p['nombre'] . ' ' . $p['apellidos']) ?></a>
                                <?php if ($p['email']): ?><br><span class="small text-muted"><?= e($p['email']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="small"><?= fmt_fecha($p['ultima']) ?></td>
                    <td><span class="badge bg-warning bg-opacity-25 text-warning"><?= $mesesInact ?> <?= et('meses') ?></span></td>
                    <td class="small text-muted"><?= e($p['telefono'] ?: '—') ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-success py-0" title="WhatsApp"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
                            <?php if ($p['telefono']): ?><a href="tel:<?= e(preg_replace('/\s+/', '', $p['telefono'])) ?>" class="btn btn-outline-secondary py-0" title="<?= e(t('Llamar')) ?>"><i class="bi bi-telephone"></i></a><?php endif; ?>
                            <a href="<?= BASE_URL ?>/citas/create?paciente_id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary py-0" title="<?= e(t('Agendar')) ?>"><i class="bi bi-calendar-plus"></i></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
