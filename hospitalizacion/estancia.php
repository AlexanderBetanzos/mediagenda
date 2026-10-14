<?php
/**
 * Una estancia: kardex del día (dosis por hora), lo que toca aplicar ahora,
 * indicaciones, notas de evolución con signos y el alta.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/hospitalizacion.php';
require_login();
require_modulo('expediente');
asegurar_hosp();

$pdo = db();
$tid = (int) tenant_id();
$u = current_user();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$esClin = has_role('medico', 'admin');

$cargar = function () use ($pdo, $tid, $id) {
    $st = $pdo->prepare('SELECT e.*, p.nombre, p.apellidos, p.alergias, p.fecha_nacimiento, COALESCE(p.foto_mime, p.foto) foto, u.nombre medico
                         FROM hosp_estancias e JOIN pacientes p ON p.id = e.paciente_id LEFT JOIN usuarios u ON u.id = e.medico_id
                         WHERE e.id = ? AND e.consultorio_id = ?');
    $st->execute([$id, $tid]);
    return $st->fetch();
};
$e = $cargar();
if (!$e) { http_response_code(404); die('Estancia no encontrada.'); }
$activa = $e['estado'] === 'activa';

$inds = function () use ($pdo, $tid, $id) {
    $st = $pdo->prepare('SELECT * FROM hosp_indicaciones WHERE consultorio_id = ? AND estancia_id = ? ORDER BY suspendida_en IS NOT NULL, inicio, id');
    $st->execute([$tid, $id]);
    return $st->fetchAll();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['dia'] ?? '')) ? '&dia=' . $_POST['dia'] : '';
    $txt = fn (string $k, int $max) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    if (!$activa && $acc !== 'reabrir') { flash(t('La estancia ya tiene alta.'), 'warning'); redirect('/hospitalizacion/estancia?id=' . $id); }

    if ($acc === 'ind_agregar' && $esClin) {
        $med = $txt('medicamento', 160);
        $ini = strtotime(str_replace('T', ' ', (string) ($_POST['inicio'] ?? ''))) ?: time();
        $frec = (float) ($_POST['frecuencia'] ?? 0);
        if ($frec === -1.0) $frec = max(0, (float) ($_POST['frecuencia_otra'] ?? 0));
        $diasDur = (float) ($_POST['dias'] ?? 0);
        if ($med === '') { flash(t('Escribe el medicamento o cuidado.'), 'warning'); }
        else {
            $pdo->prepare('INSERT INTO hosp_indicaciones (consultorio_id, estancia_id, medicamento, dosis, via, frecuencia_horas, inicio, fin, notas, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tid, $id, $med, $txt('dosis', 80) ?: null, $txt('via', 40) ?: null, $frec ?: null, date('Y-m-d H:i:s', $ini),
                           $diasDur > 0 ? date('Y-m-d H:i:s', $ini + (int) round($diasDur * 86400) - 60) : null, $txt('notas', 255) ?: null, (int) $u['id']]);
            auditar('hosp_indicacion', 'estancia', $id, $med);
            flash(t('Indicación agregada al kardex.'));
        }
    } elseif ($acc === 'ind_suspender' && $esClin) {
        $pdo->prepare('UPDATE hosp_indicaciones SET suspendida_en = NOW() WHERE id = ? AND estancia_id = ? AND consultorio_id = ? AND suspendida_en IS NULL')
            ->execute([(int) $_POST['ind'], $id, $tid]);
        flash(t('Indicación suspendida. Las dosis siguientes ya no se programan.'));
    } elseif ($acc === 'aplicar') {
        $iid = (int) ($_POST['ind'] ?? 0);
        $prog = strtotime((string) ($_POST['programada'] ?? ''));
        $estado = ($_POST['estado'] ?? '') === 'omitida' ? 'omitida' : 'aplicada';
        $st = $pdo->prepare('SELECT * FROM hosp_indicaciones WHERE id = ? AND estancia_id = ? AND consultorio_id = ?');
        $st->execute([$iid, $id, $tid]);
        $ind = $st->fetch();
        // Solo dosis que de verdad están en el kardex (y no del futuro lejano).
        if ($ind && $prog && in_array($prog, hosp_dosis($ind, $prog - 1, $prog + 1), true) && $prog <= time() + 3 * 3600) {
            $pdo->prepare('INSERT IGNORE INTO hosp_aplicaciones (consultorio_id, estancia_id, indicacion_id, programada, estado, hecho_en, hecho_por, notas) VALUES (?,?,?,?,?,NOW(),?,?)')
                ->execute([$tid, $id, $iid, date('Y-m-d H:i:s', $prog), $estado, (int) $u['id'], $txt('notas', 255) ?: null]);
            flash($estado === 'aplicada' ? t('Dosis registrada como aplicada.') : t('Dosis registrada como omitida.'));
        } else {
            flash(t('Esa dosis no se puede registrar todavía.'), 'warning');
        }
    } elseif ($acc === 'deshacer') {
        // Corregir un registro: quien lo hizo, o un médico/admin.
        $pdo->prepare('DELETE FROM hosp_aplicaciones WHERE id = ? AND estancia_id = ? AND consultorio_id = ?' . ($esClin ? '' : ' AND hecho_por = ' . (int) $u['id']))
            ->execute([(int) $_POST['apl'], $id, $tid]);
        flash(t('Registro de la dosis deshecho.'));
    } elseif ($acc === 'nota') {
        if ($txt('texto', 5000) !== '' || $txt('temp', 10) !== '' || $txt('fc', 10) !== '' || $txt('ta', 15) !== '') {
            $pdo->prepare('INSERT INTO hosp_notas (consultorio_id, estancia_id, texto, temp, fc, fr, ta, creado_por, creado_en) VALUES (?,?,?,?,?,?,?,?,NOW())')
                ->execute([$tid, $id, $txt('texto', 5000) ?: null, $txt('temp', 10) ?: null, $txt('fc', 10) ?: null, $txt('fr', 10) ?: null, $txt('ta', 15) ?: null, (int) $u['id']]);
            flash(t('Nota agregada.'));
        }
    } elseif ($acc === 'alta' && $esClin) {
        $pdo->prepare("UPDATE hosp_estancias SET estado = 'alta', alta_en = NOW(), alta_notas = ? WHERE id = ? AND consultorio_id = ?")
            ->execute([$txt('alta_notas', 5000) ?: null, $id, $tid]);
        auditar('hosp_alta', 'paciente', (int) $e['paciente_id'], 'Estancia #' . $id);
        flash(t('Alta registrada.'));
    } elseif ($acc === 'reabrir' && has_role('admin') && !$activa) {
        $pdo->prepare("UPDATE hosp_estancias SET estado = 'activa', alta_en = NULL WHERE id = ? AND consultorio_id = ?")->execute([$id, $tid]);
        flash(t('Estancia reabierta.'));
    }
    redirect('/hospitalizacion/estancia?id=' . $id . $dia);
}

$indicaciones = $inds();
$apl = hosp_aplicaciones($id);
$fin = $activa ? time() : strtotime($e['alta_en']);

/* Día del kardex: hoy (o el del alta), navegable entre el ingreso y el fin. */
$diaMin = date('Y-m-d', strtotime($e['ingreso']));
$diaMax = date('Y-m-d', $activa ? time() + 86400 : $fin);
$dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['dia'] ?? '')) ? $_GET['dia'] : date('Y-m-d', $activa ? time() : $fin);
$dia = max($diaMin, min($diaMax, $dia));
$d0 = strtotime($dia . ' 00:00:00');
$d1 = $d0 + 86399;

