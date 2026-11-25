<?php
/**
 * Créditos de prepago (consola del dueño), con una pestaña por recurso:
 *   ?r=timbre    timbres CFDI
 *   ?r=whatsapp  WhatsApp incluido (credenciales de Meta de la plataforma)
 * Precios de los paquetes, incluidos por plan, abrir/cerrar la venta, regalar
 * saldo y ver ventas y consumo.
 */
require_once __DIR__ . '/../includes/cfdi.php';
require_once __DIR__ . '/../includes/whatsapp.php';
require_platform_super();
asegurar_cfdi();

$r = ($_GET['r'] ?? $_POST['r'] ?? 'timbre') === 'whatsapp' ? 'whatsapp' : 'timbre';
$d = credito_def($r);
$aqui = '/platform/timbres?r=' . $r;
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];
$prueba = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'ajustes') {
        $paq = [];
        foreach ((array) ($_POST['paq_t'] ?? []) as $i => $t) {
            $t = (int) $t; $p = round((float) ($_POST['paq_p'][$i] ?? 0), 2);
            if ($t > 0 && $p > 0) $paq[] = ['cantidad' => $t, 'precio' => $p];
        }
        if (!$paq) { flash('Deja al menos un paquete con cantidad y precio.', 'danger'); redirect($aqui); }
        $inc = ['prueba' => max(0, (int) ($_POST['inc']['prueba'] ?? 0))];
        foreach (planes_mp() as $k => $_) $inc[$k] = max(0, (int) ($_POST['inc'][$k] ?? 0));
        $pares = [
            $d['venta']              => !empty($_POST['venta']) ? '1' : '0',
            $d['pref'] . '_paquetes' => json_encode($paq),
            $d['pref'] . '_incluidos' => json_encode($inc),
        ];
        if ($r === 'timbre') {
            $pares['cfdi_pac'] = mb_substr(trim((string) ($_POST['pac'] ?? '')), 0, 60);
            $pares['timbres_cancelar_cobra'] = !empty($_POST['cancelar_cobra']) ? '1' : '0';
        }
        guardar_plataforma_cfg($pares);
        auditar('plataforma_creditos_ajustes', 'plataforma', null, $r . ' ' . json_encode(['paq' => $paq, 'inc' => $inc]), null, $actor);
        flash('Ajustes guardados.');
        redirect($aqui);
    }

    if ($accion === 'wa_cred' && $r === 'whatsapp') {
        $pares = [
            'wa_plat_activo'    => !empty($_POST['wa_plat_activo']) ? '1' : '0',
            'wa_plat_phone_id'  => preg_replace('/\D/', '', (string) ($_POST['wa_plat_phone_id'] ?? '')),
            'wa_plat_plantilla' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['wa_plat_plantilla'] ?? ''))),
            'wa_plat_idioma'    => preg_replace('/[^A-Za-z_]/', '', (string) ($_POST['wa_plat_idioma'] ?? 'es_MX')) ?: 'es_MX',
        ];
        $tok = trim((string) ($_POST['wa_plat_token'] ?? ''));
        if ($tok !== '') $pares['wa_plat_token'] = $tok;   // vacío = no tocar
        guardar_plataforma_cfg($pares);
        auditar('plataforma_wa_cred', 'plataforma', null, 'activo=' . $pares['wa_plat_activo'], null, $actor);
        flash('Credenciales de WhatsApp de la plataforma guardadas.');
        redirect($aqui);
    }

    if ($accion === 'wa_probar' && $r === 'whatsapp') {
        $tel = tel_e164((string) ($_POST['tel'] ?? ''));
        if ($tel === '' || !wa_plat_configurado()) {
            $prueba = ['ok' => false, 'mensaje' => $tel === '' ? 'Teléfono inválido.' : 'Faltan credenciales.'];
        } else {
            try {
                $res = wa_request(wa_plat_phone_id() . '/messages', [
                    'messaging_product' => 'whatsapp', 'to' => $tel, 'type' => 'template',
                    'template' => ['name' => wa_plat_plantilla(), 'language' => ['code' => wa_plat_idioma()],
                        'components' => [['type' => 'body', 'parameters' => array_map(fn ($x) => ['type' => 'text', 'text' => $x],
                            ['Paciente de prueba', 'Consultorio de prueba', fmt_fecha(date('Y-m-d', strtotime('+1 day'))), '10:00', 'Dra. Prueba', url_absoluta('/')])]]],
                ], wa_plat_token());
                $prueba = ['ok' => true, 'mensaje' => 'Enviado. Id: ' . ($res['messages'][0]['id'] ?? '—')];
            } catch (Throwable $e) {
                $prueba = ['ok' => false, 'mensaje' => $e->getMessage()];
            }
        }
    }

    if ($accion === 'ajustar') {
        $cid = (int) ($_POST['cid'] ?? 0); $n = (int) ($_POST['cantidad'] ?? 0);
        $mot = trim((string) ($_POST['motivo'] ?? '')) ?: ($n > 0 ? 'Cortesía' : 'Corrección');
        try {
            if (!credito_consultorio($cid)) throw new RuntimeException('Elige un consultorio.');
            credito_ajustar($cid, $r, $n, $mot, $actor['nombre']);
            auditar('credito_ajuste', $r, null, "$n · $mot", $cid, $actor);
            flash(($n > 0 ? 'Se agregaron ' : 'Se quitaron ') . abs($n) . ' ' . $d['nombre'] . '.');
        } catch (Throwable $e) { flash($e->getMessage(), 'danger'); }
        redirect($aqui);
    }
}

