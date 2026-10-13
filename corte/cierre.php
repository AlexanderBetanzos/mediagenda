<?php
/**
 * Ticket de un cierre de caja ya hecho (solo lectura, imprimible).
 * El admin ve cualquiera; los demás, solo los suyos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cortes.php';
require_login();
require_modulo('facturacion');
asegurar_cortes();

$u  = current_user();
$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM cortes_caja WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$c = $st->fetch();
if (!$c || (!has_role('admin') && (int) $c['usuario_id'] !== (int) $u['id'])) {
    flash(t('Ese cierre no existe o no es tuyo.'), 'warning');
    redirect('/corte/cierres');
}

$des     = json_decode((string) $c['desglose'], true) ?: [];
$metodos = cierre_metodos();
$dif     = (float) $c['diferencia'];
$clase   = abs($dif) < 0.005 ? 'ok' : ($dif < 0 ? 'falta' : 'sobra');
$txDif   = abs($dif) < 0.005 ? t('Cuadró exacto') : ($dif < 0 ? t('Faltante') . ' ' . fmt_money(-$dif) : t('Sobrante') . ' ' . fmt_money($dif));
$entregado = $c['dejado_en_caja'] !== null ? max(0, (float) $c['efectivo_contado'] - (float) $c['dejado_en_caja']) : 0;

$titulo = t('Cierre de caja');
$activo = 'corte';
include __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
    .app-navbar, .sidebar, footer, .d-print-none, .alert { display: none !important; }
    .cc-ticket { box-shadow: none !important; border: 0 !important; max-width: none !important; }
}
.cc-ticket { max-width: 560px; margin: 0 auto; }
.cc-row { display: flex; justify-content: space-between; padding: .4rem 0; border-bottom: 1px dashed rgba(127,127,127,.25); }
.cc-row.tot { font-weight: 700; border-bottom: 0; }
.cc-big { border-radius: 16px; padding: 1rem; text-align: center; font-weight: 800; font-size: 1.35rem; }
.cc-big.ok { background: rgba(34,197,94,.14); color: #15803d; }
.cc-big.falta { background: rgba(239,68,68,.14); color: #b91c1c; }
.cc-big.sobra { background: rgba(245,158,11,.16); color: #b45309; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 d-print-none cc-ticket">
    <a href="<?= BASE_URL ?>/corte/cierres" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> <?= et('Historial') ?></a>
    <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="bi bi-printer"></i> <?= et('Imprimir') ?></button>
</div>

<div class="card cc-ticket">
    <div class="card-body p-4">
        <div class="text-center mb-3">
            <div class="fw-bold"><?= e(marca_nombre()) ?></div>
            <div class="h5 mb-1"><?= $c['alcance'] === 'general' ? et('Corte general') : et('Corte de caja') ?> #<?= (int) $c['id'] ?></div>
            <div class="small text-muted">
                <?= e($c['usuario_nombre']) ?> · <?= e(date('d/m/Y H:i', strtotime($c['cerrado_en']))) ?><br>
                <?= $c['desde'] ? et('Periodo') . ': ' . e(date('d/m H:i', strtotime($c['desde']))) . ' → ' . e(date('d/m H:i', strtotime($c['hasta']))) : et('Primer cierre') ?>
            </div>
        </div>

        <div class="cc-big <?= $clase ?> mb-3"><?= e($txDif) ?></div>

        <div class="small fw-semibold text-muted mb-1"><?= et('Cobrado') ?> · <?= (int) $c['num_cobros'] ?> <?= et('cobros') ?></div>
        <?php foreach ($metodos as $k => $lbl): $v = (float) ($des['metodos'][$k] ?? 0); if (!$v) continue; ?>
            <div class="cc-row"><span><?= et($lbl) ?></span><span><?= fmt_money($v) ?></span></div>
        <?php endforeach; ?>
        <div class="cc-row tot"><span><?= et('Total cobrado') ?></span><span><?= fmt_money($c['total_cobrado']) ?></span></div>

        <div class="small fw-semibold text-muted mt-3 mb-1"><?= et('Arqueo de efectivo') ?></div>
        <div class="cc-row"><span><?= et('Fondo inicial') ?></span><span><?= fmt_money($c['fondo_inicial']) ?></span></div>
        <div class="cc-row"><span><?= et('+ Efectivo cobrado') ?></span><span><?= fmt_money($c['efectivo_cobrado']) ?></span></div>
        <?php if ((float) $c['retiros']): ?><div class="cc-row"><span><?= et('− Retiros') ?></span><span><?= fmt_money($c['retiros']) ?></span></div><?php endif; ?>
        <?php if ((float) $c['egresos_efectivo']): ?><div class="cc-row"><span><?= et('− Egresos en efectivo') ?></span><span><?= fmt_money($c['egresos_efectivo']) ?></span></div><?php endif; ?>
        <div class="cc-row tot"><span><?= et('Esperado') ?></span><span><?= fmt_money($c['efectivo_esperado']) ?></span></div>
        <div class="cc-row tot"><span><?= et('Contado') ?></span><span><?= fmt_money($c['efectivo_contado']) ?></span></div>
        <?php if ($c['dejado_en_caja'] !== null): ?>
            <div class="cc-row"><span><?= et('Dejado en caja') ?></span><span><?= fmt_money($c['dejado_en_caja']) ?></span></div>
            <div class="cc-row"><span><?= et('Entregado') ?></span><span><?= fmt_money($entregado) ?></span></div>
        <?php endif; ?>

        <?php if ($c['tarjeta_verificado'] !== null || $c['transf_verificado'] !== null): ?>
        <div class="small fw-semibold text-muted mt-3 mb-1"><?= et('Verificación') ?></div>
        <?php foreach ([['tarjeta', t('Terminal')], ['transf', t('Transferencias')]] as [$k, $lbl]):
            if ($c[$k . '_verificado'] === null) continue;
            $d = (float) $c[$k . '_verificado'] - (float) $c[$k . '_esperado']; ?>
            <div class="cc-row"><span><?= e($lbl) ?>: <?= fmt_money($c[$k . '_verificado']) ?> <span class="text-muted">/ <?= fmt_money($c[$k . '_esperado']) ?></span></span>
                <span class="<?= abs($d) < 0.005 ? 'text-success' : 'text-danger' ?>"><?= abs($d) < 0.005 ? '✓' : fmt_money($d) ?></span></div>
        <?php endforeach; endif; ?>

        <?php if ($c['notas']): ?><div class="mt-3 small"><em>“<?= e($c['notas']) ?>”</em></div><?php endif; ?>

        <?php if (!empty($des['cobros'])): ?>
        <details class="mt-3">
            <summary class="small fw-semibold"><?= et('Cobros incluidos') ?></summary>
            <table class="table table-sm small mt-2 mb-0">
                <?php foreach ($des['cobros'] as [$folio, $total, $met, $pac, $quien, $cuando]): ?>
                <tr><td class="text-muted"><?= e(date('d/m H:i', strtotime((string) $cuando))) ?></td><td><?= e($pac) ?> · <?= e($folio) ?></td>
                    <td><?= et($metodos[$met] ?? $met) ?></td><td class="text-end"><?= fmt_money($total) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </details>
        <?php endif; ?>

        <div class="d-flex justify-content-between mt-5 pt-3 small text-muted">
            <span class="border-top pt-1 px-4"><?= et('Entrega') ?></span>
            <span class="border-top pt-1 px-4"><?= et('Recibe') ?></span>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
