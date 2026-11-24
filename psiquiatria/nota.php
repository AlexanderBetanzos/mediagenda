<?php
/**
 * Nota psiquiátrica (crear / editar): valoración inicial, seguimiento,
 * urgencia o interconsulta. Examen mental por dominios, tamizaje de riesgo
 * suicida (C-SSRS) con nivel calculado, escalas con calculadora de PHQ-9 y
 * GAD-7, diagnóstico CIE-10 y plan.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$n   = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM psiq_notas WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
    $st->execute([$id, $pid, $tid]);
    $n = $st->fetch() ?: null;
    if (!$n) { http_response_code(404); die('Nota no encontrada.'); }
}
$EM = psiq_examen_mental();
$CS = psiq_cssrs_preguntas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $txt = fn ($k, $max = 0) => ($v = trim((string) ($_POST[$k] ?? ''))) === '' ? null : ($max ? mb_substr($v, 0, $max) : $v);
    $num = function ($k, $max) { $v = $_POST[$k] ?? ''; return $v === '' || !is_numeric($v) ? null : max(0, min($max, (int) $v)); };
    $tipo = array_key_exists($_POST['tipo'] ?? '', psiq_tipos_nota()) ? $_POST['tipo'] : 'seguimiento';

    $examen = [];
    foreach ($EM as $k => [, $ops]) if (in_array($_POST['em'][$k] ?? '', $ops, true)) $examen[$k] = $_POST['em'][$k];
    $cssrs = [];
    foreach ($CS as $k => $q) if (isset($_POST['cs'][$k]) && in_array($_POST['cs'][$k], ['0', '1'], true)) $cssrs[$k] = $_POST['cs'][$k] === '1';
    $riesgos = psiq_riesgos();
    $rs = array_key_exists($_POST['riesgo_suicida'] ?? '', $riesgos) ? $_POST['riesgo_suicida'] : psiq_cssrs_nivel($cssrs);
    $rh = array_key_exists($_POST['riesgo_hetero'] ?? '', $riesgos) ? $_POST['riesgo_hetero'] : null;
    $esc = [];
    foreach (psiq_escalas() as $k => [, , $max]) $esc[$k] = $num($k, $max);

    $datos = [($_POST['fecha'] ?? '') ?: date('Y-m-d'), $tipo, $txt('motivo'), $txt('padecimiento'), $txt('antecedentes'),
        $examen ? json_encode($examen, JSON_UNESCAPED_UNICODE) : null, $txt('examen_obs'),
        $cssrs ? json_encode($cssrs) : null, $rs, $rh, $txt('factores'),
        $esc['phq9'], $esc['gad7'], $esc['hamd'], $esc['ymrs'], $esc['audit'],
        $txt('dx_codigo', 20), $txt('dx_texto', 255), $txt('dx_secundarios'), $txt('plan'), $txt('proxima', 80)];
    $cols = 'fecha, tipo, motivo, padecimiento, antecedentes, examen, examen_obs, cssrs, riesgo_suicida, riesgo_hetero, factores,
             phq9, gad7, hamd, ymrs, audit, dx_codigo, dx_texto, dx_secundarios, plan, proxima';
    if ($n) {
        $set = implode(', ', array_map(fn ($c) => trim($c) . ' = ?', explode(',', $cols)));
        db()->prepare("UPDATE psiq_notas SET $set WHERE id = ? AND consultorio_id = ?")->execute([...$datos, $id, $tid]);
        auditar('editar', 'psiq_nota', $id, 'Paciente #' . $pid);
        flash('Nota actualizada.');
    } else {
        db()->prepare("INSERT INTO psiq_notas (consultorio_id, paciente_id, $cols, creado_por) VALUES (?, ?, " . rtrim(str_repeat('?, ', 21), ', ') . ', ?)')
            ->execute([$tid, $pid, ...$datos, $u['id']]);
        $id = (int) db()->lastInsertId();
        auditar('crear', 'psiq_nota', $id, 'Paciente #' . $pid);
        flash($rs === 'alto' ? 'Nota guardada. Riesgo suicida ALTO: activa el plan de seguridad.' : 'Nota guardada.', $rs === 'alto' ? 'warning' : 'success');
    }
    redirect('/psiquiatria/index' . $P);
}

/* Nueva nota: el diagnóstico vigente viene de la nota anterior. */
$prev = null;
if (!$n) {
    $st = db()->prepare('SELECT * FROM psiq_notas WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC LIMIT 1');
    $st->execute([$pid, $tid]);
    $prev = $st->fetch() ?: null;
}
$v  = fn ($k) => $n[$k] ?? '';
$dx = fn ($k) => $n[$k] ?? ($prev[$k] ?? '');
$tipoSel = $n['tipo'] ?? ($_GET['tipo'] ?? ($prev ? 'seguimiento' : 'inicial'));
$emSel = psiq_json($n['examen'] ?? null);
$csSel = psiq_json($n['cssrs'] ?? null);
$meds = psiq_meds_activas($pid);

