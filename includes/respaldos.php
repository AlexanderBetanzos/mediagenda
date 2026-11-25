<?php
/**
 * Respaldos de la base de datos (SGSI / NOM-024).
 *
 *  - respaldo_crear(): vuelca TODAS las tablas (estructura + datos) a SQL,
 *    lo comprime (gzip) y lo cifra con AES-256-GCM. Guarda un manifiesto con
 *    el número de filas por tabla y el SHA-256 del SQL.
 *  - respaldo_verificar(): prueba de restauración. Descifra, descomprime,
 *    comprueba el SHA-256 y cuenta las filas de cada tabla contra el
 *    manifiesto. El resultado queda registrado con fecha (evidencia).
 *  - Retención: se conservan los últimos N (cfg plataforma 'respaldo_retencion', 14).
 *
 * Carpeta: fuera de la web si se puede (../respaldos_mediagenda); si no,
 * respaldos/ dentro del proyecto, bloqueada por .htaccess y fuera de git.
 * Los archivos están cifrados: sin la APP_KEY no sirven de nada.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cfdi.php';   // cfdi_llaves()

function respaldo_dir(): string
{
    foreach ([dirname(__DIR__, 2) . '/respaldos_mediagenda', dirname(__DIR__) . '/respaldos'] as $d) {
        if (is_dir($d) || @mkdir($d, 0700, true)) {
            if (is_writable($d)) {
                if (str_starts_with($d, dirname(__DIR__)) && !is_file($d . '/.htaccess')) {
                    @file_put_contents($d . '/.htaccess', "Require all denied\n");
                }
                return $d;
            }
        }
    }
    throw new RuntimeException('No hay una carpeta con permiso de escritura para los respaldos.');
}

function asegurar_respaldos(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS respaldos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        archivo VARCHAR(120) NOT NULL,
        tamano BIGINT NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL,
        tablas INT NOT NULL DEFAULT 0,
        filas BIGINT NOT NULL DEFAULT 0,
        manifiesto MEDIUMTEXT NULL,
        origen VARCHAR(20) NOT NULL DEFAULT 'cron',
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        prueba_en DATETIME NULL,
        prueba_ok TINYINT(1) NULL,
        prueba_detalle VARCHAR(500) NULL,
        borrado_en DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Crea un respaldo completo. Devuelve su fila. */
function respaldo_crear(string $origen = 'cron'): array
{
    asegurar_respaldos();
    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    $pdo = db();
    $tmp = tempnam(sys_get_temp_dir(), 'resp');
    $gz = gzopen($tmp, 'wb6');
    $hash = hash_init('sha256');
    $w = function (string $s) use ($gz, $hash) { gzwrite($gz, $s); hash_update($hash, $s); };
    $man = [];
    $w("-- Respaldo " . APP_NAME . ' ' . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$tabla, $tipo]) {
        if ($tipo !== 'BASE TABLE' || $tabla === 'respaldos') continue;
        $crear = $pdo->query('SHOW CREATE TABLE `' . $tabla . '`')->fetch(PDO::FETCH_NUM)[1];
        $w("\nDROP TABLE IF EXISTS `$tabla`;\n$crear;\n");
        $n = 0; $lote = [];
        $st = $pdo->query('SELECT * FROM `' . $tabla . '`', PDO::FETCH_NUM);
        while ($r = $st->fetch()) {
            $lote[] = '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)) . ')';
            $n++;
            if (count($lote) === 200) { $w("INSERT INTO `$tabla` VALUES\n" . implode(",\n", $lote) . ";\n"); $lote = []; }
        }
        if ($lote) $w("INSERT INTO `$tabla` VALUES\n" . implode(",\n", $lote) . ";\n");
        $man[$tabla] = $n;
    }
    $w("SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    $sha = hash_final($hash);

    // Cifrado del .gz completo con la llave de la plataforma.
    $plano = (string) file_get_contents($tmp);
    @unlink($tmp);
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plano, 'aes-256-gcm', cfdi_llaves()[0], OPENSSL_RAW_DATA, $iv, $tag);
    unset($plano);
    if ($ct === false) throw new RuntimeException('No se pudo cifrar el respaldo.');
    $nombre = 'respaldo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql.gz.enc';
    $ruta = respaldo_dir() . '/' . $nombre;
    if (file_put_contents($ruta, "MDRESP1" . $iv . $tag . $ct) === false) throw new RuntimeException('No se pudo escribir el respaldo.');
    @chmod($ruta, 0600);

    $pdo->prepare('INSERT INTO respaldos (archivo, tamano, sha256, tablas, filas, manifiesto, origen) VALUES (?,?,?,?,?,?,?)')
        ->execute([$nombre, filesize($ruta), $sha, count($man), array_sum($man), json_encode($man), $origen]);
    $id = (int) $pdo->lastInsertId();
    respaldo_retencion();
    return respaldo_fila($id);
}

