<?php
/**
 * Formulario de reserva en línea (render). Lo usan /agenda/reservar y el
 * micrositio del consultorio. La LÓGICA ya corrió (agenda_reservar_logica.php):
 * aquí solo se pinta con las variables $ag* que dejó.
 *
 * Espera definido por quien lo incluye:
 *   $agAccion  URL a la que envían los formularios (la propia página)
 */
$agAccion = $agAccion ?? '';
?>

<?php if ($agHecho): ?>
<div class="card pub-card">
    <div class="card-body p-4 p-sm-5 text-center">
        <i class="bi bi-check-circle-fill text-success" style="font-size:3.2rem"></i>
        <h2 class="h4 mt-3"><?= et('¡Listo, tu cita quedó agendada!') ?></h2>
        <p class="text-muted">
            <strong class="text-capitalize"><?= e(fmt_fecha($agHecho['fecha'])) ?></strong>
            <?= et('a las') ?> <strong><?= fmt_hora($agHecho['hora']) ?></strong>
        </p>

        <?php if (!empty($agHecho['pago'])): ?>
            <?php /* Hay pago en línea: pagar deja la cita CONFIRMADA sola (webhook).
                     Y siempre la alternativa de pagar en el consultorio. */ ?>
            <div class="alert alert-info py-2 mb-3">
                <?= et('Costo de la cita') ?>: <strong><?= fmt_money($agHecho['pago']['monto']) ?></strong>.
                <?= et('¿Cómo prefieres pagar?') ?>
            </div>
            <a href="<?= e($agHecho['pago']['url']) ?>" class="btn btn-primary btn-lg w-100 mb-2 py-3 fw-semibold">
                <i class="bi bi-credit-card"></i> <?= et('Pagar en línea ahora') ?>
            </a>
            <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($agHecho['token']) ?>" class="btn btn-outline-secondary w-100">
                <i class="bi bi-cash-coin"></i> <?= et('Pagar en el consultorio') ?>
            </a>
        <?php elseif (!empty($agHecho['precio']) && (float) $agHecho['precio'] > 0): ?>
            <?php /* Hay costo pero sin pago en línea configurado: se paga al llegar. */ ?>
            <div class="alert alert-info py-2 mb-3">
                <i class="bi bi-cash-coin"></i>
                <?= et('Costo de la cita') ?>: <strong><?= fmt_money($agHecho['precio']) ?></strong>.
                <?= et('Lo pagas en el consultorio al llegar.') ?>
            </div>
            <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($agHecho['token']) ?>" class="btn btn-outline-secondary">
                <?= et('Ver o cancelar mi cita') ?>
            </a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($agHecho['token']) ?>" class="btn btn-outline-secondary">
                <?= et('Ver o cancelar mi cita') ?>
            </a>
        <?php endif; ?>

        <p class="text-muted small mt-3 mb-0">
            <?= et('Te enviamos el detalle por correo. Si no puedes venir, avísanos desde ese enlace.') ?>
        </p>
    </div>
</div>

