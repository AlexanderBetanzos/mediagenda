<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_once __DIR__ . '/../includes/mercadopago.php';

$planes = planes_mp();

// Cancelar suscripción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar') {
    verify_csrf();
    $t = tenant();
    if (mp_configurado() && !empty($t['mp_suscripcion_id'])) {
        try {
            mp_cancelar_suscripcion($t['mp_suscripcion_id']);
            db()->prepare("UPDATE consultorios SET mp_estado='cancelled' WHERE id=?")->execute([tenant_id()]);
            flash('Suscripción cancelada. Conservas el acceso hasta el final del periodo ya pagado.');
        } catch (MpException $e) {
            flash('No se pudo cancelar: ' . $e->getMessage(), 'danger');
        }
    }
    redirect('/pagos/index.php');
}

$t          = tenant();
$estado     = $t['estado'] ?? 'trial';
$planNombre = $planes[$t['plan'] ?? '']['nombre'] ?? null;
$mpEstado   = $t['mp_estado'] ?? '';
$proximo    = $t['proximo_cobro'] ?? null;
$cancelada  = ($mpEstado === 'cancelled');
$puedeCancelar = mp_configurado() && !empty($t['mp_suscripcion_id']) && $mpEstado === 'authorized';
$dias       = trial_dias_restantes();

$titulo = 'Mi suscripción';
$activo = 'suscripcion';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4"><i class="bi bi-stars text-brand"></i> Mi suscripción</h1>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body">
                <?php if ($estado === 'activa'): ?>
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> Activa</span>
                        <h2 class="h5 mb-0">Plan <?= e($planNombre ?: '—') ?></h2>
                    </div>
                    <?php if ($cancelada): ?>
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-info-circle"></i> Tu suscripción está <strong>cancelada</strong>.
                            Conservas el acceso hasta <strong><?= $proximo ? fmt_fecha($proximo) : 'el fin del periodo' ?></strong>.
                        </div>
                    <?php elseif ($proximo): ?>
                        <p class="text-muted mb-3"><i class="bi bi-calendar-event"></i> Próximo cobro: <strong><?= fmt_fecha($proximo) ?></strong></p>
                    <?php endif; ?>

                    <?php if ($puedeCancelar): ?>
                        <form method="post" onsubmit="return confirm('¿Cancelar tu suscripción? Seguirás con acceso hasta el final del periodo pagado.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="cancelar">
                            <button class="btn btn-outline-danger"><i class="bi bi-x-circle"></i> Cancelar suscripción</button>
                        </form>
                    <?php elseif (!$cancelada): ?>
                        <p class="text-muted small mb-0">Cuenta activada manualmente. Si necesitas cambios, contáctanos.</p>
                    <?php endif; ?>

                <?php elseif ($estado === 'trial'): ?>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="badge bg-info fs-6"><i class="bi bi-stopwatch"></i> Prueba gratis</span>
                        <h2 class="h5 mb-0"><?= $dias !== null && $dias >= 0 ? "Te quedan $dias día" . ($dias === 1 ? '' : 's') : 'Prueba terminada' ?></h2>
                    </div>
                    <p class="text-muted">Tienes acceso completo a todas las funciones. Elige un plan para continuar sin interrupciones.</p>

                <?php else: ?>
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <span class="badge bg-danger fs-6"><i class="bi bi-lock"></i> Inactiva</span>
                        <h2 class="h5 mb-0">Reactiva tu cuenta</h2>
                    </div>
                    <p class="text-muted">Elige un plan para recuperar el acceso. Tus datos están guardados.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($estado !== 'activa' || $cancelada): ?>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Planes</div>
            <div class="card-body d-grid gap-3">
                <?php foreach (['estandar' => 'Crecimiento', 'premium' => 'Clínicas y equipos'] as $key => $desc):
                    $p = $planes[$key]; ?>
                <div class="d-flex align-items-center justify-content-between border rounded p-3">
                    <div>
                        <div class="fw-semibold"><?= e($p['nombre']) ?> <span class="text-brand">$<?= number_format($p['precio'], 0) ?></span><span class="text-muted small">/mes</span></div>
                        <div class="small text-muted"><?= e($desc) ?></div>
                    </div>
                    <?php if (mp_configurado()): ?>
                        <a href="<?= BASE_URL ?>/pagos/suscribir.php?plan=<?= $key ?>" class="btn btn-sm btn-primary">Suscribirme</a>
                    <?php else: ?>
                        <a href="mailto:<?= e(cfg('email') ?: 'ventas@mediagenda.com.mx') ?>" class="btn btn-sm btn-outline-primary">Contactar</a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