$venta = credito_venta_activa($r);
$paquetes = credito_paquetes($r);
$inc = credito_incluidos($r);
$planes = planes_mp();

$ini = db()->quote(date('Y-m-01 00:00:00'));
$rq = db()->quote($r);
$k = db()->query("SELECT
    (SELECT COALESCE(SUM(monto),0) FROM timbres_compras WHERE recurso=$rq AND estado='pagado' AND pagado_en >= $ini) ventas_mes,
    (SELECT COALESCE(SUM(timbres),0) FROM timbres_compras WHERE recurso=$rq AND estado='pagado' AND pagado_en >= $ini) vendidos_mes,
    (SELECT COALESCE(-SUM(cantidad),0) FROM timbres_mov WHERE recurso=$rq AND tipo IN ('consumo','devolucion') AND creado_en >= $ini) usados_mes,
    (SELECT COALESCE(SUM(cantidad),0) FROM timbres_mov WHERE recurso=$rq AND bolsa='comprado') saldo_clientes,
    (SELECT COUNT(*) FROM cfdi_emisor WHERE csd_cer IS NOT NULL) con_csd")->fetch();

$cons = db()->query("SELECT c.id, c.nombre, c.plan, c.estado,
        (SELECT COALESCE(SUM(m.cantidad),0) FROM timbres_mov m WHERE m.consultorio_id=c.id AND m.recurso=$rq AND m.bolsa='comprado') comprados,
        (SELECT COALESCE(-SUM(m.cantidad),0) FROM timbres_mov m WHERE m.consultorio_id=c.id AND m.recurso=$rq AND m.tipo IN ('consumo','devolucion') AND m.creado_en >= $ini) usados_mes,
        (SELECT COALESCE(SUM(t.monto),0) FROM timbres_compras t WHERE t.consultorio_id=c.id AND t.recurso=$rq AND t.estado='pagado') pagado_total,
        (SELECT e.csd_hasta FROM cfdi_emisor e WHERE e.consultorio_id=c.id AND e.csd_cer IS NOT NULL) csd_hasta
    FROM consultorios c ORDER BY c.nombre")->fetchAll();
$conMov = array_filter($cons, fn ($c) => $c['comprados'] || $c['usados_mes'] || $c['pagado_total'] || ($r === 'timbre' && $c['csd_hasta']));

$ventas = db()->query("SELECT t.*, c.nombre FROM timbres_compras t JOIN consultorios c ON c.id=t.consultorio_id WHERE t.recurso=$rq ORDER BY t.id DESC LIMIT 40")->fetchAll();

$titulo = 'Créditos';
$platNav = 'timbres';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-wallet2"></i> Créditos de prepago</h1>
<p class="text-muted">Los consultorios compran paquetes; el dinero cae en la cuenta de Mercado Pago de la plataforma.</p>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $r === 'timbre' ? 'active' : '' ?>" href="?r=timbre"><i class="bi bi-patch-check"></i> Timbres CFDI</a></li>
    <li class="nav-item"><a class="nav-link <?= $r === 'whatsapp' ? 'active' : '' ?>" href="?r=whatsapp"><i class="bi bi-whatsapp"></i> WhatsApp incluido</a></li>
</ul>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if ($prueba): ?><div class="alert alert-<?= $prueba['ok'] ? 'success' : 'danger' ?>"><?= e($prueba['mensaje']) ?></div><?php endif; ?>

<?php if ($r === 'timbre' && !(defined('APP_KEY') && APP_KEY !== '')): ?>
<div class="alert alert-warning small"><i class="bi bi-key"></i> <strong>Pon una APP_KEY en <code>config/secrets.php</code></strong> antes de que los consultorios suban su CSD.
    Sin ella, la llave de cifrado se guarda en <code>config/app.key</code>; si un despliegue borra ese archivo, habría que volver a subir todos los CSD.
    Genérala con <code>php -r "echo bin2hex(random_bytes(32));"</code> y no la cambies después.</div>
<?php endif; ?>
<?php if (!mp_configurado()): ?><div class="alert alert-danger small">Mercado Pago de la plataforma no está configurado: nadie podrá pagar paquetes.</div><?php endif; ?>

<div class="row g-3 mb-4">
    <?php $kpis = [['Ventas del mes', '$' . number_format($k['ventas_mes'], 2), 'bi-cash-coin'], [ucfirst($d['nombre']) . ' vendidos (mes)', number_format($k['vendidos_mes']), 'bi-bag-check'],
                   [ucfirst($d['nombre']) . ' usados (mes)', number_format($k['usados_mes']), 'bi-send'], ['Saldo de clientes sin usar', number_format($k['saldo_clientes']), 'bi-wallet2']];
    if ($r === 'timbre') $kpis[] = ['Consultorios con CSD', number_format($k['con_csd']), 'bi-key'];
    foreach ($kpis as [$l, $v, $i]): ?>
    <div class="col-6 col-lg"><div class="card h-100"><div class="card-body">
        <div class="small text-muted"><i class="bi <?= $i ?>"></i> <?= e($l) ?></div><div class="fs-4 fw-bold"><?= $v ?></div>
    </div></div></div>
    <?php endforeach; ?>
</div>

<?php if ($r === 'whatsapp'): ?>
<div class="row g-3 mb-4">
    <div class="col-lg-7"><form method="post" class="card h-100" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="accion" value="wa_cred"><input type="hidden" name="r" value="whatsapp">
        <div class="card-header d-flex justify-content-between"><span><i class="bi bi-whatsapp"></i> Número de la plataforma (WhatsApp Cloud API)</span>
            <?= wa_plat_activo() ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Apagado</span>' ?></div>
        <div class="card-body row g-2">
            <div class="col-12 form-check form-switch ms-2 mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="wpa" name="wa_plat_activo" value="1" <?= plataforma_cfg('wa_plat_activo') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="wpa">Ofrecer WhatsApp incluido a los consultorios</label>
                <div class="form-text">Al encenderlo, los recordatorios de quien no tenga cuenta propia de Meta salen de este número y gastan su saldo.</div>
            </div>
            <div class="col-md-5"><label class="form-label small">Phone Number ID</label><input name="wa_plat_phone_id" class="form-control" value="<?= e(wa_plat_phone_id()) ?>"></div>
            <div class="col-md-7"><label class="form-label small">Token permanente</label><input name="wa_plat_token" class="form-control" placeholder="<?= wa_plat_token() !== '' ? 'Guardado. Escribe uno nuevo solo para cambiarlo.' : 'EAAG…' ?>"></div>
            <div class="col-md-8"><label class="form-label small">Plantilla aprobada</label><input name="wa_plat_plantilla" class="form-control" placeholder="recordatorio_cita_consultorio" value="<?= e(wa_plat_plantilla()) ?>"></div>
            <div class="col-md-4"><label class="form-label small">Idioma</label><input name="wa_plat_idioma" class="form-control" value="<?= e(wa_plat_idioma()) ?>"></div>
            <div class="col-12"><div class="alert alert-info small mb-2">
                Crea en WhatsApp Manager una plantilla de categoría <b>Utilidad</b> con <b>6 variables</b>, en este orden:
                <div class="font-monospace mt-2 p-2 rounded" style="background:rgba(127,127,127,.12)">Hola {{1}}, te recordamos tu cita en {{2}} el {{3}} a las {{4}} con {{5}}. Confirma o cambia tu cita aquí: {{6}}</div>
                1 paciente · 2 consultorio · 3 fecha · 4 hora · 5 médico · 6 enlace para confirmar.
            </div></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button></div>
        </div>
    </form></div>
    <div class="col-lg-5"><form method="post" class="card h-100">
        <?= csrf_field() ?><input type="hidden" name="accion" value="wa_probar"><input type="hidden" name="r" value="whatsapp">
        <div class="card-header"><i class="bi bi-send-check"></i> Probar envío</div>
        <div class="card-body">
            <p class="small text-muted">Manda la plantilla con datos de ejemplo. No gasta saldo de ningún consultorio.</p>
            <input name="tel" class="form-control mb-2" placeholder="55 1234 5678" <?= wa_plat_configurado() ? '' : 'disabled' ?>>
            <button class="btn btn-outline-primary" <?= wa_plat_configurado() ? '' : 'disabled' ?>>Enviar prueba</button>
        </div>
    </form></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-lg-7"><form method="post" class="card h-100">
        <?= csrf_field() ?><input type="hidden" name="accion" value="ajustes"><input type="hidden" name="r" value="<?= e($r) ?>">
        <div class="card-header"><i class="bi bi-sliders"></i> Venta y precios</div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="venta" name="venta" value="1" <?= $venta ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="venta">Venta de paquetes abierta</label>
                <div class="form-text">Apagada, los consultorios ven los paquetes y la explicación, pero el botón dice "Disponible muy pronto".</div>
            </div>
            <?php if ($r === 'timbre'): ?>
            <div class="mb-3"><label class="form-label">PAC (proveedor de timbrado)</label>
                <input name="pac" class="form-control" value="<?= e(plataforma_cfg('cfdi_pac')) ?>" placeholder="Pendiente de elegir"></div>
            <?php endif; ?>

            <label class="form-label">Paquetes (MXN, IVA incluido)</label>
            <?php foreach (array_pad($paquetes, 5, ['cantidad' => '', 'precio' => '']) as $p): ?>
            <div class="input-group input-group-sm mb-2">
                <input name="paq_t[]" type="number" min="0" class="form-control" placeholder="Cantidad" value="<?= e((string) $p['cantidad']) ?>">
                <span class="input-group-text"><?= e($d['nombre']) ?> por $</span>
                <input name="paq_p[]" type="number" min="0" step="0.01" class="form-control" placeholder="Precio" value="<?= e((string) $p['precio']) ?>">
                <span class="input-group-text"><?= $p['cantidad'] ? '$' . number_format($p['precio'] / $p['cantidad'], 2) . ' c/u' : '—' ?></span>
            </div>
            <?php endforeach; ?>
            <div class="form-text mb-3">Deja vacía una fila para quitar ese paquete.</div>

            <label class="form-label">Incluidos al mes</label>
            <div class="row g-2 mb-3">
                <div class="col-6 col-md"><div class="input-group input-group-sm"><span class="input-group-text">Prueba</span>
                    <input name="inc[prueba]" type="number" min="0" class="form-control" value="<?= (int) $inc['prueba'] ?>"></div></div>
                <?php foreach ($planes as $kp => $pl): ?>
                <div class="col-6 col-md"><div class="input-group input-group-sm"><span class="input-group-text"><?= e($pl['nombre']) ?></span>
                    <input name="inc[<?= e($kp) ?>]" type="number" min="0" class="form-control" value="<?= (int) $inc[$kp] ?>"></div></div>
                <?php endforeach; ?>
            </div>
            <?php if ($r === 'timbre'): ?>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="cc" name="cancelar_cobra" value="1" <?= timbres_cancelar_cobra() ? 'checked' : '' ?>>
                <label class="form-check-label" for="cc">Cancelar un CFDI gasta 1 timbre (actívalo si tu PAC cobra la cancelación)</label>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary"><i class="bi bi-save"></i> Guardar</button>
        </div>
    </form></div>

    <div class="col-lg-5"><form method="post" class="card h-100">
        <?= csrf_field() ?><input type="hidden" name="accion" value="ajustar"><input type="hidden" name="r" value="<?= e($r) ?>">
        <div class="card-header"><i class="bi bi-gift"></i> Regalar o corregir <?= e($d['nombre']) ?></div>
        <div class="card-body">
            <div class="mb-2"><label class="form-label">Consultorio</label>
                <select name="cid" class="form-select" required><option value="">Elige…</option>
                <?php foreach ($cons as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> (<?= (int) $c['comprados'] ?>)</option><?php endforeach; ?></select></div>
            <div class="mb-2"><label class="form-label">Cantidad</label>
                <input name="cantidad" type="number" class="form-control" required placeholder="10 para dar, -10 para quitar"></div>
            <div class="mb-3"><label class="form-label">Motivo</label>
                <input name="motivo" class="form-control" maxlength="200" placeholder="Cortesía de arranque"></div>
            <button class="btn btn-outline-primary">Aplicar</button>
            <p class="small text-muted mt-3 mb-0">Se suman o restan a su saldo de paquetes y quedan en su historial.</p>
        </div>
    </form></div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-buildings"></i> Consultorios con movimientos</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Consultorio</th><th>Plan</th><th class="text-end">Saldo paquetes</th><th class="text-end">Usados este mes</th><th class="text-end">Ha comprado</th><?php if ($r === 'timbre'): ?><th>CSD</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$conMov): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin movimientos todavía.</td></tr><?php endif; ?>
        <?php foreach ($conMov as $c): ?>
            <tr><td><?= e($c['nombre']) ?></td><td class="small text-uppercase"><?= e($planes[$c['plan']]['nombre'] ?? $c['plan']) ?></td>
                <td class="text-end"><?= number_format($c['comprados']) ?></td><td class="text-end"><?= number_format($c['usados_mes']) ?></td>
                <td class="text-end">$<?= number_format($c['pagado_total'], 2) ?></td>
                <?php if ($r === 'timbre'): ?><td class="small"><?= $c['csd_hasta'] ? '<span class="text-success">vence ' . e(fmt_fecha($c['csd_hasta'])) . '</span>' : '<span class="text-muted">—</span>' ?></td><?php endif; ?></tr>
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
