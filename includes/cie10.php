<?php
/**
 * Catálogo CIE-10 (diagnósticos). Es el mismo para todos los consultorios, así
 * que vive en una tabla global. Fuente: "Catálogo CIE-10" de la Secretaría de
 * Salud publicado por el Hospital Juárez de México en datos.gob.mx (licencia
 * CC BY 4.0, que pide dar el crédito: se muestra en el buscador).
 *
 * El archivo va en sql/data/cie10_mx.csv; la primera búsqueda lo importa solo.
 * El importador detecta las columnas de clave y nombre por su encabezado.
 */
const CIE10_CREDITO = 'Catálogo CIE-10 · Secretaría de Salud / Hospital Juárez de México, datos.gob.mx (CC BY 4.0)';

function cie10_archivo(): string { return __DIR__ . '/../sql/data/cie10_mx.csv'; }

function asegurar_cie10(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS cie10 (
        codigo VARCHAR(10) NOT NULL PRIMARY KEY,
        nombre VARCHAR(400) NOT NULL,
        INDEX idx_cie10_nombre (nombre(120))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ((int) db()->query('SELECT COUNT(*) FROM cie10')->fetchColumn() === 0 && is_file(cie10_archivo())) {
        cie10_importar(cie10_archivo());
    }
    if (!db()->query("SHOW COLUMNS FROM consultas LIKE 'cie10'")->fetch()) {
        db()->exec('ALTER TABLE consultas ADD cie10 VARCHAR(120) NULL AFTER diagnostico');
    }
}

/** Normaliza "J029" / "j02.9" → "J02.9". */
function cie10_norm(string $c): string
{
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    return strlen($c) > 3 ? substr($c, 0, 3) . '.' . substr($c, 3) : $c;
}

/** Importa el CSV (separado por comas o punto y coma, UTF-8 o Latin-1). Devuelve cuántos quedaron. */
function cie10_importar(string $ruta): int
{
    $fh = fopen($ruta, 'r');
    if (!$fh) return 0;
    $primera = (string) fgets($fh);
    $sep = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    rewind($fh);
    $enc = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h), " \"\t")), fgetcsv($fh, 0, $sep) ?: []);
    $iCod = $iNom = null;
    foreach ($enc as $i => $h) {
        if ($iCod === null && preg_match('/^(catalog_key|clave|codigo|código|cve|code|cie10|cie_10)/u', $h)) $iCod = $i;
        if ($iNom === null && preg_match('/^(nombre|descripcion|descripción|description|diagnostico|diagnóstico)/u', $h)) $iNom = $i;
    }
    if ($iCod === null || $iNom === null) { fclose($fh); return 0; }
    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO cie10 (codigo, nombre) VALUES (?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)');
    $pdo->beginTransaction();
    $n = 0;
    while (($r = fgetcsv($fh, 0, $sep)) !== false) {
        $cod = cie10_norm((string) ($r[$iCod] ?? ''));
        $nom = trim((string) ($r[$iNom] ?? ''));
        if (!preg_match('/^[A-Z]\d{2}(\.[0-9A-Z]{1,2})?$/', $cod) || $nom === '') continue;
        if (!mb_check_encoding($nom, 'UTF-8')) $nom = mb_convert_encoding($nom, 'UTF-8', 'ISO-8859-1');
        $ins->execute([$cod, mb_substr($nom, 0, 400)]);
        $n++;
    }
    $pdo->commit();
    fclose($fh);
    return $n;
}

/** Busca por código ("J02", "j02.9") o por palabras del nombre ("faringitis aguda"). */
function cie10_buscar(string $q, int $lim = 15): array
{
    asegurar_cie10();
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    if (preg_match('/^[A-Za-z]\d{1,2}(\.?\w{0,2})?$/', $q)) {
        $st = db()->prepare('SELECT codigo, nombre FROM cie10 WHERE codigo LIKE ? ORDER BY LENGTH(codigo), codigo LIMIT ' . $lim);
        $st->execute([cie10_norm($q) . '%']);
        return $st->fetchAll();
    }
    $where = []; $params = [];
    foreach (array_slice(preg_split('/\s+/', $q), 0, 5) as $w) {
        if (mb_strlen($w) < 2) continue;
        $where[] = 'nombre LIKE ?'; $params[] = '%' . $w . '%';
    }
    if (!$where) return [];
    // Primero los que empiezan con la búsqueda y los códigos más generales.
    $st = db()->prepare('SELECT codigo, nombre FROM cie10 WHERE ' . implode(' AND ', $where)
        . ' ORDER BY (nombre LIKE ?) DESC, LENGTH(codigo), codigo LIMIT ' . $lim);
    $st->execute(array_merge($params, [$q . '%']));
    return $st->fetchAll();
}

/** Códigos CIE-10 que aparecen en un texto de diagnóstico ("J02.9 Faringitis…"), validados contra el catálogo. */
function cie10_extraer(?string $txt): ?string
{
    if (!$txt || !preg_match_all('/\b([A-Z]\d{2}(?:\.[0-9A-Z]{1,2})?)\b/', $txt, $m)) return null;
    asegurar_cie10();
    $cods = array_values(array_unique($m[1]));
    $in = implode(',', array_fill(0, count($cods), '?'));
    $st = db()->prepare("SELECT codigo FROM cie10 WHERE codigo IN ($in)");
    $st->execute($cods);
    $ok = $st->fetchAll(PDO::FETCH_COLUMN);
    return $ok ? mb_substr(implode(',', $ok), 0, 120) : null;
}
