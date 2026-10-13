<?php
/**
 * Descargar mis datos (Excel) — réplica del db_export de Khronos, adaptada a
 * MediOS. Un ZIP con un CSV por tabla, SOLO con las filas de este consultorio
 * (consultorio_id). Es la promesa de la landing hecha botón: "tus datos
 * siempre son tuyos".
 *
 * Aquí viajan expedientes clínicos, así que:
 *  - solo el administrador del consultorio la usa, y queda en la auditoría;
 *  - nunca salen credenciales ni secretos: se excluyen tablas enteras
 *    (usuarios, configuración, bitácoras de pago) y, en las demás, cualquier
 *    columna que parezca contraseña, token o llave;
 *  - los archivos binarios (fotos guardadas en la base) tampoco: en un CSV
 *    serían basura y pueden pesar cientos de MB.
 *
 * El respaldo .sql completo sigue siendo de la consola de plataforma.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_role('admin');

if (!class_exists('ZipArchive')) {
    flash('El servidor no puede generar archivos ZIP en este momento. Escríbenos y te mandamos tu respaldo.', 'warning');
    redirect('/dashboard');
}

$pdo = db();
$tid = (int) tenant_id();

$excluirTablas = ['usuarios', 'configuracion', 'pagos_log', 'bloqueos', 'consultorios',
                  'plataforma_admin_consultorios', 'ia_uso'];
$columnaSecreta = '/(pass|token|secret|hash|api_?key|clave_privada)/i';
$tiposBinarios  = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'];

$st = $pdo->query(
    "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'consultorio_id' ORDER BY TABLE_NAME"
);
$tablas = array_values(array_diff($st->fetchAll(PDO::FETCH_COLUMN), $excluirTablas));

$colsDe = $pdo->prepare(
    "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION"
);

$tmp = tempnam(sys_get_temp_dir(), 'mdx');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    flash('No se pudo preparar la descarga. Inténtalo de nuevo.', 'warning');
    redirect('/dashboard');
}

$resumen = [];
foreach ($tablas as $tabla) {
    $colsDe->execute([$tabla]);
    $cols = [];
    foreach ($colsDe->fetchAll() as $c) {
        if (preg_match($columnaSecreta, $c['COLUMN_NAME'])) continue;
        if (in_array(strtolower($c['DATA_TYPE']), $tiposBinarios, true)) continue;
        $cols[] = $c['COLUMN_NAME'];
    }
    if (!$cols) continue;

    $q   = '`' . str_replace('`', '``', $tabla) . '`';
    $sel = $pdo->prepare('SELECT `' . implode('`, `', array_map(fn ($c) => str_replace('`', '``', $c), $cols)) . "` FROM $q WHERE consultorio_id = ?");
    $sel->execute([$tid]);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");           // BOM: acentos correctos en Excel
    fputcsv($fh, $cols);
    $n = 0;
    while ($row = $sel->fetch(PDO::FETCH_NUM)) {
        fputcsv($fh, array_map(fn ($v) => $v === null ? '' : $v, $row));
        $n++;
    }
    if ($n === 0) { fclose($fh); continue; }   // tablas vacías no estorban en el ZIP
    rewind($fh);
    $zip->addFromString($tabla . '.csv', stream_get_contents($fh));
    fclose($fh);
    $resumen[] = [$tabla, $n];
}

/* Una hoja de LEEME: qué es cada archivo y cuándo se generó. */
$u = current_user();
$leeme = "\xEF\xBB\xBF" . "Respaldo de datos · " . (tenant()['nombre'] ?? marca_nombre()) . "\r\n"
       . "Generado el " . date('Y-m-d H:i') . " por " . $u['nombre'] . "\r\n\r\n"
       . "Cada archivo .csv es una tabla y se abre con Excel. Solo contiene datos de tu consultorio.\r\n"
       . "Por seguridad no se incluyen contraseñas, tokens ni archivos (fotos/estudios).\r\n\r\n"
       . "Archivo,Registros\r\n";
foreach ($resumen as [$t, $n]) $leeme .= "$t.csv,$n\r\n";
$zip->addFromString('LEEME.csv', $leeme);
$zip->close();

auditar('exportar_datos', 'consultorio', $tid, count($resumen) . ' tablas');

$slug = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(tenant()['slug'] ?? 'consultorio')) ?: 'consultorio';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="datos_' . $slug . '_' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
exit;
