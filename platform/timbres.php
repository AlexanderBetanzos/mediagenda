<?php
/**
 * Timbres CFDI (consola del dueño): precios de los paquetes, timbres incluidos
 * por plan, abrir/cerrar la venta, regalar timbres y ver ventas y consumo.
 */
require_once __DIR__ . '/../includes/cfdi.php';
require_platform_super();
asegurar_cfdi();

$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'ajustes') {
        $paq = [];
        foreach ((array) ($_POST['paq_t'] ?? []) as $i => $t) {
            $t = (int) $t; $p = round((float) ($_POST['paq_p'][$i] ?? 0), 2);
            if ($t > 0 && $p > 0) $paq[] = ['timbres' => $t, 'precio' => $p];
        }
        if (!$paq) { flash('Deja al menos un paquete con timbres y precio.', 'danger'); redirect('/platform/timbres'); }
        $inc = [];
        foreach (planes_mp() as $k => $_) $inc[$k] = max(0, (int) ($_POST['inc'][$k] ?? 0));
        guardar_plataforma_cfg([
            'cfdi_venta_activa'      => !empty($_POST['venta']) ? '1' : '0',
            'cfdi_pac'               => mb_substr(trim((string) ($_POST['pac'] ?? '')), 0, 60),
            'timbres_paquetes'       => json_encode($paq),
            'timbres_incluidos'      => json_encode($inc),
            'timbres_cancelar_cobra' => !empty($_POST['cancelar_cobra']) ? '1' : '0',
        ]);
        auditar('plataforma_timbres_ajustes', 'plataforma', null, json_encode(['paq' => $paq, 'inc' => $inc]), null, $actor);
        flash('Ajustes de timbres guardados.');
        redirect('/platform/timbres');
    }

    if ($accion === 'ajustar') {
        $cid = (int) ($_POST['cid'] ?? 0); $n = (int) ($_POST['cantidad'] ?? 0);
        $mot = trim((string) ($_POST['motivo'] ?? '')) ?: ($n > 0 ? 'Cortesía' : 'Corrección');
        try {
            if (!timbres_consultorio($cid)) throw new RuntimeException('Elige un consultorio.');
            timbres_ajustar($cid, $n, $mot, $actor['nombre']);
            auditar('timbres_ajuste', 'timbres', null, "$n · $mot", $cid, $actor);
            flash(($n > 0 ? 'Se agregaron ' : 'Se quitaron ') . abs($n) . ' timbres.');
        } catch (Throwable $e) { flash($e->getMessage(), 'danger'); }
        redirect('/platform/timbres');
    }
}

$venta = cfdi_venta_activa();
$paquetes = timbres_paquetes();
$inc = timbres_incluidos_planes();
$planes = planes_mp();

