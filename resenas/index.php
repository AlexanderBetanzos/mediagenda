<?php
/**
 * Reseñas de pacientes: bandeja del consultorio.
 *
 * Se puede invitar a reseñar una cita atendida, responder en público y ocultar
 * una reseña. OCULTAR ES PARA ABUSO —insultos, spam, la reseña del médico
 * equivocado— y por eso exige un motivo que queda guardado: si se usa para
 * tapar lo malo, el promedio de la página deja de decir la verdad y la sección
 * entera pierde su razón de ser. El contador de ocultas está a la vista para
 * que la decisión no sea invisible.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('admin')) { http_response_code(403); die('Solo el administrador.'); }

$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($accion === 'ocultar') {
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        if ($motivo === '') {
            flash('Escribe por qué la ocultas: queda constancia.', 'warning');
        } else {
            db()->prepare("UPDATE resenas SET estado = 'oculta', motivo_oculta = ?
                           WHERE id = ? AND consultorio_id = ?")
                ->execute([mb_substr($motivo, 0, 160), $id, tenant_id()]);
            auditar('resena_ocultar', 'resena', $id, $motivo);
            flash('Reseña oculta.', 'warning');
        }
        redirect('/resenas/index');
    }
    if ($accion === 'publicar') {
        db()->prepare("UPDATE resenas SET estado = 'publicada', motivo_oculta = NULL
                       WHERE id = ? AND consultorio_id = ? AND estrellas IS NOT NULL")
            ->execute([$id, tenant_id()]);
        auditar('resena_publicar', 'resena', $id);
        flash('Reseña publicada de nuevo.');
        redirect('/resenas/index');
    }
    if ($accion === 'responder') {
        $resp = trim((string) ($_POST['respuesta'] ?? ''));
        db()->prepare('UPDATE resenas SET respuesta = ? WHERE id = ? AND consultorio_id = ?')
            ->execute([$resp !== '' ? mb_substr($resp, 0, 600) : null, $id, tenant_id()]);
        auditar('resena_responder', 'resena', $id);
        flash('Respuesta guardada. Se ve junto a la reseña en tu página.');
        redirect('/resenas/index');
    }
    if ($accion === 'invitar') {
        $tok = resena_invitar((int) ($_POST['cita_id'] ?? 0));
        if ($tok === '') {
            flash('Esa cita no está marcada como atendida.', 'warning');
        } else {
            flash('Invitación lista. Cópiala y mándasela al paciente.');
        }
        redirect('/resenas/index');
    }
}

$resumen = resenas_resumen();

$lista = db()->prepare(
    'SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel,
            u.nombre AS med_nombre, c.fecha AS cita_fecha
     FROM resenas r
     JOIN pacientes p ON p.id = r.paciente_id
     LEFT JOIN usuarios u ON u.id = r.medico_id
     LEFT JOIN citas c    ON c.id = r.cita_id
     WHERE r.consultorio_id = ?
     ORDER BY (r.estrellas IS NULL) DESC, r.respondida_en DESC, r.id DESC'
);
$lista->execute([tenant_id()]);
$lista = $lista->fetchAll();

$ocultas = count(array_filter($lista, fn($r) => $r['estado'] === 'oculta'));

/* Citas atendidas sin invitación: es de donde salen reseñas nuevas. */
$sinInvitar = db()->prepare(
    "SELECT c.id, c.fecha, p.nombre, p.apellidos, p.telefono
     FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     LEFT JOIN resenas r ON r.cita_id = c.id
     WHERE c.consultorio_id = ? AND c.estado = 'atendida' AND r.id IS NULL
     ORDER BY c.fecha DESC LIMIT 10"
);
$sinInvitar->execute([tenant_id()]);
$sinInvitar = $sinInvitar->fetchAll();

$titulo = t('Reseñas');
$activo = 'resenas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-star-fill text-brand"></i> <?= et('Reseñas de pacientes') ?></h1>
    <?php if (micrositio_visible()): ?>
    <a href="<?= e(micrositio_url()) ?>#resenas" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-box-arrow-up-right"></i> <?= et('Verlas en mi página') ?>
    </a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Calificación') ?></div>
        <div class="stat-num mt-2"><?= $resumen['total'] ? number_format($resumen['promedio'], 1) : '—' ?></div>
        <?php if ($resumen['total']): ?>
            <div style="color:#f5a524"><?= resena_estrellas($resumen['promedio']) ?></div>
        <?php endif; ?>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Publicadas') ?></div>
        <div class="stat-num mt-2"><?= (int) $resumen['total'] ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Sin responder') ?></div>
        <div class="stat-num mt-2"><?= count(array_filter($lista, fn($r) => $r['estrellas'] === null)) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label"><?= et('Ocultas') ?></div>
        <div class="stat-num mt-2" style="color:<?= $ocultas ? '#f59e0b' : 'inherit' ?>"><?= $ocultas ?></div>
    </div></div></div>
</div>

