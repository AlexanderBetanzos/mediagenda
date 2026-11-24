<?php
/**
 * Solicitud de admisión desde la página pública de la clínica:
 *   /admision/solicitar?c=<slug>        formulario
 *   /admision/solicitar?c=<slug>&ok=1   "listo" (Post-Redirect-Get)
 *
 * Pensado para quien escribe POR otra persona ("mi hijo necesita ayuda"): se
 * pregunta para quién es, quién la pide y cómo contactarle. Llega a
 * Admisiones y se avisa por correo al equipo. Anti-spam: campo trampa y
 * máximo 5 solicitudes por hora desde la misma IP.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/correo.php';
require_once __DIR__ . '/../includes/admisiones.php';

$slug = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($_GET['c'] ?? $_POST['c'] ?? ''));
$con  = $slug !== '' ? consultorio_publico($slug) : null;
if (!$con) { http_response_code(404); die('Consultorio no encontrado.'); }
tenant_forzar((int) $con['id']);
if (!admision_web_activa()) { redirect('/c/' . rawurlencode($slug)); }
asegurar_admisiones();

$SERV = admision_servicios();
$URG  = admision_urgencias();
$err  = '';
$v    = fn ($k) => e((string) ($_POST[$k] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim((string) ($_POST['website'] ?? '')) !== '') { http_response_code(400); exit; }   // trampa para robots
    $txt = fn ($k, $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
    $paraMi = ($_POST['para'] ?? '') === 'mi';
    $solic  = $txt('solicitante', 160);
    $pacNom = $paraMi ? $solic : $txt('paciente_nombre', 160);
    $tel    = preg_replace('/\D/', '', $txt('telefono', 40));
    $email  = $txt('email', 150);
    $ip     = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    $rec = db()->prepare('SELECT COUNT(*) FROM admisiones WHERE consultorio_id = ? AND ip = ? AND creado_en > NOW() - INTERVAL 1 HOUR');
    $rec->execute([(int) $con['id'], $ip]);
    if ((int) $rec->fetchColumn() >= 5) $err = t('Recibimos varias solicitudes desde aquí. Si es urgente, llámanos o escríbenos por WhatsApp.');
    elseif ($solic === '' || $pacNom === '') $err = t('Necesitamos tu nombre y el de la persona que necesita atención.');
    elseif (strlen($tel) < 10) $err = t('Escribe un teléfono de 10 dígitos para poder contactarte.');
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = t('Revisa tu correo.');
    elseif (empty($_POST['acepto'])) $err = t('Acepta el aviso de privacidad para continuar.');

    if (!$err) {
        db()->prepare("INSERT INTO admisiones (consultorio_id, paciente_nombre, paciente_edad, solicitante, parentesco, telefono, email, situacion, servicio, urgencia, preferencia, origen, ip, creado_en)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,'web',?,NOW())")
            ->execute([(int) $con['id'], $pacNom, $txt('paciente_edad', 20) ?: null, $solic, $paraMi ? t('Yo mismo(a)') : ($txt('parentesco', 60) ?: null),
                       $tel, $email ?: null, $txt('situacion', 3000) ?: null,
                       array_key_exists($_POST['servicio'] ?? '', $SERV) ? $_POST['servicio'] : 'no_se',
                       array_key_exists($_POST['urgencia'] ?? '', $URG) ? $_POST['urgencia'] : null,
                       in_array($_POST['preferencia'] ?? '', ['WhatsApp', 'llamada', 'correo'], true) ? $_POST['preferencia'] : null, $ip]);
        $aid = (int) db()->lastInsertId();
        auditar('admision_web', 'admision', $aid, $pacNom, (int) $con['id']);

        header('Location: ' . BASE_URL . '/admision/solicitar?c=' . rawurlencode($slug) . '&ok=1');
        cerrar_respuesta();

        // Aviso al equipo: correo del consultorio y de sus administradores.
        try {
            $para = array_filter([cfg('email')]);
            $st = db()->prepare("SELECT email FROM usuarios WHERE consultorio_id = ? AND rol = 'admin' AND activo = 1 AND email <> ''");
            $st->execute([(int) $con['id']]);
            $para = array_unique(array_merge($para, $st->fetchAll(PDO::FETCH_COLUMN)));
            $urg = $_POST['urgencia'] ?? '';
            $cuerpo = '<p><b>' . e($solic) . '</b>' . ($paraMi ? '' : ' (' . e($txt('parentesco', 60) ?: t('familiar')) . ')')
                    . ' ' . e(t('pide atención para')) . ' <b>' . e($pacNom) . '</b>' . ($txt('paciente_edad', 20) ? ', ' . e($txt('paciente_edad', 20)) : '') . '.</p>'
                    . '<p>' . e(t($SERV[$_POST['servicio'] ?? 'no_se'] ?? '')) . ($urg === 'hoy' ? ' · <b style="color:#dc2626">' . e(t('Urgente')) . '</b>' : '') . '</p>'
                    . ($txt('situacion', 3000) ? '<p style="white-space:pre-line">' . e($txt('situacion', 3000)) . '</p>' : '')
                    . '<p>' . e(t('Teléfono')) . ': ' . e($tel) . ($email ? ' · ' . e($email) : '') . '</p>';
            foreach ($para as $dest) {
                enviar_correo($dest, ($urg === 'hoy' ? '⚡ ' : '') . t('Nueva solicitud de admisión') . ': ' . $pacNom,
                    correo_layout(t('Nueva solicitud de admisión'), $cuerpo, t('Ver en Admisiones'), correo_url('/admisiones/index'), t('Desde tu página')));
            }
            if ($email) {
                enviar_correo($email, t('Recibimos tu solicitud') . ' · ' . marca_nombre(),
                    correo_layout(t('Recibimos tu solicitud'), '<p>' . e(t('Hola')) . ' ' . e($solic) . ', ' . e(t('gracias por escribirnos. Nuestro equipo de admisión te contactará muy pronto para orientarte.')) . '</p>'
                        . '<p>' . e(t('Si se trata de una emergencia o hay riesgo inmediato, llama al 911 o acude al servicio de urgencias más cercano.')) . '</p>'));
            }
        } catch (Throwable $e) { /* el aviso falló; la solicitud ya está en Admisiones */ }
        exit;
    }
}

