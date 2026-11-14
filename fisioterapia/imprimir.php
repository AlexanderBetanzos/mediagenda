<?php
/**
 * Documentos de fisioterapia para imprimir o guardar en PDF:
 *   modo=valoracion&id=N  → historia clínica fisioterapéutica (una valoración).
 *   modo=informe          → informe de evolución / alta: valoración inicial
 *                           contra la más reciente (EVA, goniometría, fuerza,
 *                           escalas), sesiones realizadas e intervenciones.
 *                           Es el que se manda al médico que refirió.
 */
require __DIR__ . '/_comun.php';

$tid  = tenant_id();
$modo = ($_GET['modo'] ?? '') === 'informe' ? 'informe' : 'valoracion';
$tipos = fisio_tipos_valoracion();
$st = db()->prepare('SELECT v.*, u.nombre AS fisio_nombre, u.cedula FROM fisio_valoraciones v LEFT JOIN usuarios u ON u.id = v.creado_por
                     WHERE v.paciente_id = ? AND v.consultorio_id = ? ORDER BY v.fecha ASC, v.id ASC');
$st->execute([$pid, $tid]);
$vals = $st->fetchAll();

$firma = function (?array $v) use ($u) {
    $n = $v['fisio_nombre'] ?? $u['nombre'] ?? '';
    return '<div class="row avoid"><div class="col-6 offset-6 text-center"><div class="firma">' . e($n)
        . (!empty($v['cedula']) ? '<br>' . et('Céd. Prof.') . ' ' . e($v['cedula']) : '') . '</div></div></div>';
};
$campo = function (string $tit, ?string $txt) {
    if (trim((string) $txt) === '') return '';
    return '<div class="seccion">' . et($tit) . '</div><div class="narrativa">' . e($txt) . '</div>';
};
$hojaVolver = BASE_URL . '/fisioterapia/index?paciente_id=' . $pid;
$datosPac = function () use ($pac, $pacNombre) { ?>
    <div class="row mb-2 kv">
        <div class="col-7"><b><?= et('Paciente') ?>:</b> <?= e($pacNombre) ?><?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?>
            <?php if (!empty($pac['sexo'])): ?> · <?= $pac['sexo'] === 'F' ? et('Femenino') : et('Masculino') ?><?php endif; ?></div>
        <div class="col-5 text-end"><?php if (!empty($pac['telefono'])): ?><?= e($pac['telefono']) ?><?php endif; ?></div>
    </div>
<?php };

/* ------------------------------------------------------------------ */
if ($modo === 'valoracion') {
    $v = null;
    foreach ($vals as $x) if ((int) $x['id'] === (int) ($_GET['id'] ?? 0)) $v = $x;
    if (!$v) { http_response_code(404); die('Valoración no encontrada.'); }
    $hojaTitulo = t('Historia clínica fisioterapéutica');
    $hojaDoc    = $v['tipo'] === 'inicial' ? t('Historia clínica fisioterapéutica') : t($tipos[$v['tipo']]);
    $hojaFecha  = $v['fecha'];
    include __DIR__ . '/_hoja.php';
    $datosPac();
    $rom = fisio_json($v['rom']); $fza = fisio_json($v['fuerza']); $pru = fisio_json($v['pruebas']); $esc = fisio_json($v['escalas']);
    ?>
    <div class="kv mb-1">
        <?php if ($v['dx_medico']): ?><div><b><?= et('Diagnóstico médico') ?>:</b> <?= e($v['dx_medico']) ?><?= $v['referente'] ? ' · ' . et('Refiere') . ': ' . e($v['referente']) : '' ?></div><?php endif; ?>
        <div><?php if ($v['region']): ?><b><?= et('Región') ?>:</b> <?= e($v['region']) ?><?= $v['lado'] ? ' (' . e($v['lado']) . ')' : '' ?><?php endif; ?>
            <?php if ($v['inicio']): ?> · <b><?= et('Inicio') ?>:</b> <?= e($v['inicio']) ?><?php endif; ?>
            <?php if ($v['ocupacion']): ?> · <b><?= et('Ocupación') ?>:</b> <?= e($v['ocupacion']) ?><?php endif; ?></div>
    </div>
    <?= $campo('Motivo de atención', $v['motivo']) ?>
    <?= $campo('Historia actual / mecanismo de lesión', $v['mecanismo']) ?>
    <?= $campo('Antecedentes', $v['antecedentes']) ?>
    <?php if ($v['banderas_rojas'] || $v['banderas_amarillas']): ?>
    <div class="seccion"><?= et('Banderas') ?></div>
    <?php if ($v['banderas_rojas']): ?><div class="text-danger small"><b><?= et('Rojas') ?>:</b> <?= e(str_replace('|', ' · ', $v['banderas_rojas'])) ?></div><?php endif; ?>
    <?php if ($v['banderas_amarillas']): ?><div class="small"><b><?= et('Amarillas') ?>:</b> <?= e($v['banderas_amarillas']) ?></div><?php endif; ?>
    <?php endif; ?>

    <?php if ($v['eva'] !== null || $v['dolor_tipo'] || $v['agravantes'] || $v['atenuantes']): ?>
    <div class="seccion"><?= et('Dolor') ?></div>
    <div class="kv">
        <?php if ($v['eva'] !== null): ?><b>EVA:</b> <?= (int) $v['eva'] ?>/10<?php endif; ?>
        <?php if ($v['dolor_tipo']): ?> · <b><?= et('Tipo') ?>:</b> <?= e($v['dolor_tipo']) ?><?php endif; ?>
        <?php if ($v['dolor_patron']): ?> · <b><?= et('Comportamiento') ?>:</b> <?= e($v['dolor_patron']) ?><?php endif; ?>
        <?php if ($v['irradiacion']): ?> · <b><?= et('Irradiación') ?>:</b> <?= e($v['irradiacion']) ?><?php endif; ?>
        <?php if ($v['agravantes']): ?><div><b><?= et('Lo agrava') ?>:</b> <?= e($v['agravantes']) ?></div><?php endif; ?>
        <?php if ($v['atenuantes']): ?><div><b><?= et('Lo alivia') ?>:</b> <?= e($v['atenuantes']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?= $campo('Inspección y postura', $v['inspeccion']) ?>
    <?= $campo('Palpación', $v['palpacion']) ?>
    <?= $campo('Marcha y patrones funcionales', $v['marcha']) ?>
    <?= $campo('Exploración neurológica', $v['neuro']) ?>

    <?php if ($rom): ?>
    <div class="seccion avoid"><?= et('Goniometría') ?></div>
    <table class="table table-sm tbl avoid"><thead><tr><th><?= et('Movimiento') ?></th><th><?= et('Lado') ?></th><th><?= et('Activo') ?></th><th><?= et('Pasivo') ?></th><th><?= et('Normal') ?></th><th>%</th><th><?= et('Dolor') ?></th></tr></thead><tbody>
    <?php foreach ($rom as $r): $p = (is_numeric($r['activo']) && is_numeric($r['normal']) && (float) $r['normal'] > 0) ? round($r['activo'] * 100 / $r['normal']) : null; ?>
        <tr><td><?= e($r['mov']) ?></td><td><?= e($r['lado']) ?></td><td><?= $r['activo'] !== '' ? e($r['activo']) . '°' : '—' ?></td><td><?= $r['pasivo'] !== '' ? e($r['pasivo']) . '°' : '—' ?></td>
            <td class="text-muted"><?= $r['normal'] !== '' ? e($r['normal']) . '°' : '—' ?></td><td><?= $p !== null ? $p . '%' : '' ?></td><td><?= e($r['dolor']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>

    <div class="row">
    <?php if ($fza): ?><div class="col-6 avoid"><div class="seccion"><?= et('Fuerza muscular (Daniels)') ?></div>
        <table class="table table-sm tbl"><thead><tr><th><?= et('Músculo') ?></th><th><?= et('Der') ?></th><th><?= et('Izq') ?></th></tr></thead><tbody>
        <?php foreach ($fza as $r): ?><tr><td><?= e($r['musculo']) ?></td><td><?= e($r['der'] ?: '—') ?></td><td><?= e($r['izq'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if ($pru): ?><div class="col-6 avoid"><div class="seccion"><?= et('Pruebas especiales') ?></div>
        <table class="table table-sm tbl"><thead><tr><th><?= et('Prueba') ?></th><th><?= et('Lado') ?></th><th><?= et('Resultado') ?></th></tr></thead><tbody>
        <?php foreach ($pru as $r): ?><tr><td><?= e($r['prueba']) ?></td><td><?= e($r['lado']) ?></td><td class="<?= $r['resultado'] === 'Positiva' ? 'text-danger fw-semibold' : '' ?>"><?= e($r['resultado'] ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <?php if ($esc): ?><div class="seccion"><?= et('Escalas funcionales') ?></div>
        <div class="kv"><?= implode(' · ', array_map(fn ($s) => '<b>' . e($s['nombre']) . ':</b> ' . e($s['valor']), $esc)) ?></div><?php endif; ?>

    <?= $campo('Diagnóstico fisioterapéutico', $v['dx_fisio']) ?>
    <?php if ($v['deficiencias'] || $v['limitaciones']): ?><div class="row">
        <div class="col-6"><?= $campo('Deficiencias', $v['deficiencias']) ?></div><div class="col-6"><?= $campo('Limitaciones y participación', $v['limitaciones']) ?></div></div><?php endif; ?>
    <?php if ($v['obj_corto'] || $v['obj_largo']): ?><div class="row">
        <div class="col-6"><?= $campo('Objetivos a corto plazo', $v['obj_corto']) ?></div><div class="col-6"><?= $campo('Objetivos a largo plazo', $v['obj_largo']) ?></div></div><?php endif; ?>
    <?= $campo('Plan de tratamiento', $v['plan']) ?>
    <div class="kv mt-2">
        <?php if ($v['sesiones_est']): ?><b><?= et('Sesiones estimadas') ?>:</b> <?= (int) $v['sesiones_est'] ?><?php endif; ?>
        <?php if ($v['frecuencia']): ?> · <b><?= et('Frecuencia') ?>:</b> <?= e($v['frecuencia']) ?><?php endif; ?>
        <?php if ($v['pronostico']): ?> · <b><?= et('Pronóstico') ?>:</b> <?= e(t($v['pronostico'])) ?><?php endif; ?>
        <?php if ($v['alta_motivo']): ?> · <b><?= et('Motivo del alta') ?>:</b> <?= e($v['alta_motivo']) ?><?php endif; ?>
    </div>
    <?= $campo('Notas', $v['notas']) ?>
    <?= $firma($v) ?>
    </div></body></html>
    <?php
    exit;
}

/* ------------------------------------------------------------------ */
/* Informe de evolución / alta */
$st = db()->prepare('SELECT * FROM fisio_notas WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC');
$st->execute([$pid, $tid]);
$notas = $st->fetchAll();

$ini = null;
foreach ($vals as $x) if ($x['tipo'] === 'inicial') $ini = $x;
$ini = $ini ?: ($vals[0] ?? null);
$fin = $vals ? $vals[count($vals) - 1] : null;
if ($fin && $ini && (int) $fin['id'] === (int) $ini['id']) $fin = null;
if ($ini) $notas = array_values(array_filter($notas, fn ($n) => $n['fecha'] >= $ini['fecha']));
$esAlta = $fin && $fin['tipo'] === 'alta';

// EVA inicial y actual: valoraciones y sesiones, en orden.
$serie = [];
if ($ini && $ini['eva'] !== null) $serie[] = [$ini['fecha'], (int) $ini['eva']];
foreach ($notas as $n) { if ($n['eva_pre'] !== null) $serie[] = [$n['fecha'], (int) $n['eva_pre']]; if ($n['eva_post'] !== null) $serie[] = [$n['fecha'], (int) $n['eva_post']]; }
if ($fin && $fin['eva'] !== null) $serie[] = [$fin['fecha'], (int) $fin['eva']];
$evaI = $serie ? $serie[0][1] : null;
$evaF = count($serie) > 1 ? $serie[count($serie) - 1][1] : null;

$cuenta = [];
foreach ($notas as $n) foreach (array_filter(explode('|', (string) $n['intervenciones'])) as $x) $cuenta[$x] = ($cuenta[$x] ?? 0) + 1;
arsort($cuenta);

$idx = fn (array $rows, string $k) => array_column(array_map(fn ($r) => $r + ['_k' => mb_strtolower($r[$k] . '|' . ($r['lado'] ?? ''))], $rows), null, '_k');

$hojaTitulo = $esAlta ? t('Informe de alta') : t('Informe de evolución');
$hojaDoc    = $esAlta ? t('Informe de alta de fisioterapia') : t('Informe de evolución de fisioterapia');
$hojaFecha  = $fin['fecha'] ?? ($notas ? $notas[count($notas) - 1]['fecha'] : date('Y-m-d'));
include __DIR__ . '/_hoja.php';
$datosPac();
?>
<div class="kv mb-2">
    <?php if ($ini && $ini['dx_medico']): ?><div><b><?= et('Diagnóstico médico') ?>:</b> <?= e($ini['dx_medico']) ?><?= $ini['referente'] ? ' · ' . et('Refiere') . ': ' . e($ini['referente']) : '' ?></div><?php endif; ?>
    <div><?php if ($ini): ?><b><?= et('Inicio del tratamiento') ?>:</b> <?= fmt_fecha($ini['fecha']) ?> · <?php endif; ?>
        <b><?= et('Sesiones realizadas') ?>:</b> <?= count($notas) ?><?php if ($ini && $ini['sesiones_est']): ?> <?= et('de') ?> <?= (int) $ini['sesiones_est'] ?> <?= et('estimadas') ?><?php endif; ?>
        <?php if ($esAlta && $fin['alta_motivo']): ?> · <b><?= et('Motivo del alta') ?>:</b> <?= e($fin['alta_motivo']) ?><?php endif; ?></div>
</div>
<?= $ini ? $campo('Diagnóstico fisioterapéutico', ($fin['dx_fisio'] ?? '') ?: $ini['dx_fisio']) : '' ?>

<?php if ($evaI !== null): ?>
<div class="seccion"><?= et('Dolor (EVA 0-10)') ?></div>
<div class="kv"><b><?= et('Inicial') ?>:</b> <?= $evaI ?>/10<?php if ($evaF !== null): ?> → <b><?= et('Actual') ?>:</b> <?= $evaF ?>/10
    <?php if ($evaI > 0): $m = (int) round(($evaI - $evaF) * 100 / $evaI); ?> · <b><?= $m >= 0 ? sprintf(t('%d%% de reducción'), $m) : sprintf(t('%d%% de aumento'), -$m) ?></b><?php endif; ?><?php endif; ?></div>
<?php endif; ?>

<?php if ($ini && $fin):
    $rI = $idx(fisio_json($ini['rom']), 'mov'); $rF = $idx(fisio_json($fin['rom']), 'mov');
    $fI = $idx(fisio_json($ini['fuerza']), 'musculo'); $fF = $idx(fisio_json($fin['fuerza']), 'musculo');
    $eI = array_column(fisio_json($ini['escalas']), 'valor', 'nombre'); $eF = array_column(fisio_json($fin['escalas']), 'valor', 'nombre');
    $esN = fisio_escalas();
?>
    <?php if (array_intersect_key($rI, $rF)): ?>
    <div class="seccion avoid"><?= et('Goniometría: inicial contra actual') ?></div>
    <table class="table table-sm tbl avoid"><thead><tr><th><?= et('Movimiento') ?></th><th><?= et('Lado') ?></th><th><?= et('Inicial') ?></th><th><?= et('Actual') ?></th><th><?= et('Cambio') ?></th><th><?= et('Normal') ?></th></tr></thead><tbody>
    <?php foreach (array_intersect_key($rI, $rF) as $k => $a): $b = $rF[$k]; $d = (is_numeric($a['activo']) && is_numeric($b['activo'])) ? $b['activo'] - $a['activo'] : null; ?>
        <tr><td><?= e($a['mov']) ?></td><td><?= e($a['lado']) ?></td><td><?= $a['activo'] !== '' ? e($a['activo']) . '°' : '—' ?></td><td class="fw-semibold"><?= $b['activo'] !== '' ? e($b['activo']) . '°' : '—' ?></td>
            <td class="<?= $d > 0 ? 'text-success' : ($d < 0 ? 'text-danger' : '') ?>"><?= $d !== null ? ($d > 0 ? '+' : '') . $d . '°' : '' ?></td><td class="text-muted"><?= $b['normal'] !== '' ? e($b['normal']) . '°' : '' ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php endif; ?>
    <div class="row">
    <?php if (array_intersect_key($fI, $fF)): ?><div class="col-6 avoid"><div class="seccion"><?= et('Fuerza: inicial → actual') ?></div>
        <table class="table table-sm tbl"><thead><tr><th><?= et('Músculo') ?></th><th><?= et('Der') ?></th><th><?= et('Izq') ?></th></tr></thead><tbody>
        <?php foreach (array_intersect_key($fI, $fF) as $k => $a): $b = $fF[$k]; ?>
            <tr><td><?= e($a['musculo']) ?></td><td><?= e(($a['der'] ?: '—') . ' → ' . ($b['der'] ?: '—')) ?></td><td><?= e(($a['izq'] ?: '—') . ' → ' . ($b['izq'] ?: '—')) ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if (array_intersect_key($eI, $eF)): ?><div class="col-6 avoid"><div class="seccion"><?= et('Escalas funcionales') ?></div>
        <table class="table table-sm tbl"><thead><tr><th><?= et('Escala') ?></th><th><?= et('Inicial') ?></th><th><?= et('Actual') ?></th></tr></thead><tbody>
        <?php foreach (array_intersect_key($eI, $eF) as $k => $a): $b = $eF[$k];
            $mejor = null;
            if (is_numeric($a) && is_numeric($b) && isset($esN[$k])) $mejor = $esN[$k][1] === 'menos' ? $b < $a : $b > $a; ?>
            <tr><td><?= e($k) ?></td><td><?= e($a) ?></td><td class="fw-semibold <?= $mejor === true ? 'text-success' : ($mejor === false && $a != $b ? 'text-danger' : '') ?>"><?= e($b) ?></td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($cuenta): ?>
<div class="seccion"><?= et('Tratamiento aplicado') ?></div>
<div class="kv"><?= implode(' · ', array_map(fn ($k, $c) => e($k) . ' <span class="text-muted">(' . $c . ')</span>', array_keys($cuenta), $cuenta)) ?></div>
<?php endif; ?>

<?php if ($notas): ?>
<div class="seccion"><?= et('Sesiones') ?></div>
<table class="table table-sm tbl"><thead><tr><th style="width:12%"><?= et('Fecha') ?></th><th style="width:6%">#</th><th style="width:13%">EVA</th><th><?= et('Evolución') ?></th></tr></thead><tbody>
<?php foreach ($notas as $i => $n): ?>
    <tr class="avoid"><td><?= fmt_fecha($n['fecha']) ?></td><td><?= $i + 1 ?></td><td><?= ($n['eva_pre'] ?? '—') . ' → ' . ($n['eva_post'] ?? '—') ?></td>
        <td class="small"><?= e(mb_strimwidth(trim(($n['analisis'] ?: $n['subjetivo']) ?? ''), 0, 220, '…')) ?></td></tr>
<?php endforeach; ?></tbody></table>
<?php endif; ?>

<?php if ($fin): ?>
<?= $campo($esAlta ? 'Estado al alta' : 'Estado actual', $fin['notas'] ?: $fin['limitaciones']) ?>
<?= $campo($esAlta ? 'Recomendaciones' : 'Plan a seguir', $fin['plan']) ?>
<?php endif; ?>
<?= $firma($fin ?: $ini) ?>
</div></body></html>