<?php if ($ocultas): ?>
<div class="alert alert-warning py-2 small">
    <i class="bi bi-exclamation-triangle"></i>
    <?= et('Tienes reseñas ocultas. Ocultar sirve para insultos, spam o una reseña del médico equivocado. Si se usa para tapar críticas, tu promedio deja de ser cierto y los pacientes acaban notándolo.') ?>
</div>
<?php endif; ?>

<?php if ($sinInvitar): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-send text-brand"></i> <?= et('Pide opinión de estas consultas') ?>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Paciente') ?></th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($sinInvitar as $c): ?>
                <tr>
                    <td class="text-muted"><?= fmt_fecha($c['fecha']) ?></td>
                    <td><?= e($c['nombre'] . ' ' . $c['apellidos']) ?></td>
                    <td class="text-end">
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="invitar">
                            <input type="hidden" name="cita_id" value="<?= (int) $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-star"></i> <?= et('Generar enlace') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body border-top form-text">
        <?= et('Se genera un enlace único por consulta. Mándaselo por WhatsApp: el paciente califica sin crear cuenta.') ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-chat-quote text-brand"></i> <?= et('Todas las reseñas') ?></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($lista as $r):
            $enlace = resena_enlace($r['token']);
            $wa = wa_link($r['pac_tel'], t('Hola') . ' ' . $r['pac_nombre'] . ', '
                . t('nos ayudaría mucho tu opinión sobre tu última consulta') . ': ' . $enlace);
        ?>
        <li class="list-group-item">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="fw-semibold"><?= e($r['pac_nombre'] . ' ' . $r['pac_ape']) ?></span>
                        <?php if ($r['estrellas'] !== null): ?>
                            <span style="color:#f5a524"><?= resena_estrellas((float) $r['estrellas']) ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= et('Invitado, sin responder') ?></span>
                        <?php endif; ?>
                        <?php if ($r['estado'] === 'oculta'): ?>
                            <span class="badge bg-warning text-dark"><?= et('Oculta') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted">
                        <?= $r['cita_fecha'] ? fmt_fecha($r['cita_fecha']) : '—' ?>
                        <?php if ($r['med_nombre']): ?> · <?= e($r['med_nombre']) ?><?php endif; ?>
                    </div>
                    <?php if ($r['comentario']): ?>
                        <p class="mb-1 mt-2">“<?= e($r['comentario']) ?>”</p>
                    <?php endif; ?>
                    <?php if ($r['motivo_oculta']): ?>
                        <div class="small text-warning-emphasis"><?= et('Motivo') ?>: <?= e($r['motivo_oculta']) ?></div>
                    <?php endif; ?>
                    <?php if ($r['respuesta']): ?>
                        <div class="small mt-2 ps-3 border-start">
                            <span class="text-muted"><?= et('Tu respuesta') ?>:</span> <?= e($r['respuesta']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-column gap-1 text-end">
                    <?php if ($r['estrellas'] === null): ?>
                        <?php if ($wa): ?>
                        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-whatsapp"></i> <?= et('Pedir opinión') ?>
                        </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary copiar-res" data-url="<?= e($enlace) ?>">
                            <i class="bi bi-clipboard"></i> <?= et('Copiar enlace') ?>
                        </button>
                    <?php elseif ($r['estado'] === 'oculta'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="publicar">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-primary"><?= et('Volver a publicar') ?></button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="collapse" data-bs-target="#acc<?= (int) $r['id'] ?>">
                            <i class="bi bi-reply"></i> <?= et('Responder') ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($r['estrellas'] !== null && $r['estado'] !== 'oculta'): ?>
            <div class="collapse mt-3" id="acc<?= (int) $r['id'] ?>">
                <form method="post" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="responder">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <label class="form-label small"><?= et('Tu respuesta pública') ?></label>
                    <textarea name="respuesta" class="form-control form-control-sm mb-2" rows="2"
                              maxlength="600"><?= e($r['respuesta'] ?? '') ?></textarea>
                    <button class="btn btn-sm btn-primary"><?= et('Guardar respuesta') ?></button>
                </form>
                <form method="post" class="border-top pt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="ocultar">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <div class="input-group input-group-sm">
                        <input name="motivo" class="form-control" maxlength="160" required
                               placeholder="<?= e(t('Motivo para ocultarla (insultos, spam, médico equivocado…)')) ?>">
                        <button class="btn btn-outline-warning"><?= et('Ocultar') ?></button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
        <?php if (!$lista): ?>
        <li class="list-group-item text-center text-muted py-5">
            <i class="bi bi-star d-block mb-2" style="font-size:2rem;opacity:.4"></i>
            <?= et('Todavía no hay reseñas. Marca una cita como atendida y pídele su opinión al paciente.') ?>
        </li>
        <?php endif; ?>
    </ul>
</div>

<script>
document.querySelectorAll('.copiar-res').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard.writeText(b.dataset.url).then(function () {
            b.innerHTML = '<i class="bi bi-check-lg"></i> <?= e(t('Copiado')) ?>';
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
