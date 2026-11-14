<?php
/**
 * Portal del paciente: su rutina de ejercicios en casa (con videos) y el
 * registro diario "hoy la hice", que el fisioterapeuta ve como apego.
 */
require_once __DIR__ . '/../includes/functions.php';
require_paciente();
ensure_fisio_tables();

$pid = (int) current_paciente()['id'];
$tid = tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $plan = (int) ($_POST['plan_id'] ?? 0);
    $ok = db()->prepare('SELECT id FROM fisio_planes WHERE id = ? AND paciente_id = ? AND consultorio_id = ? AND activo = 1 AND portal = 1');
    $ok->execute([$plan, $pid, $tid]);
    if ($ok->fetch()) {
        $fecha = ($_POST['fecha'] ?? '') === date('Y-m-d', strtotime('-1 day')) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        if (($_POST['accion'] ?? '') === 'quitar') {
            db()->prepare('DELETE FROM fisio_plan_registro WHERE plan_id = ? AND paciente_id = ? AND fecha = ?')->execute([$plan, $pid, $fecha]);
        } else {
            $dolor = ($_POST['dolor'] ?? '') !== '' ? max(0, min(10, (int) $_POST['dolor'])) : null;
            $com = mb_substr(trim((string) ($_POST['comentario'] ?? '')), 0, 255) ?: null;
            db()->prepare('INSERT INTO fisio_plan_registro (consultorio_id, plan_id, paciente_id, fecha, dolor, comentario) VALUES (?,?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE dolor = VALUES(dolor), comentario = VALUES(comentario)')
                ->execute([$tid, $plan, $pid, $fecha, $dolor, $com]);
            flash(t('¡Bien hecho! Quedó registrado.'));
        }
    }
    redirect('/portal/ejercicios');
}

$st = db()->prepare('SELECT * FROM fisio_planes WHERE paciente_id = ? AND consultorio_id = ? AND activo = 1 AND portal = 1 ORDER BY fecha DESC, id DESC');
$st->execute([$pid, $tid]);
$planes = $st->fetchAll();
$hoy = date('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day'));

