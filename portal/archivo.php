<?php
/**
 * Descarga/vista segura de un archivo para el paciente (portal).
 * Valida sesión de paciente y que el archivo sea suyo antes de servirlo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_paciente();

$pid = current_paciente()['id'];
$id  = (int) ($_GET['id'] ?? 0);
$ver = isset($_GET['ver']);

$st = db()->prepare('SELECT * FROM archivos WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
$st->execute([$id, $pid, tenant_id()]);
$a = $st->fetch();
if (!$a) { http_response_code(404); die('Archivo no encontrado.'); }

$ruta = __DIR__ . '/../uploads/expedientes/' . tenant_id() . '/' . (int) $a['paciente_id']
      . '/' . basename($a['nombre_guardado']);
if (!is_file($ruta)) { http_response_code(404); die('El archivo ya no está disponible.'); }

$mime   = $a['mime'] ?: 'application/octet-stream';
$inline = $ver && (strpos($mime, 'image/') === 0 || $mime === 'application/pdf');

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $a['nombre_original']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($ruta);
exit;
