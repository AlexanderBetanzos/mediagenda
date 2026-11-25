<?php
/**
 * Expediente clínico electrónico: integridad y firma de las notas
 * (NOM-004-SSA3-2012 y NOM-024-SSA3-2012).
 *
 *  1. Las notas NO se editan ni se borran. Una corrección es una NOTA
 *     ACLARATORIA nueva, ligada a la original, con su fecha y autor.
 *  2. SELLO DE INTEGRIDAD: al guardarse, cada nota recibe un SHA-256 de su
 *     contenido encadenado con el sello de la nota anterior del consultorio
 *     (sello_prev). Si alguien modifica la base de datos por fuera, el sello ya
 *     no coincide y el expediente lo marca como "alterada".
 *  3. FIRMA ELECTRÓNICA: el médico sube una vez su e.firma del SAT (.cer y .key;
 *     la .key se guarda cifrada y la contraseña NUNCA se guarda) y firma cada
 *     nota escribiendo su contraseña. La firma (RSA-SHA256 sobre el contenido y
 *     el sello) se verifica contra su certificado cada vez que se muestra.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cfdi.php';   // cifrado (cfdi_cifrar/descifrar) y lectura de certificados

function asegurar_ece(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    try {
        $cols = array_column($pdo->query('SHOW COLUMNS FROM consultas')->fetchAll(), 'Field');
        $add = [];
        if (!in_array('pronostico', $cols, true)) $add[] = 'ADD COLUMN pronostico TEXT NULL AFTER tratamiento';
        if (!in_array('sello', $cols, true))      $add[] = 'ADD COLUMN sello CHAR(64) NULL, ADD COLUMN sello_prev CHAR(64) NULL';
        if (!in_array('firma', $cols, true))      $add[] = 'ADD COLUMN firma TEXT NULL, ADD COLUMN firma_cert VARCHAR(24) NULL, ADD COLUMN firma_en DATETIME NULL, ADD COLUMN firma_por INT NULL';
        if ($add) $pdo->exec('ALTER TABLE consultas ' . implode(', ', $add));
    } catch (Throwable $e) { /* sin permisos de DDL: todo sigue funcionando sin sello */ }
    $pdo->exec("CREATE TABLE IF NOT EXISTS consulta_aclaraciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        consulta_id INT NOT NULL,
        medico_id INT NOT NULL,
        texto TEXT NOT NULL,
        sello CHAR(64) NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cons (consultorio_id, consulta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS medico_efirma (
        usuario_id INT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        cer TEXT NOT NULL,
        key_enc TEXT NOT NULL,
        rfc VARCHAR(13) NULL,
        nombre VARCHAR(200) NULL,
        no_cert VARCHAR(24) NULL,
        hasta DATE NULL,
        actualizado_en DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ------------------------------------------------------------------ *
 *  Sello de integridad
 * ------------------------------------------------------------------ */

/** Contenido canónico de la nota: lo que el sello y la firma protegen. */
function ece_canon(array $c): string
{
    $campos = ['id', 'consultorio_id', 'paciente_id', 'medico_id', 'fecha', 'motivo', 'exploracion', 'diagnostico', 'pronostico',
               'tratamiento', 'receta', 'peso', 'estatura', 'presion', 'temperatura', 'glucosa', 'frecuencia_cardiaca',
               'saturacion', 'frecuencia_respiratoria', 'notas'];
    $o = [];
    foreach ($campos as $k) {
        $v = $c[$k] ?? null;
        // Los números vienen de MySQL como "72.00": se normalizan para que el sello no dependa del formato.
        if ($v !== null && in_array($k, ['peso', 'estatura', 'temperatura', 'glucosa'], true)) $v = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $o[$k] = $v === null ? null : (string) $v;
    }
    return json_encode($o, JSON_UNESCAPED_UNICODE);
}

/** Sella una nota (si aún no tiene sello). Encadena con la última nota sellada del consultorio. */
function ece_sellar(int $consultaId): void
{
    asegurar_ece();
    $pdo = db();
    $tid = (int) tenant_id();
    $lock = 'ece_sello_' . $tid;
    try {
        $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 10)');
        $st = $pdo->prepare('SELECT * FROM consultas WHERE id = ? AND consultorio_id = ?');
        $st->execute([$consultaId, $tid]);
        $c = $st->fetch();
        if (!$c || !array_key_exists('sello', $c) || !empty($c['sello'])) return;
        $prev = $pdo->prepare('SELECT sello FROM consultas WHERE consultorio_id = ? AND sello IS NOT NULL ORDER BY id DESC LIMIT 1');
        $prev->execute([$tid]);
        $p = (string) ($prev->fetchColumn() ?: str_repeat('0', 64));
        $sello = hash('sha256', $p . '|' . ece_canon($c));
        $pdo->prepare('UPDATE consultas SET sello = ?, sello_prev = ? WHERE id = ? AND consultorio_id = ? AND sello IS NULL')
            ->execute([$sello, $p, $consultaId, $tid]);
    } catch (Throwable $e) {
        /* el sello nunca debe impedir guardar la consulta */
    } finally {
        try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')'); } catch (Throwable $e) {}
    }
}