$tel = cfg('telefono');
$waNum = cfg('whatsapp') ?: $tel;
$wa = '';
if ($waNum) {
    $n = preg_replace('/\D+/', '', $waNum);
    if (strlen($n) <= 10) $n = cfg('pais_lada', '52') . $n;
    $wa = 'https://wa.me/' . $n;
}
$titulo = t('Solicitar atención');
include __DIR__ . '/../includes/publico_header.php';
?>
<style>
.ad-wrap{max-width:720px;margin:0 auto}
.ad-op{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.ad-op label{border:1.5px solid var(--bs-border-color);border-radius:14px;padding:.9rem 1rem;cursor:pointer;font-weight:600;display:flex;align-items:center;gap:.6rem;transition:border-color .2s,background .2s}
.ad-op input{display:none}
.ad-op input:checked+label{border-color:var(--brand);background:color-mix(in srgb,var(--brand) 7%,transparent);color:var(--brand)}
.ad-sec{font-size:.78rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--bs-secondary-color);margin:1.4rem 0 .6rem}
.ad-hp{position:absolute;left:-9999px}
</style>
<div class="pub-card card ad-wrap"><div class="card-body p-4 p-sm-5">
<?php if (!empty($_GET['ok'])): ?>
    <div class="text-center">
        <span class="d-inline-flex align-items-center justify-content-center" style="width:70px;height:70px;border-radius:50%;background:#e7f7ee"><i class="bi bi-heart-fill text-success" style="font-size:2rem"></i></span>
        <h1 class="h4 fw-bold mt-3"><?= et('Recibimos tu solicitud') ?></h1>
        <p class="text-muted"><?= et('Gracias por dar este paso. Nuestro equipo de admisión te contactará muy pronto para orientarte con calma y total confidencialidad.') ?></p>
        <?php if ($wa): ?><a href="<?= e($wa . '?text=' . rawurlencode(t('Hola, acabo de enviar una solicitud de atención desde su página.'))) ?>" target="_blank" rel="noopener" class="btn btn-success btn-lg mt-2"><i class="bi bi-whatsapp"></i> <?= et('¿Prefieres escribirnos ya?') ?></a><?php endif; ?>
        <div class="alert alert-warning small text-start mt-4 mb-0"><i class="bi bi-exclamation-triangle-fill"></i> <?= et('Si hay riesgo inmediato para la vida de alguien, llama al 911 o acude a urgencias. También puedes llamar a la Línea de la Vida: 800 911 2000 (24 h, gratuita).') ?></div>
        <a href="<?= BASE_URL ?>/c/<?= e($slug) ?>" class="d-inline-block mt-3 small">&larr; <?= et('Volver') ?></a>
    </div>
