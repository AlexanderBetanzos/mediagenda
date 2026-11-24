<?php
/**
 * Psiquiatría del paciente: diagnóstico vigente, riesgo suicida, escalas y su
 * evolución, y en pestañas las notas, la medicación psiquiátrica (con su
 * historial de ajustes) y el monitoreo que pide cada fármaco activo.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$tab = $_GET['tab'] ?? 'notas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $txt = fn ($k, $max) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $hoy = date('Y-m-d');
    $fecha = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$k] ?? '')) ? $_POST[$k] : $hoy;
    $med = null;
    if ($id && in_array($accion, ['ajustar', 'suspender', 'del_med'], true)) {
        $st = db()->prepare('SELECT * FROM psiq_meds WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
        $st->execute([$id, $pid, $tid]);
        $med = $st->fetch() ?: null;
        if (!$med) redirect('/psiquiatria/index' . $P . '&tab=meds');
    }

    if ($accion === 'del_nota') {
        db()->prepare('DELETE FROM psiq_notas WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')->execute([$id, $pid, $tid]);
        auditar('eliminar', 'psiq_nota', $id, 'Paciente #' . $pid);
        flash('Nota eliminada.');
    } elseif ($accion === 'add_med') {
        $f = $txt('farmaco', 120);
        if ($f) {
            db()->prepare('INSERT INTO psiq_meds (consultorio_id, paciente_id, farmaco, clase, dosis, frecuencia, indicacion, inicio, efectos, creado_por)
                           VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid, $pid, $f, psiq_clase_de($f), $txt('dosis', 60), $txt('frecuencia', 80), $txt('indicacion', 160), $fecha('inicio'), $txt('efectos', 255), $u['id']]);
            auditar('crear', 'psiq_med', (int) db()->lastInsertId(), $f . ' · Paciente #' . $pid);
            flash(psiq_es_controlado($f) ? 'Medicamento agregado. Es controlado: requiere receta especial.' : 'Medicamento agregado.', psiq_es_controlado($f) ? 'warning' : 'success');
        }
        $tab = 'meds';
    } elseif ($accion === 'ajustar' && $med && $med['fin'] === null) {
        // Un ajuste cierra el esquema anterior y abre uno nuevo ligado a él: así queda el historial de dosis.
        $f = $fecha('fecha');
        db()->prepare('UPDATE psiq_meds SET fin = ?, motivo_fin = ? WHERE id = ?')->execute([$f, mb_substr(t('Ajuste de dosis') . ($txt('motivo', 120) ? ': ' . $txt('motivo', 120) : ''), 0, 160), $id]);
        db()->prepare('INSERT INTO psiq_meds (consultorio_id, paciente_id, farmaco, clase, dosis, frecuencia, indicacion, inicio, efectos, anterior_id, creado_por)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $pid, $med['farmaco'], $med['clase'], $txt('dosis', 60) ?? $med['dosis'], $txt('frecuencia', 80) ?? $med['frecuencia'],
                       $med['indicacion'], $f, $txt('efectos', 255) ?? $med['efectos'], $id, $u['id']]);
        auditar('editar', 'psiq_med', $id, 'Ajuste · Paciente #' . $pid);
        flash('Dosis ajustada.');
        $tab = 'meds';
    } elseif ($accion === 'suspender' && $med && $med['fin'] === null) {
        db()->prepare('UPDATE psiq_meds SET fin = ?, motivo_fin = ? WHERE id = ?')->execute([$fecha('fecha'), $txt('motivo', 160) ?? t('Suspendido'), $id]);
        auditar('editar', 'psiq_med', $id, 'Suspendido · Paciente #' . $pid);
        flash('Medicamento suspendido.');
        $tab = 'meds';
    } elseif ($accion === 'del_med' && $med) {
        db()->prepare('DELETE FROM psiq_meds WHERE id = ?')->execute([$id]);
        auditar('eliminar', 'psiq_med', $id, 'Paciente #' . $pid);
        flash('Registro eliminado.');
        $tab = 'meds';
    }
    redirect('/psiquiatria/index' . $P . '&tab=' . $tab);
}

$st = db()->prepare('SELECT n.*, u.nombre AS autor FROM psiq_notas n LEFT JOIN usuarios u ON u.id = n.creado_por
                     WHERE n.paciente_id = ? AND n.consultorio_id = ? ORDER BY n.fecha ASC, n.id ASC');
$st->execute([$pid, $tid]);
$notas = $st->fetchAll();
$st = db()->prepare('SELECT * FROM psiq_meds WHERE paciente_id = ? AND consultorio_id = ? ORDER BY (fin IS NULL) DESC, COALESCE(fin, inicio) DESC, id DESC');
$st->execute([$pid, $tid]);
$medsTodas = $st->fetchAll();
$activas = array_values(array_filter($medsTodas, fn ($m) => $m['fin'] === null));
$previas = array_values(array_filter($medsTodas, fn ($m) => $m['fin'] !== null));

$ult = $notas ? $notas[count($notas) - 1] : null;
// Lo vigente: el último dato no vacío de cada cosa (una nota de urgencia puede no traer diagnóstico).
$vig = function (string $k) use ($notas) {
    for ($i = count($notas) - 1; $i >= 0; $i--) if ($notas[$i][$k] !== null && $notas[$i][$k] !== '') return $notas[$i];
    return null;
};
$nDx = $vig('dx_texto') ?? $vig('dx_codigo');
$nRs = $vig('riesgo_suicida');
$ESC = psiq_escalas();

/* Serie de escalas para la gráfica. */
$gL = []; $gS = array_fill_keys(array_keys($ESC), []); $hayGraf = false;
foreach ($notas as $nt) {
    $tiene = false;
    foreach ($ESC as $k => $_) if ($nt[$k] !== null) $tiene = true;
    if (!$tiene) continue;
    $gL[] = date('d/m/y', strtotime($nt['fecha']));
    foreach ($ESC as $k => $_) { $gS[$k][] = $nt[$k] !== null ? (int) $nt[$k] : null; if ($nt[$k] !== null) $hayGraf = true; }
}
$hayGraf = $hayGraf && count($gL) >= 2;