$titulo = t('Nota psiquiátrica');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-person-heart text-brand"></i> <?= $n ? et('Editar nota psiquiátrica') : et('Nueva nota psiquiátrica') ?></h1>
    <a href="<?= BASE_URL ?>/psiquiatria/index<?= $P ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong><?php if (!empty($pac['fecha_nacimiento'])): ?> · <?= e(edad($pac['fecha_nacimiento'])) ?><?php endif; ?>
    <?php if ($meds): ?> · <i class="bi bi-capsule"></i> <?= e(implode(', ', array_map(fn ($m) => $m['farmaco'] . ($m['dosis'] ? ' ' . $m['dosis'] : ''), $meds))) ?><?php endif; ?></p>

<form method="post" id="fNota">
<?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><?php if ($n): ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <div class="row g-2">
        <div class="col-6 col-md-3"><label class="form-label"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= e($n['fecha'] ?? date('Y-m-d')) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label"><?= et('Tipo de nota') ?></label>
            <select name="tipo" class="form-select" id="selTipo">
                <?php foreach (psiq_tipos_nota() as $k => $l): ?><option value="<?= $k ?>" <?= $tipoSel === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-12"><label class="form-label"><?= et('Motivo de consulta') ?></label><textarea name="motivo" class="form-control" rows="2" placeholder="<?= e(t('En palabras del paciente o de quien lo acompaña')) ?>"><?= e($v('motivo')) ?></textarea></div>
        <div class="col-12"><label class="form-label"><?= et('Padecimiento actual / evolución') ?></label><textarea name="padecimiento" class="form-control" rows="4" placeholder="<?= e(t('Inicio, curso, síntomas, sueño, apetito, funcionalidad, apego y respuesta al tratamiento…')) ?>"><?= e($v('padecimiento')) ?></textarea></div>
        <div class="col-12 ps-antec"><label class="form-label"><?= et('Antecedentes psiquiátricos y de consumo') ?></label><textarea name="antecedentes" class="form-control" rows="3" placeholder="<?= e(t('Diagnósticos y tratamientos previos, hospitalizaciones, intentos suicidas, consumo de alcohol y sustancias, antecedentes familiares…')) ?>"><?= e($v('antecedentes')) ?></textarea></div>
    </div>
