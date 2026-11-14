<?php
/**
 * Documentos dentales imprimibles:
 *   modo=historia   → historia clínica odontológica.
 *   modo=perio&id=N → periodontograma con sus índices.
 *   modo=expediente → historia + plan de tratamiento + periodonto + evolución.
 */
require __DIR__ . '/_comun.php';

$tid  = tenant_id();
$modo = in_array($_GET['modo'] ?? '', ['historia', 'perio', 'expediente'], true) ? $_GET['modo'] : 'expediente';
$st = db()->prepare('SELECT * FROM dental_historias WHERE paciente_id = ? AND consultorio_id = ?');
$st->execute([$pid, $tid]);
$h = $st->fetch() ?: null;

$campo = fn (string $tit, ?string $txt) => trim((string) $txt) === '' ? '' : '<div class="seccion">' . et($tit) . '</div><div class="narrativa">' . e($txt) . '</div>';
$kv = function (array $pares) { $o = []; foreach ($pares as $k => $v) if ($v !== null && $v !== '') $o[] = '<b>' . et($k) . ':</b> ' . e($v); return $o ? '<div class="kv">' . implode(' · ', $o) . '</div>' : ''; };
$firma = '<div class="row avoid"><div class="col-6 offset-6 text-center"><div class="firma">' . e($u['nombre'] ?? '') . (!empty($u['cedula']) ? '<br>' . et('Céd. Prof.') . ' ' . e($u['cedula']) : '') . '</div></div></div>';

