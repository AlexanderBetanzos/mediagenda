<?php
/** Buscador CIE-10 para el autocompletado de diagnósticos (JSON). */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cie10.php';
require_login();
asegurar_cie10();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');
$res = cie10_buscar((string) ($_GET['q'] ?? ''));
echo json_encode(['r' => $res, 'credito' => CIE10_CREDITO,
                  'vacio' => (int) db()->query('SELECT COUNT(*) FROM cie10')->fetchColumn() === 0], JSON_UNESCAPED_UNICODE);
