<?php
/**
 * Sirve la foto de un paciente desde la base de datos (tabla paciente_fotos).
 *
 * Vive en la base y no en disco porque uploads/ no estaba en .gitignore y el
 * despliegue borraba las fotos en cada subida de cambios. Verifica sesión y
 * consultorio antes de entregarla: es un dato identificable del paciente.
 *
 * Migración sola: si el paciente todavía tiene su foto en disco (columna vieja
 * `pacientes.foto`) y aún no está en la base, se importa aquí la primera vez que
 * alguien la abre, y a partir de entonces ya vive en la base.
 *
 *   GET ?id=N   -> la foto del paciente N (inline, para usar en <img src>)
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('pacientes');

$id = (int) ($_GET['id'] ?? 0);

// Solo pacientes del consultorio activo (aislamiento multi-tenant).
$st = db()->prepare('SELECT foto, foto_mime FROM pacientes WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$p = $st->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

/* 1) Lo normal: la foto ya está en la base.
      Primero solo el mime y la fecha, sin los bytes: con eso se arma el ETag y,
      si el navegador ya la tiene, se responde 304 sin leer el blob. Así la foto
      se cachea pero se actualiza al instante cuando el paciente se la cambia. */
$st = db()->prepare(
    'SELECT mime, UNIX_TIMESTAMP(actualizado_en) AS ts
     FROM paciente_fotos WHERE paciente_id = ? AND consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
if ($meta = $st->fetch()) {
    $etag = '"' . $id . '-' . (int) $meta['ts'] . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=0, must-revalidate');
        http_response_code(304);
        exit;
    }
    header('ETag: ' . $etag);
}

$foto = null;
if ($meta) {
    $st = db()->prepare('SELECT mime, bytes FROM paciente_fotos WHERE paciente_id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $foto = $st->fetch();
}

// 2) Herencia: sigue en disco. Se importa a la base y se sirve.
if (!$foto && !empty($p['foto'])) {
    $ruta = __DIR__ . '/../uploads/pacientes/' . tenant_id() . '/' . basename($p['foto']);
    if (is_file($ruta)) {
        $bytes = (string) file_get_contents($ruta);
        $mime  = (new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'image/jpeg';
        [$bytes, $mime] = foto_redimensionar($bytes, $mime);
        if (paciente_foto_escribir($id, $bytes, $mime)) {
            auditar('foto_migrada_a_bd', 'paciente', $id);
            $foto = ['mime' => $mime, 'bytes' => $bytes];
        }
    } else {
        // El archivo ya no existe (se lo llevó algún despliegue): se limpia la
        // ruta muerta para no volver a intentarlo en cada carga.
        db()->prepare('UPDATE pacientes SET foto = NULL WHERE id = ? AND consultorio_id = ?')
            ->execute([$id, tenant_id()]);
    }
}

if (!$foto) { http_response_code(404); die('Sin foto.'); }

$mime = (string) $foto['mime'];
if (strpos($mime, 'image/') !== 0) { http_response_code(415); die('No es una imagen.'); }

$bytes = $foto['bytes'];
if (is_resource($bytes)) { $bytes = stream_get_contents($bytes); }   // PDO puede devolver un stream

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen((string) $bytes));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
// Privada (es un dato del paciente, no debe quedar en cachés compartidas) y
// revalidada: el ETag de arriba resuelve el 304 sin volver a leer el blob.
header('Cache-Control: private, max-age=0, must-revalidate');
echo $bytes;
exit;
