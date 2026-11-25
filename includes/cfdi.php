<?php
/**
 * Facturación electrónica (CFDI 4.0) — parte que NO depende del PAC:
 *
 *  - Datos fiscales del emisor (cada consultorio) y su CSD cifrado.
 *  - Saldo de timbres con dos bolsas:
 *      · incluido: los que trae el plan activo cada mes (no se acumulan).
 *      · comprado: paquetes de prepago pagados con Mercado Pago (no caducan).
 *    Al timbrar se gasta primero la bolsa "incluido" del mes y luego la comprada.
 *  - Compra de paquetes: el dinero cae en la cuenta de Mercado Pago de la
 *    PLATAFORMA (no la del consultorio) y el webhook acredita los timbres.
 *
 * El timbrado real (armar el XML, sellarlo y mandarlo al PAC) se conecta
 * después en cfdi_timbrar(); todo lo de aquí ya queda listo para eso.
 *
 * Ajustes del dueño en plataforma_config (pantalla /platform/timbres):
 *   cfdi_venta_activa      '1' = los consultorios ya pueden comprar paquetes
 *   cfdi_pac               nombre del PAC (informativo)
 *   timbres_paquetes       JSON [{"timbres":50,"precio":149}, …]  (MXN, IVA incluido)
 *   timbres_incluidos      JSON {"basico":0,"profesional":0,"clinica":30}
 *   timbres_cancelar_cobra '1' = cancelar un CFDI también gasta 1 timbre
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/creditos.php';

function asegurar_cfdi(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    asegurar_creditos();
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS cfdi_emisor (
        consultorio_id  INT PRIMARY KEY,
        rfc             VARCHAR(13)  NOT NULL DEFAULT '',
        razon_social    VARCHAR(254) NOT NULL DEFAULT '',
        regimen         VARCHAR(3)   NOT NULL DEFAULT '',
        cp              VARCHAR(5)   NOT NULL DEFAULT '',
        serie           VARCHAR(10)  NOT NULL DEFAULT 'A',
        folio_sig       INT          NOT NULL DEFAULT 1,
        csd_cer         TEXT         NULL,
        csd_key_enc     TEXT         NULL,
        csd_pass_enc    TEXT         NULL,
        csd_rfc         VARCHAR(13)  NULL,
        csd_no_cert     VARCHAR(20)  NULL,
        csd_desde       DATE         NULL,
        csd_hasta       DATE         NULL,
        actualizado_en  DATETIME     NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ------------------------------------------------------------------ *
 *  Emisor y CSD
 * ------------------------------------------------------------------ */
function cfdi_regimenes_emisor(): array
{
    return [
        '612' => '612 · Personas Físicas con Actividades Empresariales y Profesionales',
        '626' => '626 · Régimen Simplificado de Confianza (RESICO)',
        '601' => '601 · General de Ley Personas Morales',
        '603' => '603 · Personas Morales con Fines no Lucrativos',
        '621' => '621 · Incorporación Fiscal',
    ];
}

function cfdi_emisor(int $cid): array
{
    asegurar_cfdi();
    $st = db()->prepare('SELECT * FROM cfdi_emisor WHERE consultorio_id = ?');
    $st->execute([$cid]);
    return $st->fetch() ?: ['consultorio_id' => $cid, 'rfc' => '', 'razon_social' => '', 'regimen' => '', 'cp' => '',
        'serie' => 'A', 'folio_sig' => 1, 'csd_cer' => null, 'csd_rfc' => null, 'csd_no_cert' => null, 'csd_desde' => null, 'csd_hasta' => null];
}

/** ¿Tiene todo para timbrar? Devuelve la lista de lo que falta (vacía = listo). */
function cfdi_emisor_faltantes(array $em): array
{
    $f = [];
    if ($em['rfc'] === '' || $em['razon_social'] === '' || $em['regimen'] === '' || $em['cp'] === '') $f[] = 'Datos fiscales (RFC, nombre, régimen y código postal)';
    if (empty($em['csd_cer'])) $f[] = 'Certificado de sello digital (CSD)';
    elseif (!empty($em['csd_hasta']) && $em['csd_hasta'] < date('Y-m-d')) $f[] = 'Tu CSD está vencido: tramita uno nuevo en el SAT';
    return $f;
}

/**
 * Llave para cifrar los CSD. Manda APP_KEY (config/secrets.php); si no existe,
 * se genera una vez en config/app.key (fuera de git y bloqueada por .htaccess).
 * Devuelve las llaves posibles: la primera cifra, todas sirven para descifrar.
 */
