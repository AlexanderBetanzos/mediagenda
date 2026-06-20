<?php
/**
 * Descarga / vista segura de un archivo del expediente.
 * Los archivos viven en /uploads (bloqueado por .htaccess); este script es
 * la ÚNICA vía de acceso y verifica sesión + consultorio antes de servirlos.
 *
 *   GET ?id=N        -> fuerza descarga (attachment)
 *   GET ?id=N&ver=1  -> muestra en línea (inline) para previsualizar
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$id  = (int) ($_GET['id'] ?? 0);
$ver = isset($_GET['ver']);

// Solo archivos del consultorio activo (aislamiento multi-tenant).
$stmt = db()->prepare('SELECT * FROM archivos WHERE id = ? AND consultorio_id = ?');
$stmt->execute([$id, tenant_id()]);
$a = $stmt->fetch();
if (!$a) { http_response_code(404); die('Archivo no encontrado.'); }

// El nombre guardado es aleatorio y generado por el servidor; aun así usamos
// basename como cinturón de seguridad contra cualquier path traversal.
$ruta = __DIR__ . '/../uploads/expedientes/' . tenant_id() . '/' . (int) $a['paciente_id']
      . '/' . basename($a['nombre_guardado']);

if (!is_file($ruta)) { http_response_code(404); die('El archivo ya no está disponible.'); }

$mime = $a['mime'] ?: 'application/octet-stream';
// Solo permitimos vista en línea para imágenes y PDF; el resto se descarga.
$inline = $ver && (strpos($mime, 'image/') === 0 || $mime === 'application/pdf');
$disp   = $inline ? 'inline' : 'attachment';

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $a['nombre_original']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($ruta);
exit;