<?php else: ?>
<div class="card pub-card">
    <div class="card-body p-4 p-sm-5">

        <?php if ($agError): ?><div class="alert alert-danger py-2"><?= e($agError) ?></div><?php endif; ?>
        <?php if (cfg('agenda_online_aviso')): ?>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> <?= e(cfg('agenda_online_aviso')) ?></div>
        <?php endif; ?>

        <?php if (!$agMedicos): ?>
            <div class="alert alert-warning mb-0">
                <?= et('Por ahora no hay horarios publicados. Comunícate con el consultorio.') ?>
            </div>
        <?php else: ?>

        <?php /* Paso 1: con quién. */ ?>
        <form method="get" action="<?= e($agAccion) ?>" class="mb-4" id="agForm">
            <input type="hidden" name="c" value="<?= e($agSlug) ?>">
            <input type="hidden" name="f" id="agFechaHidden" value="<?= e($agFecha) ?>">
            <label class="form-label small fw-semibold"><?= et('¿Con quién?') ?></label>
            <select name="m" class="form-select form-select-lg" onchange="document.getElementById('agFechaHidden').value=''; this.form.submit()">
                <option value=""><?= et('Selecciona…') ?></option>
                <?php foreach ($agMedicos as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= $agMedId === (int) $m['id'] ? 'selected' : '' ?>>
                        <?= e($m['nombre']) ?><?= $m['especialidad'] ? ' · ' . e($m['especialidad']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php /* Paso 2: calendario de días disponibles del médico. */ ?>
        <?php if ($agMedId && $agDiasLab): ?>
        <label class="form-label small fw-semibold"><?= et('¿Qué día?') ?></label>
        <div id="agCal" class="ag-cal mb-4"
             data-dias="<?= e(implode(',', $agDiasLab)) ?>"
             data-sel="<?= e($agFecha) ?>"
             data-min="<?= date('Y-m-d') ?>"
             data-max="<?= date('Y-m-d', strtotime("+$agMaxDias days")) ?>"></div>
        <?php endif; ?>

        <?php if (!$agMedId): ?>
            <p class="text-muted small mb-0"><?= et('Elige con quién quieres tu cita para ver los horarios libres.') ?></p>

        <?php elseif (!$agHuecos): ?>
            <div class="alert alert-warning mb-0">
                <i class="bi bi-calendar-x"></i>
                <?= et('No hay horarios libres ese día. Prueba con otra fecha.') ?>
            </div>

        <?php else: ?>
        <?php /* Paso 2: el hueco y los datos de contacto. */ ?>
        <form method="post" action="<?= e($agAccion) ?>">
            <input type="hidden" name="c" value="<?= e($agSlug) ?>">
            <input type="hidden" name="accion" value="reservar">
            <input type="hidden" name="medico_id" value="<?= $agMedId ?>">
            <input type="hidden" name="fecha" value="<?= e($agFecha) ?>">
            <?php /* Trampa para robots: invisible para una persona. */ ?>
            <input type="text" name="website" tabindex="-1" autocomplete="off"
                   style="position:absolute;left:-9999px" aria-hidden="true">

            <label class="form-label small fw-semibold"><?= et('Horarios libres') ?></label>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php foreach ($agHuecos as $i => $h): ?>
                <label class="hueco">
                    <input type="radio" name="hora" value="<?= e($h) ?>" required <?= $i === 0 ? 'checked' : '' ?>>
                    <span><?= e($h) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" required maxlength="120" value="<?= e($_POST['nombre'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Apellidos') ?> *</label>
                    <input name="apellidos" class="form-control" required maxlength="120" value="<?= e($_POST['apellidos'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Teléfono') ?> *</label>
                    <input name="telefono" type="tel" class="form-control" required maxlength="40" value="<?= e($_POST['telefono'] ?? '') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label small fw-semibold"><?= et('Correo') ?></label>
                    <input name="email" type="email" class="form-control" maxlength="150" value="<?= e($_POST['email'] ?? '') ?>">
                    <div class="form-text"><?= et('Para enviarte el comprobante.') ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold"><?= et('Motivo (opcional)') ?></label>
                    <input name="motivo" class="form-control" maxlength="255" placeholder="<?= e(t('Ej. Revisión general')) ?>">
                </div>
            </div>

            <?php if (!empty($agCobra)): ?>
            <div class="alert alert-info py-2 small mt-3 mb-0 text-center">
                <i class="bi bi-credit-card"></i>
                <?= et('La cita tiene un costo de') ?> <strong><?= fmt_money($agPrecio) ?></strong>.
                <?= et('Podrás pagarla en línea al terminar, o en el consultorio.') ?>
            </div>
            <?php endif; ?>

            <button class="btn btn-primary w-100 mt-4 py-3 fw-semibold">
                <i class="bi bi-check-lg"></i> <?= et('Agendar mi cita') ?>
            </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php /* Calendario de días disponibles (solo se pinta si hay #agCal). */ ?>
<style>
    .ag-cal { border: 1px solid rgba(127,127,127,.18); border-radius: 14px; padding: 1rem; max-width: 360px; }
    .ag-cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .6rem; }
    .ag-cal-head b { font-weight: 700; text-transform: capitalize; }
    .ag-cal-nav { border: 0; background: transparent; font-size: 1.2rem; line-height: 1; cursor: pointer; color: inherit; padding: .2rem .5rem; border-radius: 8px; }
    .ag-cal-nav:hover { background: rgba(127,127,127,.12); }
    .ag-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
    .ag-cal-dow { text-align: center; font-size: .68rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; padding: .2rem 0; }
    .ag-cal-day { aspect-ratio: 1; border: 0; background: transparent; border-radius: 9px; font-weight: 600; cursor: pointer; color: inherit; }
    .ag-cal-day.off { color: #cbd5e1; cursor: default; }
    html.lp-dark .ag-cal-day.off { color: rgba(255,255,255,.18); }
    .ag-cal-day.on:hover { background: color-mix(in srgb, var(--cl, #1f6b73) 14%, transparent); }
    .ag-cal-day.sel { background: var(--cl, #1f6b73); color: #fff; }
</style>
<script>
(function () {
    var el = document.getElementById('agCal');
    if (!el) return;
    var dias = (el.dataset.dias || '').split(',').filter(function (x) { return x !== ''; }).map(Number);
    var min = el.dataset.min, max = el.dataset.max, sel = el.dataset.sel || '';
    var DOW = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
    var MES = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var iso = function (d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
    var hoy = new Date(min + 'T00:00:00');
    var vista = new Date((sel || min) + 'T00:00:00'); vista.setDate(1);

    function pintar() {
        var y = vista.getFullYear(), m = vista.getMonth();
        var primero = new Date(y, m, 1), offset = primero.getDay();
        var dim = new Date(y, m + 1, 0).getDate();
        var h = '<div class="ag-cal-head"><button type="button" class="ag-cal-nav" data-mv="-1">&lsaquo;</button>'
              + '<b>' + MES[m] + ' ' + y + '</b>'
              + '<button type="button" class="ag-cal-nav" data-mv="1">&rsaquo;</button></div>';
        h += '<div class="ag-cal-grid">';
        DOW.forEach(function (d) { h += '<div class="ag-cal-dow">' + d + '</div>'; });
        for (var i = 0; i < offset; i++) h += '<div></div>';
        for (var dd = 1; dd <= dim; dd++) {
            var fecha = new Date(y, m, dd), f = iso(fecha);
            var libre = dias.indexOf(fecha.getDay()) !== -1 && f >= min && f <= max;
            var cls = libre ? 'on' : 'off';
            if (f === sel) cls += ' sel';
            h += '<button type="button" class="ag-cal-day ' + cls + '" ' + (libre ? 'data-f="' + f + '"' : 'disabled') + '>' + dd + '</button>';
        }
        h += '</div>';
        el.innerHTML = h;
    }
    pintar();

    el.addEventListener('click', function (ev) {
        var nav = ev.target.closest('.ag-cal-nav');
        if (nav) { vista.setMonth(vista.getMonth() + Number(nav.dataset.mv)); pintar(); return; }
        var day = ev.target.closest('.ag-cal-day.on');
        if (day && day.dataset.f) {
            document.getElementById('agFechaHidden').value = day.dataset.f;
            document.getElementById('agForm').submit();
        }
    });
})();
</script>