$perio = null;
if ($modo === 'perio') {
    $st = db()->prepare('SELECT * FROM dental_perio WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
    $st->execute([(int) ($_GET['id'] ?? 0), $pid, $tid]);
    $perio = $st->fetch();
    if (!$perio) { http_response_code(404); die('Periodontograma no encontrado.'); }
}

$hojaIcono  = 'bi-emoji-smile';
$hojaTitulo = ['historia' => t('Historia clínica odontológica'), 'perio' => t('Periodontograma'), 'expediente' => t('Expediente dental')][$modo];
$hojaDoc    = $hojaTitulo;
$hojaFecha  = $modo === 'perio' ? $perio['fecha'] : date('Y-m-d');
$hojaVolver = BASE_URL . '/dental/index?paciente_id=' . $pid;
include __DIR__ . '/../fisioterapia/_hoja.php';
?>
<div class="row mb-2 kv">
    <div class="col-8"><b><?= et('Paciente') ?>:</b> <?= e($pacNombre) ?><?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?></div>
    <div class="col-4 text-end"><?= e($pac['telefono'] ?? '') ?></div>
</div>

<?php if (($modo === 'historia' || $modo === 'expediente') && $h): ?>
    <?= $campo('Motivo de la visita', $h['motivo']) ?>
    <?php if ($h['riesgos'] || $h['alergias']): ?>
    <div class="seccion"><?= et('Antecedentes de riesgo') ?></div>
    <div class="text-danger kv"><?= e(implode(' · ', array_map('t', array_filter(explode('|', (string) $h['riesgos']))))) ?><?= $h['alergias'] ? ' · ' . et('Alergias') . ': ' . e($h['alergias']) : '' ?></div>
    <?php endif; ?>
    <?= $kv(['Medicamentos' => $h['medicamentos']]) ?>
    <?= $campo('Otros antecedentes', $h['antecedentes']) ?>
    <div class="seccion"><?= et('Hábitos e higiene') ?></div>
    <?= $kv(['Hábitos' => $h['habitos'] ? implode(', ', array_map('t', explode('|', $h['habitos']))) : null, 'Cepillado al día' => $h['cepillado'], 'Hilo dental' => $h['hilo'] ? t($h['hilo']) : null, 'Enjuague' => $h['enjuague'] ? t($h['enjuague']) : null, 'Última visita' => $h['ultima_visita']]) ?>
    <div class="seccion"><?= et('Exploración') ?></div>
    <?= $kv(['ATM' => $h['atm'], 'Apertura' => $h['apertura'] ? $h['apertura'] . ' mm' : null]) ?>
    <?= $kv(['Extraoral' => $h['extraoral']]) ?>
    <?= $kv(['Tejidos blandos' => $h['tejidos']]) ?>
    <div class="seccion"><?= et('Oclusión') ?></div>
    <?= $kv(['Clase molar D/I' => ($h['molar_der'] || $h['molar_izq']) ? ($h['molar_der'] ?: '—') . ' / ' . ($h['molar_izq'] ?: '—') : null,
             'Clase canina D/I' => ($h['canina_der'] || $h['canina_izq']) ? ($h['canina_der'] ?: '—') . ' / ' . ($h['canina_izq'] ?: '—') : null,
             'Overjet' => $h['overjet'] ? $h['overjet'] . ' mm' : null, 'Overbite' => $h['overbite'] ? $h['overbite'] . ' mm' : null]) ?>
    <?= $kv(['Otros' => $h['oclusion_notas']]) ?>
    <?= $kv(['Índice de placa' => $h['placa_pct'] !== null ? $h['placa_pct'] . '%' : null, 'Riesgo de caries' => $h['riesgo_caries'] ? t($h['riesgo_caries']) : null]) ?>
    <?= $campo('Diagnóstico y observaciones', $h['observaciones']) ?>
<?php elseif ($modo === 'historia'): ?>
    <p class="text-muted"><?= et('Sin historia clínica registrada.') ?></p>
<?php endif; ?>

<?php if ($modo === 'expediente'):
    $req = odo_requeridos($pid); $tr = odo_tratamientos(); ?>
    <?php if ($req): ?>
    <div class="seccion avoid"><?= et('Plan de tratamiento') ?></div>
    <table class="table table-sm tbl avoid"><thead><tr><th><?= et('Diente') ?></th><th><?= et('Caras') ?></th><th><?= et('Tratamiento') ?></th></tr></thead><tbody>
    <?php foreach ($req as $r): ?><tr><td><?= e($r['diente']) ?></td><td><?= e(implode('', array_filter(explode(',', (string) $r['caras']), fn ($c) => $c !== 'C')) ?: '—') ?></td><td><?= et($tr[$r['estado']]['label'] ?? $r['estado']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <?php $st = db()->prepare('SELECT * FROM dental_perio WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC LIMIT 1'); $st->execute([$pid, $tid]);
    if ($pp = $st->fetch()): ?>
    <div class="seccion"><?= et('Periodonto') ?> (<?= e(fmt_fecha($pp['fecha'])) ?>)</div>
    <?= $kv(['Sangrado' => (float) $pp['bop_pct'] . '%', 'Placa' => (float) $pp['placa_pct'] . '%', 'PS media' => (float) $pp['ps_media'] . ' mm', 'Sitios ≥ 4 mm' => (string) (int) $pp['sitios4'], 'Diagnóstico' => $pp['diagnostico']]) ?>
    <?php endif; ?>
    <?php $st = db()->prepare('SELECT * FROM dental_evolucion WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha ASC, id ASC'); $st->execute([$pid, $tid]); $ev = $st->fetchAll();
    if ($ev): ?>
    <div class="seccion"><?= et('Notas de evolución') ?></div>
    <table class="table table-sm tbl"><thead><tr><th style="width:13%"><?= et('Fecha') ?></th><th style="width:14%"><?= et('Pieza') ?></th><th><?= et('Procedimiento y notas') ?></th></tr></thead><tbody>
    <?php foreach ($ev as $n): ?><tr class="avoid"><td><?= e(fmt_fecha($n['fecha'])) ?></td><td><?= e(($n['dientes'] ?: '—') . ($n['caras'] ? ' ' . $n['caras'] : '')) ?></td>
        <td><b><?= e($n['procedimiento']) ?></b><?= $n['anestesia'] ? ' <span class="text-muted">· ' . e($n['anestesia']) . '</span>' : '' ?><?= $n['notas'] ? '<div class="small">' . e($n['notas']) . '</div>' : '' ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
<?php endif; ?>

<?php if ($modo === 'perio'):
    $D = json_decode((string) $perio['datos'], true) ?: [];
    $fila = function (array $dientes, string $k, int $b) use ($D) {
        $h = '';
        foreach ($dientes as $d) {
            $t = $D[$d] ?? []; $vals = [];
            $md = in_array((int) floor($d / 10), [1, 4], true);
            $ord = $md ? [$b, $b + 1, $b + 2] : [$b + 2, $b + 1, $b];
            foreach ($ord as $s) { $v = $t[$k][$s] ?? ''; $cls = ($k === 'ps' && $v !== '' && (int) $v >= 4) ? ' style="color:#b91c1c;font-weight:700"' : ''; $vals[] = '<span' . $cls . '>' . ($v === '' ? '·' : e((string) $v)) . '</span>'; }
            $h .= '<td' . (!empty($t['aus']) ? ' style="opacity:.3"' : '') . '>' . implode(' ', $vals) . '</td>';
        }
        return $h;
    };
    $tabla = function (array $dientes, bool $sup) use ($fila) { ?>
        <table class="table table-sm tbl text-center avoid" style="font-size:.7rem"><thead><tr><th></th><?php foreach ($dientes as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead><tbody>
        <tr><th class="text-start"><?= $sup ? et('Vest. PS') : et('Ling. PS') ?></th><?= $fila($dientes, 'ps', $sup ? 0 : 3) ?></tr>
        <tr><th class="text-start"><?= $sup ? et('Vest. MG') : et('Ling. MG') ?></th><?= $fila($dientes, 'mg', $sup ? 0 : 3) ?></tr>
        <tr><th class="text-start"><?= $sup ? et('Pal. PS') : et('Vest. PS') ?></th><?= $fila($dientes, 'ps', $sup ? 3 : 0) ?></tr>
        <tr><th class="text-start"><?= $sup ? et('Pal. MG') : et('Vest. MG') ?></th><?= $fila($dientes, 'mg', $sup ? 3 : 0) ?></tr>
        </tbody></table>
    <?php }; ?>
    <?= $kv(['Sangrado (BOP)' => (float) $perio['bop_pct'] . '%', 'Placa' => (float) $perio['placa_pct'] . '%', 'PS media' => (float) $perio['ps_media'] . ' mm',
             'Sitios ≥ 4 mm' => (string) (int) $perio['sitios4'], 'Sitios ≥ 6 mm' => (string) (int) $perio['sitios6']]) ?>
    <div class="seccion"><?= et('Arcada superior') ?></div><?php $tabla([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28], true); ?>
    <div class="seccion"><?= et('Arcada inferior') ?></div><?php $tabla([48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38], false); ?>
    <?= $kv(['Diagnóstico periodontal' => $perio['diagnostico']]) ?>
    <?= $campo('Notas', $perio['notas']) ?>
<?php endif; ?>
<?= $firma ?>
</div></body></html>
