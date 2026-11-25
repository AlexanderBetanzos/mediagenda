<?php
/**
 * Catálogos oficiales (NOM-024): se cargan desde los archivos que publican
 * INEGI, DGIS y la Secretaría de Salud, con su versión y fecha. Cuando sale
 * una versión nueva se vuelve a subir el archivo (proceso documentado en
 * docs/sgsi/12-actualizacion-catalogos.md). El CIE-10 tiene su propio módulo.
 *
 * Tabla única cat_oficial (catalogo, clave, nombre, padre, datos).
 */
require_once __DIR__ . '/functions.php';

/** Definición: columnas del archivo oficial (sinónimos de encabezado) → clave, nombre, padre. */
function catalogos_def(): array
{
    return [
        'municipios' => ['Municipios (INEGI, Marco Geoestadístico)', 'https://www.inegi.org.mx/app/ageeml/',
            ['ent' => ['cve_ent', 'clave entidad', 'cve_entidad'], 'mun' => ['cve_mun', 'clave municipio', 'cve_municipio'], 'nom' => ['nom_mun', 'nombre municipio', 'nom_municipio', 'municipio']]],
        'localidades' => ['Localidades (INEGI, Marco Geoestadístico)', 'https://www.inegi.org.mx/app/ageeml/',
            ['ent' => ['cve_ent'], 'mun' => ['cve_mun'], 'loc' => ['cve_loc', 'clave localidad'], 'nom' => ['nom_loc', 'nombre localidad', 'localidad']]],
        'clues' => ['CLUES (DGIS)', 'http://www.dgis.salud.gob.mx/contenidos/intercambio/clues_gobmx.html',
            ['clave' => ['clues'], 'nom' => ['nombre de la unidad', 'nombre unidad', 'nombre de la unidad medica', 'unidad'], 'ent' => ['clave de la entidad', 'cve_ent', 'clave entidad']]],
        'medicamentos' => ['Medicamentos (Compendio Nacional de Insumos para la Salud)', 'https://www.csg.gob.mx/',
            ['clave' => ['clave', 'clave cnis', 'clave del insumo'], 'nom' => ['descripcion', 'descripción', 'nombre generico', 'nombre genérico', 'insumo'], 'extra' => ['presentacion', 'presentación', 'grupo terapeutico', 'grupo terapéutico']]],
        'procedimientos' => ['Procedimientos (CIE-9-MC, DGIS)', 'http://www.dgis.salud.gob.mx/contenidos/intercambio/procedimientos_gobmx.html',
            ['clave' => ['catalog_key', 'clave', 'codigo', 'código'], 'nom' => ['pro_nombre', 'nombre', 'descripcion', 'descripción']]],
    ];
}

function asegurar_catalogos(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS cat_oficial (
        catalogo VARCHAR(20) NOT NULL,
        clave VARCHAR(40) NOT NULL,
        nombre VARCHAR(300) NOT NULL,
        padre VARCHAR(40) NULL,
        datos VARCHAR(500) NULL,
        PRIMARY KEY (catalogo, clave),
        KEY idx_padre (catalogo, padre),
        KEY idx_nombre (catalogo, nombre(60))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS cat_version (
        catalogo VARCHAR(20) PRIMARY KEY,
        version VARCHAR(60) NULL,
        fuente VARCHAR(300) NULL,
        filas INT NOT NULL DEFAULT 0,
        actualizado_en DATETIME NULL,
        actualizado_por VARCHAR(120) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function cat_norm(string $s): string
{
    $s = strtr(mb_strtolower(trim($s)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9_]+/', ' ', $s));
}

/**
 * Importa un CSV oficial (reemplaza el catálogo completo).
 * @return int filas cargadas
 */
function catalogo_importar(string $cat, string $ruta, string $version, string $quien): int
{
    asegurar_catalogos();
    $def = catalogos_def()[$cat] ?? null;
    if (!$def) throw new RuntimeException('Catálogo desconocido.');
    $raw = (string) file_get_contents($ruta);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $primera = strtok($raw, "\n") ?: '';
    $sep = ','; $max = 0;
    foreach ([',', ';', "\t", '|'] as $c) { $n = substr_count($primera, $c); if ($n > $max) { $max = $n; $sep = $c; } }
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh); unset($raw);
    $enc = array_map('cat_norm', fgetcsv($fh, 0, $sep, '"', '\\') ?: []);
    $idx = [];
    foreach ($def[2] as $campo => $sin) {
        foreach ($enc as $i => $h) if (in_array($h, array_map('cat_norm', $sin), true)) { $idx[$campo] = $i; break; }
    }
    $necesita = $cat === 'municipios' ? ['ent', 'mun', 'nom'] : ($cat === 'localidades' ? ['ent', 'mun', 'loc', 'nom'] : ['clave', 'nom']);
    $faltan = array_diff($necesita, array_keys($idx));
    if ($faltan) throw new RuntimeException('No encontré estas columnas en el archivo: ' . implode(', ', $faltan) . '. Encabezados leídos: ' . implode(', ', array_slice($enc, 0, 12)));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM cat_oficial WHERE catalogo = ?')->execute([$cat]);
        $ins = $pdo->prepare('INSERT INTO cat_oficial (catalogo, clave, nombre, padre, datos) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)');
        $n = 0;
        $g = fn (array $r, string $k) => isset($idx[$k]) ? trim((string) ($r[$idx[$k]] ?? '')) : '';
        while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            if (!array_filter($r, fn ($x) => trim((string) $x) !== '')) continue;
            $nom = $g($r, 'nom');
            if ($cat === 'municipios') {
                $ent = str_pad($g($r, 'ent'), 2, '0', STR_PAD_LEFT); $mun = str_pad($g($r, 'mun'), 3, '0', STR_PAD_LEFT);
                [$clave, $padre] = [$ent . $mun, $ent];
            } elseif ($cat === 'localidades') {
                $ent = str_pad($g($r, 'ent'), 2, '0', STR_PAD_LEFT); $mun = str_pad($g($r, 'mun'), 3, '0', STR_PAD_LEFT); $loc = str_pad($g($r, 'loc'), 4, '0', STR_PAD_LEFT);
                [$clave, $padre] = [$ent . $mun . $loc, $ent . $mun];
            } else {
                [$clave, $padre] = [strtoupper($g($r, 'clave')), $g($r, 'ent') ?: null];
            }
            if ($clave === '' || $nom === '') continue;
            $ins->execute([$cat, mb_substr($clave, 0, 40), mb_substr($nom, 0, 300), $padre, mb_substr($g($r, 'extra'), 0, 500) ?: null]);
            $n++;
        }
        if ($n === 0) throw new RuntimeException('El archivo no trae filas válidas.');
        $pdo->prepare('REPLACE INTO cat_version (catalogo, version, fuente, filas, actualizado_en, actualizado_por) VALUES (?,?,?,?,NOW(),?)')
            ->execute([$cat, mb_substr($version, 0, 60), $def[1], $n, $quien]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        fclose($fh);
    }
    return $n;
}

function catalogo_version(string $cat): ?array
{
    try {
        asegurar_catalogos();
        $st = db()->prepare('SELECT * FROM cat_version WHERE catalogo = ?');
        $st->execute([$cat]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}

function catalogo_nombre(string $cat, ?string $clave): string
{
    if ($clave === null || $clave === '') return '';
    try {
        $st = db()->prepare('SELECT nombre FROM cat_oficial WHERE catalogo = ? AND clave = ?');
        $st->execute([$cat, $clave]);
        return (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}