/* Monitoreo según las familias de los fármacos activos. */
$MON = psiq_monitoreo();
$mon = [];
foreach ($activas as $m) { $c = $m['clase'] ?: psiq_clase_de($m['farmaco']); if ($c && isset($MON[$c])) $mon[$c][] = $m['farmaco']; }

$tipos = psiq_tipos_nota();
$EM = psiq_examen_mental();
$titulo = t('Psiquiatría');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<style>
.pq-kpi{border:1px solid var(--bs-border-color);border-radius:.85rem;padding:.9rem 1rem;height:100%;background:var(--bs-body-bg)}
.pq-kpi .t{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--bs-secondary-color);font-weight:600}
.pq-kpi .v{font-size:1.3rem;font-weight:700;line-height:1.2}
.pq-chip{display:inline-block;font-size:.74rem;padding:.14rem .55rem;border-radius:99px;background:var(--bs-secondary-bg);margin:0 .25rem .25rem 0}
.pq-chip.alt{background:color-mix(in srgb,#f59e0b 16%,transparent)}
.pq-dl dt{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--bs-secondary-color);margin-top:.5rem}
.pq-dl dd{margin:0;white-space:pre-wrap}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-person-heart text-brand"></i> <?= et('Psiquiatría') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted mb-3"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong>
    <?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?>
    · <?= count($notas) ?> <?= et('notas') ?></p>

<?php if ($nRs && in_array($nRs['riesgo_suicida'], ['moderado', 'alto'], true)): ?>
<div class="alert alert-<?= $nRs['riesgo_suicida'] === 'alto' ? 'danger' : 'warning' ?> d-flex gap-2 align-items-start">
    <i class="bi bi-exclamation-octagon-fill fs-5"></i>
    <div><strong><?= sprintf(et('Riesgo suicida %s'), mb_strtolower(t(psiq_riesgos()[$nRs['riesgo_suicida']]))) ?></strong> · <?= et('última evaluación') ?> <?= e(fmt_fecha($nRs['fecha'])) ?>.
        <?php if ($nRs['factores']): ?><div class="small mt-1"><?= e(mb_strimwidth($nRs['factores'], 0, 240, '…')) ?></div><?php endif; ?></div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap mb-4">
    <a href="<?= BASE_URL ?>/psiquiatria/nota<?= $P ?>" class="btn btn-primary"><i class="bi bi-journal-plus"></i> <?= $notas ? et('Nota de seguimiento') : et('Valoración inicial') ?></a>
    <?php if ($notas): ?><a href="<?= BASE_URL ?>/psiquiatria/nota<?= $P ?>&tipo=urgencia" class="btn btn-outline-danger"><i class="bi bi-lightning-charge"></i> <?= et('Nota de urgencia') ?></a><?php endif; ?>
    <a href="<?= BASE_URL ?>/psiquiatria/index<?= $P ?>&tab=meds#fMed" class="btn btn-outline-primary"><i class="bi bi-capsule"></i> <?= et('Medicación') ?></a>
    <?php if (modulo_activo('recetas')): ?><a href="<?= BASE_URL ?>/recetas/create?paciente_id=<?= $pid ?>" class="btn btn-outline-secondary"><i class="bi bi-prescription2"></i> <?= et('Hacer receta') ?></a><?php endif; ?>
    <?php if ($notas): ?><a href="<?= BASE_URL ?>/psiquiatria/imprimir<?= $P ?>&modo=resumen" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-medical"></i> <?= et('Resumen clínico') ?></a><?php endif; ?>
</div>

<?php if (!$notas && !$medsTodas): ?>
<div class="card mb-4"><div class="card-body text-center py-5">
    <i class="bi bi-person-heart text-brand" style="font-size:2.5rem"></i>
    <h2 class="h5 mt-2"><?= et('Empieza con la valoración inicial') ?></h2>
    <p class="text-muted mb-3 mx-auto" style="max-width:60ch"><?= et('Padecimiento actual, antecedentes, examen mental, tamizaje de riesgo suicida, escalas y diagnóstico CIE-10. Después, cada consulta es una nota de seguimiento y la medicación lleva su propio historial.') ?></p>
    <a href="<?= BASE_URL ?>/psiquiatria/nota<?= $P ?>&tipo=inicial" class="btn btn-primary"><i class="bi bi-clipboard2-pulse"></i> <?= et('Valoración inicial') ?></a>
</div></div>
<?php else: ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-4"><div class="pq-kpi">
        <div class="t"><?= et('Diagnóstico vigente') ?></div>
        <?php if ($nDx): ?>
        <div class="fw-semibold mt-1"><?= $nDx['dx_codigo'] ? '<span class="text-brand">' . e($nDx['dx_codigo']) . '</span> · ' : '' ?><?= e($nDx['dx_texto'] ?: (psiq_cie10()[$nDx['dx_codigo']] ?? '')) ?></div>
        <?php if ($nDx['dx_secundarios']): ?><div class="small text-muted mt-1"><?= e(mb_strimwidth($nDx['dx_secundarios'], 0, 120, '…')) ?></div><?php endif; ?>
        <?php else: ?><div class="text-muted mt-1"><?= et('Sin diagnóstico registrado') ?></div><?php endif; ?>
    </div></div>
    <div class="col-6 col-lg-2"><div class="pq-kpi">
        <div class="t"><?= et('Riesgo suicida') ?></div>
        <?php if ($nRs): ?>
        <div class="v"><span class="badge bg-<?= psiq_riesgo_color($nRs['riesgo_suicida']) ?>"><?= et(psiq_riesgos()[$nRs['riesgo_suicida']] ?? '—') ?></span></div>
        <div class="small text-muted mt-1"><?= e(fmt_fecha($nRs['fecha'])) ?></div>
        <?php else: ?><div class="v text-muted">—</div><?php endif; ?>
    </div></div>
    <div class="col-6 col-lg-3"><div class="pq-kpi">
        <div class="t"><?= et('Escalas (más recientes)') ?></div>
        <div class="mt-1">
        <?php $alguna = false; foreach ($ESC as $k => [$nom, , $max]): $nk = $vig($k); if (!$nk) continue; $alguna = true; [$l, $c] = psiq_escala_nivel($k, $nk[$k]); ?>
            <span class="badge bg-<?= $c ?> me-1 mb-1" title="<?= e(fmt_fecha($nk['fecha'])) ?>"><?= e($nom) ?> <?= (int) $nk[$k] ?> · <?= et($l) ?></span>
        <?php endforeach; if (!$alguna): ?><span class="text-muted"><?= et('Sin escalas') ?></span><?php endif; ?>
        </div>
    </div></div>
    <div class="col-12 col-lg-3"><div class="pq-kpi">
        <div class="t"><?= et('Medicación activa') ?></div>
        <?php if ($activas): ?>
        <div class="mt-1"><?php foreach ($activas as $m): ?><span class="pq-chip <?= psiq_es_controlado($m['farmaco']) ? 'alt' : '' ?>"><?= e($m['farmaco']) ?><?= $m['dosis'] ? ' ' . e($m['dosis']) : '' ?></span><?php endforeach; ?></div>
        <?php else: ?><div class="text-muted mt-1"><?= et('Sin medicación') ?> · <a href="<?= BASE_URL ?>/psiquiatria/index<?= $P ?>&tab=meds#fMed"><?= et('Agregar') ?></a></div><?php endif; ?>
    </div></div>
</div>

<?php if ($hayGraf): ?>
<div class="card mb-4"><div class="card-body">
    <h2 class="h6 mb-3"><i class="bi bi-graph-up text-brand"></i> <?= et('Evolución de escalas') ?></h2>
    <div style="height:230px"><canvas id="chEsc"></canvas></div>
</div></div>
<?php endif; ?>
<?php endif; ?>

<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item"><button class="nav-link <?= $tab === 'notas' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tNotas" type="button"><i class="bi bi-journal-text"></i> <?= et('Notas') ?> <span class="badge bg-secondary-subtle text-body"><?= count($notas) ?></span></button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'meds' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tMeds" type="button"><i class="bi bi-capsule"></i> <?= et('Medicación') ?> <span class="badge bg-secondary-subtle text-body"><?= count($activas) ?></span></button></li>
    <li class="nav-item"><button class="nav-link <?= $tab === 'mon' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tMon" type="button"><i class="bi bi-clipboard2-check"></i> <?= et('Monitoreo') ?><?php if ($mon): ?> <span class="badge bg-warning-subtle text-body"><?= count($mon) ?></span><?php endif; ?></button></li>
    <li class="nav-item ms-auto d-flex align-items-center pe-2"><?= ayuda_btn('psiq.medicacion') ?></li>
</ul>
<div class="tab-content card border-top-0 rounded-top-0 mb-4">
    <!-- Notas -->
    <div class="tab-pane fade <?= $tab === 'notas' ? 'show active' : '' ?>" id="tNotas">
        <?php if (!$notas): ?>
            <div class="card-body text-center text-muted py-4"><?= et('Sin notas.') ?> <a href="<?= BASE_URL ?>/psiquiatria/nota<?= $P ?>&tipo=inicial"><?= et('Registrar la valoración inicial') ?></a></div>
        <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach (array_reverse($notas) as $nt): $em = psiq_json($nt['examen']); ?>
            <li class="list-group-item py-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold">
                            <span class="badge bg-<?= ['inicial' => 'primary', 'seguimiento' => 'secondary', 'urgencia' => 'danger', 'interconsulta' => 'info'][$nt['tipo']] ?? 'secondary' ?> me-1"><?= et($tipos[$nt['tipo']] ?? $nt['tipo']) ?></span>
                            <?= e(fmt_fecha($nt['fecha'])) ?>
                            <?php if ($nt['autor']): ?><span class="text-muted fw-normal small">· <?= e($nt['autor']) ?></span><?php endif; ?>
                        </div>
                        <div class="mt-1">
                            <?php if ($nt['dx_codigo'] || $nt['dx_texto']): ?><span class="pq-chip"><b><?= e($nt['dx_codigo'] ?? '') ?></b> <?= e($nt['dx_texto'] ?? '') ?></span><?php endif; ?>
                            <?php if ($nt['riesgo_suicida']): ?><span class="badge bg-<?= psiq_riesgo_color($nt['riesgo_suicida']) ?> me-1"><?= et('Riesgo suicida') ?>: <?= et(psiq_riesgos()[$nt['riesgo_suicida']]) ?></span><?php endif; ?>
                            <?php foreach ($ESC as $k => [$nom]): if ($nt[$k] === null) continue; [$l, $c] = psiq_escala_nivel($k, $nt[$k]); ?>
                                <span class="badge bg-<?= $c ?>-subtle text-body border me-1"><?= e($nom) ?> <?= (int) $nt[$k] ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($nt['padecimiento']): ?><div class="small mt-2"><?= nl2br(e(mb_strimwidth($nt['padecimiento'], 0, 420, '…'))) ?></div><?php endif; ?>
                        <?php $alter = array_filter($em, fn ($val, $k) => isset($EM[$k]) && $val !== $EM[$k][1][0], ARRAY_FILTER_USE_BOTH);
                        if ($alter): ?><div class="small mt-1"><span class="text-muted"><?= et('Examen mental') ?>:</span> <?= e(implode(' · ', array_map(fn ($k) => t($EM[$k][0]) . ': ' . t($alter[$k]), array_keys($alter)))) ?></div>
                        <?php elseif ($em): ?><div class="small mt-1 text-muted"><?= et('Examen mental sin alteraciones.') ?></div><?php endif; ?>
                        <?php if ($nt['plan']): ?><div class="small mt-1"><span class="text-muted"><?= et('Plan') ?>:</span> <?= e(mb_strimwidth($nt['plan'], 0, 260, '…')) ?></div><?php endif; ?>
                        <?php if ($nt['proxima']): ?><div class="small text-muted mt-1"><i class="bi bi-calendar-event"></i> <?= et('Próxima cita') ?>: <?= e($nt['proxima']) ?></div><?php endif; ?>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <a href="<?= BASE_URL ?>/psiquiatria/imprimir<?= $P ?>&modo=nota&id=<?= (int) $nt['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Imprimir')) ?>"><i class="bi bi-printer"></i></a>
                        <a href="<?= BASE_URL ?>/psiquiatria/nota<?= $P ?>&id=<?= (int) $nt['id'] ?>" class="btn btn-sm btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta nota?')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="del_nota"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $nt['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Medicación -->
    <div class="tab-pane fade <?= $tab === 'meds' ? 'show active' : '' ?>" id="tMeds">
        <div class="card-body">
            <?php if ($activas): ?>
            <div class="table-responsive mb-3"><table class="table align-middle mb-0">
                <thead><tr><th><?= et('Fármaco') ?></th><th><?= et('Dosis') ?></th><th><?= et('Indicación') ?></th><th><?= et('Desde') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
                <tbody>
                <?php foreach ($activas as $m): $ctl = psiq_es_controlado($m['farmaco']); ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($m['farmaco']) ?><?php if ($ctl): ?> <span class="badge bg-warning" title="<?= e(t('Requiere receta especial o retenida')) ?>"><?= et('Controlado') ?></span><?php endif; ?></div>
                        <div class="small text-muted"><?= e(t($m['clase'] ?? '')) ?><?= $m['efectos'] ? ' · ' . et('Efectos') . ': ' . e($m['efectos']) : '' ?></div></td>
                    <td><?= e(trim(($m['dosis'] ?? '') . ' ' . ($m['frecuencia'] ?? ''))) ?: '—' ?></td>
                    <td class="small"><?= e($m['indicacion'] ?? '') ?></td>
                    <td class="small text-nowrap"><?= e(fmt_fecha($m['inicio'])) ?><?php if ($m['anterior_id']): ?><div class="text-muted"><?= et('ajustado') ?></div><?php endif; ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary py-0" data-bs-toggle="collapse" data-bs-target="#aj<?= (int) $m['id'] ?>"><?= et('Ajustar') ?></button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse" data-bs-target="#su<?= (int) $m['id'] ?>"><?= et('Suspender') ?></button>
                    </td>
                </tr>
                <tr class="collapse" id="aj<?= (int) $m['id'] ?>"><td colspan="5" class="bg-body-tertiary">
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="ajustar"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Nueva dosis') ?></label><input name="dosis" class="form-control form-control-sm" value="<?= e($m['dosis'] ?? '') ?>" required></div>
                        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Frecuencia') ?></label><input name="frecuencia" class="form-control form-control-sm" value="<?= e($m['frecuencia'] ?? '') ?>" list="dlFrec"></div>
                        <div class="col-6 col-md-2"><label class="form-label small"><?= et('Desde') ?></label><input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Motivo') ?></label><input name="motivo" class="form-control form-control-sm" placeholder="<?= e(t('Respuesta parcial, efectos…')) ?>"></div>
                        <div class="col-8 col-md-2"><label class="form-label small"><?= et('Efectos adversos') ?></label><input name="efectos" class="form-control form-control-sm" value="<?= e($m['efectos'] ?? '') ?>"></div>
                        <div class="col-4 col-md-1"><button class="btn btn-sm btn-primary w-100"><?= et('Guardar') ?></button></div>
                    </form>
                </td></tr>
                <tr class="collapse" id="su<?= (int) $m['id'] ?>"><td colspan="5" class="bg-body-tertiary">
                    <form method="post" class="row g-2 align-items-end">
                        <?= csrf_field() ?><input type="hidden" name="accion" value="suspender"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6 col-md-7"><label class="form-label small"><?= et('Motivo de suspensión') ?></label><input name="motivo" class="form-control form-control-sm" list="dlMotivo" required></div>
                        <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-danger w-100"><?= et('Suspender') ?></button></div>
                    </form>
                </td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>

            <form method="post" class="border rounded-3 p-3" id="fMed">
                <?= csrf_field() ?><input type="hidden" name="accion" value="add_med"><input type="hidden" name="paciente_id" value="<?= $pid ?>">
                <div class="fw-semibold mb-2"><i class="bi bi-plus-circle text-brand"></i> <?= et('Agregar medicamento') ?></div>
                <div class="row g-2">
                    <div class="col-md-3"><label class="form-label small"><?= et('Fármaco') ?></label><input name="farmaco" class="form-control" list="dlFarm" required id="inFarm"><div class="small text-muted mt-1" id="farmClase"></div></div>
                    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Dosis') ?></label><input name="dosis" class="form-control" placeholder="50 mg"></div>
                    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Frecuencia') ?></label><input name="frecuencia" class="form-control" list="dlFrec"></div>
                    <div class="col-md-3"><label class="form-label small"><?= et('Indicación') ?></label><input name="indicacion" class="form-control" placeholder="<?= e(t('Depresión, insomnio, estabilizar el ánimo…')) ?>"></div>
                    <div class="col-md-2"><label class="form-label small"><?= et('Inicio') ?></label><input type="date" name="inicio" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Agregar') ?></button></div>
                </div>
            </form>

            <?php if ($previas): ?>
            <h3 class="h6 mt-4 mb-2 text-muted"><?= et('Historial (esquemas anteriores)') ?></h3>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th><?= et('Fármaco') ?></th><th><?= et('Dosis') ?></th><th><?= et('Periodo') ?></th><th><?= et('Motivo del cambio') ?></th><th></th></tr></thead>
                <tbody>
                <?php foreach ($previas as $m): ?>
                <tr class="text-body-secondary">
                    <td><?= e($m['farmaco']) ?></td>
                    <td><?= e(trim(($m['dosis'] ?? '') . ' ' . ($m['frecuencia'] ?? ''))) ?: '—' ?></td>
                    <td class="small text-nowrap"><?= e(fmt_fecha($m['inicio'])) ?> → <?= e(fmt_fecha($m['fin'])) ?></td>
                    <td class="small"><?= e($m['motivo_fin'] ?? '') ?><?= $m['efectos'] ? ' · ' . e($m['efectos']) : '' ?></td>
                    <td class="text-end">
                        <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar este registro del historial?')) ?>');">
                            <?= csrf_field() ?><input type="hidden" name="accion" value="del_med"><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button class="btn btn-sm btn-link text-danger p-0" title="<?= e(t('Eliminar')) ?>"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Monitoreo -->
    <div class="tab-pane fade <?= $tab === 'mon' ? 'show active' : '' ?>" id="tMon">
        <div class="card-body">
            <?php if (!$mon): ?>
                <p class="text-muted text-center py-3 mb-0"><?= $activas ? et('Los medicamentos activos no tienen un monitoreo específico en la guía.') : et('Agrega la medicación del paciente para ver los estudios y la vigilancia que pide cada fármaco.') ?></p>
            <?php else: foreach ($mon as $clase => $farms): ?>
                <div class="mb-3">
                    <div class="fw-semibold"><?= e(t($clase)) ?> <span class="text-muted fw-normal small">· <?= e(implode(', ', array_unique($farms))) ?></span></div>
                    <ul class="small mb-0 mt-1"><?php foreach ($MON[$clase] as $li): ?><li><?= e(t($li)) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endforeach; ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top pt-3">
                    <span class="small text-muted"><i class="bi bi-info-circle"></i> <?= et('Guía de referencia: no sustituye el criterio clínico ni la ficha técnica de cada medicamento.') ?></span>
                    <?php if (modulo_activo('laboratorio')): ?><a href="<?= BASE_URL ?>/laboratorio/orden?paciente_id=<?= $pid ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eyedropper"></i> <?= et('Ordenar laboratorios') ?></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<datalist id="dlFarm"><?php foreach (psiq_farmacos() as $f => [$c]): ?><option value="<?= e($f) ?>"><?= e(t($c)) ?></option><?php endforeach; ?></datalist>
