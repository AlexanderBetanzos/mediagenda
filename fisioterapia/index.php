<?php
/**
 * Fisioterapia del paciente: tablero con su diagnóstico vigente, avance de
 * sesiones, evolución del dolor (EVA) y apego al plan en casa; abajo, las
 * notas de evolución SOAP, las valoraciones y los planes de ejercicio.
 */
require __DIR__ . '/_comun.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $tid = tenant_id();
    $tab = 'notas';
    if ($accion === 'del_nota') {
        db()->prepare('DELETE FROM fisio_notas WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')->execute([$id, $pid, $tid]);
        auditar('eliminar', 'fisio_nota', $id, 'Paciente #' . $pid);
        flash('Nota eliminada.');
    } elseif ($accion === 'del_val') {
        db()->prepare('DELETE FROM fisio_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')->execute([$id, $pid, $tid]);
        auditar('eliminar', 'fisio_valoracion', $id, 'Paciente #' . $pid);
        flash('Valoración eliminada.');
        $tab = 'valoraciones';
    } elseif ($accion === 'del_plan' || $accion === 'toggle_plan') {
        $ok = db()->prepare('SELECT id FROM fisio_planes WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
        $ok->execute([$id, $pid, $tid]);
        if ($ok->fetch()) {
            if ($accion === 'del_plan') {
                db()->prepare('DELETE FROM fisio_plan_items WHERE plan_id = ?')->execute([$id]);
                db()->prepare('DELETE FROM fisio_plan_registro WHERE plan_id = ?')->execute([$id]);
                db()->prepare('DELETE FROM fisio_planes WHERE id = ?')->execute([$id]);
                auditar('eliminar', 'fisio_plan', $id, 'Paciente #' . $pid);
                flash('Plan eliminado.');
            } else {
                db()->prepare('UPDATE fisio_planes SET activo = 1 - activo WHERE id = ?')->execute([$id]);
                flash('Plan actualizado.');
            }
        }
        $tab = 'planes';
    }
    redirect('/fisioterapia/index?paciente_id=' . $pid . '&tab=' . $tab);
}