function cfdi_llaves(): array
{
    $ll = [];
    if (defined('APP_KEY') && APP_KEY !== '') $ll[] = hash('sha256', 'cfdi|' . APP_KEY, true);
    $f = __DIR__ . '/../config/app.key';
    if (is_file($f)) {
        $ll[] = hash('sha256', 'cfdi|' . trim((string) file_get_contents($f)), true);
    } elseif (!$ll) {
        $k = bin2hex(random_bytes(32));
        if (@file_put_contents($f, $k) === false) throw new RuntimeException('No se pudo crear la llave de cifrado (config/app.key).');
        @chmod($f, 0600);
        $ll[] = hash('sha256', 'cfdi|' . $k, true);
    }
    return $ll;
}

function cfdi_cifrar(string $plano): string
{
    $k = cfdi_llaves()[0];
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plano, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('No se pudo cifrar.');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function cfdi_descifrar(string $cifrado): ?string
{
    if (strncmp($cifrado, 'v1:', 3) !== 0) return null;
    $b = base64_decode(substr($cifrado, 3), true);
    if ($b === false || strlen($b) < 29) return null;
    $iv = substr($b, 0, 12); $tag = substr($b, 12, 16); $ct = substr($b, 28);
    foreach (cfdi_llaves() as $k) {
        $p = openssl_decrypt($ct, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag);
        if ($p !== false) return $p;
    }
    return null;
}

function cfdi_pem(string $bin, string $tipo): string
{
    if (strpos($bin, '-----BEGIN') !== false) return $bin;
    return "-----BEGIN $tipo-----\n" . chunk_split(base64_encode($bin), 64, "\n") . "-----END $tipo-----\n";
}

/**
 * Valida un CSD del SAT (.cer + .key + contraseña) y devuelve sus datos.
 * Rechaza la e.firma (FIEL): para facturar se usa el sello digital, no la firma.
 */
function cfdi_leer_csd(string $cer, string $key, string $pass): array
{
    $cerPem = cfdi_pem($cer, 'CERTIFICATE');
    $x = @openssl_x509_parse($cerPem);
    if (!$x) throw new RuntimeException('El archivo .cer no es un certificado válido.');

    $uso = (string) ($x['extensions']['keyUsage'] ?? '');
    if (stripos($uso, 'Key Agreement') !== false || stripos($uso, 'Data Encipherment') !== false) {
        throw new RuntimeException('Ese certificado es tu e.firma (FIEL). Para facturar se usa el Certificado de Sello Digital (CSD), que se tramita en el portal del SAT con tu e.firma.');
    }
    $hasta = (int) ($x['validTo_time_t'] ?? 0);
    if ($hasta && $hasta < time()) throw new RuntimeException('Ese CSD ya venció (' . date('d/m/Y', $hasta) . '). Tramita uno nuevo en el SAT.');

    // El número de certificado del SAT viene como texto dentro del serial en hexadecimal.
    $hex = (string) ($x['serialNumberHex'] ?? '');
    $no = '';
    if ($hex !== '') {
        $hex = strlen($hex) % 2 ? '0' . $hex : $hex;
        $bin = @hex2bin($hex);
        $no = ($bin !== false && ctype_digit($bin)) ? $bin : $hex;
    }

    $rfc = '';
    $subj = $x['subject'] ?? [];
    foreach (['x500UniqueIdentifier', 'UID', 'uniqueIdentifier'] as $campo) {
        if (!empty($subj[$campo])) { $rfc = strtoupper(trim(explode('/', is_array($subj[$campo]) ? $subj[$campo][0] : $subj[$campo])[0])); break; }
    }
    if ($rfc === '' && preg_match('/\b([A-ZÑ&]{3,4}\d{6}[A-Z\d]{3})\b/u', strtoupper((string) ($x['name'] ?? '')), $m)) $rfc = $m[1];

    $pk = @openssl_pkey_get_private(cfdi_pem($key, 'ENCRYPTED PRIVATE KEY'), $pass);
    if (!$pk) throw new RuntimeException('La contraseña no abre el archivo .key (o el .key no es del SAT). Revisa la contraseña de tu CSD, no la de tu e.firma.');
    if (!openssl_x509_check_private_key($cerPem, $pk)) throw new RuntimeException('El .key no corresponde a ese .cer. Sube los dos archivos del mismo certificado.');

    return [
        'rfc'     => $rfc,
        'no_cert' => $no,
        'desde'   => date('Y-m-d', (int) ($x['validFrom_time_t'] ?? time())),
        'hasta'   => $hasta ? date('Y-m-d', $hasta) : null,
        'nombre'  => (string) ($subj['CN'] ?? $subj['O'] ?? ''),
    ];
}

/**
 * Punto de conexión con el PAC (pendiente de elegir proveedor). Cuando se
 * conecte: armar XML 4.0 → sellar con el CSD → timbres_consumir() → enviar al
 * PAC → si falla, timbres_devolver().
 */
function cfdi_timbrar(int $cid, array $datos): array
{
    throw new RuntimeException('El timbrado se activa en cuanto conectemos el proveedor autorizado (PAC).');
}
