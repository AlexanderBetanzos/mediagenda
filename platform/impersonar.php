<?php
/**
 * Impersonación desde la plataforma: el dueño entra a un consultorio para dar
 * soporte, viéndolo tal como lo ve el cliente (según su plan). La sesión de
 * plataforma se conserva; se abre una sesión temporal de consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_platform();

/* Salir de la impersonación (volver a la plataforma). */
if (isset($_GET['salir'])) {
    unset($_SESSION['usuario'], $_SESSION['impersonando'], $_SESSION['impersonando_desde_plataforma']);
    tenant(true);
    redirect('/platform/index');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/platform/index');
verify_csrf();

$cid = (int) ($_POST['id'] ?? 0);
require_platform_consultorio($cid);   // socios: solo pueden entrar a sus asignados
$st  = db()->prepare("SELECT nombre FROM consultorios WHERE id = ?");
$st->execute([$cid]);
$nombre = $st->fetchColumn();
if ($nombre === false) {
    flash('Consultorio no encontrado.', 'warning');
    redirect('/platform/index');
}

/* Toma un usuario admin del consultorio (o uno sintético) para la sesión. */
$au = db()->prepare("SELECT id, nombre, email FROM usuarios WHERE consultorio_id = ? AND activo = 1 ORDER BY (rol='admin') DESC, id LIMIT 1");
$au->execute([$cid]);
$au = $au->fetch();

$_SESSION['usuario'] = [
    'id'             => $au ? (int) $au['id'] : 0,
    'nombre'         => $au ? $au['nombre'] : 'Soporte plataforma',
    'email'          => $au['email'] ?? '',
    'rol'            => 'admin',   // vista completa del consultorio
    'consultorio_id' => $cid,
    'es_superadmin'  => 0,          // ve solo lo que el plan del cliente permite
];
$_SESSION['impersonando'] = $cid;
$_SESSION['impersonando_desde_plataforma'] = 1;
tenant(true);
auditar('impersonar', 'consultorio', $cid, $nombre, $cid);
flash('Estás viendo como «' . $nombre . '». Modo plataforma.');
redirect('/dashboard');
