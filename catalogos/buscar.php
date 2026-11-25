<?php
/**
 * Búsqueda en catálogos oficiales (JSON).
 *   ?c=municipios&padre=20          → municipios de Oaxaca
 *   ?c=localidades&padre=20067      → localidades de un municipio (opcional q=)
 *   ?c=medicamentos&q=paracetamol   → búsqueda por texto o clave
 */
require_once __DIR__ . '/../includes/catalogos.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
asegurar_catalogos();
$c = (string) ($_GET['c'] ?? '');
if (!isset(catalogos_def()[$c])) { echo '{"r":[]}'; exit; }
$padre = preg_replace('/[^0-9A-Za-z]/', '', (string) ($_GET['padre'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT clave, nombre FROM cat_oficial WHERE catalogo = ?';
$p = [$c];
if ($padre !== '') { $sql .= ' AND padre = ?'; $p[] = $padre; }
if ($q !== '') { $sql .= ' AND (nombre LIKE ? OR clave LIKE ?)'; $p[] = '%' . $q . '%'; $p[] = $q . '%'; }
$sql .= ' ORDER BY nombre LIMIT ' . ($padre !== '' && $q === '' ? 3000 : 30);
$st = db()->prepare($sql);
$st->execute($p);
echo json_encode(['r' => $st->fetchAll(PDO::FETCH_NUM), 'version' => catalogo_version($c)['version'] ?? null], JSON_UNESCAPED_UNICODE);
