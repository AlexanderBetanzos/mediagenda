<?php
/**
 * Historia clínica fisioterapéutica: valoración inicial, reevaluación o alta.
 * Una reevaluación / alta nueva arranca con el contexto de la anterior y sus
 * mediciones como referencia ("antes: 90°"), para comparar en el momento.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$v   = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM fisio_valoraciones WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
    $st->execute([$id, $pid, $tid]);
    $v = $st->fetch();
    if (!$v) { http_response_code(404); die('Valoración no encontrada.'); }
}

$tipos = fisio_tipos_valoracion();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $txt = fn ($k, $max = 65000) => ($s = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($s, 0, $max);
    $int = fn ($k, $min, $max) => (isset($_POST[$k]) && $_POST[$k] !== '') ? max($min, min($max, (int) $_POST[$k])) : null;
    $tipo = isset($tipos[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'inicial';
    $br = array_values(array_intersect((array) ($_POST['banderas'] ?? []), fisio_banderas_rojas()));
    $json = fn ($a) => $a ? json_encode($a, JSON_UNESCAPED_UNICODE) : null;

    $datos = [
        'fecha' => ($_POST['fecha'] ?? '') ?: date('Y-m-d'), 'tipo' => $tipo,
        'referente' => $txt('referente', 160), 'dx_medico' => $txt('dx_medico', 255), 'motivo' => $txt('motivo'),
        'region' => in_array($_POST['region'] ?? '', fisio_regiones(), true) ? $_POST['region'] : null,
        'lado' => in_array($_POST['lado'] ?? '', ['Derecho', 'Izquierdo', 'Bilateral', 'N/A'], true) ? $_POST['lado'] : null,
        'inicio' => $txt('inicio', 80), 'mecanismo' => $txt('mecanismo'), 'ocupacion' => $txt('ocupacion', 160),
        'antecedentes' => $txt('antecedentes'), 'banderas_rojas' => $br ? implode('|', $br) : null,
        'banderas_amarillas' => $txt('banderas_amarillas'),
        'eva' => $int('eva', 0, 10), 'dolor_tipo' => $txt('dolor_tipo', 160), 'dolor_patron' => $txt('dolor_patron', 160),
        'agravantes' => $txt('agravantes'), 'atenuantes' => $txt('atenuantes'), 'irradiacion' => $txt('irradiacion', 255),
        'inspeccion' => $txt('inspeccion'), 'palpacion' => $txt('palpacion'), 'marcha' => $txt('marcha'), 'neuro' => $txt('neuro'),
        'rom'     => $json(fisio_filas('rom', ['mov', 'lado', 'activo', 'pasivo', 'normal', 'dolor'], 'mov')),
        'fuerza'  => $json(fisio_filas('fza', ['musculo', 'der', 'izq'], 'musculo')),
        'pruebas' => $json(fisio_filas('pr', ['prueba', 'lado', 'resultado'], 'prueba')),
        'escalas' => $json(fisio_filas('esc', ['nombre', 'valor'], 'nombre')),
        'dx_fisio' => $txt('dx_fisio'), 'deficiencias' => $txt('deficiencias'), 'limitaciones' => $txt('limitaciones'),
        'obj_corto' => $txt('obj_corto'), 'obj_largo' => $txt('obj_largo'), 'plan' => $txt('plan'),
        'pronostico' => in_array($_POST['pronostico'] ?? '', ['Bueno', 'Regular', 'Reservado'], true) ? $_POST['pronostico'] : null,
        'sesiones_est' => $int('sesiones_est', 0, 500), 'frecuencia' => $txt('frecuencia', 80),
        'alta_motivo' => $tipo === 'alta' ? $txt('alta_motivo', 40) : null, 'notas' => $txt('notas'),
    ];
    $cols = array_keys($datos);
    if ($v) {
        $set = implode(', ', array_map(fn ($c) => "$c = ?", $cols));
        db()->prepare("UPDATE fisio_valoraciones SET $set WHERE id = ? AND consultorio_id = ?")
            ->execute([...array_values($datos), $id, $tid]);
        auditar('editar', 'fisio_valoracion', $id, 'Paciente #' . $pid);
        flash('Valoración actualizada.');
    } else {
        db()->prepare('INSERT INTO fisio_valoraciones (consultorio_id, paciente_id, creado_por, ' . implode(', ', $cols) . ')
                       VALUES (?,?,?' . str_repeat(',?', count($cols)) . ')')
            ->execute([$tid, $pid, $u['id'], ...array_values($datos)]);
        $id = (int) db()->lastInsertId();
        auditar('crear', 'fisio_valoracion', $id, 'Paciente #' . $pid);
        flash($tipo === 'alta' ? 'Alta registrada.' : 'Valoración guardada.');
    }
    if (($_POST['despues'] ?? '') === 'imprimir') redirect('/fisioterapia/imprimir?paciente_id=' . $pid . '&modo=valoracion&id=' . $id);
    redirect('/fisioterapia/index?paciente_id=' . $pid . '&tab=valoraciones');
}

/* Valoración anterior: contexto para una reevaluación / alta nueva. */
$prev = null;
if (!$v) {
    $st = db()->prepare('SELECT * FROM fisio_valoraciones WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC LIMIT 1');
    $st->execute([$pid, $tid]);
    $prev = $st->fetch() ?: null;
}
$tipo = $v['tipo'] ?? (isset($tipos[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : ($prev && $prev['tipo'] !== 'alta' ? 'reevaluacion' : 'inicial'));
if (!$v && $prev && $tipo !== 'inicial') {
    $v = array_intersect_key($prev, array_flip(['referente', 'dx_medico', 'motivo', 'region', 'lado', 'inicio', 'mecanismo', 'ocupacion',
        'antecedentes', 'banderas_rojas', 'banderas_amarillas', 'dx_fisio', 'deficiencias', 'limitaciones', 'obj_corto', 'obj_largo',
        'plan', 'pronostico', 'sesiones_est', 'frecuencia']));
    // Mismas filas, valores vacíos: lo anterior queda como referencia.
    $vaciar = function (?string $j, array $limpiar) {
        return array_map(function ($f) use ($limpiar) { $f['_ant'] = $f; foreach ($limpiar as $c) $f[$c] = ''; return $f; }, fisio_json($j));
    };
    $rom = $vaciar($prev['rom'], ['activo', 'pasivo', 'dolor']);
    $fza = $vaciar($prev['fuerza'], ['der', 'izq']);
    $pru = $vaciar($prev['pruebas'], ['resultado']);
    $esc = $vaciar($prev['escalas'], ['valor']);
    $evaAnt = $prev['eva'];
} else {
    $rom = fisio_json($v['rom'] ?? null);
    $fza = fisio_json($v['fuerza'] ?? null);
    $pru = fisio_json($v['pruebas'] ?? null);
    $esc = fisio_json($v['escalas'] ?? null);
    $evaAnt = null;
}
$val = fn ($k) => e((string) ($v[$k] ?? ''));
$brSel = array_filter(explode('|', (string) ($v['banderas_rojas'] ?? '')));

$titulo = t($tipos[$tipo]) . ' · ' . t('Fisioterapia');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';

$ladoOpts = ['', 'Der', 'Izq', 'Bilat'];
$sel = fn ($opts, $cur) => implode('', array_map(fn ($o) => '<option value="' . e($o) . '"' . ((string) $cur === (string) $o ? ' selected' : '') . '>' . e($o === '' ? '—' : $o) . '</option>', $opts));
?>
<style>
.fv-sec{scroll-margin-top:80px}
.fv-sec .card-header{background:transparent;font-weight:600}
.fv-tbl td{padding:.2rem .25rem;vertical-align:middle}
.fv-tbl input,.fv-tbl select{min-width:0}
.fv-ant{font-size:.7rem;color:var(--bs-secondary-color);display:block;line-height:1.1}
.fv-eva{display:flex;gap:.25rem;flex-wrap:wrap}
.fv-eva label{width:2.2rem;height:2.2rem;border-radius:.5rem;display:flex;align-items:center;justify-content:center;border:1px solid var(--bs-border-color);cursor:pointer;font-weight:600}
.fv-eva input{display:none}
.fv-eva input:checked+label{color:#fff;border-color:transparent}
.fv-nav{position:sticky;top:64px;z-index:5;background:var(--bs-body-bg);padding:.4rem 0;overflow-x:auto;white-space:nowrap}
.fv-nav a{font-size:.8rem;margin-right:.6rem;text-decoration:none}
</style>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-clipboard2-pulse text-brand"></i> <?= et($tipos[$tipo]) ?></h1>
    <a href="<?= BASE_URL ?>/fisioterapia/index?paciente_id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver') ?></a>
</div>
<p class="text-muted mb-2"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong>
<?php if ($prev && empty($_GET['id']) && $tipo !== 'inicial'): ?> · <?= et('Se cargó el contexto de la valoración del') ?> <?= e(fmt_fecha($prev['fecha'])) ?>; <?= et('los valores anteriores aparecen como referencia.') ?><?php endif; ?></p>

<nav class="fv-nav border-bottom mb-3">
    <a href="#s-datos"><?= et('Datos') ?></a><a href="#s-dolor"><?= et('Dolor') ?></a><a href="#s-expl"><?= et('Exploración') ?></a>
    <a href="#s-rom"><?= et('Goniometría') ?></a><a href="#s-fza"><?= et('Fuerza') ?></a><a href="#s-pru"><?= et('Pruebas') ?></a>
    <a href="#s-esc"><?= et('Escalas') ?></a><a href="#s-dx"><?= et('Diagnóstico y plan') ?></a>
</nav>

<form method="post" id="fvForm">
<?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><?php if (!empty($_GET['id'])): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

<div class="card mb-3 fv-sec" id="s-datos"><div class="card-header"><i class="bi bi-person-vcard text-brand"></i> <?= et('Datos de la valoración y anamnesis') ?></div><div class="card-body row g-2">
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control" value="<?= e($v['fecha'] ?? date('Y-m-d')) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Tipo') ?></label>
        <select name="tipo" class="form-select" id="fvTipo"><?php foreach ($tipos as $k => $l): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small"><?= et('Región') ?></label>
        <select name="region" class="form-select" id="fvRegion"><option value="">—</option><?php foreach (fisio_regiones() as $r): ?><option <?= ($v['region'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Lado') ?></label>
        <select name="lado" class="form-select"><option value="">—</option><?php foreach (['Derecho', 'Izquierdo', 'Bilateral', 'N/A'] as $l): ?><option value="<?= $l ?>" <?= ($v['lado'] ?? '') === $l ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-3"><label class="form-label small"><?= et('Inicio de síntomas') ?></label><input type="text" name="inicio" class="form-control" maxlength="80" placeholder="<?= e(t('p. ej. hace 3 semanas')) ?>" value="<?= $val('inicio') ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Diagnóstico médico / de referencia') ?></label><input type="text" name="dx_medico" class="form-control" maxlength="255" value="<?= $val('dx_medico') ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Médico que refiere') ?></label><input type="text" name="referente" class="form-control" maxlength="160" value="<?= $val('referente') ?>"></div>
    <div class="col-12"><label class="form-label small"><?= et('Motivo de atención (en palabras del paciente)') ?></label><textarea name="motivo" class="form-control" rows="2"><?= $val('motivo') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Mecanismo de lesión / historia actual') ?></label><textarea name="mecanismo" class="form-control" rows="2"><?= $val('mecanismo') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Antecedentes (cirugías, enfermedades, medicamentos, estudios)') ?></label><textarea name="antecedentes" class="form-control" rows="2"><?= $val('antecedentes') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Ocupación / actividad física / deporte') ?></label><input type="text" name="ocupacion" class="form-control" maxlength="160" value="<?= $val('ocupacion') ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Banderas amarillas (creencias, miedo al movimiento, estrés)') ?></label><input type="text" name="banderas_amarillas" class="form-control" value="<?= $val('banderas_amarillas') ?>"></div>
    <div class="col-12"><label class="form-label small text-danger"><i class="bi bi-flag-fill"></i> <?= et('Banderas rojas') ?></label>
        <div class="d-flex flex-wrap gap-2">
        <?php foreach (fisio_banderas_rojas() as $i => $b): ?>
            <input type="checkbox" class="btn-check" name="banderas[]" value="<?= e($b) ?>" id="br<?= $i ?>" <?= in_array($b, $brSel, true) ? 'checked' : '' ?>>
            <label class="btn btn-sm btn-outline-danger" for="br<?= $i ?>"><?= e($b) ?></label>
        <?php endforeach; ?>
        </div>
    </div>
</div></div>

<div class="card mb-3 fv-sec" id="s-dolor"><div class="card-header"><i class="bi bi-activity text-brand"></i> <?= et('Dolor') ?></div><div class="card-body row g-2">
    <div class="col-12"><label class="form-label small"><?= et('EVA (0 = sin dolor, 10 = el peor imaginable)') ?><?php if ($evaAnt !== null): ?> · <span class="text-muted"><?= et('anterior:') ?> <?= (int) $evaAnt ?></span><?php endif; ?></label>
        <div class="fv-eva">
            <?php $evaCur = $v['eva'] ?? null; for ($n = 0; $n <= 10; $n++): $c = $n <= 3 ? '#10b981' : ($n <= 6 ? '#f59e0b' : '#ef4444'); ?>
            <input type="radio" name="eva" value="<?= $n ?>" id="eva<?= $n ?>" <?= $evaCur !== null && $evaCur !== '' && (int) $evaCur === $n ? 'checked' : '' ?>><label for="eva<?= $n ?>" data-c="<?= $c ?>"><?= $n ?></label>
            <?php endfor; ?>
        </div>
    </div>
    <div class="col-12 col-md-4"><label class="form-label small"><?= et('Tipo de dolor') ?></label><input type="text" name="dolor_tipo" class="form-control" list="dlDolorTipo" value="<?= $val('dolor_tipo') ?>">
        <datalist id="dlDolorTipo"><?php foreach (['Punzante', 'Sordo', 'Quemante', 'Eléctrico', 'Opresivo', 'Pulsátil', 'Hormigueo', 'Rigidez'] as $o): ?><option value="<?= $o ?>"><?php endforeach; ?></datalist></div>
    <div class="col-12 col-md-4"><label class="form-label small"><?= et('Comportamiento') ?></label><input type="text" name="dolor_patron" class="form-control" list="dlPatron" value="<?= $val('dolor_patron') ?>">
        <datalist id="dlPatron"><?php foreach (['Mecánico (cambia con el movimiento)', 'Inflamatorio (rigidez matutina)', 'Constante', 'Intermitente', 'Nocturno', 'Neuropático'] as $o): ?><option value="<?= $o ?>"><?php endforeach; ?></datalist></div>
    <div class="col-12 col-md-4"><label class="form-label small"><?= et('Irradiación') ?></label><input type="text" name="irradiacion" class="form-control" maxlength="255" value="<?= $val('irradiacion') ?>"></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Lo agrava') ?></label><textarea name="agravantes" class="form-control" rows="2"><?= $val('agravantes') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Lo alivia') ?></label><textarea name="atenuantes" class="form-control" rows="2"><?= $val('atenuantes') ?></textarea></div>
</div></div>

<div class="card mb-3 fv-sec" id="s-expl"><div class="card-header"><i class="bi bi-search text-brand"></i> <?= et('Exploración física') ?></div><div class="card-body row g-2">
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Inspección y postura') ?></label><textarea name="inspeccion" class="form-control" rows="3" placeholder="<?= e(t('Alineación, asimetrías, edema, atrofia, cicatrices…')) ?>"><?= $val('inspeccion') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Palpación') ?></label><textarea name="palpacion" class="form-control" rows="3" placeholder="<?= e(t('Puntos dolorosos, puntos gatillo, temperatura, tono…')) ?>"><?= $val('palpacion') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Marcha y patrones funcionales') ?></label><textarea name="marcha" class="form-control" rows="2" placeholder="<?= e(t('Marcha, sentadilla, subir escaleras, alcanzar…')) ?>"><?= $val('marcha') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Exploración neurológica') ?></label><textarea name="neuro" class="form-control" rows="2" placeholder="<?= e(t('Dermatomas, miotomas, reflejos, neurodinamia…')) ?>"><?= $val('neuro') ?></textarea></div>
</div></div>