function respaldo_fila(int $id): array
{
    $st = db()->prepare('SELECT * FROM respaldos WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

/** Descifra un respaldo y devuelve el SQL (gunzip). */
function respaldo_leer_sql(array $r): string
{
    $ruta = respaldo_dir() . '/' . basename($r['archivo']);
    $b = @file_get_contents($ruta);
    if ($b === false || strncmp($b, 'MDRESP1', 7) !== 0) throw new RuntimeException('El archivo no existe o no es un respaldo válido.');
    $iv = substr($b, 7, 12); $tag = substr($b, 19, 16); $ct = substr($b, 35);
    $gz = false;
    foreach (cfdi_llaves() as $k) { $gz = openssl_decrypt($ct, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag); if ($gz !== false) break; }
    if ($gz === false) throw new RuntimeException('No se pudo descifrar (¿cambió la APP_KEY?).');
    $sql = gzdecode($gz);
    if ($sql === false) throw new RuntimeException('El contenido comprimido está dañado.');
    return $sql;
}

/**
 * Prueba de restauración: lectura completa, hash y conteo de filas por tabla.
 * Registra el resultado (es la evidencia que pide el SGSI).
 */
function respaldo_verificar(int $id): array
{
    asegurar_respaldos();
    $r = respaldo_fila($id);
    if (!$r) throw new RuntimeException('Respaldo no encontrado.');
    $ok = false; $det = '';
    try {
        $sql = respaldo_leer_sql($r);
        if (!hash_equals($r['sha256'], hash('sha256', $sql))) throw new RuntimeException('El SHA-256 no coincide con el registrado.');
        $man = json_decode((string) $r['manifiesto'], true) ?: [];
        $contadas = [];
        // Cuenta filas por tabla: cada INSERT trae líneas "(…)," o "(…);"
        foreach (preg_split('/\n(?=INSERT INTO `)/', $sql) as $bloque) {
            if (!preg_match('/^INSERT INTO `([^`]+)` VALUES\n/', $bloque, $m)) continue;
            $cuerpo = substr($bloque, strlen($m[0]));
            $cuerpo = substr($cuerpo, 0, strpos($cuerpo, ";\n") !== false ? strpos($cuerpo, ";\n") : strlen($cuerpo));
            $contadas[$m[1]] = ($contadas[$m[1]] ?? 0) + substr_count($cuerpo, "),\n(") + 1;
        }
        $mal = [];
        foreach ($man as $t => $n) if (($contadas[$t] ?? 0) !== (int) $n) $mal[] = "$t: " . ($contadas[$t] ?? 0) . " de $n";
        if (preg_match_all('/^CREATE TABLE `/m', $sql) < count($man)) $mal[] = 'faltan estructuras de tablas';
        if ($mal) throw new RuntimeException('Diferencias: ' . implode('; ', array_slice($mal, 0, 5)));
        $ok = true;
        $det = sprintf('Descifrado, descomprimido y verificado: %d tablas, %s filas, SHA-256 correcto.', count($man), number_format(array_sum($man)));
    } catch (Throwable $e) {
        $det = mb_substr($e->getMessage(), 0, 480);
    }
    db()->prepare('UPDATE respaldos SET prueba_en = NOW(), prueba_ok = ?, prueba_detalle = ? WHERE id = ?')->execute([$ok ? 1 : 0, $det, $id]);
    return ['ok' => $ok, 'detalle' => $det];
}

/** Borra los respaldos más viejos que excedan la retención. */
function respaldo_retencion(): void
{
    $n = max(3, (int) (plataforma_cfg('respaldo_retencion') ?: 14));
    $viejos = db()->query("SELECT id, archivo FROM respaldos WHERE borrado_en IS NULL ORDER BY id DESC LIMIT 1000 OFFSET $n")->fetchAll();
    foreach ($viejos as $v) {
        @unlink(respaldo_dir() . '/' . basename($v['archivo']));
        db()->prepare('UPDATE respaldos SET borrado_en = NOW() WHERE id = ?')->execute([$v['id']]);
    }
}
