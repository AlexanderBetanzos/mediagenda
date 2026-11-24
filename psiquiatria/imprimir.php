<?php
/**
 * Documentos de psiquiatría para imprimir o guardar en PDF:
 *   modo=nota&id=N  → nota psiquiátrica completa (con firma y cédula).
 *   modo=resumen    → resumen clínico: diagnóstico vigente, riesgo, escalas,
 *                     medicación actual e historial de cambios. Sirve para
 *                     interconsulta o para el expediente en papel.
 */
require __DIR__ . '/_comun.php';

$tid  = tenant_id();
$modo = ($_GET['modo'] ?? '') === 'resumen' ? 'resumen' : 'nota';
$EM = psiq_examen_mental(); $CS = psiq_cssrs_preguntas(); $ESC = psiq_escalas(); $tipos = psiq_tipos_nota();
$st = db()->prepare('SELECT n.*, u.nombre AS autor, u.cedula FROM psiq_notas n LEFT JOIN usuarios u ON u.id = n.creado_por
                     WHERE n.paciente_id = ? AND n.consultorio_id = ? ORDER BY n.fecha ASC, n.id ASC');
$st->execute([$pid, $tid]);
$notas = $st->fetchAll();

$campo = function (string $tit, ?string $txt) {
    if (trim((string) $txt) === '') return '';
    return '<div class="seccion">' . et($tit) . '</div><div class="narrativa">' . e($txt) . '</div>';
};
$firma = function (?array $nt) use ($u) {
    $nom = $nt['autor'] ?? $u['nombre'] ?? '';
    return '<div class="row avoid"><div class="col-6 offset-6 text-center"><div class="firma">' . e($nom)
        . (!empty($nt['cedula']) ? '<br>' . et('Céd. Prof.') . ' ' . e($nt['cedula']) : '') . '</div></div></div>';
};
$escalasHtml = function (array $nt) use ($ESC) {
    $h = '';
    foreach ($ESC as $k => [$nom, $mide, $max]) {
        if ($nt[$k] === null) continue;
        [$l] = psiq_escala_nivel($k, $nt[$k]);
        $h .= '<tr><td>' . e($nom) . ' <span class="text-muted">(' . e(t($mide)) . ')</span></td><td>' . (int) $nt[$k] . ' / ' . $max . '</td><td>' . e(t($l)) . '</td></tr>';
    }
    return $h ? '<div class="seccion">' . et('Escalas') . '</div><table class="table table-sm tbl mb-0"><tbody>' . $h . '</tbody></table>' : '';
};
$hojaVolver = BASE_URL . '/psiquiatria/index?paciente_id=' . $pid;
$hojaIcono  = 'bi-person-heart';
$datosPac = function () use ($pac, $pacNombre) { ?>
    <div class="row mb-2 kv">
        <div class="col-8"><b><?= et('Paciente') ?>:</b> <?= e($pacNombre) ?><?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?>
            <?php if (!empty($pac['sexo'])): ?> · <?= $pac['sexo'] === 'F' ? et('Femenino') : et('Masculino') ?><?php endif; ?></div>
        <div class="col-4 text-end"><?php if (!empty($pac['telefono'])): ?><?= e($pac['telefono']) ?><?php endif; ?></div>
    </div>
<?php };

if ($modo === 'nota') {
    $nt = null;
    foreach ($notas as $x) if ((int) $x['id'] === (int) ($_GET['id'] ?? 0)) $nt = $x;
    if (!$nt) { http_response_code(404); die('Nota no encontrada.'); }
    $hojaTitulo = t('Nota psiquiátrica');
    $hojaDoc    = t($tipos[$nt['tipo']] ?? 'Nota psiquiátrica');
    $hojaFecha  = $nt['fecha'];
    include __DIR__ . '/../fisioterapia/_hoja.php';
    $datosPac();
    $em = psiq_json($nt['examen']); $cs = psiq_json($nt['cssrs']);
    echo $campo('Motivo de consulta', $nt['motivo']);
    echo $campo('Padecimiento actual / evolución', $nt['padecimiento']);
    echo $campo('Antecedentes psiquiátricos y de consumo', $nt['antecedentes']);
    if ($em || $nt['examen_obs']): ?>
        <div class="seccion"><?= et('Examen mental') ?></div>
        <?php if ($em): ?><div class="row kv g-1"><?php foreach ($EM as $k => [$lbl]): if (empty($em[$k])) continue; ?>
            <div class="col-6"><b><?= et($lbl) ?>:</b> <?= e(t($em[$k])) ?></div>
        <?php endforeach; ?></div><?php endif; ?>
        <?php if ($nt['examen_obs']): ?><div class="narrativa mt-1"><?= e($nt['examen_obs']) ?></div><?php endif; ?>
    <?php endif;
    if ($cs || $nt['riesgo_suicida'] || $nt['riesgo_hetero']): ?>
        <div class="seccion"><?= et('Valoración de riesgo') ?></div>
        <?php if ($cs): ?><table class="table table-sm tbl mb-2"><tbody><?php foreach ($CS as $k => $q): if (!array_key_exists($k, $cs)) continue; ?>
            <tr><td><?= $k ?>. <?= et($q) ?></td><td class="text-end fw-semibold"><?= $cs[$k] ? et('Sí') : et('No') ?></td></tr>
        <?php endforeach; ?></tbody></table><?php endif; ?>
        <div class="kv"><?php if ($nt['riesgo_suicida']): ?><b><?= et('Riesgo suicida') ?>:</b> <?= et(psiq_riesgos()[$nt['riesgo_suicida']]) ?><?php endif; ?>
            <?php if ($nt['riesgo_hetero']): ?> · <b><?= et('Heteroagresión') ?>:</b> <?= et(psiq_riesgos()[$nt['riesgo_hetero']]) ?><?php endif; ?></div>
        <?php if ($nt['factores']): ?><div class="narrativa mt-1"><?= e($nt['factores']) ?></div><?php endif; ?>
    <?php endif;
    echo $escalasHtml($nt);
    if ($nt['dx_codigo'] || $nt['dx_texto']): ?>
        <div class="seccion"><?= et('Diagnóstico') ?></div>
        <div><?= $nt['dx_codigo'] ? '<b>' . e($nt['dx_codigo']) . '</b> · ' : '' ?><?= e($nt['dx_texto'] ?? '') ?></div>
        <?php if ($nt['dx_secundarios']): ?><div class="small text-muted"><?= e($nt['dx_secundarios']) ?></div><?php endif; ?>
    <?php endif;
    echo $campo('Plan', $nt['plan']);
    $meds = psiq_meds_activas($pid);
    if ($meds): ?>
        <div class="seccion"><?= et('Medicación actual') ?></div>
        <table class="table table-sm tbl mb-0"><tbody><?php foreach ($meds as $m): ?>
            <tr><td><?= e($m['farmaco']) ?></td><td><?= e(trim(($m['dosis'] ?? '') . ' ' . ($m['frecuencia'] ?? ''))) ?></td><td class="text-muted"><?= e($m['indicacion'] ?? '') ?></td></tr>
        <?php endforeach; ?></tbody></table>
    <?php endif;
    if ($nt['proxima']): ?><div class="kv mt-3"><b><?= et('Próxima cita') ?>:</b> <?= e($nt['proxima']) ?></div><?php endif;
    echo $firma($nt);
    echo '</div></body></html>';
    exit;
}

/* ── Resumen clínico ─────────────────────────────────────────────── */
$ult = $notas ? $notas[count($notas) - 1] : null;
$vig = function (string $k) use ($notas) {
    for ($i = count($notas) - 1; $i >= 0; $i--) if ($notas[$i][$k] !== null && $notas[$i][$k] !== '') return $notas[$i];
    return null;
};
$st = db()->prepare('SELECT * FROM psiq_meds WHERE paciente_id = ? AND consultorio_id = ? ORDER BY inicio, id');
$st->execute([$pid, $tid]);
$meds = $st->fetchAll();
$hojaTitulo = t('Resumen clínico psiquiátrico');
$hojaDoc    = t('Resumen clínico');
$hojaFecha  = date('Y-m-d');
include __DIR__ . '/../fisioterapia/_hoja.php';
$datosPac();
$ini = null; foreach ($notas as $x) if ($x['tipo'] === 'inicial') { $ini = $x; break; }
?>
<div class="kv mb-2"><b><?= et('En atención desde') ?>:</b> <?= $notas ? e(fmt_fecha($notas[0]['fecha'])) : '—' ?> · <b><?= et('Notas') ?>:</b> <?= count($notas) ?></div>
<?php $nDx = $vig('dx_texto') ?? $vig('dx_codigo'); if ($nDx): ?>
<div class="seccion"><?= et('Diagnóstico vigente') ?></div>
<div><?= $nDx['dx_codigo'] ? '<b>' . e($nDx['dx_codigo']) . '</b> · ' : '' ?><?= e($nDx['dx_texto'] ?? '') ?></div>
<?php if ($nDx['dx_secundarios']): ?><div class="small text-muted"><?= e($nDx['dx_secundarios']) ?></div><?php endif; ?>
<?php endif; ?>
<?= $ini ? $campo('Motivo de la valoración inicial', $ini['motivo']) . $campo('Antecedentes', $ini['antecedentes']) : '' ?>
<?php $nRs = $vig('riesgo_suicida'); if ($nRs): ?>
<div class="seccion"><?= et('Riesgo') ?></div>
<div class="kv"><b><?= et('Riesgo suicida') ?>:</b> <?= et(psiq_riesgos()[$nRs['riesgo_suicida']]) ?> (<?= e(fmt_fecha($nRs['fecha'])) ?>)</div>
<?php endif; ?>
<?php $filas = array_filter($notas, fn ($x) => array_filter(array_intersect_key($x, $ESC), fn ($v) => $v !== null)); if ($filas): ?>
<div class="seccion"><?= et('Evolución de escalas') ?></div>
<table class="table table-sm tbl"><thead><tr><th><?= et('Fecha') ?></th><?php foreach ($ESC as [$nom]): ?><th><?= e($nom) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($filas as $x): ?><tr><td><?= e(fmt_fecha($x['fecha'])) ?></td><?php foreach ($ESC as $k => $_): ?><td><?= $x[$k] ?? '—' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?php if ($meds): ?>
<div class="seccion"><?= et('Tratamiento farmacológico') ?></div>
<table class="table table-sm tbl"><thead><tr><th><?= et('Fármaco') ?></th><th><?= et('Dosis') ?></th><th><?= et('Periodo') ?></th><th><?= et('Estado / motivo') ?></th></tr></thead><tbody>
<?php foreach ($meds as $m): ?><tr><td><?= e($m['farmaco']) ?></td><td><?= e(trim(($m['dosis'] ?? '') . ' ' . ($m['frecuencia'] ?? ''))) ?></td>
    <td><?= e(fmt_fecha($m['inicio'])) ?> → <?= $m['fin'] ? e(fmt_fecha($m['fin'])) : et('actual') ?></td><td><?= $m['fin'] ? e($m['motivo_fin'] ?? '') : '<b>' . et('Activo') . '</b>' ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<?= $ult ? $campo('Plan actual', $ult['plan']) : '' ?>
<?= $firma($ult) ?>
</div></body></html>
