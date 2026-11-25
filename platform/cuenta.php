<?php
/** Mi cuenta de plataforma: verificación en dos pasos y contraseña. */
require_once __DIR__ . '/../includes/totp.php';
require_platform();
asegurar_totp_plataforma();
$pa = platform_admin();
$id = (int) $pa['id'];
$actor = ['nombre' => $pa['nombre'] . ' (plataforma)'];
$nuevos = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $ac = (string) ($_POST['accion'] ?? '');
    if ($ac === 'iniciar') { $_SESSION['plat_totp_nuevo'] = b32_encode(random_bytes(20)); redirect('/platform/cuenta'); }
    if ($ac === 'confirmar') {
        $sec = (string) ($_SESSION['plat_totp_nuevo'] ?? '');
        if ($sec !== '' && totp_valido($sec, (string) ($_POST['codigo'] ?? ''))) {
            db()->prepare('UPDATE plataforma_admins SET totp_secret = ?, totp_activo = 1 WHERE id = ?')->execute([cfdi_cifrar($sec), $id]);
            unset($_SESSION['plat_totp_nuevo']);
            $nuevos = totp_respaldo_plataforma($id);
            auditar('plataforma_2fa_activado', 'plataforma_admin', $id, null, null, $actor);
        } else { flash('El código no coincide.', 'danger'); redirect('/platform/cuenta'); }
    }
    if ($ac === 'desactivar') {
        if (totp_verificar_plataforma($id, (string) ($_POST['codigo'] ?? ''))) {
            db()->prepare('UPDATE plataforma_admins SET totp_secret = NULL, totp_activo = 0, totp_respaldo = NULL WHERE id = ?')->execute([$id]);
            auditar('plataforma_2fa_desactivado', 'plataforma_admin', $id, null, null, $actor);
            flash('Verificación en dos pasos desactivada.', 'warning');
        } else flash('Código incorrecto.', 'danger');
        redirect('/platform/cuenta');
    }
    if ($ac === 'password') {
        $st = db()->prepare('SELECT password_hash FROM plataforma_admins WHERE id = ?'); $st->execute([$id]);
        if (!password_verify((string) ($_POST['actual'] ?? ''), (string) $st->fetchColumn())) flash('Tu contraseña actual no es correcta.', 'danger');
        elseif ($e = password_politica((string) ($_POST['nueva'] ?? ''), (string) $pa['email'])) flash($e, 'danger');
        else {
            db()->prepare('UPDATE plataforma_admins SET password_hash = ? WHERE id = ?')->execute([password_hash((string) $_POST['nueva'], PASSWORD_DEFAULT), $id]);
            auditar('plataforma_password', 'plataforma_admin', $id, null, null, $actor);
            flash('Contraseña actualizada.');
        }
        redirect('/platform/cuenta');
    }
}
$yo = totp_plataforma($id);
$activo = (int) ($yo['totp_activo'] ?? 0) === 1;
$sec = $_SESSION['plat_totp_nuevo'] ?? null;
$titulo = 'Mi cuenta';
$platNav = 'cuenta';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-shield-lock"></i> Mi cuenta de plataforma</h1>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if ($nuevos): ?><div class="alert alert-warning"><b>Códigos de respaldo (guárdalos ahora):</b><div class="font-monospace mt-2 d-flex flex-wrap gap-2"><?php foreach ($nuevos as $c): ?><span class="badge text-bg-light border fs-6"><?= e($c) ?></span><?php endforeach; ?></div></div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-header d-flex justify-content-between"><span>Verificación en dos pasos</span><?= $activo ? '<span class="badge text-bg-success">Activa</span>' : '<span class="badge text-bg-danger">Inactiva</span>' ?></div><div class="card-body">
<?php if ($activo): ?>
    <p class="small">Al entrar a la consola se pide el código de tu app autenticadora.</p>
    <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="accion" value="desactivar"><input name="codigo" class="form-control" style="max-width:180px" placeholder="Código actual" required><button class="btn btn-outline-danger">Desactivar</button></form>
<?php elseif ($sec): ?>
    <p class="small">Escanea el QR con Google Authenticator, Microsoft Authenticator o Authy y escribe el código.</p>
    <div class="d-flex flex-wrap gap-4 align-items-center mb-3">
        <div id="qr2" style="width:170px;height:170px;background:#fff;padding:8px;border-radius:12px" data-uri="<?= e(totp_uri($sec, 'plataforma:' . $pa['email'])) ?>"></div>
        <code class="fs-6"><?= e(trim(chunk_split($sec, 4, ' '))) ?></code>
    </div>
    <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="accion" value="confirmar"><input name="codigo" class="form-control" style="max-width:180px" required autofocus placeholder="123456"><button class="btn btn-primary">Activar</button></form>
    <script src="<?= asset('assets/js/vendor/qrcode.min.js') ?>"></script>
    <script>(function(){var el=document.getElementById('qr2');var q=qrcode(0,'M');q.addData(el.dataset.uri);q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});})();</script>
<?php else: ?>
    <p class="small">La consola da acceso a todos los consultorios: protégela con un segundo factor (requisito del SGSI).</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="iniciar"><button class="btn btn-primary">Activar verificación en dos pasos</button></form>
<?php endif; ?>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header">Cambiar contraseña</div><div class="card-body">
    <form method="post" class="d-flex flex-column gap-2" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="accion" value="password">
        <input type="password" name="actual" class="form-control" placeholder="Actual" required>
        <input type="password" name="nueva" class="form-control" placeholder="Nueva (10+, letras y números)" required minlength="10">
        <button class="btn btn-outline-primary">Actualizar</button></form>
</div></div></div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