$titulo = t('Mis ejercicios');
$portalActivo = 'ejercicios';
include __DIR__ . '/../includes/portal_header.php';
?>
<style>
.pe-it{display:flex;gap:.9rem;padding:.9rem 0;border-bottom:1px solid var(--bs-border-color-translucent)}
.pe-it:last-child{border-bottom:0}
.pe-n{width:1.8rem;height:1.8rem;border-radius:50%;background:var(--bs-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
.pe-img{width:120px;height:90px;object-fit:cover;border-radius:.5rem;flex:none}
.pe-d{display:inline-block;background:var(--bs-tertiary-bg);border-radius:99px;padding:.1rem .6rem;font-weight:600;font-size:.8rem;margin:.15rem .25rem .15rem 0}
.pe-dia{flex:1;text-align:center;font-size:.65rem;color:var(--bs-secondary-color)}
.pe-dia span{display:block;height:10px;border-radius:3px;margin-bottom:.2rem;background:var(--bs-secondary-bg)}
.pe-dia.on span{background:var(--bs-success)}
@media(max-width:576px){.pe-img{width:88px;height:66px}}
</style>
<h1 class="h3 mb-3"><i class="bi bi-person-walking text-brand"></i> <?= et('Mis ejercicios') ?></h1>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if (!$planes): ?>
<div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-clipboard2-check fs-1 d-block mb-2"></i><?= et('Por ahora no tienes una rutina asignada. Tu fisioterapeuta te la compartirá aquí.') ?></div></div>
<?php endif; ?>

<?php foreach ($planes as $pl):
    $items = fisio_plan_items((int) $pl['id']);
    $ap = fisio_apego((int) $pl['id']);
    $hechos = array_column($ap['registros'], 'fecha');
    $hoyHecho = in_array($hoy, $hechos, true);
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h5 mb-0"><?= e($pl['nombre']) ?></h2>
                <div class="small text-muted"><?= $pl['frecuencia'] ? e($pl['frecuencia']) : '' ?><?= $pl['objetivo'] ? ' · ' . e($pl['objetivo']) : '' ?></div>
            </div>
            <div class="text-end small"><b><?= $ap['dias'] ?></b> <?= et('de los últimos 14 días') ?></div>
        </div>
        <div class="d-flex gap-1 mt-2">
            <?php for ($d = 13; $d >= 0; $d--): $f = date('Y-m-d', strtotime("-$d days")); ?>
            <div class="pe-dia <?= in_array($f, $hechos, true) ? 'on' : '' ?>"><span></span><?= $d === 0 ? et('hoy') : date('j', strtotime($f)) ?></div>
            <?php endfor; ?>
        </div>
        <?php if ($pl['indicaciones']): ?><div class="alert alert-light border small mt-3 mb-0" style="white-space:pre-wrap"><i class="bi bi-info-circle"></i> <?= e($pl['indicaciones']) ?></div><?php endif; ?>

        <div class="mt-2">
        <?php foreach ($items as $i => $it): $yt = fisio_youtube_id($it['video_url']); $img = $it['imagen_url'] ?: ($yt ? 'https://img.youtube.com/vi/' . $yt . '/mqdefault.jpg' : ''); ?>
            <div class="pe-it">
                <div class="pe-n"><?= $i + 1 ?></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($it['nombre']) ?></div>
                    <div>
                        <?php if ($it['series'] && $it['reps']): ?><span class="pe-d"><?= (int) $it['series'] ?> × <?= e($it['reps']) ?></span><?php elseif ($it['reps']): ?><span class="pe-d"><?= e($it['reps']) ?> <?= et('rep.') ?></span><?php elseif ($it['series']): ?><span class="pe-d"><?= (int) $it['series'] ?> <?= et('series') ?></span><?php endif; ?>
                        <?php if ($it['segundos']): $s = (int) $it['segundos']; ?><span class="pe-d"><?= $s >= 120 ? round($s / 60) . ' min' : $s . ' ' . et('seg') ?></span><?php endif; ?>
                        <?php if ($it['carga']): ?><span class="pe-d"><?= e($it['carga']) ?></span><?php endif; ?>
                        <?php if ($it['lado']): ?><span class="pe-d"><?= e($it['lado']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($it['instrucciones']): ?><div class="small mt-1"><?= e($it['instrucciones']) ?></div><?php endif; ?>
                    <?php if ($it['notas']): ?><div class="small text-muted mt-1"><?= e($it['notas']) ?></div><?php endif; ?>
                    <?php if ($it['video_url']): ?><a href="<?= e($it['video_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-play-btn"></i> <?= et('Ver video') ?></a><?php endif; ?>
                </div>
                <?php if ($img): ?><a href="<?= e($it['video_url'] ?: $img) ?>" target="_blank" rel="noopener"><img class="pe-img" src="<?= e($img) ?>" alt="" loading="lazy"></a><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <div class="card-footer bg-transparent">
        <?php if ($hoyHecho): ?>
        <form method="post" class="d-flex align-items-center justify-content-between gap-2 flex-wrap m-0">
            <?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>"><input type="hidden" name="fecha" value="<?= $hoy ?>"><input type="hidden" name="accion" value="quitar">
            <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill"></i> <?= et('Hoy ya hiciste tu rutina') ?></span>
            <button class="btn btn-sm btn-link text-muted"><?= et('Deshacer') ?></button>
        </form>
        <?php else: ?>
        <form method="post" class="row g-2 align-items-end m-0">
            <?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>">
            <div class="col-6 col-md-2"><label class="form-label small mb-0"><?= et('¿Cuándo?') ?></label>
                <select name="fecha" class="form-select form-select-sm"><option value="<?= $hoy ?>"><?= et('Hoy') ?></option><?php if (!in_array($ayer, $hechos, true)): ?><option value="<?= $ayer ?>"><?= et('Ayer') ?></option><?php endif; ?></select></div>
            <div class="col-6 col-md-3"><label class="form-label small mb-0"><?= et('Dolor al terminar (0-10)') ?></label>
                <select name="dolor" class="form-select form-select-sm"><option value="">—</option><?php for ($i = 0; $i <= 10; $i++): ?><option><?= $i ?></option><?php endfor; ?></select></div>
            <div class="col-12 col-md-4"><input type="text" name="comentario" maxlength="255" class="form-control form-control-sm" placeholder="<?= e(t('Comentario para tu fisioterapeuta (opcional)')) ?>"></div>
            <div class="col-12 col-md-3"><button class="btn btn-success btn-sm w-100"><i class="bi bi-check2-circle"></i> <?= et('Hice mi rutina') ?></button></div>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<p class="small text-muted"><i class="bi bi-exclamation-triangle"></i> <?= et('Si un ejercicio te provoca dolor fuerte, mareo o adormecimiento, suspéndelo y avísanos.') ?></p>
<?php include __DIR__ . '/../includes/portal_footer.php'; ?>
