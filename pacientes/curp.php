<?php
/**
 * ¿Ya existe un paciente con esta CURP en el consultorio? (JSON para el
 * formulario de paciente). ?curp=...&id=<paciente que se edita, para excluirlo>
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$curp = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['curp'] ?? '')));
$id   = (int) ($_GET['id'] ?? 0);
if (strlen($curp) !== 18) { echo json_encode(['existe' => false]); exit; }

$st = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? AND UPPER(curp) = ? AND id <> ? LIMIT 1');
$st->execute([tenant_id(), $curp, $id]);
$p = $st->fetch();
echo json_encode($p ? ['existe' => true, 'nombre' => trim($p['nombre'] . ' ' . $p['apellidos']),
                       'url' => BASE_URL . '/pacientes/ver?id=' . (int) $p['id']] : ['existe' => false], JSON_UNESCAPED_UNICODE);
