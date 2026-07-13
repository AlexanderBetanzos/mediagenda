<?php
/**
 * Sirve la foto de un paciente.
 *
 * Las fotos viven en /uploads (bloqueado por .htaccess, porque ahí hay datos
 * clínicos): enlazarlas directo daba 403 y la imagen salía rota. Este script es
 * la ÚNICA vía de acceso y verifica sesión + consultorio antes de servirla,
 * igual que pacientes/archivo.php hace con los archivos del expediente.
 *
 *   GET ?id=N   -> la foto del paciente N (inline, para usar en <img src>)
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('pacientes');

$id = (int) ($_GET['id'] ?? 0);

// Solo pacientes del consultorio activo (aislamiento multi-tenant).
$st = db()->prepare('SELECT foto FROM pacientes WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$foto = $st->fetchColumn();
if (!$foto) { http_response_code(404); die('Sin foto.'); }

// El nombre guardado es aleatorio y lo generó el servidor; aun así se reconstruye
// la ruta desde cero (tenant + basename) como cinturón contra path traversal.
$ruta = __DIR__ . '/../uploads/pacientes/' . tenant_id() . '/' . basename($foto);
if (!is_file($ruta)) { http_response_code(404); die('La foto ya no está disponible.'); }

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'application/octet-stream';
if (strpos($mime, 'image/') !== 0) { http_response_code(415); die('No es una imagen.'); }

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
// Privada: es un dato del paciente, no debe quedar en cachés compartidas.
header('Cache-Control: private, max-age=600');
readfile($ruta);
exit;