$ini = date('Y-m-01 00:00:00');
$k = db()->query("SELECT
    (SELECT COALESCE(SUM(monto),0) FROM timbres_compras WHERE estado='pagado' AND pagado_en >= " . db()->quote($ini) . ") ventas_mes,
    (SELECT COALESCE(SUM(timbres),0) FROM timbres_compras WHERE estado='pagado' AND pagado_en >= " . db()->quote($ini) . ") vendidos_mes,
    (SELECT COALESCE(-SUM(cantidad),0) FROM timbres_mov WHERE tipo='consumo' AND creado_en >= " . db()->quote($ini) . ")
      - (SELECT COALESCE(SUM(cantidad),0) FROM timbres_mov WHERE tipo='devolucion' AND creado_en >= " . db()->quote($ini) . ") usados_mes,
    (SELECT COALESCE(SUM(cantidad),0) FROM timbres_mov WHERE bolsa='comprado') saldo_clientes,
    (SELECT COUNT(*) FROM cfdi_emisor WHERE csd_cer IS NOT NULL) con_csd")->fetch();

$cons = db()->query("SELECT c.id, c.nombre, c.plan, c.estado,
        (SELECT COALESCE(SUM(m.cantidad),0) FROM timbres_mov m WHERE m.consultorio_id=c.id AND m.bolsa='comprado') comprados,
        (SELECT COALESCE(-SUM(m.cantidad),0) FROM timbres_mov m WHERE m.consultorio_id=c.id AND m.tipo IN ('consumo','devolucion') AND m.creado_en >= " . db()->quote($ini) . ") usados_mes,
        (SELECT COALESCE(SUM(t.monto),0) FROM timbres_compras t WHERE t.consultorio_id=c.id AND t.estado='pagado') pagado_total,
        (SELECT e.csd_hasta FROM cfdi_emisor e WHERE e.consultorio_id=c.id AND e.csd_cer IS NOT NULL) csd_hasta
    FROM consultorios c ORDER BY c.nombre")->fetchAll();
$conMov = array_filter($cons, fn ($c) => $c['comprados'] || $c['usados_mes'] || $c['pagado_total'] || $c['csd_hasta']);

$ventas = db()->query("SELECT t.*, c.nombre FROM timbres_compras t JOIN consultorios c ON c.id=t.consultorio_id ORDER BY t.id DESC LIMIT 40")->fetchAll();

$titulo = 'Timbres CFDI';
$platNav = 'timbres';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-patch-check"></i> Timbres CFDI</h1>
<p class="text-muted">Los consultorios compran paquetes de timbres de prepago; el dinero cae en la cuenta de Mercado Pago de la plataforma.</p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if (!(defined('APP_KEY') && APP_KEY !== '')): ?>
<div class="alert alert-warning small"><i class="bi bi-key"></i> <strong>Pon una APP_KEY en <code>config/secrets.php</code></strong> antes de que los consultorios suban su CSD.
    Sin ella, la llave de cifrado se guarda en <code>config/app.key</code>; si un despliegue borra ese archivo, habría que volver a subir todos los CSD.
    Genérala con <code>php -r "echo bin2hex(random_bytes(32));"</code> y no la cambies después.</div>
<?php endif; ?>
<?php if (!mp_configurado()): ?><div class="alert alert-danger small">Mercado Pago de la plataforma no está configurado: nadie podrá pagar paquetes.</div><?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([['Ventas del mes', '$' . number_format($k['ventas_mes'], 2), 'bi-cash-coin'], ['Timbres vendidos (mes)', number_format($k['vendidos_mes']), 'bi-bag-check'],
                    ['Timbres usados (mes)', number_format($k['usados_mes']), 'bi-receipt'], ['Saldo de clientes sin usar', number_format($k['saldo_clientes']), 'bi-wallet2'],
                    ['Consultorios con CSD', number_format($k['con_csd']), 'bi-key']] as [$l, $v, $i]): ?>
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body">
        <div class="small text-muted"><i class="bi <?= $i ?>"></i> <?= e($l) ?></div><div class="fs-4 fw-bold"><?= $v ?></div>
    </div></div></div>
    <?php endforeach; ?>
</div>
<p class="small text-muted mb-4"><i class="bi bi-info-circle"></i> "Saldo de clientes sin usar" son timbres que ya cobraste y que tu cuenta con el PAC debe poder cubrir.</p>

<div class="row g-3 mb-4">
    <div class="col-lg-7"><form method="post" class="card h-100">
        <?= csrf_field() ?><input type="hidden" name="accion" value="ajustes">
        <div class="card-header"><i class="bi bi-sliders"></i> Venta y precios</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="venta" name="venta" value="1" <?= $venta ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="venta">Venta de paquetes abierta</label>
                <div class="form-text">Mientras esté apagada, los consultorios ven los paquetes y la explicación, pero el botón dice "Disponible muy pronto".</div>
            </div>
            <div class="mb-3"><label class="form-label">PAC (proveedor de timbrado)</label>
                <input name="pac" class="form-control" value="<?= e(plataforma_cfg('cfdi_pac')) ?>" placeholder="Pendiente de elegir"></div>

            <label class="form-label">Paquetes (MXN, IVA incluido)</label>
            <?php $filas = array_pad($paquetes, 5, ['timbres' => '', 'precio' => '']); foreach ($filas as $p): ?>
            <div class="input-group input-group-sm mb-2">
                <input name="paq_t[]" type="number" min="0" class="form-control" placeholder="Timbres" value="<?= e((string) $p['timbres']) ?>">
                <span class="input-group-text">timbres por $</span>
                <input name="paq_p[]" type="number" min="0" step="0.01" class="form-control" placeholder="Precio" value="<?= e((string) $p['precio']) ?>">
                <span class="input-group-text"><?= $p['timbres'] ? '$' . number_format($p['precio'] / $p['timbres'], 2) . ' c/u' : '—' ?></span>
            </div>
            <?php endforeach; ?>
            <div class="form-text mb-3">Deja vacía una fila para quitar ese paquete.</div>

            <label class="form-label">Timbres incluidos al mes por plan (solo membresía activa)</label>
            <div class="row g-2 mb-3">
                <?php foreach ($planes as $kp => $pl): ?>
                <div class="col"><div class="input-group input-group-sm"><span class="input-group-text"><?= e($pl['nombre']) ?></span>
                    <input name="inc[<?= e($kp) ?>]" type="number" min="0" class="form-control" value="<?= (int) $inc[$kp] ?>"></div></div>
                <?php endforeach; ?>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="cc" name="cancelar_cobra" value="1" <?= timbres_cancelar_cobra() ? 'checked' : '' ?>>
                <label class="form-check-label" for="cc">Cancelar un CFDI gasta 1 timbre (actívalo si tu PAC cobra la cancelación)</label>
            </div>
            <button class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
        </div>
    </form></div>

    <div class="col-lg-5"><form method="post" class="card h-100">
        <?= csrf_field() ?><input type="hidden" name="accion" value="ajustar">
        <div class="card-header"><i class="bi bi-gift"></i> Regalar o corregir timbres</div>
        <div class="card-body">
            <div class="mb-2"><label class="form-label">Consultorio</label>
                <select name="cid" class="form-select" required><option value="">Elige…</option>
                <?php foreach ($cons as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> (<?= (int) $c['comprados'] ?>)</option><?php endforeach; ?></select></div>
            <div class="mb-2"><label class="form-label">Cantidad</label>
                <input name="cantidad" type="number" class="form-control" required placeholder="10 para dar, -10 para quitar"></div>
            <div class="mb-3"><label class="form-label">Motivo</label>
                <input name="motivo" class="form-control" maxlength="200" placeholder="Cortesía de arranque"></div>
            <button class="btn btn-outline-primary">Aplicar</button>
            <p class="small text-muted mt-3 mb-0">Se suman o restan a sus timbres de paquete y quedan en su historial.</p>
        </div>
    </form></div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-buildings"></i> Consultorios con timbres o CSD</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Consultorio</th><th>Plan</th><th class="text-end">Saldo paquetes</th><th class="text-end">Usados este mes</th><th class="text-end">Ha comprado</th><th>CSD</th></tr></thead>
        <tbody>
        <?php if (!$conMov): ?><tr><td colspan="6" class="text-center text-muted py-4">Ningún consultorio ha comprado timbres ni subido su CSD todavía.</td></tr><?php endif; ?>
        <?php foreach ($conMov as $c): ?>
            <tr><td><?= e($c['nombre']) ?></td><td class="small text-uppercase"><?= e($planes[$c['plan']]['nombre'] ?? $c['plan']) ?></td>
                <td class="text-end"><?= number_format($c['comprados']) ?></td><td class="text-end"><?= number_format($c['usados_mes']) ?></td>
                <td class="text-end">$<?= number_format($c['pagado_total'], 2) ?></td>
                <td class="small"><?= $c['csd_hasta'] ? '<span class="text-success">vence ' . e(fmt_fecha($c['csd_hasta'])) . '</span>' : '<span class="text-muted">—</span>' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-receipt"></i> Últimas compras</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Fecha</th><th>Consultorio</th><th>Paquete</th><th class="text-end">Monto</th><th>Estado</th><th>Pago MP</th></tr></thead>
        <tbody>
        <?php if (!$ventas): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin compras todavía.</td></tr><?php endif; ?>
        <?php foreach ($ventas as $v): ?>
            <tr><td class="small"><?= e(fmt_fecha($v['creado_en'])) ?> <?= e(date('H:i', strtotime($v['creado_en']))) ?></td><td><?= e($v['nombre']) ?></td>
                <td><?= number_format($v['timbres']) ?></td><td class="text-end">$<?= number_format($v['monto'], 2) ?></td>
                <td><span class="badge text-bg-<?= ['pagado' => 'success', 'pendiente' => 'warning', 'rechazado' => 'danger'][$v['estado']] ?? 'secondary' ?>"><?= e($v['estado']) ?></span></td>
                <td class="small text-muted"><?= e($v['mp_pago_id'] ?? '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