/* Por hora del día: [indicacion_id][hora] => [[ts, registro|null], ...] */
$grid = [];
foreach ($indicaciones as $i) {
    foreach (hosp_dosis($i, $d0, $activa ? $d1 : min($d1, $fin)) as $t) {
        $grid[$i['id']][(int) date('G', $t)][] = [$t, $apl[$i['id'] . '|' . date('Y-m-d H:i:s', $t)] ?? null];
    }
}

/* Lo que toca: dosis sin registrar de las últimas 24 h y las próximas 2 h. */
$toca = [];
if ($activa) {
    foreach ($indicaciones as $i) {
        if ($i['suspendida_en']) continue;
        foreach (hosp_dosis($i, time() - 86400, time() + 7200) as $t) {
            if (!isset($apl[$i['id'] . '|' . date('Y-m-d H:i:s', $t)])) $toca[] = [$t, $i];
        }
    }
    usort($toca, fn ($a, $b) => $a[0] <=> $b[0]);
}

$notas = $pdo->prepare('SELECT n.*, u.nombre quien FROM hosp_notas n LEFT JOIN usuarios u ON u.id = n.creado_por
                        WHERE n.consultorio_id = ? AND n.estancia_id = ? ORDER BY n.creado_en DESC, n.id DESC');
$notas->execute([$tid, $id]);
$notas = $notas->fetchAll();
$frecs = hosp_frecuencias();
$etqFrec = fn ($h) => $h ? ($frecs[(int) $h] ?? ('c/' . rtrim(rtrim(number_format((float) $h, 2), '0'), '.') . ' h')) : t('Dosis única');

$titulo = t('Hospitalización') . ' · ' . $e['nombre'];
$activo = 'hospitalizacion';
include __DIR__ . '/../includes/header.php';
?>
<style>
.kx-wrap{overflow-x:auto}
.kx{border-collapse:separate;border-spacing:0;width:100%;min-width:900px;font-size:.82rem}
.kx th,.kx td{border-bottom:1px solid var(--bs-border-color);padding:6px 2px;text-align:center;vertical-align:middle}
.kx th.med,.kx td.med{position:sticky;left:0;background:var(--bs-body-bg);text-align:left;padding:6px 10px;min-width:200px;z-index:1;box-shadow:1px 0 0 var(--bs-border-color)}
.kx thead th{font-size:.7rem;color:var(--bs-secondary-color);font-weight:600}
.kx th.ahora{color:var(--brand);font-weight:800}
.kx td.ahora{background:color-mix(in srgb,var(--brand) 7%,transparent)}
.kx .dosis{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;border:2px solid var(--bs-border-color);background:transparent;padding:0;font-size:.8rem;line-height:1}
.kx .dosis.pend{border-color:color-mix(in srgb,var(--brand) 55%,transparent);color:var(--brand)}
.kx .dosis.atr{border-color:#dc2626;background:#fee2e2;color:#b91c1c;animation:kxPulso 1.6s ease-in-out infinite}
.kx .dosis.ok{border-color:#16a34a;background:#16a34a;color:#fff}
.kx .dosis.omi{border-color:#94a3b8;background:#e2e8f0;color:#475569}
.kx tr.susp td{opacity:.5}
@keyframes kxPulso{50%{box-shadow:0 0 0 5px rgba(220,38,38,.18)}}
@media (prefers-reduced-motion:reduce){.kx .dosis.atr{animation:none}}
.kx-ley span{display:inline-flex;align-items:center;gap:6px;margin-right:14px}
.kx-ley i{width:14px;height:14px;border-radius:50%;border:2px solid;display:inline-block}
@media print{
 .app-navbar,.sidebar,.breadcrumb,.no-print,footer,.ayuda-fab{display:none!important}
 main{width:100%!important;max-width:100%!important;flex:0 0 100%!important}
 .kx-wrap{overflow:visible}.kx{min-width:0;font-size:.7rem}.kx .dosis{width:18px;height:18px;font-size:.6rem}
 .kx .dosis.atr{animation:none}
 *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>

<nav aria-label="breadcrumb" class="no-print"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/hospitalizacion/index"><?= et('Hospitalización') ?></a></li>
    <li class="breadcrumb-item active"><?= e($e['nombre'] . ' ' . $e['apellidos']) ?></li>
</ol></nav>

<div class="card mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <?= avatar_paciente((int) $e['paciente_id'], $e['nombre'], $e['apellidos'], $e['foto'] ?? null, 56) ?>
    <div class="flex-grow-1 min-w-0">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h1 class="h4 mb-0"><?= e($e['nombre'] . ' ' . $e['apellidos']) ?></h1>
            <?php if ($e['cama']): ?><span class="badge text-bg-primary"><?= et(es_veterinaria() ? 'Jaula' : 'Cama') ?> <?= e($e['cama']) ?></span><?php endif; ?>
            <span class="badge <?= $activa ? 'text-bg-warning' : 'text-bg-success' ?>"><?= $activa ? et('Hospitalizado') : et('Alta') ?></span>
        </div>
        <div class="small text-muted mt-1">
            <?= et('Ingreso') ?> <?= fmt_fecha($e['ingreso']) ?> <?= date('H:i', strtotime($e['ingreso'])) ?> · <?= e(hosp_tiempo($e['ingreso'], $activa ? null : $e['alta_en'])) ?>
            <?php if ($e['medico']): ?> · <?= e($e['medico']) ?><?php endif; ?>
        </div>
        <?php if ($e['diagnostico'] || $e['motivo']): ?><div class="small mt-1"><b><?= et('Dx') ?>:</b> <?= e($e['diagnostico'] ?: '—') ?><?php if ($e['motivo']): ?> · <span class="text-muted"><?= e($e['motivo']) ?></span><?php endif; ?></div><?php endif; ?>
        <?php if (trim((string) $e['alergias']) !== ''): ?><div class="mt-1"><span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= et('Alergias') ?>: <?= e($e['alergias']) ?></span></div><?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $e['paciente_id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-person"></i> <?= et('Ficha') ?></a>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="bi bi-printer"></i> <?= et('Imprimir kardex') ?></button>
        <?php if ($activa && $esClin): ?><button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#mAlta"><i class="bi bi-box-arrow-right"></i> <?= et('Dar de alta') ?></button><?php endif; ?>
        <?php if (!$activa && has_role('admin')): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="reabrir">
            <button class="btn btn-outline-warning"><i class="bi bi-arrow-counterclockwise"></i> <?= et('Reabrir') ?></button></form>
        <?php endif; ?>
    </div>
</div></div>

<?php if (!$activa && $e['alta_notas']): ?>
<div class="alert alert-success"><b><?= et('Alta') ?> <?= fmt_fecha($e['alta_en']) ?>:</b> <?= nl2br(e($e['alta_notas'])) ?></div>
<?php endif; ?>

<?php if ($activa): ?>
<div class="card mb-3 no-print"><div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-alarm text-danger"></i> <?= et('Lo que toca') ?> <span class="text-muted fw-normal small">· <?= et('últimas 24 h y próximas 2 h') ?></span></h2>
    <?php if (!$toca): ?>
        <p class="text-muted small mb-0"><i class="bi bi-check-circle text-success"></i> <?= $indicaciones ? et('Todo al día: no hay dosis pendientes.') : et('Aún no hay indicaciones. Agrégalas abajo para armar el kardex.') ?></p>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($toca as [$t, $i]): $atr = $t < time() - 1800; ?>
        <div class="list-group-item px-0 d-flex flex-wrap align-items-center gap-2">
            <span class="badge <?= $atr ? 'text-bg-danger' : ($t <= time() ? 'text-bg-warning' : 'text-bg-light border') ?> fs-6" style="min-width:64px"><?= date('H:i', $t) ?></span>
            <div class="flex-grow-1 min-w-0"><b><?= e($i['medicamento']) ?></b> <span class="text-muted"><?= e(implode(' · ', array_filter([$i['dosis'], $i['via']]))) ?></span>
                <?php if ($atr): ?><div class="small text-danger"><?= et('Atrasada') ?> · <?= date('d/m', $t) ?></div><?php endif; ?></div>
            <form method="post" class="d-flex gap-1">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="aplicar">
                <input type="hidden" name="ind" value="<?= (int) $i['id'] ?>"><input type="hidden" name="programada" value="<?= date('Y-m-d H:i:s', $t) ?>">
                <button name="estado" value="aplicada" class="btn btn-sm btn-success"><i class="bi bi-check2"></i> <?= et('Aplicada') ?></button>
                <button name="estado" value="omitida" class="btn btn-sm btn-outline-secondary" title="<?= et('No se aplicó') ?>"><?= et('Omitir') ?></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 class="h6 mb-0"><i class="bi bi-grid-3x3-gap"></i> <?= et('Kardex') ?> · <?= fmt_fecha($dia) ?></h2>
        <div class="btn-group btn-group-sm no-print">
            <?php $prev = date('Y-m-d', $d0 - 86400); $next = date('Y-m-d', $d0 + 86400); ?>
            <a class="btn btn-outline-secondary<?= $prev < $diaMin ? ' disabled' : '' ?>" href="?id=<?= $id ?>&dia=<?= $prev ?>"><i class="bi bi-chevron-left"></i></a>
            <a class="btn btn-outline-secondary" href="?id=<?= $id ?>"><?= et('Hoy') ?></a>
            <a class="btn btn-outline-secondary<?= $next > $diaMax ? ' disabled' : '' ?>" href="?id=<?= $id ?>&dia=<?= $next ?>"><i class="bi bi-chevron-right"></i></a>
        </div>
    </div>
    <?php if (!$indicaciones): ?>
        <p class="text-muted small mb-0"><?= et('Sin indicaciones todavía.') ?></p>
    <?php else: $horaAhora = date('Y-m-d') === $dia ? (int) date('G') : -1; ?>
    <div class="kx-wrap">
    <table class="kx">
        <thead><tr><th class="med"><?= et('Indicación') ?></th><?php for ($h = 0; $h < 24; $h++): ?><th class="<?= $h === $horaAhora ? 'ahora' : '' ?>"><?= str_pad((string) $h, 2, '0', STR_PAD_LEFT) ?></th><?php endfor; ?></tr></thead>
        <tbody>
        <?php foreach ($indicaciones as $i): ?>
            <tr class="<?= $i['suspendida_en'] ? 'susp' : '' ?>">
                <td class="med"><b><?= e($i['medicamento']) ?></b>
                    <div class="small text-muted"><?= e(implode(' · ', array_filter([$i['dosis'], $i['via'], $etqFrec($i['frecuencia_horas'])]))) ?><?= $i['suspendida_en'] ? ' · ' . et('suspendida') : '' ?></div></td>
                <?php for ($h = 0; $h < 24; $h++): ?><td class="<?= $h === $horaAhora ? 'ahora' : '' ?>">
                    <?php foreach ($grid[$i['id']][$h] ?? [] as [$t, $r]):
                        $cls = $r ? ($r['estado'] === 'aplicada' ? 'ok' : 'omi') : ($t < time() - 1800 ? 'atr' : 'pend');
                        $tip = date('H:i', $t) . ($r ? ' · ' . t($r['estado'] === 'aplicada' ? 'Aplicada' : 'Omitida') . ' ' . date('H:i', strtotime($r['hecho_en'])) . ' · ' . ($r['quien'] ?? '') . ($r['notas'] ? ' · ' . $r['notas'] : '') : ''); ?>
                        <button type="button" class="dosis <?= $cls ?>" title="<?= e($tip) ?>" data-bs-toggle="modal" data-bs-target="#mDosis"
                            data-ind="<?= (int) $i['id'] ?>" data-prog="<?= date('Y-m-d H:i:s', $t) ?>" data-apl="<?= $r ? (int) $r['id'] : '' ?>"
                            data-med="<?= e($i['medicamento'] . ' ' . ($i['dosis'] ?? '')) ?>" data-info="<?= e($tip) ?>"
                            data-puede="<?= ($activa && !$r && $t <= time() + 3 * 3600) ? '1' : '' ?>"><?= $cls === 'ok' ? '✓' : ($cls === 'omi' ? '–' : '') ?></button>
                    <?php endforeach; ?>
                </td><?php endfor; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="kx-ley small text-muted mt-2">
        <span><i style="border-color:var(--brand)"></i><?= et('Programada') ?></span><span><i style="border-color:#dc2626;background:#fee2e2"></i><?= et('Atrasada') ?></span>
        <span><i style="border-color:#16a34a;background:#16a34a"></i><?= et('Aplicada') ?></span><span><i style="border-color:#94a3b8;background:#e2e8f0"></i><?= et('Omitida') ?></span>
    </div>
    <?php endif; ?>
</div></div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-capsule"></i> <?= et('Indicaciones') ?></h2>
            <?php foreach ($indicaciones as $i): ?>
            <div class="d-flex align-items-start gap-2 py-2 border-bottom<?= $i['suspendida_en'] ? ' opacity-50' : '' ?>">
                <div class="flex-grow-1"><b><?= e($i['medicamento']) ?></b> <span class="text-muted small"><?= e(implode(' · ', array_filter([$i['dosis'], $i['via'], $etqFrec($i['frecuencia_horas'])]))) ?></span>
                    <div class="small text-muted"><?= et('Desde') ?> <?= date('d/m H:i', strtotime($i['inicio'])) ?><?= $i['fin'] ? ' · ' . et('hasta') . ' ' . date('d/m H:i', strtotime($i['fin'])) : '' ?>
                        <?= $i['suspendida_en'] ? ' · <span class="text-danger">' . et('suspendida') . ' ' . date('d/m H:i', strtotime($i['suspendida_en'])) . '</span>' : '' ?></div>
                    <?php if ($i['notas']): ?><div class="small"><?= e($i['notas']) ?></div><?php endif; ?></div>
                <?php if ($activa && $esClin && !$i['suspendida_en']): ?>
                <form method="post" class="no-print" onsubmit="return confirm(<?= e(json_encode(t('¿Suspender esta indicación?'))) ?>)">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="ind_suspender"><input type="hidden" name="ind" value="<?= (int) $i['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><?= et('Suspender') ?></button></form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($activa && $esClin): ?>
            <form method="post" class="row g-2 mt-2 no-print">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="ind_agregar"><input type="hidden" name="dia" value="<?= e($dia) ?>">
                <div class="col-12"><input name="medicamento" class="form-control" maxlength="160" required placeholder="<?= et('Medicamento o cuidado (ej. Ceftriaxona, curación, signos vitales)') ?>"></div>
                <div class="col-6 col-md-3"><input name="dosis" class="form-control" maxlength="80" placeholder="<?= et('Dosis') ?>"></div>
                <div class="col-6 col-md-3"><input name="via" class="form-control" maxlength="40" list="vias" placeholder="<?= et('Vía') ?>">
                    <datalist id="vias"><?php foreach (hosp_vias() as $v): ?><option value="<?= e($v) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-6 col-md-3"><select name="frecuencia" class="form-select" id="kxFrec">
                    <?php foreach ($frecs as $h => $l): ?><option value="<?= $h ?>" <?= $h === 8 ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    <option value="-1"><?= et('Otra…') ?></option></select>
                    <input type="number" name="frecuencia_otra" id="kxFrecOtra" class="form-control mt-1 d-none" step="0.5" min="0.5" placeholder="<?= et('Cada cuántas horas') ?>"></div>
                <div class="col-6 col-md-3"><input type="number" name="dias" class="form-control" step="0.5" min="0" placeholder="<?= et('Días (opcional)') ?>"></div>
                <div class="col-md-5"><label class="small text-muted"><?= et('Primera dosis') ?></label><input type="datetime-local" name="inicio" class="form-control" value="<?= date('Y-m-d\TH:00', time() + 3600) ?>"></div>
                <div class="col-md-7"><label class="small text-muted"><?= et('Nota') ?></label><input name="notas" class="form-control" maxlength="255" placeholder="<?= et('Diluir en 100 ml, pasar en 30 min…') ?>"></div>
                <div class="col-12 text-end"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> <?= et('Agregar indicación') ?></button></div>
            </form>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-journal-medical"></i> <?= et('Evolución y signos') ?></h2>
            <?php if ($activa): ?>
            <form method="post" class="mb-3 no-print">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="nota"><input type="hidden" name="dia" value="<?= e($dia) ?>">
                <div class="row g-2 mb-2">
                    <div class="col-3"><input name="temp" class="form-control form-control-sm" maxlength="10" placeholder="T °C"></div>
                    <div class="col-3"><input name="fc" class="form-control form-control-sm" maxlength="10" placeholder="FC"></div>
                    <div class="col-3"><input name="fr" class="form-control form-control-sm" maxlength="10" placeholder="FR"></div>
                    <div class="col-3"><input name="ta" class="form-control form-control-sm" maxlength="15" placeholder="TA"></div>
                </div>
                <textarea name="texto" class="form-control mb-2" rows="2" placeholder="<?= et('Cómo sigue: evolución, cambios, pendientes…') ?>"></textarea>
                <div class="text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Agregar nota') ?></button></div>
            </form>
            <?php endif; ?>
            <?php if (!$notas): ?><p class="text-muted small mb-0"><?= et('Sin notas todavía.') ?></p><?php endif; ?>
            <?php foreach ($notas as $n): ?>
            <div class="border-start border-3 ps-3 mb-3" style="border-color:var(--brand)!important">
                <div class="small text-muted"><?= date('d/m H:i', strtotime($n['creado_en'])) ?> · <?= e($n['quien'] ?? '') ?></div>
                <?php $sv = array_filter(['T' => $n['temp'] ? $n['temp'] . ' °C' : '', 'FC' => $n['fc'], 'FR' => $n['fr'], 'TA' => $n['ta']]); if ($sv): ?>
                <div class="d-flex flex-wrap gap-1 my-1"><?php foreach ($sv as $k => $v): ?><span class="badge text-bg-light border"><?= $k ?> <?= e($v) ?></span><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if ($n['texto']): ?><div><?= nl2br(e($n['texto'])) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div></div>
    </div>
</div>

<div class="modal fade" id="mDosis" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title fs-6" id="mdMed"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <p class="small text-muted mb-2" id="mdInfo"></p>
        <form method="post" id="mdForm">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="aplicar"><input type="hidden" name="dia" value="<?= e($dia) ?>">
            <input type="hidden" name="ind" id="mdInd"><input type="hidden" name="programada" id="mdProg">
            <input name="notas" class="form-control form-control-sm mb-2" maxlength="255" placeholder="<?= et('Nota (opcional)') ?>">
            <div class="d-flex gap-2"><button name="estado" value="aplicada" class="btn btn-success flex-fill"><i class="bi bi-check2"></i> <?= et('Aplicada') ?></button>
                <button name="estado" value="omitida" class="btn btn-outline-secondary flex-fill"><?= et('Omitida') ?></button></div>
        </form>
        <form method="post" id="mdUndo" class="d-none">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="deshacer"><input type="hidden" name="dia" value="<?= e($dia) ?>">
            <input type="hidden" name="apl" id="mdApl">
            <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise"></i> <?= et('Deshacer este registro') ?></button>
        </form>
        <p class="small text-muted mb-0 d-none" id="mdNo"><?= et('Esta dosis aún no se puede registrar.') ?></p>
    </div>
</div></div></div>

<?php if ($activa && $esClin): ?>
<div class="modal fade" id="mAlta" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="alta">
        <div class="modal-header"><h5 class="modal-title"><?= et('Dar de alta') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label"><?= et('Resumen de alta e indicaciones para casa') ?></label>
            <textarea name="alta_notas" class="form-control" rows="5"></textarea>
            <?php if ($toca): ?><div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-exclamation-triangle"></i> <?= sprintf(et('Hay %d dosis sin registrar en el kardex.'), count($toca)) ?></div><?php endif; ?>
        </div>
        <div class="modal-footer"><button class="btn btn-success"><i class="bi bi-box-arrow-right"></i> <?= et('Confirmar alta') ?></button></div>
    </form>
</div></div></div>
<?php endif; ?>

<script>
(function () {
    var f = document.getElementById('kxFrec');
    if (f) f.addEventListener('change', function () { document.getElementById('kxFrecOtra').classList.toggle('d-none', f.value !== '-1'); });
    var m = document.getElementById('mDosis');
    m.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget, d = b.dataset;
        document.getElementById('mdMed').textContent = d.med;
        document.getElementById('mdInfo').textContent = d.info;
        document.getElementById('mdInd').value = d.ind;
        document.getElementById('mdProg').value = d.prog;
        document.getElementById('mdApl').value = d.apl;
        document.getElementById('mdForm').classList.toggle('d-none', !d.puede);
        document.getElementById('mdUndo').classList.toggle('d-none', !d.apl || <?= $activa ? 'false' : 'true' ?>);
        document.getElementById('mdNo').classList.toggle('d-none', !!(d.puede || d.apl));
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