<?php else: ?>
    <h1 class="h3 fw-bold mb-1"><?= et('¿Tú o alguien que quieres necesita acompañamiento?') ?></h1>
    <p class="text-muted"><?= sprintf(et('Cuéntanos un poco y el equipo de admisión de %s te contactará para orientarte. Todo lo que compartas es confidencial.'), e($con['nombre'] ?? marca_nombre())) ?></p>
    <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>
    <form method="post" novalidate>
        <input type="hidden" name="c" value="<?= e($slug) ?>">
        <div class="ad-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="ad-sec"><?= et('¿Para quién es la atención?') ?></div>
        <div class="ad-op mb-3">
            <input type="radio" name="para" id="paraOtro" value="otro" <?= ($_POST['para'] ?? 'otro') !== 'mi' ? 'checked' : '' ?>><label for="paraOtro"><i class="bi bi-people"></i> <?= et('Para un ser querido') ?></label>
            <input type="radio" name="para" id="paraMi" value="mi" <?= ($_POST['para'] ?? '') === 'mi' ? 'checked' : '' ?>><label for="paraMi"><i class="bi bi-person"></i> <?= et('Para mí') ?></label>
        </div>
        <div class="row g-3" id="bloqueOtro">
            <div class="col-sm-8"><label class="form-label fw-semibold small"><?= et('Nombre de la persona') ?> *</label><input name="paciente_nombre" class="form-control" maxlength="160" value="<?= $v('paciente_nombre') ?>"></div>
            <div class="col-sm-4"><label class="form-label fw-semibold small"><?= et('Edad') ?></label><input name="paciente_edad" class="form-control" maxlength="20" value="<?= $v('paciente_edad') ?>"></div>
        </div>

        <div class="ad-sec"><?= et('Tus datos de contacto') ?></div>
        <div class="row g-3">
            <div class="col-sm-8"><label class="form-label fw-semibold small"><?= et('Tu nombre') ?> *</label><input name="solicitante" class="form-control" maxlength="160" required value="<?= $v('solicitante') ?>"></div>
            <div class="col-sm-4" id="bloqueParen"><label class="form-label fw-semibold small"><?= et('Parentesco') ?></label>
                <select name="parentesco" class="form-select"><?php foreach (['Madre', 'Padre', 'Hijo(a)', 'Pareja', 'Hermano(a)', 'Amigo(a)', 'Otro familiar'] as $o): ?><option <?= ($_POST['parentesco'] ?? '') === t($o) ? 'selected' : '' ?>><?= e(t($o)) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-6"><label class="form-label fw-semibold small"><?= et('Teléfono') ?> *</label><input name="telefono" type="tel" inputmode="numeric" class="form-control" maxlength="20" required value="<?= $v('telefono') ?>"></div>
            <div class="col-sm-6"><label class="form-label fw-semibold small"><?= et('Correo (opcional)') ?></label><input name="email" type="email" class="form-control" maxlength="150" value="<?= $v('email') ?>"></div>
            <div class="col-12"><label class="form-label fw-semibold small"><?= et('¿Cómo prefieres que te contactemos?') ?></label>
                <div class="d-flex flex-wrap gap-3"><?php foreach (['WhatsApp' => 'bi-whatsapp', 'llamada' => 'bi-telephone', 'correo' => 'bi-envelope'] as $k => $ic): ?>
                    <div class="form-check"><input class="form-check-input" type="radio" name="preferencia" id="pref<?= $k ?>" value="<?= $k ?>" <?= ($_POST['preferencia'] ?? 'WhatsApp') === $k ? 'checked' : '' ?>><label class="form-check-label" for="pref<?= $k ?>"><i class="bi <?= $ic ?>"></i> <?= e(t(ucfirst($k))) ?></label></div>
                <?php endforeach; ?></div></div>
        </div>

        <div class="ad-sec"><?= et('¿Cómo podemos ayudar?') ?></div>
        <div class="row g-3">
            <div class="col-sm-7"><label class="form-label fw-semibold small"><?= et('Lo que buscas') ?></label>
                <select name="servicio" class="form-select"><?php foreach ($SERV as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['servicio'] ?? 'no_se') === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-sm-5"><label class="form-label fw-semibold small"><?= et('¿Qué tan pronto?') ?></label>
                <select name="urgencia" class="form-select"><?php foreach ($URG as $k => $l): ?><option value="<?= $k ?>" <?= ($_POST['urgencia'] ?? 'semana') === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label fw-semibold small"><?= et('Cuéntanos brevemente qué está pasando (opcional)') ?></label>
                <textarea name="situacion" class="form-control" rows="4" maxlength="3000" placeholder="<?= e(t('Desde cuándo, qué han notado, si ya ha recibido tratamiento…')) ?>"><?= $v('situacion') ?></textarea></div>
        </div>

        <div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="acepto" id="acepto" value="1" <?= !empty($_POST['acepto']) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="acepto"><?= et('Acepto que la clínica use estos datos solo para contactarme sobre esta solicitud, conforme a su aviso de privacidad.') ?></label></div>
        <button class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-send"></i> <?= et('Enviar solicitud') ?></button>
        <div class="alert alert-warning small mt-3 mb-0"><i class="bi bi-exclamation-triangle-fill"></i> <?= et('Si hay riesgo inmediato para la vida de alguien, llama al 911 o acude a urgencias. Línea de la Vida: 800 911 2000 (24 h, gratuita).') ?></div>
    </form>
<?php endif; ?>
</div></div>
<script>
(function () {
    var mi = document.getElementById('paraMi'), otro = document.getElementById('paraOtro');
    if (!mi) return;
    function v() { var esMi = mi.checked; document.getElementById('bloqueOtro').hidden = esMi; document.getElementById('bloqueParen').hidden = esMi; }
    mi.addEventListener('change', v); otro.addEventListener('change', v); v();
})();
</script>
<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
