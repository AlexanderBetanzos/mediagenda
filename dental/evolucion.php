<?php
/**
 * Notas de evolución dentales: qué se hizo, en qué pieza y caras, con qué
 * anestesia y materiales, indicaciones y próxima cita. Si el procedimiento
 * corresponde a un tratamiento del odontograma, se puede marcar como
 * realizado ahí mismo (y sale del plan pendiente).
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$odoProc = ['obturacion' => 'Obturación con resina', 'sellante' => 'Sellador de fosetas', 'endodoncia' => 'Endodoncia', 'corona' => 'Corona',
            'protesis' => 'Puente fijo', 'implante' => 'Colocación de implante', 'extraccion' => 'Extracción simple'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? 'guardar';
    $eid = (int) ($_POST['id'] ?? 0);
    if ($accion === 'borrar') {
        db()->prepare('DELETE FROM dental_evolucion WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')->execute([$eid, $pid, $tid]);
        flash(t('Nota eliminada.'));
        redirect('/dental/evolucion?paciente_id=' . $pid);
    }
    $txt = fn ($k, $max = 5000) => ($s = trim((string) ($_POST[$k] ?? ''))) === '' ? null : mb_substr($s, 0, $max);
    $dientes = array_values(array_intersect(array_map('intval', (array) ($_POST['dientes'] ?? [])), dientes_fdi()));
    sort($dientes);
    $caras = implode('', array_intersect(str_split(strtoupper((string) ($_POST['caras'] ?? ''))), array_keys(odo_caras())));
    $proc = $txt('procedimiento', 160);
    if (!$proc) { flash(t('Escribe el procedimiento realizado.'), 'warning'); redirect('/dental/evolucion?paciente_id=' . $pid); }
    $fila = [preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d'),
             $dientes ? implode(', ', $dientes) : null, $caras ?: null, $proc, $txt('anestesia', 80), $txt('cartuchos', 10),
             $txt('materiales'), $txt('notas'), $txt('indicaciones'), $txt('proxima', 255)];
    if ($eid) {
        db()->prepare('UPDATE dental_evolucion SET fecha=?, dientes=?, caras=?, procedimiento=?, anestesia=?, cartuchos=?, materiales=?, notas=?, indicaciones=?, proxima=? WHERE id = ? AND paciente_id = ? AND consultorio_id = ?')
            ->execute([...$fila, $eid, $pid, $tid]);
        flash(t('Nota actualizada.'));
    } else {
        db()->prepare('INSERT INTO dental_evolucion (consultorio_id, paciente_id, creado_por, fecha, dientes, caras, procedimiento, anestesia, cartuchos, materiales, notas, indicaciones, proxima) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $pid, $u['id'], ...$fila]);
        flash(t('Nota de evolución registrada.'));
    }
    // Marcar realizado en el odontograma.
    $odo = $_POST['odo'] ?? '';
    if (isset($_POST['marcar_odo']) && isset(odo_tratamientos()[$odo]) && $dientes) {
        $def = odo_tratamientos()[$odo];
        $carasCsv = $def['completo'] ? 'C' : implode(',', str_split($caras ?: 'O'));
        $insR = db()->prepare("INSERT INTO odontograma_marcas (consultorio_id, paciente_id, diente, cara, condicion, estado, actualizado_por)
                               VALUES (?,?,?,?,'realizado',?,?) ON DUPLICATE KEY UPDATE estado = VALUES(estado)");
        foreach ($dientes as $d) {
            odo_aplicar_tratamiento($pid, (string) $d, $carasCsv, $odo);
            foreach (explode(',', $carasCsv) as $c) $insR->execute([$tid, $pid, (string) $d, $c, $odo, $u['id']]);
        }
        odo_snapshot($pid, t('Realizado') . ': ' . $proc);
        flash(t('Marcado como realizado en la carta dental.'));
    }
    auditar($eid ? 'editar' : 'crear', 'dental_evolucion', $eid ?: (int) db()->lastInsertId(), 'Paciente #' . $pid);
    redirect('/dental/evolucion?paciente_id=' . $pid);
}

$notas = db()->prepare('SELECT e.*, us.nombre AS por FROM dental_evolucion e LEFT JOIN usuarios us ON us.id = e.creado_por WHERE e.paciente_id = ? AND e.consultorio_id = ? ORDER BY e.fecha DESC, e.id DESC');
$notas->execute([$pid, $tid]);
$notas = $notas->fetchAll();
$edit = null;
if ($xid = (int) ($_GET['editar'] ?? 0)) foreach ($notas as $n) if ((int) $n['id'] === $xid) $edit = $n;
$pend = odo_requeridos($pid);
$preD = $edit ? array_map('intval', explode(',', (string) $edit['dientes'])) : (isset($_GET['diente']) ? [(int) $_GET['diente']] : []);

$titulo = t('Evolución dental') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
dental_encabezado('Notas de evolución', 'bi-journal-medical', $pac, $pid, 'evolucion');
$v = fn ($k) => e((string) ($edit[$k] ?? ''));
?>
<style>
.mini{display:grid;grid-template-columns:repeat(17,1fr);gap:2px;font-size:.7rem}
.mini .g{width:6px}
.mini label{display:block}
.mini input{display:none}
.mini span{display:block;text-align:center;border:1px solid var(--bs-border-color);border-radius:4px;padding:.2rem 0;cursor:pointer;font-weight:600}
.mini input:checked+span{background:#2563eb;border-color:#2563eb;color:#fff}
.mini span.p{border-color:#e11d48;color:#e11d48}
.ev-item{border-left:3px solid var(--bs-primary);padding-left:.8rem}
</style>
<div class="row g-3">
<div class="col-12 col-xl-5">
    <form method="post" class="card" id="evForm"><div class="card-body row g-2">
        <?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
        <input type="hidden" name="odo" id="evOdo">
        <h2 class="h6 col-12 mb-0"><?= $edit ? et('Editar nota') : et('Nueva nota de evolución') ?></h2>
        <?php if ($pend && !$edit): ?>
        <div class="col-12"><div class="small text-muted mb-1"><?= et('Pendientes del plan (toca para llenar):') ?></div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($pend as $r): $def = odo_tratamientos()[$r['estado']] ?? null; if (!$def) continue; $cs = implode('', array_filter(explode(',', (string) $r['caras']), fn ($c) => $c !== 'C')); ?>
                <button type="button" class="btn btn-sm btn-outline-danger py-0" data-pend data-d="<?= e($r['diente']) ?>" data-c="<?= e($cs) ?>" data-odo="<?= e($r['estado']) ?>" data-proc="<?= e(t($odoProc[$r['estado']] ?? $def['label'])) ?>">
                    <?= e($r['diente']) ?> · <?= et($def['label']) ?><?= $cs ? ' ' . e($cs) : '' ?></button>
            <?php endforeach; ?></div></div>
        <?php endif; ?>
        <div class="col-6"><label class="form-label small mb-0"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control form-control-sm" value="<?= e($edit['fecha'] ?? date('Y-m-d')) ?>"></div>
        <div class="col-6"><label class="form-label small mb-0"><?= et('Caras') ?></label><input type="text" name="caras" id="evCaras" class="form-control form-control-sm text-uppercase" maxlength="5" placeholder="O M D V L" value="<?= $v('caras') ?>"></div>
        <div class="col-12"><label class="form-label small mb-0"><?= et('Pieza(s)') ?></label>
            <?php $pendD = array_column($pend, 'diente'); $grid = function ($fila) use ($preD, $pendD) {
                $h = ''; foreach ($fila as $i => $d) { if ($i === 8) $h .= '<span class="g"></span>';
                    $h .= '<label><input type="checkbox" name="dientes[]" value="' . $d . '"' . (in_array($d, $preD, true) ? ' checked' : '') . '><span class="' . (in_array((string) $d, $pendD, true) ? 'p' : '') . '">' . $d . '</span></label>'; }
                return $h; }; ?>
            <div class="mini mb-1"><?= $grid([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28]) ?></div>
            <div class="mini"><?= $grid([48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38]) ?></div></div>
        <div class="col-12"><label class="form-label small mb-0"><?= et('Procedimiento') ?></label><input type="text" name="procedimiento" id="evProc" class="form-control form-control-sm" list="dlProc" maxlength="160" required value="<?= $v('procedimiento') ?>">
            <datalist id="dlProc"><?php foreach (dental_procedimientos() as $p): ?><option value="<?= e(t($p)) ?>"><?php endforeach; ?></datalist></div>
        <div class="col-8"><label class="form-label small mb-0"><?= et('Anestesia') ?></label><input type="text" name="anestesia" class="form-control form-control-sm" list="dlAnest" maxlength="80" value="<?= $v('anestesia') ?>">
            <datalist id="dlAnest"><option value="Lidocaína 2% c/epinefrina · infiltrativa"><option value="Lidocaína 2% c/epinefrina · bloqueo dentario inferior"><option value="Articaína 4% c/epinefrina"><option value="Mepivacaína 3% sin vasoconstrictor"><option value="Tópica"><option value="Sin anestesia"></datalist></div>
        <div class="col-4"><label class="form-label small mb-0"><?= et('Cartuchos') ?></label><input type="text" name="cartuchos" class="form-control form-control-sm" maxlength="10" inputmode="decimal" value="<?= $v('cartuchos') ?>"></div>
        <div class="col-12"><label class="form-label small mb-0"><?= et('Materiales y técnica') ?></label><input type="text" name="materiales" class="form-control form-control-sm" placeholder="<?= e(t('Aislamiento, resina A2, adhesivo, lima, cemento…')) ?>" value="<?= $v('materiales') ?>"></div>
        <div class="col-12"><label class="form-label small mb-0"><?= et('Descripción / hallazgos') ?></label><textarea name="notas" class="form-control form-control-sm" rows="3"><?= $v('notas') ?></textarea></div>
        <div class="col-12"><label class="form-label small mb-0"><?= et('Indicaciones al paciente') ?></label><textarea name="indicaciones" class="form-control form-control-sm" rows="2"><?= $v('indicaciones') ?></textarea></div>
        <div class="col-12"><label class="form-label small mb-0"><?= et('Próxima cita / siguiente paso') ?></label><input type="text" name="proxima" class="form-control form-control-sm" maxlength="255" value="<?= $v('proxima') ?>"></div>
        <div class="col-12" id="evOdoBox" hidden><div class="form-check"><input class="form-check-input" type="checkbox" name="marcar_odo" id="evMarca" checked><label class="form-check-label small" for="evMarca"><?= et('Marcar como realizado en la carta dental') ?> <b id="evOdoL"></b></label></div></div>
        <div class="col-12 d-flex justify-content-end gap-2">
            <?php if ($edit): ?><a href="<?= BASE_URL ?>/dental/evolucion<?= $P ?>" class="btn btn-sm btn-light"><?= et('Cancelar') ?></a><?php endif; ?>
            <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar nota') ?></button>
        </div>
    </div></form>
</div>
<div class="col-12 col-xl-7">
    <?php if (!$notas): ?><div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-journal-medical fs-1 d-block mb-2"></i><?= et('Sin notas de evolución.') ?></div></div><?php endif; ?>
    <?php foreach ($notas as $n): ?>
    <div class="card mb-2"><div class="card-body py-2">
        <div class="d-flex justify-content-between gap-2">
            <div class="ev-item flex-grow-1">
                <div class="fw-semibold"><?= e($n['procedimiento']) ?><?php if ($n['dientes']): ?> · <span class="badge bg-primary-subtle text-primary-emphasis"><?= e($n['dientes']) ?><?= $n['caras'] ? ' ' . e($n['caras']) : '' ?></span><?php endif; ?></div>
                <div class="small text-muted"><?= e(fmt_fecha($n['fecha'])) ?><?= $n['por'] ? ' · ' . e($n['por']) : '' ?><?= $n['anestesia'] ? ' · ' . e($n['anestesia']) . ($n['cartuchos'] ? ' (' . e($n['cartuchos']) . ' ' . et('cart.') . ')' : '') : '' ?></div>
                <?php if ($n['materiales']): ?><div class="small mt-1"><i class="bi bi-tools text-muted"></i> <?= e($n['materiales']) ?></div><?php endif; ?>
                <?php if ($n['notas']): ?><div class="small mt-1" style="white-space:pre-wrap"><?= e($n['notas']) ?></div><?php endif; ?>
                <?php if ($n['indicaciones']): ?><div class="small mt-1 text-muted"><i class="bi bi-info-circle"></i> <?= e($n['indicaciones']) ?></div><?php endif; ?>
                <?php if ($n['proxima']): ?><div class="small mt-1"><i class="bi bi-arrow-right-circle text-brand"></i> <?= e($n['proxima']) ?></div><?php endif; ?>
            </div>
            <div class="d-flex flex-column gap-1">
                <a href="?paciente_id=<?= $pid ?>&editar=<?= (int) $n['id'] ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-pencil"></i></a>
                <form method="post" class="m-0" onsubmit="return confirm('<?= e(t('¿Eliminar esta nota?')) ?>')"><?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form>
            </div>
        </div>
    </div></div>
    <?php endforeach; ?>
</div>
</div>
<script>
(function () {
    var PROC2ODO = <?php $m = []; foreach (dental_procedimientos() as $p) { if ($k = dental_proc_a_odo($p)) $m[mb_strtolower(t($p))] = $k; } echo json_encode((object) $m, JSON_UNESCAPED_UNICODE); ?>;
    var LBL = <?= json_encode(array_map(fn ($d) => t($d['label']), odo_tratamientos()), JSON_UNESCAPED_UNICODE) ?>;
    var proc = document.getElementById('evProc'), odo = document.getElementById('evOdo'), box = document.getElementById('evOdoBox');
    function sync() {
        var k = PROC2ODO[proc.value.trim().toLowerCase()] || odo.dataset.forz || '';
        odo.value = k; box.hidden = !k || <?= $edit ? 'true' : 'false' ?>;
        document.getElementById('evOdoL').textContent = k ? '(' + LBL[k] + ')' : '';
    }
    proc.addEventListener('input', function () { odo.dataset.forz = ''; sync(); });
    document.querySelectorAll('[data-pend]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('input[name="dientes[]"]').forEach(function (c) { c.checked = c.value === b.dataset.d; });
            document.getElementById('evCaras').value = b.dataset.c;
            proc.value = b.dataset.proc; odo.dataset.forz = b.dataset.odo; sync();
        });
    });
    sync();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
