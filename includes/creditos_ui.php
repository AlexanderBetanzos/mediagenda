<?php
/**
 * Piezas de pantalla compartidas por los créditos de prepago (timbres CFDI y
 * mensajes de WhatsApp): saldo, paquetes con confirmación detallada antes de
 * pagar e historial. El formulario de compra se envía a la misma página con
 * accion=comprar y paquete=<índice>.
 */
require_once __DIR__ . '/creditos.php';

/** Maneja el POST de compra (llamar antes de pintar). */
function credito_ui_post_compra(string $r): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['accion'] ?? '') !== 'comprar') return;
    verify_csrf();
    $u = current_user();
    try {
        $url = credito_iniciar_compra((int) tenant_id(), $r, (int) ($_POST['paquete'] ?? -1), (string) ($u['nombre'] ?? ''), (string) ($u['email'] ?? ''));
        auditar('credito_compra_iniciada', $r, null, 'paquete ' . (int) ($_POST['paquete'] ?? -1));
        header('Location: ' . $url);
        exit;
    } catch (Throwable $e) {
        flash($e->getMessage(), 'danger');
        redirect(credito_def($r)['pagina'] . '#paquetes');
    }
}

function credito_ui_estilos(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true; ?>
<style>
.tb-saldo{border-radius:16px;padding:1.1rem 1.25rem;height:100%}
.tb-saldo .n{font-size:2.1rem;font-weight:800;letter-spacing:-.03em;line-height:1}
.tb-saldo .l{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;opacity:.75}
.tb-paq{border:1px solid var(--bs-border-color);border-radius:16px;padding:1.25rem;height:100%;display:flex;flex-direction:column;position:relative;background:var(--bs-body-bg)}
.tb-paq.pop{border:2px solid var(--brand,#0f8dbf);box-shadow:0 10px 28px rgba(15,141,191,.14)}
.tb-paq .tag{position:absolute;top:-.7rem;left:50%;transform:translateX(-50%);background:var(--brand,#0f8dbf);color:#fff;font-size:.7rem;font-weight:700;padding:.15rem .6rem;border-radius:20px;white-space:nowrap}
.tb-paq .t{font-size:1.9rem;font-weight:800;letter-spacing:-.03em}
.tb-paq .p{font-size:1.25rem;font-weight:700}
.tb-paq ul{list-style:none;padding:0;margin:.75rem 0 1rem;font-size:.85rem}
.tb-paq li{padding:.15rem 0}
.tb-paq li i{color:#16a34a}
.tb-como dt{font-weight:650}
.tb-como dd{color:var(--bs-secondary-color);margin-bottom:.8rem}
</style>
<?php }

/** Tarjetas de saldo + aviso de saldo bajo. */
function credito_ui_saldo(string $r, array $s): void
{
    credito_ui_estilos();
    $d = credito_def($r);
    $tn = tenant();
    $inc = credito_incluidos($r);
    $incPlan = $inc[$tn['plan'] ?? ''] ?? 0; ?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="tb-saldo text-white" style="background:linear-gradient(135deg,#0b6e96,#0f8dbf)">
        <div class="l"><?= et('Disponibles hoy') ?></div>
        <div class="n mt-2"><?= number_format($s['total']) ?></div>
        <div class="small mt-1 opacity-75"><?= e(t($d['nombre'])) ?></div>
    </div></div>
    <div class="col-md-4"><div class="tb-saldo border">
        <div class="l text-muted"><?= ($tn['estado'] ?? '') === 'trial' ? et('Incluidos en tu prueba este mes') : et('Incluidos en tu plan este mes') ?></div>
        <div class="n mt-2"><?= number_format($s['incluidos_restan']) ?> <span class="fs-6 text-muted fw-normal">/ <?= number_format($s['incluidos_mes']) ?></span></div>
        <div class="small text-muted mt-1">
            <?php if ($s['incluidos_mes'] > 0): ?>
                <?= et('Se renuevan el') ?> <?= e(fmt_fecha($s['renuevan'])) ?> · <?= et('no se acumulan') ?>
            <?php elseif (($tn['estado'] ?? '') !== 'activa' && $incPlan > 0): ?>
                <?= sprintf(et('Tu plan incluye %d al mes en cuanto activas tu membresía.'), $incPlan) ?>
            <?php else: ?>
                <?= et('Tu plan no incluye; usa paquetes.') ?>
            <?php endif; ?>
        </div>
    </div></div>
    <div class="col-md-4"><div class="tb-saldo border">
        <div class="l text-muted"><?= et('De paquetes comprados') ?></div>
        <div class="n mt-2"><?= number_format($s['comprados']) ?></div>
        <div class="small text-muted mt-1"><?= et('No caducan') ?></div>
    </div></div>
</div>
<?php if ($s['total'] <= $d['aviso']): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i>
    <?= $s['total'] ? sprintf(et('Te quedan solo %d %s.'), $s['total'], e(t($d['nombre']))) : sprintf(et('No tienes %s disponibles.'), e(t($d['nombre']))) ?>
    <?= et('Compra un paquete para no quedarte sin servicio.') ?></div>
<?php endif;
}

/**
 * Paquetes a la venta y ventana de confirmación.
 * @param string[] $beneficios  viñetas de cada tarjeta
 * @param string[] $reglas      viñetas de la confirmación (HTML permitido, ya escapado)
 */
function credito_ui_paquetes(string $r, array $beneficios, array $reglas): void
{
    credito_ui_estilos();
    $d = credito_def($r);
    $paq = credito_paquetes($r);
    $venta = credito_venta_activa($r);
    $base = $paq ? $paq[0]['precio'] / $paq[0]['cantidad'] : 0;
    $popIx = count($paq) > 2 ? 1 : -1;
    $mid = 'cm' . $r; ?>
<h2 class="h5 mb-1" id="paquetes"><?= et('Paquetes') ?> <?= e(t($d['nombre'])) ?></h2>
<p class="text-muted small mb-4"><?= et('Pago único con Mercado Pago (tarjeta, OXXO o transferencia). No es suscripción: compras cuando lo necesitas. Precios con IVA incluido.') ?></p>
<div class="row g-3 mb-4">
    <?php foreach ($paq as $ix => $p): $unit = $p['precio'] / $p['cantidad']; $ahorro = $base > 0 ? (int) round((1 - $unit / $base) * 100) : 0; ?>
    <div class="col-sm-6 col-lg-3">
        <div class="tb-paq <?= $ix === $popIx ? 'pop' : '' ?>">
            <?php if ($ix === $popIx): ?><span class="tag"><?= et('El más elegido') ?></span><?php endif; ?>
            <div class="t"><?= number_format($p['cantidad']) ?> <span class="fs-6 fw-semibold text-muted"><?= e(t($d['nombre'])) ?></span></div>
            <div class="p">$<?= number_format($p['precio'], $p['precio'] == floor($p['precio']) ? 0 : 2) ?> <span class="small fw-normal text-muted">MXN</span></div>
            <div class="small text-muted">$<?= number_format($unit, 2) ?> <?= et('cada') ?> <?= e(t($d['uno'])) ?><?= $ahorro > 0 ? ' · <span class="text-success fw-semibold">' . sprintf(et('ahorras %d%%'), $ahorro) . '</span>' : '' ?></div>
            <ul>
                <?php foreach ($beneficios as $b): ?><li><i class="bi bi-check-lg"></i> <?= str_replace('%n', number_format($p['cantidad']), $b) ?></li><?php endforeach; ?>
            </ul>
            <button type="button" class="btn <?= $ix === $popIx ? 'btn-primary' : 'btn-outline-primary' ?> mt-auto w-100" <?= $venta ? '' : 'disabled' ?>
                    data-bs-toggle="modal" data-bs-target="#<?= $mid ?>" data-ix="<?= $ix ?>" data-t="<?= (int) $p['cantidad'] ?>"
                    data-p="<?= e(number_format($p['precio'], 2)) ?>" data-u="<?= e(number_format($unit, 2)) ?>">
                <?= $venta ? et('Comprar') : et('Disponible muy pronto') ?>
            </button>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="<?= $mid ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
        <?= csrf_field() ?><input type="hidden" name="accion" value="comprar"><input type="hidden" name="paquete" class="cm-ix">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-bag"></i> <?= et('Paquete de') ?> <span class="cm-t"></span> <?= e(t($d['nombre'])) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="d-flex justify-content-between align-items-baseline border rounded-3 p-3 mb-3">
                <div><div class="small text-muted"><?= et('Total a pagar (IVA incluido)') ?></div><div class="fs-3 fw-bold">$<span class="cm-p"></span> MXN</div></div>
                <div class="text-end small text-muted">$<span class="cm-u"></span><br><?= et('cada') ?> <?= e(t($d['uno'])) ?></div>
            </div>
            <ul class="small mb-0 ps-3">
                <li class="mb-1"><?= et('Te llevamos a Mercado Pago para un') ?> <strong><?= et('pago único') ?></strong> <?= et('(no es suscripción).') ?></li>
                <li class="mb-1"><?= et('Con tarjeta, se suman a tu saldo') ?> <strong><?= et('al instante') ?></strong>; <?= et('con OXXO o transferencia, cuando el pago se acredite.') ?></li>
                <?php foreach ($reglas as $x): ?><li class="mb-1"><?= $x ?></li><?php endforeach; ?>
                <li class="mb-1"><?= et('Primero se usan los incluidos de tu plan en el mes y después los de este paquete.') ?></li>
                <li class="mb-1"><?= et('Los de paquete') ?> <strong><?= et('no caducan') ?></strong>; <?= et('no son reembolsables ni transferibles a otra cuenta.') ?></li>
            </ul>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= et('Cancelar') ?></button>
            <button class="btn btn-primary"><i class="bi bi-lock"></i> <?= et('Ir a pagar') ?></button>
        </div>
    </form></div>
</div>
<script>
document.getElementById('<?= $mid ?>').addEventListener('show.bs.modal', function (ev) {
    var b = ev.relatedTarget, m = this; if (!b) return;
    m.querySelector('.cm-ix').value = b.dataset.ix;
    m.querySelector('.cm-t').textContent = Number(b.dataset.t).toLocaleString('es-MX');
    m.querySelector('.cm-p').textContent = b.dataset.p;
    m.querySelector('.cm-u').textContent = b.dataset.u;
});
</script>
<?php }

/** Movimientos y compras. */
function credito_ui_historial(string $r, array $conceptos): void
{
    $cid = (int) tenant_id();
    $d = credito_def($r);
    $movs = db()->prepare('SELECT * FROM timbres_mov WHERE consultorio_id = ? AND recurso = ? ORDER BY id DESC LIMIT 30');
    $movs->execute([$cid, $r]);
    $movs = $movs->fetchAll();
    $cmp = db()->prepare("SELECT * FROM timbres_compras WHERE consultorio_id = ? AND recurso = ? AND (estado <> 'pendiente' OR creado_en > NOW() - INTERVAL 2 DAY) ORDER BY id DESC LIMIT 15");
    $cmp->execute([$cid, $r]);
    $cmp = $cmp->fetchAll();
    $conceptos += ['compra' => t('Compra de paquete'), 'devolucion' => t('Devolución (no se usó)'), 'ajuste' => t('Ajuste')]; ?>
<div class="row g-3">
    <div class="col-lg-7"><div class="card h-100">
        <div class="card-header"><i class="bi bi-clock-history"></i> <?= et('Movimientos') ?></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Concepto') ?></th><th><?= et('Bolsa') ?></th><th class="text-end"><?= e(ucfirst(t($d['nombre']))) ?></th></tr></thead>
            <tbody>
            <?php if (!$movs): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= et('Aún no hay movimientos.') ?></td></tr><?php endif; ?>
            <?php foreach ($movs as $m): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(fmt_fecha($m['creado_en'])) ?> <span class="text-muted"><?= e(date('H:i', strtotime($m['creado_en']))) ?></span></td>
                    <td class="small"><?= e($conceptos[$m['tipo']] ?? $m['tipo']) ?><?php if ($m['detalle']): ?><div class="text-muted"><?= e($m['detalle']) ?></div><?php endif; ?></td>
                    <td class="small"><?= $m['bolsa'] === 'incluido' ? et('Del plan') : et('Paquete') ?></td>
                    <td class="text-end fw-semibold <?= $m['cantidad'] > 0 ? 'text-success' : '' ?>"><?= $m['cantidad'] > 0 ? '+' : '' ?><?= number_format($m['cantidad']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header"><i class="bi bi-bag-check"></i> <?= et('Tus compras') ?></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th><?= et('Fecha') ?></th><th><?= et('Paquete') ?></th><th class="text-end"><?= et('Monto') ?></th><th><?= et('Estado') ?></th></tr></thead>
            <tbody>
            <?php if (!$cmp): ?><tr><td colspan="4" class="text-center text-muted py-4"><?= et('Sin compras todavía.') ?></td></tr><?php endif; ?>
            <?php foreach ($cmp as $c): [$lbl, $col] = ['pagado' => [t('Pagado'), 'success'], 'pendiente' => [t('Pendiente'), 'warning'], 'rechazado' => [t('Rechazado'), 'danger']][$c['estado']] ?? [$c['estado'], 'secondary']; ?>
                <tr>
                    <td class="small"><?= e(fmt_fecha($c['creado_en'])) ?></td>
                    <td class="small"><?= number_format($c['timbres']) ?> <?= e(t($d['nombre'])) ?></td>
                    <td class="text-end small">$<?= number_format($c['monto'], 2) ?></td>
                    <td><span class="badge text-bg-<?= $col ?>"><?= e($lbl) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div></div>
</div>
<?php }
