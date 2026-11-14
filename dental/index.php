<?php
/**
 * Expediente dental del paciente: en una sola pantalla las alertas médicas
 * de la historia clínica, el plan pendiente de la carta dental, el estado
 * periodontal, las fotos recientes y las últimas notas de evolución.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$q1 = function (string $sql) use ($pid, $tid) { $st = db()->prepare($sql); $st->execute([$pid, $tid]); return $st->fetch() ?: null; };
$qa = function (string $sql) use ($pid, $tid) { $st = db()->prepare($sql); $st->execute([$pid, $tid]); return $st->fetchAll(); };

$h     = $q1('SELECT * FROM dental_historias WHERE paciente_id = ? AND consultorio_id = ?');
$perio = $qa('SELECT * FROM dental_perio WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC LIMIT 2');
$evol  = $qa('SELECT * FROM dental_evolucion WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC LIMIT 5');
$fotos = dental_fotos($pid);
$odo   = odo_cargar($pid)['marcas'];

$pend = $hechos = 0; $presentes = 32; $caries = 0; $pendLista = [];
foreach ($odo as $d => $porCara) {
    if (($porCara['C']['existente'] ?? '') === 'ausente') $presentes--;
    foreach ($porCara as $c => $m) {
        if (($m['existente'] ?? '') === 'caries') $caries++;
        if (!empty($m['requerido'])) { $pend++; $pendLista[$d . '|' . $m['requerido']][] = $c; }
        if (!empty($m['realizado'])) $hechos++;
    }
}
ksort($pendLista, SORT_NATURAL);
$riesgos = array_filter(explode('|', (string) ($h['riesgos'] ?? '')));

$titulo = t('Expediente dental') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
dental_encabezado('Expediente dental', 'bi-clipboard2-heart', $pac, $pid, 'index');
$tr = odo_tratamientos();
?>
<style>
.dk{border:1px solid var(--bs-border-color);border-radius:.8rem;padding:.9rem 1rem;height:100%;display:block;color:inherit;text-decoration:none;transition:.15s}
a.dk:hover{border-color:var(--bs-primary);box-shadow:0 4px 14px rgba(37,99,235,.08)}
.dk .t{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--bs-secondary-color);font-weight:700}
.dk .v{font-size:1.45rem;font-weight:800;line-height:1.15}
.dk .s{font-size:.8rem;color:var(--bs-secondary-color)}
.strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.5rem}
.strip a{aspect-ratio:4/3;border-radius:.5rem;overflow:hidden;background:#0b1220;display:block}
.strip img{width:100%;height:100%;object-fit:cover}
</style>

<?php if ($riesgos || !empty($h['alergias'])): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start py-2">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div><b><?= et('Antes de atender:') ?></b> <?= e(implode(' · ', array_map('t', $riesgos))) ?><?= !empty($h['alergias']) ? ($riesgos ? ' · ' : '') . et('Alergias:') . ' ' . e($h['alergias']) : '' ?></div>
</div>
<?php elseif (!$h): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
    <span><i class="bi bi-clipboard2-pulse"></i> <?= et('Este paciente aún no tiene historia clínica odontológica.') ?></span>
    <a href="<?= BASE_URL ?>/dental/historia<?= $P ?>" class="btn btn-sm btn-warning"><?= et('Llenar ahora') ?></a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><a class="dk" href="<?= BASE_URL ?>/dental/carta<?= $P ?>">
        <div class="t"><i class="bi bi-emoji-smile"></i> <?= et('Plan pendiente') ?></div>
        <div class="v <?= $pend ? 'text-danger' : '' ?>"><?= count($pendLista) ?></div>
        <div class="s"><?= $hechos ?> <?= et('realizados') ?> · <?= $presentes ?> <?= et('piezas presentes') ?><?= $caries ? ' · ' . $caries . ' ' . et('caras con caries') : '' ?></div></a></div>
    <div class="col-6 col-lg-3"><a class="dk" href="<?= BASE_URL ?>/dental/perio<?= $P ?>">
        <div class="t"><i class="bi bi-rulers"></i> <?= et('Periodonto') ?></div>
        <?php if ($perio): $p0 = $perio[0]; $p1 = $perio[1] ?? null; ?>
        <div class="v <?= (float) $p0['bop_pct'] >= 10 ? 'text-danger' : 'text-success' ?>">BOP <?= (float) $p0['bop_pct'] ?>%</div>
        <div class="s">PS <?= (float) $p0['ps_media'] ?> mm · <?= (int) $p0['sitios4'] ?> <?= et('sitios ≥4') ?><?= $p1 ? ' · ' . et('antes') . ' ' . (float) $p1['bop_pct'] . '%' : '' ?></div>
        <?php else: ?><div class="v text-muted">—</div><div class="s"><?= et('Sin periodontograma') ?></div><?php endif; ?></a></div>
    <div class="col-6 col-lg-3"><a class="dk" href="<?= BASE_URL ?>/dental/fotos<?= $P ?>">
        <div class="t"><i class="bi bi-images"></i> <?= et('Fotos clínicas') ?></div>
        <div class="v"><?= count($fotos) ?></div>
        <div class="s"><?= $fotos ? et('Última:') . ' ' . e(fmt_fecha($fotos[0]['fecha'])) : et('Sube la serie inicial') ?></div></a></div>
    <div class="col-6 col-lg-3"><a class="dk" href="<?= BASE_URL ?>/dental/historia<?= $P ?>">
        <div class="t"><i class="bi bi-shield-check"></i> <?= et('Riesgo de caries') ?></div>
        <div class="v <?= ['Alto' => 'text-danger', 'Moderado' => 'text-warning', 'Bajo' => 'text-success'][$h['riesgo_caries'] ?? ''] ?? 'text-muted' ?>"><?= $h && $h['riesgo_caries'] ? e(t($h['riesgo_caries'])) : '—' ?></div>
        <div class="s"><?= $h && $h['placa_pct'] !== null ? et('Placa') . ' ' . (int) $h['placa_pct'] . '%' : et('Historia clínica') ?><?= $h && $h['molar_der'] ? ' · ' . et('Clase') . ' ' . e($h['molar_der']) . '/' . e($h['molar_izq'] ?: '—') : '' ?></div></a></div>
</div>

<div class="row g-3">
<div class="col-12 col-lg-6">
    <div class="card mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center"><span class="fw-semibold"><i class="bi bi-list-task text-brand"></i> <?= et('Tratamientos por hacer') ?></span>
            <a href="<?= BASE_URL ?>/dental/carta<?= $P ?>" class="btn btn-sm btn-primary"><i class="bi bi-emoji-smile"></i> <?= et('Abrir carta dental') ?></a></div>
        <?php if (!$pendLista): ?><div class="card-body text-muted small"><?= et('Sin tratamientos planeados.') ?></div>
        <?php else: ?><ul class="list-group list-group-flush">
            <?php foreach (array_slice($pendLista, 0, 10, true) as $k => $cs): [$d, $est] = explode('|', $k); $cs = array_filter($cs, fn ($c) => $c !== 'C'); ?>
            <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                <span><span class="badge bg-danger me-2"><?= e($d) ?></span><?= et($tr[$est]['label'] ?? $est) ?><?= $cs ? ' <span class="text-muted small">' . e(implode('', $cs)) . '</span>' : '' ?></span>
                <a href="<?= BASE_URL ?>/dental/evolucion<?= $P ?>&diente=<?= e($d) ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-journal-plus"></i></a>
            </li>
            <?php endforeach; ?>
        </ul><?php endif; ?>
    </div>

    <?php if ($fotos): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex justify-content-between mb-2"><span class="fw-semibold"><i class="bi bi-images text-brand"></i> <?= et('Fotos recientes') ?></span><a href="<?= BASE_URL ?>/dental/fotos<?= $P ?>" class="small"><?= et('Ver todas') ?></a></div>
        <div class="strip"><?php foreach (array_slice($fotos, 0, 8) as $f): ?><a href="<?= BASE_URL ?>/dental/fotos<?= $P ?>" title="<?= e(t($f['vista']) . ' · ' . fmt_fecha($f['fecha'])) ?>"><img src="<?= e(dental_foto_url((int) $f['archivo_id'])) ?>" alt="" loading="lazy"></a><?php endforeach; ?></div>
    </div></div>
    <?php endif; ?>
</div>
<div class="col-12 col-lg-6">
    <div class="card mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center"><span class="fw-semibold"><i class="bi bi-journal-medical text-brand"></i> <?= et('Últimas notas de evolución') ?></span>
            <a href="<?= BASE_URL ?>/dental/evolucion<?= $P ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> <?= et('Nota') ?></a></div>
        <?php if (!$evol): ?><div class="card-body text-muted small"><?= et('Sin notas de evolución.') ?></div>
        <?php else: ?><ul class="list-group list-group-flush">
            <?php foreach ($evol as $n): ?>
            <li class="list-group-item py-2">
                <div class="fw-semibold small"><?= e($n['procedimiento']) ?><?= $n['dientes'] ? ' · ' . e($n['dientes']) . ($n['caras'] ? ' ' . e($n['caras']) : '') : '' ?></div>
                <div class="small text-muted"><?= e(fmt_fecha($n['fecha'])) ?><?= $n['notas'] ? ' · ' . e(mb_strimwidth($n['notas'], 0, 110, '…')) : '' ?></div>
            </li>
            <?php endforeach; ?>
        </ul><?php endif; ?>
    </div>
    <div class="card"><div class="card-body d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/dental/imprimir<?= $P ?>&modo=expediente" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> <?= et('Imprimir expediente dental') ?></a>
        <?php if (modulo_activo('presupuestos')): ?><a href="<?= BASE_URL ?>/presupuestos/index<?= $P ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clipboard2-check"></i> <?= et('Presupuestos') ?></a><?php endif; ?>
        <a href="<?= BASE_URL ?>/consentimientos/nuevo<?= $P ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pen"></i> <?= et('Consentimientos') ?></a>
        <a href="<?= BASE_URL ?>/citas/create<?= $P ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
    </div></div>
</div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
