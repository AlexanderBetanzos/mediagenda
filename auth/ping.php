<?php
/**
 * Latido de sesión. Sin ?sinmov=1 renueva la actividad ("Seguir conectado");
 * con ?sinmov=1 solo informa cuántos segundos quedan (revisión de fondo).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$min = sesion_minutos();
$restan = $min > 0 ? max(0, $min * 60 - (time() - (int) ($_SESSION['ultimo_mov'] ?? time()))) : null;
echo json_encode(['ok' => true, 'restan' => $restan]);
