<?php
/** Una respuesta de cuestionario previo: revisar y pasar al expediente. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cuestionarios.php';
require_login();
require_modulo('agenda_online');
asegurar_cuestionarios();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$st = db()->prepare('SELECT r.*, c.fecha, c.hora, c.motivo, c.medico_id, c.token, p.nombre AS pnom, p.apellidos AS pape, p.fecha_nacimiento,
                            u.nombre AS med_nombre, rv.nombre AS rev_nombre
                     FROM cuestionario_respuestas r JOIN citas c ON c.id = r.cita_id JOIN pacientes p ON p.id = r.paciente_id
                     LEFT JOIN usuarios u ON u.id = c.medico_id LEFT JOIN usuarios rv ON rv.id = r.revisado_por
                     WHERE r.id = ? AND r.consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$r = $st->fetch();
if (!$r) { flash(t('Esa respuesta no existe.'), 'warning'); redirect('/cuestionarios/index'); }
$r['respuestas'] = json_decode((string) $r['respuestas'], true) ?: [];
$puedeCopiar = has_role('admin', 'medico');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    if ($acc === 'copiar' && $puedeCopiar) {
        $n = cuest_copiar_al_expediente($r);
        auditar('editar', 'paciente', (int) $r['paciente_id'], 'Cuestionario previo pasado al expediente (' . $n . ' campos)');
        flash($n ? sprintf(t('Listo: %d campos del expediente actualizados. Lo anterior se conserva.'), $n) : t('Ninguna respuesta tenía un campo del expediente asignado.'), $n ? 'success' : 'info');
    } elseif ($acc === 'revisado') {
        db()->prepare('UPDATE cuestionario_respuestas SET revisado_en = NOW(), revisado_por = ? WHERE id = ? AND consultorio_id = ? AND revisado_en IS NULL')
            ->execute([(int) current_user()['id'], $id, tenant_id()]);
        auditar('revisar', 'cuestionario_respuesta', $id, $r['nombre']);
        flash(t('Marcado como revisado.'));
    }
    redirect('/cuestionarios/ver?id=' . $id);
}

$campos = cuest_campos();
$hayCampos = (bool) array_filter($r['respuestas'], fn ($a) => ($a['campo'] ?? '') !== '' && cuest_valor_txt($a['valor'] ?? '') !== '');

$titulo = t('Cuestionario de') . ' ' . $r['pnom'];
$activo = 'cuestionarios';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/cuestionarios/index"><?= et('Cuestionarios previos') ?></a></li>
    <li class="breadcrumb-item active"><?= e($r['pnom'] . ' ' . $r['pape']) ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?= e($r['nombre']) ?></h1>
        <div class="text-muted small">
            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $r['paciente_id'] ?>"><?= e($r['pnom'] . ' ' . $r['pape']) ?></a>
            · <?= et('cita') ?> <?= e(fmt_fecha($r['fecha'])) ?> <?= fmt_hora($r['hora']) ?><?= $r['med_nombre'] ? ' · ' . e($r['med_nombre']) : '' ?>
            · <?= et('respondido') ?> <?= e(fmt_fecha($r['respondido_en'])) ?> <?= e(date('H:i', strtotime($r['respondido_en']))) ?>
        </div>
        <?php if ($r['revisado_en']): ?><div class="small text-success mt-1"><i class="bi bi-check2-circle"></i> <?= et('Revisado') ?> <?= e(fmt_fecha($r['revisado_en'])) ?><?= $r['rev_nombre'] ? ' · ' . e($r['rev_nombre']) : '' ?><?= $r['copiado_en'] ? ' · ' . et('pasado al expediente') : '' ?></div><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($puedeCopiar && $hayCampos): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="copiar">
            <button class="btn btn-primary" <?= $r['copiado_en'] ? 'onclick="return confirm(\'' . e(t('Ya lo pasaste una vez. ¿Agregarlo otra vez?')) . '\')"' : '' ?>><i class="bi bi-box-arrow-in-right"></i> <?= et('Pasar al expediente') ?></button></form>
        <?php endif; ?>
        <?php if (!$r['revisado_en']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="revisado">
            <button class="btn btn-outline-success"><i class="bi bi-check2"></i> <?= et('Marcar como revisado') ?></button></form>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $r['paciente_id'] ?>" class="btn btn-light"><i class="bi bi-person"></i> <?= et('Ficha') ?></a>
    </div>
</div>

<div class="card" style="max-width:860px">
    <div class="list-group list-group-flush">
    <?php foreach ($r['respuestas'] as $i => $a): $v = cuest_valor_txt($a['valor'] ?? ''); ?>
        <div class="list-group-item py-3">
            <div class="d-flex justify-content-between gap-2">
                <div class="small text-muted"><?= $i + 1 ?>. <?= e($a['texto']) ?></div>
                <?php if (!empty($a['campo'])): ?><span class="badge text-bg-light border flex-shrink-0"><i class="bi bi-arrow-right-short"></i><?= e(t($campos[$a['campo']] ?? $a['campo'])) ?></span><?php endif; ?>
            </div>
            <div class="mt-1 <?= $v === '' ? 'text-muted fst-italic' : 'fw-semibold' ?>" style="white-space:pre-wrap"><?= $v === '' ? et('Sin respuesta') : e($v) ?><?= ($a['tipo'] ?? '') === 'escala' && $v !== '' ? ' / 10' : '' ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<p class="small text-muted mt-2" style="max-width:860px"><i class="bi bi-info-circle"></i> <?= et('Lo escribió el paciente. "Pasar al expediente" agrega las respuestas marcadas a esos campos de la ficha con la fecha del cuestionario, sin borrar lo que ya había.') ?></p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
