<?php
/**
 * Impersonación desde la plataforma: el dueño entra a un consultorio para dar
 * soporte, y luego vuelve a la consola. Cambia el consultorio activo en sesión
 * guardando el original. Solo súper-administradores.
 */
require_once __DIR__ . '/../includes/functions.php';
require_superadmin();

/* Salir de la impersonación (volver a la plataforma). */
if (isset($_GET['salir'])) {
    if (isset($_SESSION['plataforma_origen'])) {
        $_SESSION['usuario']['consultorio_id'] = (int) $_SESSION['plataforma_origen'];
        unset($_SESSION['plataforma_origen'], $_SESSION['impersonando']);
        tenant(true); // limpia caché del tenant
    }
    redirect('/platform/index');
}

/* Entrar como consultorio (POST). */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/platform/index');
verify_csrf();

$cid = (int) ($_POST['id'] ?? 0);
$st  = db()->prepare("SELECT nombre FROM consultorios WHERE id = ?");
$st->execute([$cid]);
$nombre = $st->fetchColumn();
if ($nombre === false) {
    flash('Consultorio no encontrado.', 'warning');
    redirect('/platform/index');
}

/* Guarda el consultorio original (solo la primera vez) y cambia al destino. */
if (!isset($_SESSION['plataforma_origen'])) {
    $_SESSION['plataforma_origen'] = (int) ($_SESSION['usuario']['consultorio_id'] ?? 1);
}
$_SESSION['usuario']['consultorio_id'] = $cid;
$_SESSION['impersonando'] = $cid;
tenant(true); // limpia caché del tenant
auditar('impersonar', 'consultorio', $cid, $nombre, $cid);
flash('Estás viendo como «' . $nombre . '». Modo plataforma.');
redirect('/dashboard');