</div></div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= et('Examen mental') ?></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="emNormal"><i class="bi bi-check2-all"></i> <?= et('Todo sin alteraciones') ?></button>
    </div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($EM as $k => [$lbl, $ops]): ?>
            <div class="col-6 col-md-4 col-xl-3">
                <label class="form-label"><?= et($lbl) ?></label>
                <select name="em[<?= $k ?>]" class="form-select form-select-sm em-sel">
                    <option value="">—</option>
                    <?php foreach ($ops as $i => $o): ?><option value="<?= e($o) ?>" <?= ($emSel[$k] ?? '') === $o ? 'selected' : '' ?> <?= $i === 0 ? 'data-normal="1"' : '' ?>><?= et($o) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <div class="col-12"><label class="form-label"><?= et('Observaciones del examen mental') ?></label><textarea name="examen_obs" class="form-control" rows="2"><?= e($v('examen_obs')) ?></textarea></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= et('Riesgo suicida (tamizaje C-SSRS)') ?></span>
        <span id="csNivel" class="badge bg-secondary"><?= et('Sin evaluar') ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive"><table class="table table-sm align-middle mb-2">
            <tbody>
            <?php foreach ($CS as $k => $q): $sel = array_key_exists($k, $csSel) ? ($csSel[$k] ? '1' : '0') : ''; ?>
            <tr class="cs-row" data-q="<?= $k ?>" <?= $k >= 3 && $k <= 5 ? 'data-dep="1"' : '' ?>>
                <td class="small"><b><?= $k ?>.</b> <?= et($q) ?></td>
                <td class="text-nowrap text-end" style="width:130px">
                    <input type="radio" class="btn-check" name="cs[<?= $k ?>]" value="1" id="cs<?= $k ?>s" <?= $sel === '1' ? 'checked' : '' ?>><label class="btn btn-sm btn-outline-danger" for="cs<?= $k ?>s"><?= et('Sí') ?></label>
                    <input type="radio" class="btn-check" name="cs[<?= $k ?>]" value="0" id="cs<?= $k ?>n" <?= $sel === '0' ? 'checked' : '' ?>><label class="btn btn-sm btn-outline-secondary" for="cs<?= $k ?>n"><?= et('No') ?></label>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="small text-muted mb-3"><?= et('Las preguntas 3 a 5 se hacen si la 2 fue "Sí". Sí en 4, 5 o 6 = riesgo alto; sí en 3 = moderado; sí en 1 o 2 = bajo.') ?></div>
        <div class="row g-2">
            <div class="col-md-4"><label class="form-label"><?= et('Riesgo suicida (juicio clínico)') ?></label>
                <select name="riesgo_suicida" class="form-select" id="selRs">
                    <option value=""><?= et('Según el tamizaje') ?></option>
                    <?php foreach (psiq_riesgos() as $k => $l): ?><option value="<?= $k ?>" <?= ($n && $n['riesgo_suicida'] === $k && psiq_cssrs_nivel($csSel) !== $k) ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-4"><label class="form-label"><?= et('Riesgo de heteroagresión') ?></label>
                <select name="riesgo_hetero" class="form-select">
                    <option value="">—</option>
                    <?php foreach (psiq_riesgos() as $k => $l): ?><option value="<?= $k ?>" <?= $v('riesgo_hetero') === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-12"><label class="form-label"><?= et('Factores de riesgo / protectores y plan de seguridad') ?></label>
                <textarea name="factores" class="form-control" rows="2" placeholder="<?= e(t('Acceso a medios, red de apoyo, contactos de emergencia, acuerdos de seguridad…')) ?>"><?= e($v('factores')) ?></textarea></div>
        </div>
        <div class="alert alert-danger mt-3 mb-0 d-none" id="csAlerta"><i class="bi bi-exclamation-octagon-fill"></i>
            <?= et('Riesgo alto: no dejar solo al paciente, retirar medios letales, contactar a su red de apoyo y valorar envío a urgencias u hospitalización.') ?></div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><?= et('Escalas') ?></div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach (psiq_escalas() as $k => [$nom, $mide, $max]): ?>
            <div class="col-6 col-md">
                <label class="form-label"><?= e($nom) ?> <span class="text-muted fw-normal">· <?= et($mide) ?></span></label>
                <div class="input-group">
                    <input type="number" name="<?= $k ?>" id="in_<?= $k ?>" min="0" max="<?= $max ?>" class="form-control" value="<?= e((string) $v($k)) ?>">
                    <span class="input-group-text">/<?= $max ?></span>
                </div>
                <?php if (in_array($k, ['phq9', 'gad7'], true)): ?>
                <button type="button" class="btn btn-link btn-sm px-0" data-bs-toggle="collapse" data-bs-target="#calc_<?= $k ?>"><i class="bi bi-calculator"></i> <?= et('Calcular') ?></button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php foreach (psiq_items() as $k => $items): ?>
        <div class="collapse mt-3" id="calc_<?= $k ?>">
            <div class="border rounded-3 p-3">
                <div class="fw-semibold mb-2"><?= $k === 'phq9' ? 'PHQ-9' : 'GAD-7' ?> · <span class="text-muted fw-normal small"><?= et('En las últimas 2 semanas, ¿con qué frecuencia…?') ?></span></div>
                <?php foreach ($items as $i => $it): ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-1 border-bottom">
                    <span class="small"><?= $i + 1 ?>. <?= et($it) ?></span>
                    <span class="btn-group btn-group-sm calc-item" data-calc="<?= $k ?>" data-i="<?= $i ?>">
                        <?php foreach ([t('Nunca'), t('Varios días'), t('Más de la mitad'), t('Casi diario')] as $pts => $lbl): ?>
                        <input type="radio" class="btn-check" name="c_<?= $k ?>_<?= $i ?>" id="c_<?= $k ?>_<?= $i ?>_<?= $pts ?>" value="<?= $pts ?>">
                        <label class="btn btn-outline-secondary" for="c_<?= $k ?>_<?= $i ?>_<?= $pts ?>" title="<?= e($lbl) ?>"><?= $pts ?></label>
                        <?php endforeach; ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <div class="small text-muted mt-2">0 = <?= et('Nunca') ?> · 1 = <?= et('Varios días') ?> · 2 = <?= et('Más de la mitad de los días') ?> · 3 = <?= et('Casi todos los días') ?></div>
                <?php if ($k === 'phq9'): ?><div class="alert alert-warning small mt-2 mb-0 d-none" id="phq9Item9"><i class="bi bi-exclamation-triangle-fill"></i> <?= et('El reactivo 9 es positivo: completa el tamizaje de riesgo suicida.') ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card mb-3"><div class="card-body">
    <div class="row g-2">
        <div class="col-4 col-md-2"><label class="form-label"><?= et('CIE-10') ?></label><input type="text" name="dx_codigo" id="dxCod" class="form-control" list="dlCie" maxlength="20" value="<?= e($dx('dx_codigo')) ?>" placeholder="F32.1"></div>
        <div class="col-8 col-md-10"><label class="form-label"><?= et('Diagnóstico principal') ?></label><input type="text" name="dx_texto" id="dxTxt" class="form-control" list="dlCieTxt" maxlength="255" value="<?= e($dx('dx_texto')) ?>"></div>
        <div class="col-12"><label class="form-label"><?= et('Diagnósticos secundarios / comorbilidades') ?></label><input type="text" name="dx_secundarios" class="form-control" value="<?= e($dx('dx_secundarios')) ?>"></div>
        <div class="col-12"><label class="form-label"><?= et('Plan') ?></label><textarea name="plan" class="form-control" rows="4" placeholder="<?= e(t('Tratamiento farmacológico, psicoterapia, estudios, indicaciones, plan de seguridad…')) ?>"><?= e($v('plan')) ?></textarea>
            <div class="form-text"><?= et('Los cambios de medicamento regístralos en la pestaña Medicación para que quede su historial.') ?></div></div>
        <div class="col-md-4"><label class="form-label"><?= et('Próxima cita') ?></label><input type="text" name="proxima" class="form-control" maxlength="80" list="dlProx" value="<?= e($v('proxima')) ?>"></div>
    </div>