<datalist id="dlFrec"><?php foreach (['Cada 24 h (mañana)', 'Cada 24 h (noche)', 'Cada 12 h', 'Cada 8 h', 'Por razón necesaria'] as $o): ?><option value="<?= e(t($o)) ?>"><?php endforeach; ?></datalist>
<datalist id="dlMotivo"><?php foreach (['Remisión / fin de tratamiento', 'Efectos adversos', 'Falta de respuesta', 'Cambio a otro fármaco', 'Decisión del paciente', 'Embarazo / lactancia', 'Interacción'] as $o): ?><option value="<?= e(t($o)) ?>"><?php endforeach; ?></datalist>

<script>
(function () {
    // Clase del fármaco y aviso de controlado mientras se escribe.
    var F = <?= json_encode(psiq_farmacos(), JSON_UNESCAPED_UNICODE) ?>, inp = document.getElementById('inFarm'), out = document.getElementById('farmClase');
    if (inp) inp.addEventListener('input', function () {
        var d = F[inp.value];
        out.innerHTML = d ? d[0] + (d[1] ? ' · <span class="text-warning-emphasis fw-semibold"><?= e(t('Controlado: receta especial')) ?></span>' : '') : '';
    });
    // Al abrir con #fMed, enfocar el formulario.
    if (location.hash === '#fMed' && inp) setTimeout(function () { inp.focus(); }, 300);
})();
</script>

<?php if ($hayGraf): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('chEsc'); if (!el) return;
    var grid = 'rgba(127,127,127,.14)', col = { phq9: '#7c3aed', gad7: '#f59e0b', hamd: '#2563eb', ymrs: '#ef4444', audit: '#0d9488' };
    var S = <?= json_encode($gS) ?>, N = <?= json_encode(array_map(fn ($x) => $x[0], $ESC)) ?>;
    var ds = Object.keys(S).filter(function (k) { return S[k].some(function (v) { return v !== null; }); }).map(function (k) {
        return { label: N[k], data: S[k], borderColor: col[k], backgroundColor: col[k], tension: .3, spanGaps: true, pointRadius: 3, borderWidth: 2 };
    });
    new Chart(el, { type: 'line', data: { labels: <?= json_encode($gL) ?>, datasets: ds }, options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 10, font: { size: 11 } } } },
        scales: { x: { grid: { color: grid }, ticks: { maxTicksLimit: 8, font: { size: 10 } } }, y: { grid: { color: grid }, beginAtZero: true, ticks: { font: { size: 10 } } } } } });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
