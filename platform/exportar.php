<?php
/**
 * Consola de PLATAFORMA — exportar el respaldo SQL de UN consultorio.
 * Genera un .sql con la fila de `consultorios` y todas las filas de las
 * tablas multitenant (columna consultorio_id) de ese consultorio. Sirve
 * para respaldo o para migrarlo a otra instalación (los CREATE TABLE ya
 * los dan los scripts de sql/, aquí solo van los datos).
 */
require_once __DIR__ . '/../includes/functions.php';
require_platform();

$pdo = db();
$cid = (int) ($_GET['id'] ?? 0);
require_platform_consultorio($cid);   // socios: solo sus consultorios asignados

$st = $pdo->prepare('SELECT * FROM consultorios WHERE id = ?');
$st->execute([$cid]);
$consultorio = $st->fetch();
if (!$consultorio) {
    flash('Consultorio no encontrado.', 'warning');
    redirect('/platform/index');
}

/* Valor SQL seguro: NULL literal o cadena escapada por PDO. */
$sqlVal = function ($v) use ($pdo): string {
    if ($v === null) return 'NULL';
    return $pdo->quote((string) $v);
};

/* INSERT de una fila asociativa. */
$sqlInsert = function (string $tabla, array $fila) use ($sqlVal): string {
    $cols = '`' . implode('`, `', array_keys($fila)) . '`';
    $vals = implode(', ', array_map($sqlVal, array_values($fila)));
    return "INSERT INTO `$tabla` ($cols) VALUES ($vals);\n";
};

auditar('exportar_sql', 'consultorio', $cid, $consultorio['nombre'], $cid);

$slug = preg_replace('/[^a-z0-9_-]/i', '_', $consultorio['slug'] ?: ('consultorio_' . $cid));
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="respaldo_' . $slug . '_' . date('Y-m-d') . '.sql"');

$out = fopen('php://output', 'w');
fwrite($out, "-- Respaldo de datos · consultorio #{$cid} «{$consultorio['nombre']}» (/{$consultorio['slug']})\n");
fwrite($out, '-- Generado el ' . date('Y-m-d H:i:s') . " desde la consola de plataforma\n");
fwrite($out, "-- Solo datos: ejecutar sobre un esquema creado con los scripts de sql/.\n\n");
fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

/* La ficha del consultorio primero (las demás tablas apuntan a su id). */
fwrite($out, "-- ---- consultorios ----\n");
fwrite($out, $sqlInsert('consultorios', $consultorio) . "\n");

/* Todas las tablas multitenant, en orden alfabético. */
$tablas = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'consultorio_id'
     ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($tablas as $tabla) {
    $rows = $pdo->prepare("SELECT * FROM `$tabla` WHERE consultorio_id = ?");
    $rows->execute([$cid]);
    $n = 0;
    foreach ($rows as $fila) {
        if ($n === 0) fwrite($out, "-- ---- $tabla ----\n");
        fwrite($out, $sqlInsert($tabla, $fila));
        $n++;
    }
    if ($n > 0) fwrite($out, "-- $tabla: $n filas\n\n");
}

fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
exit;