</div></div>

<datalist id="dlCie"><?php foreach (psiq_cie10() as $c => $t): ?><option value="<?= e($c) ?>"><?= e($t) ?></option><?php endforeach; ?></datalist>
<datalist id="dlCieTxt"><?php foreach (psiq_cie10() as $c => $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
<datalist id="dlProx"><?php foreach (['En 1 semana', 'En 2 semanas', 'En 4 semanas', 'En 6 semanas', 'En 3 meses'] as $o): ?><option value="<?= e(t($o)) ?>"><?php endforeach; ?></datalist>

<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="<?= BASE_URL ?>/psiquiatria/index<?= $P ?>" class="btn btn-light"><?= et('Cancelar') ?></a>
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar nota') ?></button>
</div>
</form>

<script>
(function () {
    var CIE = <?= json_encode(psiq_cie10(), JSON_UNESCAPED_UNICODE) ?>;
    var NIV = { ninguno: ['<?= e(t('Sin riesgo')) ?>', 'success'], bajo: ['<?= e(t('Riesgo bajo')) ?>', 'info'],
                moderado: ['<?= e(t('Riesgo moderado')) ?>', 'warning'], alto: ['<?= e(t('Riesgo alto')) ?>', 'danger'] };

    // Antecedentes: siempre en la valoración inicial; en las demás, solo si ya hay texto.
    var tipo = document.getElementById('selTipo'), antec = document.querySelector('.ps-antec');
    function verAntec() { antec.hidden = tipo.value !== 'inicial' && !antec.querySelector('textarea').value.trim(); }
    tipo.addEventListener('change', verAntec); verAntec();

    document.getElementById('emNormal').addEventListener('click', function () {
        document.querySelectorAll('.em-sel').forEach(function (s) {
            if (!s.value) { var o = s.querySelector('option[data-normal]'); if (o) s.value = o.value; }
        });
    });

    // Diagnóstico: escribir el código llena el texto y al revés.
    var cod = document.getElementById('dxCod'), txt = document.getElementById('dxTxt');
    cod.addEventListener('change', function () { var c = cod.value.trim().toUpperCase(); cod.value = c; if (CIE[c] && !txt.value.trim()) txt.value = CIE[c]; });
    txt.addEventListener('change', function () { for (var c in CIE) if (CIE[c] === txt.value && !cod.value.trim()) cod.value = c; });

    // C-SSRS: nivel en vivo; 3-5 se atenúan si la 2 es "No".
    var badge = document.getElementById('csNivel'), alerta = document.getElementById('csAlerta'), selRs = document.getElementById('selRs');
    function val(k) { var r = document.querySelector('input[name="cs[' + k + ']"]:checked'); return r ? r.value : null; }
    function nivel() {
        var a = {}, alguna = false;
        for (var k = 1; k <= 6; k++) { a[k] = val(k); if (a[k] !== null) alguna = true; }
        document.querySelectorAll('.cs-row[data-dep]').forEach(function (tr) { tr.style.opacity = a[2] === '0' ? .45 : 1; });
        var n = !alguna ? null : (a[4] === '1' || a[5] === '1' || a[6] === '1') ? 'alto' : a[3] === '1' ? 'moderado' : (a[1] === '1' || a[2] === '1') ? 'bajo' : 'ninguno';
        var fin = selRs.value || n;
        badge.className = 'badge bg-' + (fin ? NIV[fin][1] : 'secondary');
        badge.textContent = fin ? NIV[fin][0] + (selRs.value ? ' · <?= e(t('juicio clínico')) ?>' : '') : '<?= e(t('Sin evaluar')) ?>';
        alerta.classList.toggle('d-none', fin !== 'alto');
    }
    document.querySelectorAll('.cs-row input').forEach(function (i) { i.addEventListener('change', nivel); });
    selRs.addEventListener('change', nivel);
    nivel();

    // Calculadoras de PHQ-9 y GAD-7: suman los reactivos y llenan el total.
    document.querySelectorAll('.calc-item input').forEach(function (i) {
        i.addEventListener('change', function () {
            var k = i.closest('.calc-item').dataset.calc, tot = 0;
            document.querySelectorAll('.calc-item[data-calc="' + k + '"] input:checked').forEach(function (x) { tot += +x.value; });
            document.getElementById('in_' + k).value = tot;
            if (k === 'phq9') {
                var r9 = document.querySelector('.calc-item[data-calc="phq9"][data-i="8"] input:checked');
                document.getElementById('phq9Item9').classList.toggle('d-none', !(r9 && +r9.value > 0));
            }
        });
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
