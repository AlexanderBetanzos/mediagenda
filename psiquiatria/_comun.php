<?php
/**
 * Arranque común de las pantallas de psiquiatría: sesión, plan, rol, tablas
 * y el paciente del consultorio activo ($pid, $pac, $pacNombre, $P).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die(t('Esta sección es solo para el personal clínico y el administrador.')); }
ensure_psiq_tables();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));
$P = '?paciente_id=' . $pid;