/** Sella las notas de un paciente que se guardaron antes de existir el sello. */
function ece_sellar_pendientes(int $pacienteId): void
{
    asegurar_ece();
    try {
        $st = db()->prepare('SELECT id FROM consultas WHERE consultorio_id = ? AND paciente_id = ? AND sello IS NULL ORDER BY id');
        $st->execute([tenant_id(), $pacienteId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) ece_sellar((int) $cid);
    } catch (Throwable $e) {}
}

/** 'integra' | 'alterada' | 'sin_sello' */
function ece_estado_sello(array $c): string
{
    if (empty($c['sello'])) return 'sin_sello';
    return hash_equals($c['sello'], hash('sha256', ($c['sello_prev'] ?? '') . '|' . ece_canon($c))) ? 'integra' : 'alterada';
}

/* ------------------------------------------------------------------ *
 *  Notas aclaratorias
 * ------------------------------------------------------------------ */
function ece_aclarar(int $consultaId, int $medicoId, string $texto): void
{
    asegurar_ece();
    $texto = trim($texto);
    if ($texto === '') throw new RuntimeException('Escribe la aclaración.');
    $st = db()->prepare('SELECT sello FROM consultas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$consultaId, tenant_id()]);
    $orig = $st->fetch();
    if (!$orig) throw new RuntimeException('Nota no encontrada.');
    $ahora = date('Y-m-d H:i:s');
    $sello = hash('sha256', ($orig['sello'] ?? '') . '|' . $consultaId . '|' . $medicoId . '|' . $ahora . '|' . $texto);
    db()->prepare('INSERT INTO consulta_aclaraciones (consultorio_id, consulta_id, medico_id, texto, sello, creado_en) VALUES (?,?,?,?,?,?)')
        ->execute([tenant_id(), $consultaId, $medicoId, $texto, $sello, $ahora]);
}

/** Aclaraciones por nota: [consulta_id => [filas]]. */
function ece_aclaraciones(array $consultaIds): array
{
    asegurar_ece();
    if (!$consultaIds) return [];
    $in = implode(',', array_map('intval', $consultaIds));
    $st = db()->prepare("SELECT a.*, u.nombre med_nombre FROM consulta_aclaraciones a LEFT JOIN usuarios u ON u.id = a.medico_id
                         WHERE a.consultorio_id = ? AND a.consulta_id IN ($in) ORDER BY a.id");
    $st->execute([tenant_id()]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['consulta_id']][] = $r;
    return $out;
}

/* ------------------------------------------------------------------ *
 *  e.firma del médico
 * ------------------------------------------------------------------ */

/** Valida la e.firma (FIEL) del SAT: .cer + .key + contraseña. Rechaza el CSD (es para facturar). */
function ece_leer_efirma(string $cer, string $key, string $pass): array
{
    $cerPem = cfdi_pem($cer, 'CERTIFICATE');
    $x = @openssl_x509_parse($cerPem);
    if (!$x) throw new RuntimeException('El archivo .cer no es un certificado válido.');
    $uso = (string) ($x['extensions']['keyUsage'] ?? '');
    if (stripos($uso, 'Key Agreement') === false && stripos($uso, 'Data Encipherment') === false) {
        throw new RuntimeException('Ese certificado parece un Certificado de Sello Digital (CSD), que sirve para facturar. Para firmar notas se usa tu e.firma (antes FIEL).');
    }
    $hasta = (int) ($x['validTo_time_t'] ?? 0);
    if ($hasta && $hasta < time()) throw new RuntimeException('Tu e.firma venció el ' . date('d/m/Y', $hasta) . '. Renuévala en el SAT.');
    $pk = @openssl_pkey_get_private(cfdi_pem($key, 'ENCRYPTED PRIVATE KEY'), $pass);
    if (!$pk) throw new RuntimeException('La contraseña no abre el archivo .key. Es la contraseña de tu e.firma.');
    if (!openssl_x509_check_private_key($cerPem, $pk)) throw new RuntimeException('El .key no corresponde a ese .cer.');
    $hex = (string) ($x['serialNumberHex'] ?? '');
    $hex = strlen($hex) % 2 ? '0' . $hex : $hex;
    $bin = $hex !== '' ? @hex2bin($hex) : false;
    $subj = $x['subject'] ?? [];
    $rfc = '';
    foreach (['x500UniqueIdentifier', 'UID'] as $k) if (!empty($subj[$k])) { $rfc = strtoupper(trim(explode('/', is_array($subj[$k]) ? $subj[$k][0] : $subj[$k])[0])); break; }
    return [
        'rfc'     => $rfc,
        'nombre'  => (string) ($subj['CN'] ?? $subj['name'] ?? ''),
        'no_cert' => ($bin !== false && ctype_digit($bin)) ? $bin : $hex,
        'hasta'   => $hasta ? date('Y-m-d', $hasta) : null,
    ];
}

function ece_efirma(int $usuarioId): ?array
{
    asegurar_ece();
    $st = db()->prepare('SELECT * FROM medico_efirma WHERE usuario_id = ? AND consultorio_id = ?');
    $st->execute([$usuarioId, tenant_id()]);
    return $st->fetch() ?: null;
}

/** Firma una nota con la e.firma del médico (debe ser su autor). */
function ece_firmar(int $consultaId, int $usuarioId, string $pass): void
{
    asegurar_ece();
    $ef = ece_efirma($usuarioId);
    if (!$ef) throw new RuntimeException('Primero sube tu e.firma en "Mi firma electrónica".');
    if (!empty($ef['hasta']) && $ef['hasta'] < date('Y-m-d')) throw new RuntimeException('Tu e.firma está vencida.');
    ece_sellar($consultaId);
    $st = db()->prepare('SELECT * FROM consultas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$consultaId, tenant_id()]);
    $c = $st->fetch();
    if (!$c) throw new RuntimeException('Nota no encontrada.');
    if ((int) $c['medico_id'] !== $usuarioId) throw new RuntimeException('Solo quien escribió la nota puede firmarla.');
    if (!empty($c['firma'])) throw new RuntimeException('Esta nota ya está firmada.');
    if (ece_estado_sello($c) === 'alterada') throw new RuntimeException('La nota no coincide con su sello; no se puede firmar.');
    $key = cfdi_descifrar((string) $ef['key_enc']);
    if ($key === null) throw new RuntimeException('No se pudo leer tu e.firma guardada. Vuelve a subirla.');
    $pk = @openssl_pkey_get_private(cfdi_pem($key, 'ENCRYPTED PRIVATE KEY'), $pass);
    if (!$pk) throw new RuntimeException('Contraseña de la e.firma incorrecta.');
    $firma = '';
    if (!openssl_sign(ece_canon($c) . '|' . $c['sello'], $firma, $pk, OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar.');
    db()->prepare('UPDATE consultas SET firma = ?, firma_cert = ?, firma_en = NOW(), firma_por = ? WHERE id = ? AND consultorio_id = ? AND firma IS NULL')
        ->execute([base64_encode($firma), $ef['no_cert'], $usuarioId, $consultaId, tenant_id()]);
}

/** 'valida' | 'invalida' | 'sin_firma' */
function ece_estado_firma(array $c): string
{
    if (empty($c['firma'])) return 'sin_firma';
    static $certs = [];
    $uid = (int) ($c['firma_por'] ?? 0);
    if (!array_key_exists($uid, $certs)) { $ef = ece_efirma($uid); $certs[$uid] = $ef ? cfdi_pem(base64_decode($ef['cer']), 'CERTIFICATE') : null; }
    if (!$certs[$uid]) return 'invalida';
    $ok = openssl_verify(ece_canon($c) . '|' . ($c['sello'] ?? ''), (string) base64_decode($c['firma']), $certs[$uid], OPENSSL_ALGO_SHA256);
    return $ok === 1 ? 'valida' : 'invalida';
}

/** Insignias de sello y firma para mostrar junto a la nota. */
function ece_insignias(array $c): string
{
    $h = '';
    $s = ece_estado_sello($c);
    if ($s === 'integra') $h .= '<span class="badge text-bg-light border" title="' . e(t('Sello de integridad SHA-256: la nota no ha cambiado desde que se guardó.') . ' ' . substr($c['sello'], 0, 16) . '…') . '"><i class="bi bi-shield-check text-success"></i> ' . et('Íntegra') . '</span> ';
    elseif ($s === 'alterada') $h .= '<span class="badge text-bg-danger" title="' . e(t('El contenido ya no coincide con su sello: se modificó fuera del sistema.')) . '"><i class="bi bi-shield-exclamation"></i> ' . et('Alterada') . '</span> ';
    $f = ece_estado_firma($c);
    if ($f === 'valida') $h .= '<span class="badge text-bg-success" title="' . e(t('Firmada con e.firma, certificado') . ' ' . $c['firma_cert'] . ' · ' . fmt_fecha($c['firma_en'])) . '"><i class="bi bi-pen"></i> ' . et('Firmada') . '</span>';
    elseif ($f === 'invalida') $h .= '<span class="badge text-bg-danger"><i class="bi bi-pen"></i> ' . et('Firma no válida') . '</span>';
    return $h;
}
