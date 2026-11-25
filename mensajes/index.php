<?php
/**
 * WhatsApp incluido: saldo de mensajes, paquetes, cómo funciona y el
 * interruptor de recordatorios automáticos. Solo administradores.
 * Si el consultorio conectó su propia cuenta de Meta, aquí solo se le avisa
 * que sus mensajes salen de su número y no gastan saldo.
 */
require_once __DIR__ . '/../includes/whatsapp.php';
require_once __DIR__ . '/../includes/creditos_ui.php';
require_role('admin');
credito_ui_post_compra('whatsapp');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'auto') {
    verify_csrf();
    guardar_cfg(['wa_incluido_auto' => !empty($_POST['wa_incluido_auto']) ? '1' : '0']);
    auditar('wa_incluido_auto', 'whatsapp', null, !empty($_POST['wa_incluido_auto']) ? 'encendido' : 'apagado');
    flash('Listo, guardado.');
    redirect('/mensajes/index');
}

$cid = (int) tenant_id();
$modo = wa_modo();
$saldo = credito_saldo($cid, 'whatsapp');
$inc = credito_incluidos('whatsapp');
$planes = planes_mp();
$tieneModulo = modulo_activo('whatsapp');

$titulo = t('Mensajes de WhatsApp');
$activo = 'mensajes';
include __DIR__ . '/../includes/header.php';
credito_ui_estilos();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-whatsapp text-success"></i> <?= et('Mensajes de WhatsApp') ?></h1>
    <a href="#como" class="btn btn-sm btn-outline-secondary"><i class="bi bi-question-circle"></i> <?= et('¿Cómo funciona?') ?></a>
</div>

<?php if ($modo === 'propio'): ?>
<div class="alert alert-success d-flex gap-2"><i class="bi bi-check-circle fs-5"></i>
    <div><strong><?= et('Conectaste tu propia cuenta de WhatsApp Business.') ?></strong>
    <?= et('Tus recordatorios salen de tu número y Meta te los cobra directo; no gastan saldo de aquí. Lo administras en Configuración.') ?></div></div>
<?php elseif (!wa_plat_activo()): ?>
<div class="alert alert-info d-flex gap-2"><i class="bi bi-hourglass-split fs-5"></i>
    <div><strong><?= et('Estamos terminando de activar WhatsApp incluido.') ?></strong>
    <?= et('Muy pronto tus recordatorios saldrán solos por WhatsApp, sin que tengas que tramitar nada con Meta.') ?></div></div>
<?php elseif (!$tieneModulo): ?>
<div class="alert alert-warning"><i class="bi bi-lock"></i> <?= et('Los avisos automáticos por WhatsApp vienen en los planes Profesional y Clínica.') ?>
    <a href="<?= BASE_URL ?>/pagos/index"><?= et('Ver planes') ?></a></div>
<?php endif; ?>

<?php if ($modo !== 'propio'): ?>
    <?php credito_ui_saldo('whatsapp', $saldo); ?>

    <form method="post" class="card mb-4"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <?= csrf_field() ?><input type="hidden" name="accion" value="auto">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="wia" name="wa_incluido_auto" value="1" onchange="this.form.submit()"
                   <?= cfg('wa_incluido_auto', '1') === '1' ? 'checked' : '' ?> <?= $tieneModulo && wa_plat_activo() ? '' : 'disabled' ?>>
            <label class="form-check-label fw-semibold" for="wia"><?= et('Mandar los recordatorios de cita por WhatsApp automáticamente') ?></label>
            <div class="small text-muted"><?= et('Un día antes y, si lo activaste en Configuración, 2 horas antes. El paciente confirma o cambia su cita con un clic.') ?></div>
        </div>
        <?php if (wa_auto_activo()): ?><span class="badge text-bg-success"><i class="bi bi-broadcast"></i> <?= et('Activo') ?></span><?php endif; ?>
    </div></form>

    <?php credito_ui_paquetes('whatsapp', [
        t('%n recordatorios o avisos a tus pacientes'),
        t('Salen con el nombre de tu consultorio'),
        t('No caducan'),
        t('Sin trámites con Meta'),
    ], [
        e(t('Cada mensaje enviado a un paciente usa 1. Si WhatsApp no lo acepta (número inválido o sin WhatsApp), regresa a tu saldo.')),
    ]); ?>
<?php endif; ?>

<div class="card mb-4" id="como">
    <div class="card-header"><i class="bi bi-info-circle"></i> <?= et('Cómo funciona WhatsApp incluido') ?></div>
    <div class="card-body tb-como"><div class="row g-4">
        <div class="col-lg-6"><dl class="mb-0">
            <dt>1. <?= et('De qué número sale') ?></dt>
            <dd><?= et('Del número oficial de la plataforma, verificado por Meta. El mensaje dice el nombre de tu consultorio, la fecha, la hora y el médico, con un enlace para confirmar o cambiar la cita.') ?></dd>
            <dt>2. <?= et('Qué gasta 1 mensaje') ?></dt>
            <dd><?= et('Cada recordatorio o aviso automático que se envía a un paciente. Si la cita tiene recordatorio del día anterior y de 2 horas antes, son 2 mensajes.') ?></dd>
            <dt>3. <?= et('Qué NO gasta') ?></dt>
            <dd><?= et('Los botones de WhatsApp que abres tú a mano (wa.me) desde la agenda o el expediente: esos salen de tu propio celular y son gratis. Tampoco gastan los mensajes que WhatsApp rechaza.') ?></dd>
            <dt>4. <?= et('En qué orden se usan') ?></dt>
            <dd><?= et('Primero los incluidos en tu plan del mes y, cuando se acaban, los de tus paquetes.') ?></dd>
        </dl></div>
        <div class="col-lg-6"><dl class="mb-0">
            <dt>5. <?= et('Mensajes incluidos') ?></dt>
            <dd><?php $txt = [et('Prueba gratis') . ': <b>' . (int) $inc['prueba'] . '</b>'];
                foreach ($inc as $k => $n) { if ($k === 'prueba') continue; $txt[] = e($planes[$k]['nombre'] ?? $k) . ': <b>' . ($n ?: et('ninguno')) . '</b>'; }
                echo implode(' · ', $txt); ?> <?= et('al mes.') ?>
                <?= et('Se renuevan el día 1 y los que no uses no pasan al siguiente mes.') ?></dd>
            <dt>6. <?= et('Mensajes de paquete') ?></dt>
            <dd><?= et('Los pagas una sola vez y no caducan mientras tu cuenta exista. No son reembolsables ni se pasan a otra cuenta.') ?></dd>
            <dt>7. <?= et('Si se te acaban') ?></dt>
            <dd><?= et('Los recordatorios siguen saliendo por correo; solo se pausa WhatsApp hasta que compres otro paquete o empiece el mes.') ?></dd>
            <dt>8. <?= et('¿Prefieres tu propio número?') ?></dt>
            <dd><?= et('Puedes conectar tu cuenta de WhatsApp Business API en Configuración. Entonces los mensajes salen de tu número, Meta te los cobra directo y no gastan saldo aquí.') ?></dd>
        </dl></div>
    </div></div>
</div>

<?php if ($modo !== 'propio') credito_ui_historial('whatsapp', ['consumo' => t('Mensaje enviado')]); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