<div class="card mb-3 fv-sec" id="s-rom"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-rulers text-brand"></i> <?= et('Goniometría (arcos de movimiento, en grados)') ?> <?= ayuda_btn('fisio.goniometria') ?></span>
    <span class="d-flex gap-1"><button type="button" class="btn btn-sm btn-outline-primary" id="romCargar"><i class="bi bi-magic"></i> <?= et('Cargar movimientos de la región') ?></button>
    <button type="button" class="btn btn-sm btn-light" data-add="rom"><i class="bi bi-plus"></i> <?= et('Fila') ?></button></span>
</div><div class="card-body p-2"><div class="table-responsive">
    <table class="table table-sm fv-tbl mb-0" id="tbl-rom"><thead><tr class="small text-muted">
        <th style="min-width:180px"><?= et('Movimiento') ?></th><th style="width:90px"><?= et('Lado') ?></th><th style="width:90px"><?= et('Activo') ?></th>
        <th style="width:90px"><?= et('Pasivo') ?></th><th style="width:90px"><?= et('Normal') ?></th><th style="width:90px"><?= et('% normal') ?></th><th style="width:70px"><?= et('Dolor') ?></th><th style="width:36px"></th></tr></thead>
    <tbody>
    <?php foreach ($rom ?: [[]] as $r): $a = $r['_ant'] ?? null; ?>
        <tr><td><input name="rom[mov][]" class="form-control form-control-sm" value="<?= e($r['mov'] ?? '') ?>" placeholder="<?= e(t('Hombro · Flexión')) ?>"></td>
            <td><select name="rom[lado][]" class="form-select form-select-sm"><?= $sel($ladoOpts, $r['lado'] ?? '') ?></select></td>
            <td><input name="rom[activo][]" type="number" class="form-control form-control-sm" data-g="act" value="<?= e($r['activo'] ?? '') ?>"><?php if ($a && $a['activo'] !== ''): ?><span class="fv-ant"><?= et('antes') ?> <?= e($a['activo']) ?>°</span><?php endif; ?></td>
            <td><input name="rom[pasivo][]" type="number" class="form-control form-control-sm" value="<?= e($r['pasivo'] ?? '') ?>"><?php if ($a && $a['pasivo'] !== ''): ?><span class="fv-ant"><?= et('antes') ?> <?= e($a['pasivo']) ?>°</span><?php endif; ?></td>
            <td><input name="rom[normal][]" type="number" class="form-control form-control-sm" data-g="nor" value="<?= e($r['normal'] ?? '') ?>"></td>
            <td class="small fw-semibold" data-g="pct"></td>
            <td><select name="rom[dolor][]" class="form-select form-select-sm"><?= $sel(['', 'No', 'Sí'], $r['dolor'] ?? '') ?></select></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del><i class="bi bi-x-lg"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div></div></div>

