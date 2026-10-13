<?php
/**
 * Cerrar corte de caja (ver includes/cortes.php). Cubre lo cobrado desde el
 * último cierre del mismo alcance hasta ahora:
 *   ?alcance=propio   (por omisión) lo que cobró la cuenta en sesión;
 *   ?alcance=general  todo el consultorio (solo admin).
 * Fondo inicial + efectivo cobrado − retiros − egresos en efectivo = esperado;
 * contado − esperado = diferencia. Al cerrar queda inmutable, se audita y se
 * avisa por correo al dueño.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cortes.php';
require_login();
require_modulo('facturacion');

$u       = current_user();
$esAdmin = has_role('admin');
$alcance = ($_GET['alcance'] ?? $_POST['alcance'] ?? 'propio') === 'general' && $esAdmin ? 'general' : 'propio';
$uid     = $alcance === 'propio' ? (int) $u['id'] : null;

$num = fn ($k) => max(0, round((float) str_replace([',', '$', ' '], '', (string) ($_POST[$k] ?? '0')), 2));
$numOpc = fn ($k) => trim((string) ($_POST[$k] ?? '')) === '' ? null : $num($k);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pend = cierre_pendiente($alcance, $uid);   // se recalcula: pudo entrar un cobro mientras contaba

    // Doble clic / doble envío: si este alcance se cerró hace segundos, no se duplica.
    if ($pend['ultimo'] && strtotime($pend['ultimo']['cerrado_en']) > time() - 15) {
        redirect('/corte/cierre?id=' . (int) $pend['ultimo']['id']);
    }

    $relevo = $alcance === 'propio' ? cierre_relevo() : cierre_ultimo('general');
    $fondo    = $num('fondo_inicial');
    $retiros  = $num('retiros');
    $contado  = $num('efectivo_contado');
    $dejado   = $numOpc('dejado_en_caja');
    if ($dejado !== null && $dejado > $contado) $dejado = $contado;
    $efectivo = (float) $pend['porMetodo']['efectivo'];
    $esperado = round($fondo + $efectivo - $retiros - $pend['egresos'], 2);

    $cobros = array_map(fn ($c) => [$c['folio'], (float) $c['total'], cierre_metodo($c['metodo_pago']),
                                    trim($c['nombre'] . ' ' . $c['apellidos']), $c['cobrador'], $c['cobrado_en']],
                        array_slice($pend['cobros'], 0, 300));

    $pdo = db();
    $pdo->prepare(
        "INSERT INTO cortes_caja (consultorio_id, alcance, usuario_id, usuario_nombre, usuario_rol, desde, hasta,
            fondo_inicial, recibido_de_corte_id, efectivo_cobrado, retiros, egresos_efectivo, efectivo_esperado,
            efectivo_contado, dejado_en_caja, diferencia, tarjeta_esperado, tarjeta_verificado, transf_esperado,
            transf_verificado, total_cobrado, num_cobros, desglose, notas, cerrado_en)
         VALUES (?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    )->execute([
        tenant_id(), $alcance, (int) $u['id'], $u['nombre'], $u['rol'], $pend['desde'],
        $fondo, $relevo['id'] ?? null, $efectivo, $retiros, $pend['egresos'], $esperado,
        $contado, $dejado, round($contado - $esperado, 2),
        $pend['porMetodo']['tarjeta'], $numOpc('tarjeta_verificado'),
        $pend['porMetodo']['transferencia'], $numOpc('transf_verificado'),
        $pend['total'], $pend['num'],
        json_encode(['metodos' => $pend['porMetodo'], 'cobros' => $cobros], JSON_UNESCAPED_UNICODE),
        mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 500) ?: null,
    ]);
    $cid = (int) $pdo->lastInsertId();
    $dif = round($contado - $esperado, 2);
    auditar('cerrar_corte', 'corte', $cid, ($alcance === 'general' ? 'General' : 'Propio') . ' · ' . fmt_money($pend['total'])
        . ' · dif ' . fmt_money($dif));

    $st = $pdo->prepare('SELECT * FROM cortes_caja WHERE id = ?');
    $st->execute([$cid]);
    cierre_avisar($st->fetch());

    flash(abs($dif) < 0.005 ? t('Corte cerrado. ¡Cuadró exacto!') : ($dif < 0
        ? sprintf(t('Corte cerrado con faltante de %s.'), fmt_money(-$dif))
        : sprintf(t('Corte cerrado con sobrante de %s.'), fmt_money($dif))), abs($dif) < 0.005 ? 'success' : 'warning');
    redirect('/corte/cierre?id=' . $cid);
}

$pend   = cierre_pendiente($alcance, $uid);
$relevo = $alcance === 'propio' ? cierre_relevo() : cierre_ultimo('general');
$fondoSugerido = $relevo ? cierre_dejado($relevo) : 0.0;
$metodos = cierre_metodos();

$titulo = $alcance === 'general' ? t('Cerrar corte general') : t('Cerrar mi corte');
$activo = 'corte';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-safe2 text-brand"></i> <?= e($titulo) ?></h1>
        <div class="text-muted small">
            <?= $pend['desde']
                ? sprintf(et('Desde tu último cierre (%s) hasta ahora.'), e(date('d/m/Y H:i', strtotime($pend['desde']))))
                : et('Primer cierre: incluye todo lo cobrado desde que se activó el cierre de caja.') ?>
            <?= $alcance === 'general' ? et('Todo el consultorio.') : et('Solo lo que cobraste tú.') ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($esAdmin): ?>
            <a href="<?= BASE_URL ?>/corte/cerrar?alcance=<?= $alcance === 'general' ? 'propio' : 'general' ?>" class="btn btn-sm btn-outline-secondary">
                <?= $alcance === 'general' ? et('Mi corte') : et('Corte general') ?></a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/corte/cierres" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clock-history"></i> <?= et('Historial de cierres') ?></a>
    </div>
</div>

<div class="row g-3">
    <!-- Lo que dice el sistema -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-receipt text-brand"></i> <?= et('Lo cobrado') ?>
                <span class="text-muted small fw-normal">· <?= (int) $pend['num'] ?> <?= et('cobros') ?></span></div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <?php foreach ($metodos as $k => $lbl): if ($k === 'otro' && !$pend['porMetodo']['otro']) continue; ?>
                    <div class="col-6"><div class="cc-tile">
                        <div class="text-muted small"><?= et($lbl) ?></div>
                        <div class="fw-bold fs-5"><?= fmt_money($pend['porMetodo'][$k]) ?></div>
                    </div></div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between border-top pt-2 fw-semibold">
                    <span><?= et('Total cobrado') ?></span><span><?= fmt_money($pend['total']) ?></span>
                </div>
                <?php if ($pend['egresos'] > 0): ?>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span><?= et('Egresos pagados en efectivo') ?></span><span>−<?= fmt_money($pend['egresos']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($pend['cobros']): ?>
            <div class="table-responsive" style="max-height:320px">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th><?= et('Hora') ?></th><th><?= et('Paciente') ?></th>
                        <?php if ($alcance === 'general'): ?><th><?= et('Cobró') ?></th><?php endif; ?>
                        <th><?= et('Método') ?></th><th class="text-end"><?= et('Monto') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($pend['cobros'] as $c): ?>
                        <tr>
                            <td class="small text-muted"><?= e(date('d/m H:i', strtotime($c['cobrado_en']))) ?></td>
                            <td class="small"><?= e($c['nombre'] . ' ' . $c['apellidos']) ?> <span class="text-muted">· <?= e($c['folio']) ?></span></td>
                            <?php if ($alcance === 'general'): ?><td class="small"><?= e($c['cobrador'] ?? '—') ?></td><?php endif; ?>
                            <td class="small"><?= et($metodos[cierre_metodo($c['metodo_pago'])]) ?></td>
                            <td class="small text-end fw-semibold"><?= fmt_money($c['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lo que cuenta quien cierra -->
    <div class="col-lg-6">
        <form method="post" class="card h-100" id="ccForm" onsubmit="return confirm('<?= e(t('¿Cerrar el corte? Ya no se podrá modificar.')) ?>');">
            <?= csrf_field() ?>
            <input type="hidden" name="alcance" value="<?= e($alcance) ?>">
            <div class="card-header fw-semibold"><i class="bi bi-cash-coin text-brand"></i> <?= et('Arqueo de efectivo') ?></div>
            <div class="card-body">
                <?php if ($relevo): ?>
                <div class="alert alert-light border small py-2">
                    <i class="bi bi-arrow-left-right"></i>
                    <?= sprintf(et('Recibes la caja de %s, que dejó %s al cerrar el %s.'),
                        '<strong>' . e($relevo['usuario_nombre']) . '</strong>', '<strong>' . fmt_money($fondoSugerido) . '</strong>',
                        e(date('d/m H:i', strtotime($relevo['cerrado_en'])))) ?>
                </div>
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold"><?= et('Fondo inicial') ?></label>
                        <input type="number" step="0.01" min="0" name="fondo_inicial" class="form-control cc-in" value="<?= number_format($fondoSugerido, 2, '.', '') ?>">
                        <div class="form-text"><?= et('Con lo que abriste la caja.') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold"><?= et('Retiros') ?></label>
                        <input type="number" step="0.01" min="0" name="retiros" class="form-control cc-in" value="0">
                        <div class="form-text"><?= et('Efectivo que sacaste (no registrado como egreso).') ?></div>
                    </div>
                    <div class="col-12">
                        <div class="cc-esperado d-flex justify-content-between align-items-center">
                            <span><?= et('Efectivo que debería haber') ?></span>
                            <strong id="ccEsperado">—</strong>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold"><?= et('Efectivo contado') ?> *</label>
                        <input type="number" step="0.01" min="0" name="efectivo_contado" class="form-control form-control-lg cc-in" required autofocus>
                    </div>
                    <div class="col-sm-6 d-flex align-items-end">
                        <div id="ccDif" class="cc-dif w-100 text-center">—</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold"><?= et('Dejas en caja') ?></label>
                        <input type="number" step="0.01" min="0" name="dejado_en_caja" class="form-control" placeholder="<?= e(t('Todo lo contado')) ?>">
                        <div class="form-text"><?= et('Lo demás se entrega (al dueño o a depósito). Es el fondo de quien sigue.') ?></div>
                    </div>
                    <div class="col-sm-6"></div>
                    <?php if ($pend['porMetodo']['tarjeta'] > 0 || $pend['porMetodo']['transferencia'] > 0): ?>
                    <div class="col-12"><div class="small fw-semibold text-muted"><?= et('Verificación (opcional)') ?></div></div>
                    <?php if ($pend['porMetodo']['tarjeta'] > 0): ?>
                    <div class="col-sm-6">
                        <label class="form-label small"><?= sprintf(et('Cierre de terminal (sistema: %s)'), fmt_money($pend['porMetodo']['tarjeta'])) ?></label>
                        <input type="number" step="0.01" min="0" name="tarjeta_verificado" class="form-control">
                    </div>
                    <?php endif; ?>
                    <?php if ($pend['porMetodo']['transferencia'] > 0): ?>
                    <div class="col-sm-6">
                        <label class="form-label small"><?= sprintf(et('Transferencias en la cuenta (sistema: %s)'), fmt_money($pend['porMetodo']['transferencia'])) ?></label>
                        <input type="number" step="0.01" min="0" name="transf_verificado" class="form-control">
                    </div>
                    <?php endif; endif; ?>
                    <div class="col-12">
                        <label class="form-label small fw-semibold"><?= et('Notas') ?></label>
                        <input type="text" name="notas" maxlength="500" class="form-control" placeholder="<?= e(t('Ej. faltaron $50 de cambio')) ?>">
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent">
                <button class="btn btn-primary w-100 btn-lg">
                    <i class="bi bi-lock-fill"></i> <?= et('Cerrar corte') ?></button>
                <div class="form-text text-center"><?= et('Al cerrar queda guardado sin cambios y se avisa al administrador.') ?></div>
            </div>
        </form>
    </div>
</div>

<style>
.cc-tile { background: color-mix(in srgb, var(--brand) 6%, transparent); border-radius: 14px; padding: .7rem .9rem; }
.cc-esperado { background: color-mix(in srgb, var(--brand) 8%, transparent); border-radius: 14px; padding: .8rem 1rem; }
.cc-dif { border-radius: 14px; padding: .7rem; font-weight: 700; background: rgba(127,127,127,.1); }
.cc-dif.ok { background: rgba(34,197,94,.14); color: #15803d; }
.cc-dif.falta { background: rgba(239,68,68,.14); color: #b91c1c; }
.cc-dif.sobra { background: rgba(245,158,11,.16); color: #b45309; }
</style>
<script>
(function () {
    var f = document.getElementById('ccForm');
    var efectivo = <?= json_encode(round((float) $pend['porMetodo']['efectivo'], 2)) ?>;
    var egresos  = <?= json_encode(round((float) $pend['egresos'], 2)) ?>;
    var money = function (v) { return '$' + Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var val = function (n) { var x = parseFloat(f.elements[n].value); return isNaN(x) ? 0 : x; };
    function calc() {
        var esp = Math.round((val('fondo_inicial') + efectivo - val('retiros') - egresos) * 100) / 100;
        document.getElementById('ccEsperado').textContent = money(esp);
        var dif = document.getElementById('ccDif');
        if (f.elements['efectivo_contado'].value === '') { dif.textContent = '—'; dif.className = 'cc-dif w-100 text-center'; return; }
        var d = Math.round((val('efectivo_contado') - esp) * 100) / 100;
        dif.textContent = Math.abs(d) < 0.005 ? '<?= e(t('Cuadra exacto')) ?>' : (d < 0 ? '<?= e(t('Faltan')) ?> ' + money(-d) : '<?= e(t('Sobran')) ?> ' + money(d));
        dif.className = 'cc-dif w-100 text-center ' + (Math.abs(d) < 0.005 ? 'ok' : (d < 0 ? 'falta' : 'sobra'));
    }
    f.querySelectorAll('.cc-in').forEach(function (i) { i.addEventListener('input', calc); });
    calc();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