$q = function (string $sql) use ($pid) {
    $st = db()->prepare($sql);
    $st->execute([$pid, tenant_id()]);
    return $st->fetchAll();
};
$vals   = $q('SELECT * FROM fisio_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$notas  = $q('SELECT * FROM fisio_notas WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$planes = $q('SELECT p.*, (SELECT COUNT(*) FROM fisio_plan_items i WHERE i.plan_id = p.id) AS n_items
              FROM fisio_planes p WHERE p.paciente_id = ? AND p.consultorio_id = ? ORDER BY p.activo DESC, p.fecha DESC, p.id DESC');

/* Episodio vigente: la última valoración inicial y lo que vino después. */
$inicial = null;
foreach ($vals as $v) if ($v['tipo'] === 'inicial') $inicial = $v;
$ultimaVal = $vals ? $vals[count($vals) - 1] : null;
$dePeriodo = fn ($f) => !$inicial || $f >= $inicial['fecha'];
$sesEpisodio = count(array_filter($notas, fn ($n) => $dePeriodo($n['fecha'])));
$estimadas = $inicial ? (int) $inicial['sesiones_est'] : 0;
foreach ($vals as $v) if ($dePeriodo($v['fecha']) && (int) $v['sesiones_est'] > 0) $estimadas = (int) $v['sesiones_est'];
$enAlta = $ultimaVal && $ultimaVal['tipo'] === 'alta';

/* Serie de dolor: valoraciones (EVA) y sesiones (antes / después). */
$serie = [];
foreach ($vals as $v)  if ($v['eva'] !== null) $serie[] = [$v['fecha'], 'v', (int) $v['eva'], null];
foreach ($notas as $n) if ($n['eva_pre'] !== null || $n['eva_post'] !== null)
    $serie[] = [$n['fecha'], 'n', $n['eva_pre'] !== null ? (int) $n['eva_pre'] : null, $n['eva_post'] !== null ? (int) $n['eva_post'] : null];
usort($serie, fn ($a, $b) => strcmp($a[0], $b[0]));
$gL = $gPre = $gPost = [];
foreach ($serie as [$f, , $pre, $post]) { $gL[] = date('d/m', strtotime($f)); $gPre[] = $pre; $gPost[] = $post; }
$evaIni = $serie ? $serie[0][2] : null;
$evaAct = null;
for ($i = count($serie) - 1; $i >= 0 && $evaAct === null; $i--) $evaAct = $serie[$i][3] ?? $serie[$i][2];

$planActivo = null;
foreach ($planes as $pl) if ($pl['activo']) { $planActivo = $pl; break; }
$apego = $planActivo ? fisio_apego((int) $planActivo['id']) : null;

$tab = $_GET['tab'] ?? 'notas';
$titulo = t('Fisioterapia');
$activo = 'pacientes';
$P = '?paciente_id=' . $pid;
include __DIR__ . '/../includes/header.php';
?>
<style>
.fs-kpi{border:1px solid var(--bs-border-color);border-radius:.75rem;padding:.9rem 1rem;height:100%}
.fs-kpi .t{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--bs-secondary-color);font-weight:600}
.fs-kpi .v{font-size:1.35rem;font-weight:700;line-height:1.2}
.fs-soap dt{font-weight:700;color:var(--bs-secondary-color);width:1.4rem;float:left;clear:left}
.fs-soap dd{margin-left:1.6rem;margin-bottom:.25rem;white-space:pre-wrap}
.fs-chip{display:inline-block;font-size:.72rem;padding:.12rem .5rem;border-radius:99px;background:var(--bs-secondary-bg);margin:0 .2rem .2rem 0}
</style>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-person-walking text-brand"></i> <?= et('Fisioterapia') ?></h1>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/fisioterapia/ejercicios" class="btn btn-sm btn-light"><i class="bi bi-collection-play"></i> <?= et('Biblioteca de ejercicios') ?></a>
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
    </div>
</div>
<p class="text-muted mb-3"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong>
    <?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?>
    <?php if ($enAlta): ?> · <span class="badge bg-success"><?= et('De alta') ?></span><?php endif; ?></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<div class="d-flex gap-2 flex-wrap mb-4">
    <a href="<?= BASE_URL ?>/fisioterapia/nota<?= $P ?>" class="btn btn-primary"><i class="bi bi-journal-plus"></i> <?= et('Nota de evolución') ?></a>
    <a href="<?= BASE_URL ?>/fisioterapia/valoracion<?= $P ?><?= $inicial && !$enAlta ? '&tipo=reevaluacion' : '' ?>" class="btn btn-outline-primary"><i class="bi bi-clipboard2-pulse"></i> <?= $inicial && !$enAlta ? et('Reevaluación') : et('Historia clínica') ?></a>
    <a href="<?= BASE_URL ?>/fisioterapia/plan<?= $P ?>" class="btn btn-outline-primary"><i class="bi bi-list-check"></i> <?= et('Plan de ejercicios') ?></a>
    <?php if ($vals || $notas): ?>
    <a href="<?= BASE_URL ?>/fisioterapia/imprimir<?= $P ?>&modo=informe" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-medical"></i> <?= et('Informe de evolución') ?></a>
    <?php endif; ?>
    <?php if ($inicial && !$enAlta): ?>
    <a href="<?= BASE_URL ?>/fisioterapia/valoracion<?= $P ?>&tipo=alta" class="btn btn-outline-success"><i class="bi bi-check2-circle"></i> <?= et('Dar de alta') ?></a>
    <?php endif; ?>
</div>

<?php if (!$vals && !$notas && !$planes): ?>
<div class="card mb-4"><div class="card-body text-center py-5">
    <i class="bi bi-clipboard2-pulse text-brand" style="font-size:2.5rem"></i>
    <h2 class="h5 mt-2"><?= et('Empieza con la historia clínica') ?></h2>
    <p class="text-muted mb-3"><?= et('Registra la valoración inicial: dolor, goniometría, fuerza, pruebas especiales y escalas. Después cada sesión se documenta en una nota SOAP.') ?></p>
    <a href="<?= BASE_URL ?>/fisioterapia/valoracion<?= $P ?>" class="btn btn-primary"><i class="bi bi-clipboard2-pulse"></i> <?= et('Valoración inicial') ?></a>
</div></div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-5"><div class="fs-kpi">
        <div class="t"><?= et('Diagnóstico fisioterapéutico') ?></div>
        <?php $dxV = $ultimaVal['dx_fisio'] ?? ($inicial['dx_fisio'] ?? ''); ?>
        <div class="fw-semibold mt-1"><?= $dxV ? e(mb_strimwidth($dxV, 0, 160, '…')) : '<span class="text-muted">' . et('Sin valoración') . '</span>' ?></div>
        <?php if ($inicial): ?><div class="small text-muted mt-1"><?= e(trim(($inicial['region'] ?? '') . ' ' . ($inicial['lado'] && $inicial['lado'] !== 'N/A' ? '· ' . $inicial['lado'] : ''))) ?><?= $inicial['dx_medico'] ? ' · ' . e($inicial['dx_medico']) : '' ?></div><?php endif; ?>
    </div></div>
    <div class="col-6 col-lg-2"><div class="fs-kpi">
        <div class="t"><?= et('Sesiones') ?></div>
        <div class="v"><?= $sesEpisodio ?><?php if ($estimadas): ?><span class="text-muted fs-6"> / <?= $estimadas ?></span><?php endif; ?></div>
        <?php if ($estimadas): $pc = min(100, (int) round($sesEpisodio * 100 / $estimadas)); ?>
        <div class="progress mt-2" style="height:6px"><div class="progress-bar" style="width:<?= $pc ?>%"></div></div>
        <?php endif; ?>
    </div></div>
    <div class="col-6 col-lg-2"><div class="fs-kpi">
        <div class="t"><?= et('Dolor (EVA)') ?></div>
        <?php if ($evaAct !== null): ?>
        <div class="v"><?php if ($evaIni !== null && $evaIni !== $evaAct): ?><span class="text-muted fs-6"><?= $evaIni ?> →</span> <?php endif; ?><span class="text-<?= fisio_eva_color($evaAct) ?>"><?= $evaAct ?></span><span class="text-muted fs-6">/10</span></div>
        <?php if ($evaIni !== null && $evaIni > 0): $mej = (int) round(($evaIni - $evaAct) * 100 / $evaIni); ?>
        <div class="small <?= $mej >= 30 ? 'text-success' : 'text-muted' ?>"><?= $mej >= 0 ? sprintf(t('%d%% menos dolor'), $mej) : sprintf(t('%d%% más dolor'), -$mej) ?></div>
        <?php endif; else: ?><div class="v text-muted">—</div><?php endif; ?>
    </div></div>
    <div class="col-12 col-lg-3"><div class="fs-kpi">
        <div class="t"><?= et('Ejercicio en casa (14 días)') ?></div>
        <?php if ($apego): $pc = (int) round($apego['dias'] * 100 / $apego['de']); ?>
        <div class="v"><?= $apego['dias'] ?> <span class="text-muted fs-6"><?= et('días') ?> · <?= $pc ?>%</span></div>
        <div class="d-flex gap-1 mt-2" title="<?= e(t('Días en que el paciente marcó su rutina en el portal')) ?>">
            <?php $hechos = array_column($apego['registros'], 'fecha');
            for ($d = 13; $d >= 0; $d--): $f = date('Y-m-d', strtotime("-$d days")); ?>
            <span style="flex:1;height:8px;border-radius:2px;background:<?= in_array($f, $hechos, true) ? 'var(--bs-success)' : 'var(--bs-secondary-bg)' ?>"></span>
            <?php endfor; ?>
        </div>
        <?php $ultCom = null; foreach (array_reverse($apego['registros']) as $rg) if (!$ultCom && $rg['comentario']) $ultCom = $rg; ?>
        <?php if ($ultCom): ?><div class="small text-muted mt-2 text-truncate" title="<?= e($ultCom['comentario']) ?>"><i class="bi bi-chat-left-quote"></i> <?= e(fmt_fecha($ultCom['fecha'])) ?><?= $ultCom['dolor'] !== null ? ' · EVA ' . (int) $ultCom['dolor'] : '' ?>: <?= e($ultCom['comentario']) ?></div><?php endif; ?>
        <?php else: ?><div class="small text-muted mt-1"><?= et('Sin plan activo.') ?> <a href="<?= BASE_URL ?>/fisioterapia/plan<?= $P ?>"><?= et('Crear plan') ?></a></div><?php endif; ?>
    </div></div>
</div>

<?php if (count($gL) >= 2): ?>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-down-arrow text-brand"></i> <?= et('Evolución del dolor (EVA)') ?></h2>
    <div style="height:220px"><canvas id="chEva"></canvas></div>
</div></div>
<?php endif; ?>
<?php endif; ?>

<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item"><button class="nav-link <?= $tab === 'notas' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tNotas" type="button"><i class="bi bi-journal-text"></i> <?= et('Notas de evolución') ?> <span class="badge bg-secondary-subtle text-body"><?= count($notas) ?></span></button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'valoraciones' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tVals" type="button"><i class="bi bi-clipboard2-pulse"></i> <?= et('Valoraciones') ?> <span class="badge bg-secondary-subtle text-body"><?= count($vals) ?></span></button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'planes' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tPlanes" type="button"><i class="bi bi-list-check"></i> <?= et('Planes de ejercicio') ?> <span class="badge bg-secondary-subtle text-body"><?= count($planes) ?></span></button></li>
</ul>
<div class="tab-content card border-top-0 rounded-top-0 mb-4">
    <!-- Notas SOAP -->
    <div class="tab-pane fade <?= $tab === 'notas' ? 'show active' : '' ?>" id="tNotas">
        <?php if (!$notas): ?>
            <div class="card-body text-center text-muted py-4"><?= et('Sin notas de evolución.') ?> <a href="<?= BASE_URL ?>/fisioterapia/nota<?= $P ?>"><?= et('Registrar la primera sesión') ?></a></div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach (array_reverse($notas, true) as $i => $n): $ints = array_filter(explode('|', (string) $n['intervenciones'])); ?>
            <li class="list-group-item py-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= et('Sesión') ?> #<?= $i + 1 ?> · <?= e(fmt_fecha($n['fecha'])) ?>
                            <?php if ($n['eva_pre'] !== null || $n['eva_post'] !== null): ?>
                            <span class="badge bg-<?= fisio_eva_color($n['eva_pre'] !== null ? (int) $n['eva_pre'] : null) ?>-subtle text-body border ms-1">EVA <?= $n['eva_pre'] ?? '—' ?> → <?= $n['eva_post'] ?? '—' ?></span>
                            <?php endif; ?>
                            <?php if ($n['tolerancia']): ?><span class="badge bg-<?= ['buena' => 'success', 'regular' => 'warning', 'mala' => 'danger'][$n['tolerancia']] ?? 'secondary' ?> ms-1"><?= et('Tolerancia') ?> <?= e(t(ucfirst($n['tolerancia']))) ?></span><?php endif; ?>
                        </div>
                        <?php if ($ints): ?><div class="mt-1"><?php foreach ($ints as $x): ?><span class="fs-chip"><?= e($x) ?></span><?php endforeach; ?></div><?php endif; ?>
                        <dl class="fs-soap small mt-2 mb-0">
                            <?php foreach (['S' => 'subjetivo', 'O' => 'objetivo', 'A' => 'analisis', 'P' => 'plan'] as $k => $c): if (trim((string) $n[$c]) === '') continue; ?>
                            <dt><?= $k ?></dt><dd><?= e($n[$c]) ?></dd>
                            <?php endforeach; ?>
                            <?php if ($n['parametros']): ?><dt><i class="bi bi-sliders"></i></dt><dd class="text-muted"><?= e($n['parametros']) ?></dd><?php endif; ?>
                        </dl>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="<?= BASE_URL ?>/fisioterapia/nota<?= $P ?>&id=<?= (int) $n['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta nota?')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="del_nota"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Valoraciones -->
    <div class="tab-pane fade <?= $tab === 'valoraciones' ? 'show active' : '' ?>" id="tVals">
        <?php if (!$vals): ?>
            <div class="card-body text-center text-muted py-4"><?= et('Sin valoraciones.') ?></div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php $tv = fisio_tipos_valoracion(); foreach (array_reverse($vals) as $v): ?>
            <li class="list-group-item py-3 d-flex justify-content-between align-items-start gap-2">
                <div class="flex-grow-1">
                    <div class="fw-semibold">
                        <span class="badge bg-<?= ['inicial' => 'primary', 'reevaluacion' => 'info', 'alta' => 'success'][$v['tipo']] ?> me-1"><?= e(t($tv[$v['tipo']] ?? $v['tipo'])) ?></span>
                        <?= e(fmt_fecha($v['fecha'])) ?><?php if ($v['region']): ?> · <?= e($v['region']) ?><?php endif; ?>
                        <?php if ($v['eva'] !== null): ?><span class="badge bg-<?= fisio_eva_color((int) $v['eva']) ?>-subtle text-body border ms-1">EVA <?= (int) $v['eva'] ?></span><?php endif; ?>
                    </div>
                    <?php if ($v['dx_fisio']): ?><div class="small mt-1"><?= e(mb_strimwidth($v['dx_fisio'], 0, 220, '…')) ?></div><?php endif; ?>
                    <?php $esc = fisio_json($v['escalas']); if ($esc): ?><div class="mt-1"><?php foreach ($esc as $s): ?><span class="fs-chip"><?= e($s['nombre']) ?>: <b><?= e($s['valor']) ?></b></span><?php endforeach; ?></div><?php endif; ?>
                </div>
                <div class="d-flex gap-1">
                    <a href="<?= BASE_URL ?>/fisioterapia/imprimir<?= $P ?>&modo=valoracion&id=<?= (int) $v['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Imprimir')) ?>"><i class="bi bi-printer"></i></a>
                    <a href="<?= BASE_URL ?>/fisioterapia/valoracion<?= $P ?>&id=<?= (int) $v['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                    <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta valoración?')) ?>');">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="del_val"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Planes -->
    <div class="tab-pane fade <?= $tab === 'planes' ? 'show active' : '' ?>" id="tPlanes">
        <?php if (!$planes): ?>
            <div class="card-body text-center text-muted py-4"><?= et('Sin planes de ejercicio.') ?> <a href="<?= BASE_URL ?>/fisioterapia/plan<?= $P ?>"><?= et('Armar el primero') ?></a></div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($planes as $pl): $ap = $pl['activo'] ? fisio_apego((int) $pl['id']) : null; ?>
            <li class="list-group-item py-3 d-flex justify-content-between align-items-start gap-2 flex-wrap">
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($pl['nombre']) ?>
                        <?= $pl['activo'] ? '<span class="badge bg-success ms-1">' . et('Activo') . '</span>' : '<span class="badge bg-secondary ms-1">' . et('Terminado') . '</span>' ?>
                        <?php if ($pl['portal']): ?><span class="badge bg-info-subtle text-body border ms-1"><i class="bi bi-phone"></i> <?= et('En el portal') ?></span><?php endif; ?>
                    </div>
                    <div class="small text-muted"><?= e(fmt_fecha($pl['fecha'])) ?> · <?= (int) $pl['n_items'] ?> <?= et('ejercicios') ?><?= $pl['frecuencia'] ? ' · ' . e($pl['frecuencia']) : '' ?><?= $pl['semanas'] ? ' · ' . (int) $pl['semanas'] . ' ' . et('semanas') : '' ?>
                        <?php if ($ap): ?> · <?= et('Apego 14 días:') ?> <b><?= $ap['dias'] ?>/14</b><?php endif; ?></div>
                    <?php if ($pl['objetivo']): ?><div class="small mt-1"><?= e($pl['objetivo']) ?></div><?php endif; ?>
                </div>
                <div class="d-flex gap-1 flex-wrap">
                    <a href="<?= BASE_URL ?>/fisioterapia/plan_imprimir?id=<?= (int) $pl['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Hoja para el paciente')) ?>"><i class="bi bi-printer"></i></a>
                    <?php $msg = fisio_plan_wa_mensaje($pl, $pac); $wa = wa_link($pac['telefono'] ?? '', $msg); if ($wa): ?>
                    <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0" title="<?= e(t('Enviar por WhatsApp')) ?>"><i class="bi bi-whatsapp"></i></a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/fisioterapia/plan<?= $P ?>&id=<?= (int) $pl['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                    <a href="<?= BASE_URL ?>/fisioterapia/plan<?= $P ?>&copiar=<?= (int) $pl['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Duplicar como nuevo plan')) ?>"><i class="bi bi-copy"></i></a>
                    <form method="post" class="m-0">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="toggle_plan"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary py-0" title="<?= $pl['activo'] ? e(t('Marcar como terminado')) : e(t('Reactivar')) ?>"><i class="bi <?= $pl['activo'] ? 'bi-stop-circle' : 'bi-play-circle' ?>"></i></button>
                    </form>
                    <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar este plan?')) ?>');">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="del_plan"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $pl['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php if (count($gL) >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('chEva'); if (!el) return;
    var grid = 'rgba(127,127,127,.14)';
    new Chart(el, { type: 'line', data: { labels: <?= json_encode($gL) ?>, datasets: [
        { label: <?= json_encode(t('EVA al llegar')) ?>, data: <?= json_encode($gPre) ?>, borderColor: '#ef4444', backgroundColor: '#ef4444', tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 },
        { label: <?= json_encode(t('EVA al salir')) ?>, data: <?= json_encode($gPost) ?>, borderColor: '#10b981', backgroundColor: '#10b981', tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2, borderDash: [4, 3] }
    ] }, options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 10, font: { size: 11 } } } },
        scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 10, font: { size: 10 } } },
                  y: { grid: { color: grid }, min: 0, max: 10, ticks: { stepSize: 2, font: { size: 10 } } } } } });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
