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
            <?php /* Pagar en línea deja la cita CONFIRMADA sola (webhook). Es
                     opcional: quien no pague aquí, paga en el consultorio. */ ?>
            <div class="alert alert-info py-2 small mb-3">
                <?= et('Puedes dejar tu cita pagada desde ahora') ?>:
                <strong><?= fmt_money($agHecho['pago']['monto']) ?></strong>.
            </div>
            <a href="<?= e($agHecho['pago']['url']) ?>" class="btn btn-primary btn-lg w-100 mb-2 py-3 fw-semibold">
                <i class="bi bi-credit-card"></i> <?= et('Pagar mi cita en línea') ?>
            </a>
            <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($agHecho['token']) ?>" class="btn btn-link">
                <?= et('Prefiero pagar en el consultorio') ?>
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

        <?php /* Paso 1: con quién y qué día. */ ?>
        <form method="get" action="<?= e($agAccion) ?>" class="row g-3 mb-4">
            <input type="hidden" name="c" value="<?= e($agSlug) ?>">
            <div class="col-sm-7">
                <label class="form-label small fw-semibold"><?= et('¿Con quién?') ?></label>
                <select name="m" class="form-select form-select-lg" onchange="this.form.submit()">
                    <option value=""><?= et('Selecciona…') ?></option>
                    <?php foreach ($agMedicos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $agMedId === (int) $m['id'] ? 'selected' : '' ?>>
                            <?= e($m['nombre']) ?><?= $m['especialidad'] ? ' · ' . e($m['especialidad']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-5">
                <label class="form-label small fw-semibold"><?= et('¿Qué día?') ?></label>
                <input type="date" name="f" class="form-control form-control-lg" value="<?= e($agFecha) ?>"
                       min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime("+$agMaxDias days")) ?>"
                       onchange="this.form.submit()">
            </div>
        </form>

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
