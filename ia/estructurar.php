<?php
/**
 * Convierte el dictado o las notas sueltas del médico en los campos de la
 * consulta. Lo llama por fetch el formulario de nueva consulta del expediente.
 *
 * Responde SIEMPRE JSON: el formulario tiene que seguir usable si esto falla,
 * porque capturar a mano es el camino normal y la IA solo es un atajo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ia.php';
require_login();
require_modulo_json('ia');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

// CSRF manual (para responder JSON en vez de morir con texto).
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token inválido']);
    exit;
}

// Solo quien firma una consulta puede pedirle a la IA que la redacte.
if (!has_role('medico', 'admin')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => t('No tienes permiso para esto.')]);
    exit;
}

$texto = (string) ($_POST['texto'] ?? '');
if (mb_strlen($texto) > 20000) { $texto = mb_substr($texto, 0, 20000); }

/* Contexto del paciente: ayuda a redactar (edad, sexo, alergias) y se manda
   solo si el paciente es de ESTE consultorio. */
$paciente = [];
$pid = (int) ($_POST['paciente_id'] ?? 0);
if ($pid && pertenece_al_tenant('pacientes', $pid)) {
    $st = db()->prepare(
        'SELECT nombre, apellidos, fecha_nacimiento, sexo, alergias
         FROM pacientes WHERE id = ? AND consultorio_id = ?'
    );
    $st->execute([$pid, tenant_id()]);
    if ($p = $st->fetch()) {
        $paciente = [
            'nombre'    => $p['nombre'],
            'apellidos' => $p['apellidos'],
            'edad'      => $p['fecha_nacimiento'] ? edad($p['fecha_nacimiento']) : '',
            'sexo'      => $p['sexo'] ?: '',
            'alergias'  => $p['alergias'] ?: '',
        ];
    }
}

$r = ia_estructurar_consulta($texto, $paciente);

if ($r['ok']) {
    auditar('ia_consulta', 'paciente', $pid ?: null,
            sprintf('in=%d out=%d', $r['uso']['entrada'] ?? 0, $r['uso']['salida'] ?? 0));
}

echo json_encode([
    'ok'        => $r['ok'],
    'campos'    => $r['campos'],
    'error'     => $r['mensaje'],
    'restantes' => $r['uso']['restantes'] ?? ia_restantes(),
], JSON_UNESCAPED_UNICODE);
