<?php
/**
 * Reagenda una cita (drag & drop del calendario). Recibe id + nueva fecha/hora,
 * valida CSRF y pertenencia al consultorio, actualiza y registra en auditoría.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo_json('citas');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'Método no permitido']); exit;
}

// CSRF manual (para responder JSON en vez de morir con texto).
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Token inválido']); exit;
}

$id    = (int) ($_POST['id'] ?? 0);
$fecha = $_POST['fecha'] ?? '';
$hora  = $_POST['hora'] ?? '';

// Validación de formato.
$df = DateTime::createFromFormat('Y-m-d', $fecha);
$th = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora);
if (!$id || !$df || $df->format('Y-m-d') !== $fecha || !$th) {
    http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Datos inválidos']); exit;
}
if (strlen($hora) === 5) { $hora .= ':00'; }

// Solo citas del consultorio activo.
$st = db()->prepare('SELECT id FROM citas WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
if (!$st->fetchColumn()) {
    http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Cita no encontrada']); exit;
}

db()->prepare('UPDATE citas SET fecha = ?, hora = ? WHERE id = ? AND consultorio_id = ?')
    ->execute([$fecha, $hora, $id, tenant_id()]);
auditar('reagendar', 'cita', $id, $fecha . ' ' . substr($hora, 0, 5));

echo json_encode(['ok' => true]);