<div class="card mb-3 fv-sec" id="s-fza"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-lightning-charge text-brand"></i> <?= et('Fuerza muscular (Daniels 0-5)') ?></span>
    <button type="button" class="btn btn-sm btn-light" data-add="fza"><i class="bi bi-plus"></i> <?= et('Fila') ?></button>
</div><div class="card-body p-2"><div class="table-responsive">
    <datalist id="dlMusculos"><?php foreach (fisio_grupos_musculares() as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
    <table class="table table-sm fv-tbl mb-0" id="tbl-fza"><thead><tr class="small text-muted"><th style="min-width:200px"><?= et('Músculo / grupo') ?></th><th style="width:110px"><?= et('Derecho') ?></th><th style="width:110px"><?= et('Izquierdo') ?></th><th style="width:36px"></th></tr></thead>
    <tbody>
    <?php $gr = array_merge([''], fisio_grados_fuerza()); foreach ($fza ?: [[]] as $r): $a = $r['_ant'] ?? null; ?>
        <tr><td><input name="fza[musculo][]" class="form-control form-control-sm" list="dlMusculos" value="<?= e($r['musculo'] ?? '') ?>"></td>
            <td><select name="fza[der][]" class="form-select form-select-sm"><?= $sel($gr, $r['der'] ?? '') ?></select><?php if ($a && $a['der'] !== ''): ?><span class="fv-ant"><?= et('antes') ?> <?= e($a['der']) ?></span><?php endif; ?></td>
            <td><select name="fza[izq][]" class="form-select form-select-sm"><?= $sel($gr, $r['izq'] ?? '') ?></select><?php if ($a && $a['izq'] !== ''): ?><span class="fv-ant"><?= et('antes') ?> <?= e($a['izq']) ?></span><?php endif; ?></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del><i class="bi bi-x-lg"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div></div></div>

<div class="card mb-3 fv-sec" id="s-pru"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-ui-checks text-brand"></i> <?= et('Pruebas especiales') ?></span>
    <button type="button" class="btn btn-sm btn-light" data-add="pr"><i class="bi bi-plus"></i> <?= et('Fila') ?></button>
</div><div class="card-body p-2">
    <div class="small text-muted px-1 mb-1" id="prSug"></div>
    <datalist id="dlPruebas"></datalist>
    <div class="table-responsive"><table class="table table-sm fv-tbl mb-0" id="tbl-pr"><thead><tr class="small text-muted"><th style="min-width:200px"><?= et('Prueba') ?></th><th style="width:100px"><?= et('Lado') ?></th><th style="width:140px"><?= et('Resultado') ?></th><th style="width:36px"></th></tr></thead>
    <tbody>
    <?php foreach ($pru ?: [[]] as $r): $a = $r['_ant'] ?? null; ?>
        <tr><td><input name="pr[prueba][]" class="form-control form-control-sm" list="dlPruebas" value="<?= e($r['prueba'] ?? '') ?>"></td>
            <td><select name="pr[lado][]" class="form-select form-select-sm"><?= $sel($ladoOpts, $r['lado'] ?? '') ?></select></td>
            <td><select name="pr[resultado][]" class="form-select form-select-sm"><?= $sel(['', 'Positiva', 'Negativa', 'Dudosa'], $r['resultado'] ?? '') ?></select><?php if ($a && $a['resultado'] !== ''): ?><span class="fv-ant"><?= et('antes') ?> <?= e($a['resultado']) ?></span><?php endif; ?></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del><i class="bi bi-x-lg"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></div>

<div class="card mb-3 fv-sec" id="s-esc"><div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-speedometer2 text-brand"></i> <?= et('Escalas funcionales') ?> <?= ayuda_btn('fisio.escalas') ?></span>
    <button type="button" class="btn btn-sm btn-light" data-add="esc"><i class="bi bi-plus"></i> <?= et('Fila') ?></button>
</div><div class="card-body p-2">
    <datalist id="dlEscalas"><?php foreach (fisio_escalas() as $n => [$max]): ?><option value="<?= e($n) ?>"><?= $max ? '0-' . $max : '' ?></option><?php endforeach; ?></datalist>
    <div class="table-responsive"><table class="table table-sm fv-tbl mb-0" id="tbl-esc"><thead><tr class="small text-muted"><th style="min-width:220px"><?= et('Escala') ?></th><th style="width:160px"><?= et('Puntaje') ?></th><th style="width:36px"></th></tr></thead>
    <tbody>
    <?php foreach ($esc ?: [[]] as $r): $a = $r['_ant'] ?? null; ?>
        <tr><td><input name="esc[nombre][]" class="form-control form-control-sm" list="dlEscalas" value="<?= e($r['nombre'] ?? '') ?>"></td>
            <td><input name="esc[valor][]" class="form-control form-control-sm" value="<?= e($r['valor'] ?? '') ?>"><?php if ($a && $a['valor'] !== ''): ?><span class="fv-ant"><?= et('antes') ?> <?= e($a['valor']) ?></span><?php endif; ?></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del><i class="bi bi-x-lg"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></div>

<div class="card mb-3 fv-sec" id="s-dx"><div class="card-header"><i class="bi bi-bullseye text-brand"></i> <?= et('Diagnóstico fisioterapéutico, objetivos y plan') ?></div><div class="card-body row g-2">
    <div class="col-12" id="altaBox" <?= $tipo === 'alta' ? '' : 'hidden' ?>><label class="form-label small"><?= et('Motivo del alta') ?></label>
        <select name="alta_motivo" class="form-select"><option value="">—</option><?php foreach (['Objetivos cumplidos', 'Mejoría parcial', 'Alta voluntaria', 'Abandono del tratamiento', 'Referido a otro profesional'] as $o): ?><option <?= ($v['alta_motivo'] ?? '') === $o ? 'selected' : '' ?>><?= et($o) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label small"><?= et('Diagnóstico fisioterapéutico') ?></label><textarea name="dx_fisio" class="form-control" rows="2" placeholder="<?= e(t('p. ej. Disfunción del manguito rotador derecho con dolor mecánico y limitación de la elevación')) ?>"><?= $val('dx_fisio') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Deficiencias (estructura y función)') ?></label><textarea name="deficiencias" class="form-control" rows="2"><?= $val('deficiencias') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Limitaciones en actividades y participación') ?></label><textarea name="limitaciones" class="form-control" rows="2"><?= $val('limitaciones') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Objetivos a corto plazo') ?></label><textarea name="obj_corto" class="form-control" rows="2"><?= $val('obj_corto') ?></textarea></div>
    <div class="col-12 col-md-6"><label class="form-label small"><?= et('Objetivos a largo plazo') ?></label><textarea name="obj_largo" class="form-control" rows="2"><?= $val('obj_largo') ?></textarea></div>
    <div class="col-12"><label class="form-label small"><?= et('Plan de tratamiento') ?></label><textarea name="plan" class="form-control" rows="3" placeholder="<?= e(t('Técnicas, agentes físicos, ejercicio terapéutico, educación…')) ?>"><?= $val('plan') ?></textarea></div>
    <div class="col-4 col-md-2"><label class="form-label small"><?= et('Sesiones estimadas') ?></label><input type="number" min="0" name="sesiones_est" class="form-control" value="<?= $val('sesiones_est') ?>"></div>
    <div class="col-8 col-md-4"><label class="form-label small"><?= et('Frecuencia') ?></label><input type="text" name="frecuencia" class="form-control" maxlength="80" list="dlFrec" value="<?= $val('frecuencia') ?>">
        <datalist id="dlFrec"><option value="1 vez por semana"><option value="2 veces por semana"><option value="3 veces por semana"><option value="Diario"></datalist></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Pronóstico') ?></label><select name="pronostico" class="form-select"><option value="">—</option><?php foreach (['Bueno', 'Regular', 'Reservado'] as $o): ?><option value="<?= $o ?>" <?= ($v['pronostico'] ?? '') === $o ? 'selected' : '' ?>><?= et($o) ?></option><?php endforeach; ?></select></div>
    <div class="col-12"><label class="form-label small"><?= et('Notas') ?></label><textarea name="notas" class="form-control" rows="2"><?= $val('notas') ?></textarea></div>
</div></div>

<div class="d-flex justify-content-end gap-2 mb-5">
    <button class="btn btn-outline-primary" name="despues" value="imprimir"><i class="bi bi-printer"></i> <?= et('Guardar e imprimir') ?></button>
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
</div>
</form>

<script>
(function () {
    var ROM = <?= json_encode(fisio_rom_normales(), JSON_UNESCAPED_UNICODE) ?>;
    var PRU = <?= json_encode(fisio_pruebas_region(), JSON_UNESCAPED_UNICODE) ?>;
    var region = document.getElementById('fvRegion');

    // Plantillas de fila: la primera fila de cada tabla, vacía.
    var tpl = {};
    ['rom', 'fza', 'pr', 'esc'].forEach(function (k) {
        var tb = document.querySelector('#tbl-' + k + ' tbody');
        var r = tb.rows[0].cloneNode(true);
        r.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        r.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        r.querySelectorAll('.fv-ant').forEach(function (s) { s.remove(); });
        r.querySelectorAll('[data-g=pct]').forEach(function (s) { s.textContent = ''; });
        tpl[k] = r;
    });
    function addRow(k, vals) {
        var tb = document.querySelector('#tbl-' + k + ' tbody');
        var r = tpl[k].cloneNode(true);
        if (vals) Object.keys(vals).forEach(function (c) { var el = r.querySelector('[name="' + k + '[' + c + '][]"]'); if (el) el.value = vals[c]; });
        tb.appendChild(r); pct(r); return r;
    }
    document.querySelectorAll('[data-add]').forEach(function (b) { b.addEventListener('click', function () { addRow(b.dataset.add).querySelector('input').focus(); }); });
    document.addEventListener('click', function (ev) {
        var d = ev.target.closest('[data-del]'); if (!d) return;
        var tr = d.closest('tr'), tb = tr.parentNode;
        if (tb.rows.length > 1) tr.remove(); else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    });

    // % del rango normal alcanzado (activo / normal).
    function pct(tr) {
        var a = tr.querySelector('[data-g=act]'), n = tr.querySelector('[data-g=nor]'), o = tr.querySelector('[data-g=pct]');
        if (!a || !n || !o) return;
        var av = parseFloat(a.value), nv = parseFloat(n.value);
        if (isNaN(av) || isNaN(nv) || nv <= 0) { o.textContent = ''; return; }
        var p = Math.round(av * 100 / nv);
        o.textContent = p + '%'; o.className = 'small fw-semibold text-' + (p >= 90 ? 'success' : p >= 60 ? 'warning' : 'danger');
    }
    document.getElementById('tbl-rom').addEventListener('input', function (ev) { pct(ev.target.closest('tr')); });
    document.querySelectorAll('#tbl-rom tbody tr').forEach(pct);

    document.getElementById('romCargar').addEventListener('click', function () {
        var r = region.value, movs = ROM[r];
        if (!movs) { alert(<?= json_encode(t('Elige primero una región con goniometría (cervical, hombro, rodilla…).')) ?>); return; }
        var tb = document.querySelector('#tbl-rom tbody');
        var ya = Array.prototype.map.call(tb.querySelectorAll('[name="rom[mov][]"]'), function (i) { return i.value.trim(); });
        // Si la única fila está vacía, se reemplaza.
        if (tb.rows.length === 1 && !ya[0]) tb.rows[0].remove();
        movs.forEach(function (m) { var nom = r + ' · ' + m[0]; if (ya.indexOf(nom) === -1) addRow('rom', { mov: nom, normal: m[1] }); });
    });

    function sugerencias() {
        var lista = PRU[region.value] || [], dl = document.getElementById('dlPruebas'), box = document.getElementById('prSug');
        var todas = []; Object.keys(PRU).forEach(function (k) { todas = todas.concat(PRU[k]); });
        dl.innerHTML = (lista.length ? lista : todas).map(function (p) { return '<option value="' + p.replace(/"/g, '&quot;') + '">'; }).join('');
        box.innerHTML = '';
        lista.forEach(function (p) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary py-0 me-1 mb-1'; b.textContent = '+ ' + p;
            b.addEventListener('click', function () {
                var tb = document.querySelector('#tbl-pr tbody'), first = tb.rows[0].querySelector('input');
                if (tb.rows.length === 1 && !first.value) first.value = p; else addRow('pr', { prueba: p });
                b.disabled = true;
            });
            box.appendChild(b);
        });
    }
    region.addEventListener('change', sugerencias); sugerencias();

    // EVA coloreada.
    function evaPaint() { document.querySelectorAll('.fv-eva label').forEach(function (l) { var i = document.getElementById(l.htmlFor); l.style.background = i.checked ? l.dataset.c : ''; }); }
    document.querySelectorAll('.fv-eva input').forEach(function (i) { i.addEventListener('change', evaPaint); }); evaPaint();
    document.querySelectorAll('.fv-eva label').forEach(function (l) { l.addEventListener('dblclick', function () { document.getElementById(l.htmlFor).checked = false; evaPaint(); }); });

    document.getElementById('fvTipo').addEventListener('change', function () { document.getElementById('altaBox').hidden = this.value !== 'alta'; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
