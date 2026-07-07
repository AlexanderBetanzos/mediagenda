<?php
/** Cierra la sesión de plataforma (no toca la sesión del consultorio). */
require_once __DIR__ . '/../includes/functions.php';
unset($_SESSION['plataforma_admin']);
// Si había una impersonación abierta desde la plataforma, la cerramos también.
unset($_SESSION['impersonando'], $_SESSION['impersonando_desde_plataforma']);
redirect('/platform/login');
