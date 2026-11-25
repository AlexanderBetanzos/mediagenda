<?php
/**
 * Cuestionarios previos a la consulta (includes/cuestionarios.php):
 * respuestas recibidas, cuestionarios del consultorio y citas próximas a las
 * que se les puede mandar el enlace a mano.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cuestionarios.php';
require_once __DIR__ . '/../includes/ui.php';
require_login();
require_modulo('agenda_online');
asegurar_cuestionarios();

$tid = (int) tenant_id();
$pdo = db();
$esAdm = has_role('admin');

/* Crear desde plantilla, activar/desactivar, eliminar (solo admin). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdm) {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    if ($acc === 'plantilla' && isset(cuest_plantillas()[$_POST['clave'] ?? ''])) {
        $p = cuest_plantillas()[$_POST['clave']];
        $pdo->prepare('INSERT INTO cuestionarios (consultorio_id, nombre, descripcion, preguntas, aplica) VALUES (?,?,?,?,?)')
            ->execute([$tid, t($p['nombre']), t($p['descripcion']), json_encode(cuest_limpiar_preguntas($p['preguntas']), JSON_UNESCAPED_UNICODE), $p['aplica']]);
        $nid = (int) $pdo->lastInsertId();
        auditar('crear', 'cuestionario', $nid, $p['nombre']);
        flash(t('Cuestionario creado. Revísalo y ajústalo a tu consultorio.'));
        redirect('/cuestionarios/editar?id=' . $nid);
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id && pertenece_al_tenant('cuestionarios', $id)) {
        if ($acc === 'activo') {
            $pdo->prepare('UPDATE cuestionarios SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
            auditar('editar', 'cuestionario', $id, 'activar/pausar');
        } elseif ($acc === 'eliminar') {
            // Las respuestas guardan su propia copia de las preguntas: no se pierden.
            $pdo->prepare('DELETE FROM cuestionarios WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
            auditar('eliminar', 'cuestionario', $id);
            flash(t('Cuestionario eliminado. Las respuestas que ya llegaron se conservan.'));
        }
    }
    redirect('/cuestionarios/index');
}

$cuests = $pdo->prepare('SELECT q.*, u.nombre AS med_nombre,
                                (SELECT COUNT(*) FROM cuestionario_respuestas r WHERE r.cuestionario_id = q.id) AS nresp
                         FROM cuestionarios q LEFT JOIN usuarios u ON u.id = q.medico_id
                         WHERE q.consultorio_id = ? ORDER BY q.activo DESC, q.id');
$cuests->execute([$tid]);
$cuests = $cuests->fetchAll();

$soloPend = ($_GET['ver'] ?? '') !== 'todas';
$sqlR = 'SELECT r.id, r.nombre, r.respondido_en, r.revisado_en, r.copiado_en, c.fecha, c.hora, c.id AS cita_id,
                p.id AS pid, p.nombre AS pnom, p.apellidos AS pape, u.nombre AS med_nombre
         FROM cuestionario_respuestas r JOIN citas c ON c.id = r.cita_id JOIN pacientes p ON p.id = r.paciente_id
         LEFT JOIN usuarios u ON u.id = c.medico_id
         WHERE r.consultorio_id = ?' . ($soloPend ? ' AND r.revisado_en IS NULL' : '');
$pR = [$tid];
if (has_role('medico') && !$esAdm) { $sqlR .= ' AND c.medico_id = ?'; $pR[] = (int) current_user()['id']; }
$st = $pdo->prepare($sqlR . ' ORDER BY c.fecha, c.hora LIMIT 100');
$st->execute($pR);
$resps = $st->fetchAll();

/* Citas de los próximos 7 días sin responder: para mandar el enlace a mano. */
$proximas = [];
if ($cuests) {
    $st = $pdo->prepare("SELECT c.*, p.nombre AS pnom, p.apellidos AS pape, p.telefono, u.nombre AS med_nombre
                         FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id
                         LEFT JOIN cuestionario_respuestas r ON r.cita_id = c.id
                         WHERE c.consultorio_id = ? AND c.estado IN ('programada','confirmada') AND r.id IS NULL
                           AND TIMESTAMP(c.fecha, c.hora) > NOW() AND c.fecha <= ?
                         ORDER BY c.fecha, c.hora LIMIT 40");
    $st->execute([$tid, date('Y-m-d', strtotime('+7 days'))]);
    foreach ($st->fetchAll() as $c) {
        if ($q = cuest_para_cita($c)) { $c['cuest'] = $q['nombre']; $proximas[] = $c; }
    }
}
$nPend = ui_cuenta('SELECT COUNT(*) FROM cuestionario_respuestas WHERE consultorio_id = ? AND revisado_en IS NULL', [$tid]);
$aplicaTxt = ['todas' => t('Todas las citas'), 'primera' => t('Solo primera vez'), 'subsecuente' => t('Solo subsecuentes')];

$titulo = t('Cuestionarios previos');
$activo = 'cuestionarios';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et('Cuestionarios previos') ?></h1>
        <div class="text-muted small"><?= et('Tus pacientes responden antes de llegar; tú lo revisas y lo pasas al expediente con un clic.') ?></div>
    </div>
    <?php if ($esAdm): ?><a href="<?= BASE_URL ?>/cuestionarios/editar" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nuevo cuestionario') ?></a><?php endif; ?>
</div>

<?= ui_stats([
    [t('Por revisar'), $nPend, 'bi-inbox', '#f59e0b', null, $nPend > 0],
    [t('Cuestionarios activos'), count(array_filter($cuests, fn ($q) => (int) $q['activo'] === 1)), 'bi-clipboard2-pulse', 'var(--brand)'],
    [t('Citas próximas sin responder'), count($proximas), 'bi-hourglass-split', '#64748b'],
]) ?>

<?php if (!$cuests): ?>
<div class="card mb-3"><div class="card-body text-center py-5">
    <i class="bi bi-clipboard2-plus text-brand" style="font-size:2.5rem"></i>
    <h2 class="h5 mt-2"><?= et('Empieza con una plantilla') ?></h2>
    <p class="text-muted small mb-3"><?= et('Al agendar, el paciente recibe el enlace en su correo y en la página de su cita. Llenarlo le toma unos minutos.') ?></p>
    <?php if ($esAdm): ?>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        <?php foreach (cuest_plantillas() as $k => $p): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="plantilla"><input type="hidden" name="clave" value="<?= e($k) ?>">
            <button class="btn btn-outline-primary"><i class="bi bi-magic"></i> <?= e(t($p['nombre'])) ?> <span class="text-muted small">· <?= count($p['preguntas']) ?> <?= et('preguntas') ?></span></button></form>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/cuestionarios/editar" class="btn btn-light"><?= et('En blanco') ?></a>
    </div>
    <?php else: ?><p class="small text-muted mb-0"><?= et('Pídele al administrador que cree el primero.') ?></p><?php endif; ?>
</div></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-7">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-inbox"></i> <?= et('Respuestas') ?></span>
            <div class="btn-group btn-group-sm">
                <a href="?ver=pendientes" class="btn btn-<?= $soloPend ? 'primary' : 'outline-secondary' ?>"><?= et('Por revisar') ?></a>
                <a href="?ver=todas" class="btn btn-<?= $soloPend ? 'outline-secondary' : 'primary' ?>"><?= et('Todas') ?></a>
            </div>
        </div>
        <div class="list-group list-group-flush">
            <?php if (!$resps): ?><div class="list-group-item text-center text-muted small py-4"><?= $soloPend ? et('Nada por revisar.') : et('Aún no llegan respuestas.') ?></div><?php endif; ?>
            <?php foreach ($resps as $r): ?>
            <a href="<?= BASE_URL ?>/cuestionarios/ver?id=<?= (int) $r['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
                <div>
                    <div class="fw-semibold"><?= e($r['pnom'] . ' ' . $r['pape']) ?></div>
                    <div class="small text-muted"><?= e($r['nombre']) ?> · <?= et('cita') ?> <?= e(fmt_fecha($r['fecha'])) ?> <?= fmt_hora($r['hora']) ?><?= $r['med_nombre'] ? ' · ' . e($r['med_nombre']) : '' ?></div>
                </div>
                <?php if (!$r['revisado_en']): ?><span class="badge text-bg-warning"><?= et('Por revisar') ?></span>
                <?php elseif ($r['copiado_en']): ?><span class="badge text-bg-success"><?= et('En expediente') ?></span>
                <?php else: ?><span class="badge text-bg-light border"><?= et('Revisado') ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($proximas): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-send"></i> <?= et('Citas próximas sin responder') ?></div>
        <div class="card-body py-2 small text-muted"><?= et('El enlace ya va en el correo de la cita y en su recordatorio. Si el paciente no usa correo, mándaselo por WhatsApp.') ?></div>
        <div class="list-group list-group-flush">
            <?php foreach ($proximas as $c):
                $lnk = cuest_enlace(cita_token((int) $c['id']));
                $wa = wa_link($c['telefono'], sprintf(t('Hola %s, antes de tu cita del %s a las %s en %s, ¿nos ayudas a responder estas preguntas? Te toma unos minutos: %s'),
                      $c['pnom'], fmt_fecha($c['fecha']), fmt_hora($c['hora']), marca_nombre(), $lnk)); ?>
            <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="fw-semibold"><?= e($c['pnom'] . ' ' . $c['pape']) ?></div>
                    <div class="small text-muted"><?= e(fmt_fecha($c['fecha'])) ?> <?= fmt_hora($c['hora']) ?> · <?= e($c['cuest']) ?></div>
                </div>
                <div class="d-flex gap-1">
                    <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp"></i> <?= et('Enviar') ?></a><?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-copiar="<?= e($lnk) ?>"><i class="bi bi-link-45deg"></i> <?= et('Copiar enlace') ?></button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="col-lg-5">
    <div class="card">
        <div class="card-header fw-semibold"><i class="bi bi-clipboard2-pulse"></i> <?= et('Tus cuestionarios') ?></div>
        <div class="list-group list-group-flush">
            <?php if (!$cuests): ?><div class="list-group-item small text-muted"><?= et('Ninguno todavía.') ?></div><?php endif; ?>
            <?php foreach ($cuests as $q): $n = count(json_decode((string) $q['preguntas'], true) ?: []); ?>
            <div class="list-group-item">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold"><?= e($q['nombre']) ?> <?php if (!(int) $q['activo']): ?><span class="badge text-bg-secondary"><?= et('Pausado') ?></span><?php endif; ?></div>
                        <div class="small text-muted"><?= $n ?> <?= et('preguntas') ?> · <?= e($aplicaTxt[$q['aplica']] ?? $q['aplica']) ?> · <?= $q['med_nombre'] ? e($q['med_nombre']) : et('Todos los médicos') ?> · <?= (int) $q['nresp'] ?> <?= et('respuestas') ?></div>
                    </div>
                    <?php if ($esAdm): ?>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <a href="<?= BASE_URL ?>/cuestionarios/editar?id=<?= (int) $q['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= et('Editar') ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="activo"><input type="hidden" name="id" value="<?= (int) $q['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary" title="<?= (int) $q['activo'] ? et('Pausar') : et('Activar') ?>"><i class="bi bi-<?= (int) $q['activo'] ? 'pause' : 'play' ?>"></i></button></form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($esAdm && $cuests): ?>
        <div class="card-footer bg-transparent small">
            <?= et('Plantillas') ?>:
            <?php foreach (cuest_plantillas() as $k => $p): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="plantilla"><input type="hidden" name="clave" value="<?= e($k) ?>">
                <button class="btn btn-link btn-sm p-0 me-2"><?= e(t($p['nombre'])) ?></button></form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="small text-muted mt-2 px-1"><i class="bi bi-info-circle"></i> <?= et('A cada cita le toca el primer cuestionario activo que coincida: primero los del médico de la cita y luego los de todos. "Primera vez" es para pacientes sin consultas registradas.') ?></div>
</div>
</div>

<script>
document.querySelectorAll('[data-copiar]').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard.writeText(b.dataset.copiar).then(function () { var h = b.innerHTML; b.innerHTML = '<i class="bi bi-check-lg"></i> <?= e(t('Copiado')) ?>'; setTimeout(function () { b.innerHTML = h; }, 1500); });
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
