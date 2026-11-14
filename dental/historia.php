<?php
/**
 * Historia clínica odontológica: una por paciente, se actualiza en cada
 * revisión. Lo que cambia la conducta en el sillón (alergias, anticoagulantes,
 * bifosfonatos, embarazo…) queda marcado y se muestra como alerta en el
 * resumen y en la carta dental.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$st = db()->prepare('SELECT h.*, u.nombre AS por FROM dental_historias h LEFT JOIN usuarios u ON u.id = h.actualizado_por WHERE h.paciente_id = ? AND h.consultorio_id = ?');
$st->execute([$pid, $tid]);
$h = $st->fetch() ?: [];

$clases = ['', 'I', 'II', 'III', 'N/D'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $txt = fn ($k, $max = 5000) => ($s = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($s, 0, $max);
    $lista = fn ($k, $cat) => ($a = array_values(array_intersect((array) ($_POST[$k] ?? []), $cat))) ? implode('|', $a) : null;
    $cls = fn ($k) => in_array($_POST[$k] ?? '', $clases, true) && $_POST[$k] !== '' ? $_POST[$k] : null;
    $d = [
        'motivo' => $txt('motivo'), 'riesgos' => $lista('riesgos', dental_riesgos()), 'alergias' => $txt('alergias', 255),
        'medicamentos' => $txt('medicamentos'), 'antecedentes' => $txt('antecedentes'), 'habitos' => $lista('habitos', dental_habitos()),
        'cepillado' => $txt('cepillado', 20), 'hilo' => $txt('hilo', 20), 'enjuague' => $txt('enjuague', 20), 'ultima_visita' => $txt('ultima_visita', 80),
        'atm' => $txt('atm'), 'apertura' => $txt('apertura', 10), 'extraoral' => $txt('extraoral'), 'tejidos' => $txt('tejidos'),
        'molar_der' => $cls('molar_der'), 'molar_izq' => $cls('molar_izq'), 'canina_der' => $cls('canina_der'), 'canina_izq' => $cls('canina_izq'),
        'overjet' => $txt('overjet', 10), 'overbite' => $txt('overbite', 10), 'oclusion_notas' => $txt('oclusion_notas'),
        'placa_pct' => ($_POST['placa_pct'] ?? '') !== '' ? max(0, min(100, (int) $_POST['placa_pct'])) : null,
        'riesgo_caries' => in_array($_POST['riesgo_caries'] ?? '', ['Bajo', 'Moderado', 'Alto'], true) ? $_POST['riesgo_caries'] : null,
        'observaciones' => $txt('observaciones'), 'actualizado_por' => $u['id'],
    ];
    $cols = array_keys($d);
    db()->prepare('INSERT INTO dental_historias (consultorio_id, paciente_id, ' . implode(', ', $cols) . ') VALUES (?,?' . str_repeat(',?', count($cols)) . ')
                   ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn ($c) => "$c = VALUES($c)", $cols)))
        ->execute([$tid, $pid, ...array_values($d)]);
    auditar($h ? 'editar' : 'crear', 'dental_historia', null, 'Paciente #' . $pid);
    flash(t('Historia clínica guardada.'));
    if (($_POST['despues'] ?? '') === 'imprimir') redirect('/dental/imprimir?paciente_id=' . $pid . '&modo=historia');
    redirect('/dental/index?paciente_id=' . $pid);
}

$v = fn ($k) => e((string) ($h[$k] ?? ''));
$riesgosSel = array_filter(explode('|', (string) ($h['riesgos'] ?? '')));
$habitosSel = array_filter(explode('|', (string) ($h['habitos'] ?? '')));
$sel = fn ($opts, $cur) => implode('', array_map(fn ($o) => '<option value="' . e($o) . '"' . ((string) $cur === (string) $o ? ' selected' : '') . '>' . e($o === '' ? '—' : t($o)) . '</option>', $opts));

$titulo = t('Historia clínica dental') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
dental_encabezado('Historia clínica odontológica', 'bi-clipboard2-pulse', $pac, $pid, 'historia');
?>
<style>.hc .card-header{background:transparent;font-weight:600}</style>
<?php if ($h): ?><p class="small text-muted"><?= et('Última actualización:') ?> <?= e(fmt_fecha($h['actualizado_en'])) ?><?= $h['por'] ? ' · ' . e($h['por']) : '' ?></p><?php endif; ?>
<form method="post" class="hc">
<?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>">
<div class="row g-3">
<div class="col-12 col-xl-6">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-chat-left-text text-brand"></i> <?= et('Motivo y antecedentes') ?></div><div class="card-body row g-2">
        <div class="col-12"><label class="form-label small"><?= et('Motivo de la visita') ?></label><textarea name="motivo" class="form-control" rows="2"><?= $v('motivo') ?></textarea></div>
        <div class="col-12"><label class="form-label small text-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= et('Antecedentes que cambian la atención') ?></label>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach (dental_riesgos() as $i => $r): ?>
                <input type="checkbox" class="btn-check" name="riesgos[]" value="<?= e($r) ?>" id="r<?= $i ?>" <?= in_array($r, $riesgosSel, true) ? 'checked' : '' ?>>
                <label class="btn btn-sm btn-outline-danger" for="r<?= $i ?>"><?= et($r) ?></label>
            <?php endforeach; ?>
            </div></div>
        <div class="col-12 col-md-6"><label class="form-label small"><?= et('Alergias') ?></label><input type="text" name="alergias" class="form-control" maxlength="255" value="<?= $v('alergias') ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label small"><?= et('Medicamentos actuales') ?></label><input type="text" name="medicamentos" class="form-control" value="<?= $v('medicamentos') ?>"></div>
        <div class="col-12"><label class="form-label small"><?= et('Otros antecedentes médicos y odontológicos') ?></label><textarea name="antecedentes" class="form-control" rows="2" placeholder="<?= e(t('Enfermedades, cirugías, tratamientos dentales previos, experiencias con anestesia…')) ?>"><?= $v('antecedentes') ?></textarea></div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-droplet text-brand"></i> <?= et('Hábitos e higiene') ?></div><div class="card-body row g-2">
        <div class="col-12"><div class="d-flex flex-wrap gap-1">
            <?php foreach (dental_habitos() as $i => $r): ?>
                <input type="checkbox" class="btn-check" name="habitos[]" value="<?= e($r) ?>" id="hb<?= $i ?>" <?= in_array($r, $habitosSel, true) ? 'checked' : '' ?>>
                <label class="btn btn-sm btn-outline-secondary" for="hb<?= $i ?>"><?= et($r) ?></label>
            <?php endforeach; ?></div></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Cepillado al día') ?></label><select name="cepillado" class="form-select"><?= $sel(['', '0', '1', '2', '3 o más'], $h['cepillado'] ?? '') ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Hilo dental') ?></label><select name="hilo" class="form-select"><?= $sel(['', 'Nunca', 'A veces', 'Diario'], $h['hilo'] ?? '') ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Enjuague') ?></label><select name="enjuague" class="form-select"><?= $sel(['', 'No', 'Sí'], $h['enjuague'] ?? '') ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small"><?= et('Última visita al dentista') ?></label><input type="text" name="ultima_visita" class="form-control" maxlength="80" value="<?= $v('ultima_visita') ?>"></div>
    </div></div>
</div>

<div class="col-12 col-xl-6">
    <div class="card mb-3"><div class="card-header"><i class="bi bi-search text-brand"></i> <?= et('Exploración') ?></div><div class="card-body row g-2">
        <div class="col-12 col-md-8"><label class="form-label small"><?= et('ATM (ruidos, dolor, desviación)') ?></label><input type="text" name="atm" class="form-control" value="<?= $v('atm') ?>"></div>
        <div class="col-12 col-md-4"><label class="form-label small"><?= et('Apertura máxima (mm)') ?></label><input type="text" name="apertura" class="form-control" maxlength="10" inputmode="decimal" value="<?= $v('apertura') ?>"></div>
        <div class="col-12"><label class="form-label small"><?= et('Exploración extraoral') ?></label><textarea name="extraoral" class="form-control" rows="2" placeholder="<?= e(t('Simetría facial, perfil, ganglios, labios…')) ?>"><?= $v('extraoral') ?></textarea></div>
        <div class="col-12"><label class="form-label small"><?= et('Tejidos blandos intraorales') ?></label><textarea name="tejidos" class="form-control" rows="2" placeholder="<?= e(t('Encía, lengua, piso de boca, paladar, carrillos, frenillos…')) ?>"><?= $v('tejidos') ?></textarea></div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-distribute-horizontal text-brand"></i> <?= et('Oclusión') ?></div><div class="card-body row g-2">
        <div class="col-12"><table class="table table-sm mb-1"><thead><tr class="small text-muted"><th></th><th><?= et('Derecha') ?></th><th><?= et('Izquierda') ?></th></tr></thead><tbody>
            <tr><td class="small"><?= et('Clase molar (Angle)') ?></td><td><select name="molar_der" class="form-select form-select-sm"><?= $sel($clases, $h['molar_der'] ?? '') ?></select></td><td><select name="molar_izq" class="form-select form-select-sm"><?= $sel($clases, $h['molar_izq'] ?? '') ?></select></td></tr>
            <tr><td class="small"><?= et('Clase canina') ?></td><td><select name="canina_der" class="form-select form-select-sm"><?= $sel($clases, $h['canina_der'] ?? '') ?></select></td><td><select name="canina_izq" class="form-select form-select-sm"><?= $sel($clases, $h['canina_izq'] ?? '') ?></select></td></tr>
        </tbody></table></div>
        <div class="col-6"><label class="form-label small"><?= et('Overjet (mm)') ?></label><input type="text" name="overjet" class="form-control" maxlength="10" inputmode="decimal" value="<?= $v('overjet') ?>"></div>
        <div class="col-6"><label class="form-label small"><?= et('Overbite (mm)') ?></label><input type="text" name="overbite" class="form-control" maxlength="10" inputmode="decimal" value="<?= $v('overbite') ?>"></div>
        <div class="col-12"><label class="form-label small"><?= et('Otros hallazgos de oclusión') ?></label><input type="text" name="oclusion_notas" class="form-control" placeholder="<?= e(t('Mordida cruzada, abierta, línea media, apiñamiento…')) ?>" value="<?= $v('oclusion_notas') ?>"></div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><i class="bi bi-shield-check text-brand"></i> <?= et('Riesgo y diagnóstico') ?></div><div class="card-body row g-2">
        <div class="col-6"><label class="form-label small"><?= et('Índice de placa (O\'Leary) %') ?></label><input type="number" min="0" max="100" name="placa_pct" class="form-control" value="<?= $v('placa_pct') ?>"></div>
        <div class="col-6"><label class="form-label small"><?= et('Riesgo de caries') ?></label><select name="riesgo_caries" class="form-select"><?= $sel(['', 'Bajo', 'Moderado', 'Alto'], $h['riesgo_caries'] ?? '') ?></select></div>
        <div class="col-12"><label class="form-label small"><?= et('Diagnóstico y observaciones') ?></label><textarea name="observaciones" class="form-control" rows="3"><?= $v('observaciones') ?></textarea></div>
    </div></div>
</div>
</div>
<div class="d-flex justify-content-end gap-2 mb-5">
    <button class="btn btn-outline-primary" name="despues" value="imprimir"><i class="bi bi-printer"></i> <?= et('Guardar e imprimir') ?></button>
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar historia clínica') ?></button>
</div>
</form>
<?php include __DIR__ . '/../includes/footer.php'; ?>
