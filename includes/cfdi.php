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
require_once __DIR__ . '/mercadopago.php';

const TIMBRES_AVISO_BAJO = 10;

/* ------------------------------------------------------------------ *
 *  Tablas (migración perezosa)
 * ------------------------------------------------------------------ */
function asegurar_cfdi(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS timbres_mov (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id  INT NOT NULL,
        tipo            VARCHAR(20) NOT NULL,          -- compra | consumo | devolucion | ajuste
        bolsa           VARCHAR(10) NOT NULL,          -- incluido | comprado
        cantidad        INT NOT NULL,                  -- + entra, - sale
        referencia      VARCHAR(60) NULL,              -- compra:ID, cfdi:ID…
        detalle         VARCHAR(255) NULL,
        usuario_nombre  VARCHAR(120) NULL,
        creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cons (consultorio_id, bolsa, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS timbres_compras (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id  INT NOT NULL,
        timbres         INT NOT NULL,
        monto           DECIMAL(10,2) NOT NULL,
        estado          VARCHAR(12) NOT NULL DEFAULT 'pendiente',   -- pendiente | pagado | rechazado
        token           CHAR(32) NOT NULL,
        mp_pref_id      VARCHAR(80) NULL,
        mp_pago_id      VARCHAR(40) NULL,
        usuario_nombre  VARCHAR(120) NULL,
        creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        pagado_en       DATETIME NULL,
        UNIQUE KEY uk_token (token),
        KEY idx_cons (consultorio_id, estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ------------------------------------------------------------------ *
 *  Ajustes de la plataforma
 * ------------------------------------------------------------------ */
function cfdi_venta_activa(): bool { return plataforma_cfg('cfdi_venta_activa') === '1'; }

/** Paquetes a la venta, ordenados de menor a mayor. Precios en MXN con IVA. */
function timbres_paquetes(): array
{
    $p = json_decode(plataforma_cfg('timbres_paquetes'), true);
    if (!is_array($p) || !$p) {
        // Valores de arranque: el dueño los ajusta en /platform/timbres antes de abrir la venta.
        $p = [['timbres' => 50, 'precio' => 149], ['timbres' => 100, 'precio' => 249],
              ['timbres' => 250, 'precio' => 549], ['timbres' => 500, 'precio' => 949]];
    }
    $p = array_values(array_filter(array_map(fn ($x) => ['timbres' => (int) ($x['timbres'] ?? 0), 'precio' => round((float) ($x['precio'] ?? 0), 2)], $p),
        fn ($x) => $x['timbres'] > 0 && $x['precio'] > 0));
    usort($p, fn ($a, $b) => $a['timbres'] <=> $b['timbres']);
    return $p;
}

/** Timbres que incluye cada plan al mes. */
function timbres_incluidos_planes(): array
{
    $d = json_decode(plataforma_cfg('timbres_incluidos'), true);
    if (!is_array($d)) $d = ['basico' => 0, 'profesional' => 0, 'clinica' => 30];
    $out = [];
    foreach (planes_mp() as $k => $_) $out[$k] = max(0, (int) ($d[$k] ?? 0));
    return $out;
}

function timbres_cancelar_cobra(): bool { return plataforma_cfg('timbres_cancelar_cobra') === '1'; }

/* ------------------------------------------------------------------ *
 *  Saldo
 * ------------------------------------------------------------------ */
function timbres_consultorio(int $cid): ?array
{
    $st = db()->prepare('SELECT id, nombre, plan, estado, email FROM consultorios WHERE id = ?');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

/**
 * Saldo del consultorio:
 *   incluidos_mes  timbres que su plan le da este mes (0 si no tiene membresía activa)
 *   incluidos_usados, incluidos_restan
 *   comprados      saldo de paquetes (no caduca)
 *   total          lo que puede timbrar hoy
 */
function timbres_saldo(int $cid): array
{
    asegurar_cfdi();
    $c = timbres_consultorio($cid);
    // Solo la membresía pagada trae timbres incluidos: en la prueba gratis
    // no hay (cada timbre le cuesta a la plataforma con el PAC).
    $inc = ($c && $c['estado'] === 'activa') ? (timbres_incluidos_planes()[$c['plan']] ?? 0) : 0;

    $ini = date('Y-m-01 00:00:00');
    $st = db()->prepare("SELECT COALESCE(-SUM(cantidad),0) FROM timbres_mov
                         WHERE consultorio_id = ? AND bolsa = 'incluido' AND creado_en >= ?");
    $st->execute([$cid, $ini]);
    $usados = max(0, (int) $st->fetchColumn());

    $st = db()->prepare("SELECT COALESCE(SUM(cantidad),0) FROM timbres_mov WHERE consultorio_id = ? AND bolsa = 'comprado'");
    $st->execute([$cid]);
    $comprados = max(0, (int) $st->fetchColumn());

    $restan = max(0, $inc - $usados);
    return [
        'incluidos_mes'    => $inc,
        'incluidos_usados' => min($usados, $inc),
        'incluidos_restan' => $restan,
        'comprados'        => $comprados,
        'total'            => $restan + $comprados,
        'renuevan'         => date('Y-m-01', strtotime('first day of next month')),
    ];
}

/**
 * Gasta 1 timbre (primero de la bolsa del mes, luego de la comprada).
 * Devuelve el id del movimiento, para devolverlo si el PAC rechaza el CFDI.
 * Lanza RuntimeException si no hay saldo.
 */
function timbres_consumir(int $cid, string $referencia, string $detalle, string $quien = ''): int
{
    asegurar_cfdi();
    $pdo = db();
    $lock = 'timbres_' . $cid;
    $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 10)");
    try {
        $s = timbres_saldo($cid);
        if ($s['total'] < 1) throw new RuntimeException('Ya no tienes timbres. Compra un paquete para seguir facturando.');
        $bolsa = $s['incluidos_restan'] > 0 ? 'incluido' : 'comprado';
        $pdo->prepare("INSERT INTO timbres_mov (consultorio_id, tipo, bolsa, cantidad, referencia, detalle, usuario_nombre)
                       VALUES (?, 'consumo', ?, -1, ?, ?, ?)")
            ->execute([$cid, $bolsa, $referencia, mb_substr($detalle, 0, 255), $quien ?: null]);
        return (int) $pdo->lastInsertId();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
    }
}

/** Regresa el timbre de un consumo que no llegó a timbrarse (el PAC o el SAT lo rechazó). */
function timbres_devolver(int $movId, string $motivo): void
{
    asegurar_cfdi();
    $st = db()->prepare("SELECT * FROM timbres_mov WHERE id = ? AND tipo = 'consumo'");
    $st->execute([$movId]);
    if (!$m = $st->fetch()) return;
    $ya = db()->prepare("SELECT 1 FROM timbres_mov WHERE tipo = 'devolucion' AND referencia = ?");
    $ya->execute(['mov:' . $movId]);
    if ($ya->fetchColumn()) return;
    db()->prepare("INSERT INTO timbres_mov (consultorio_id, tipo, bolsa, cantidad, referencia, detalle)
                   VALUES (?, 'devolucion', ?, 1, ?, ?)")
        ->execute([$m['consultorio_id'], $m['bolsa'], 'mov:' . $movId, mb_substr($motivo, 0, 255)]);
}

/** Ajuste manual desde la plataforma (cortesía, corrección). Va a la bolsa comprada. */
function timbres_ajustar(int $cid, int $cantidad, string $motivo, string $quien): void
{
    asegurar_cfdi();
    if ($cantidad === 0) return;
    if ($cantidad < 0 && timbres_saldo($cid)['comprados'] + $cantidad < 0) {
        throw new RuntimeException('No puedes quitar más timbres comprados de los que tiene.');
    }
    db()->prepare("INSERT INTO timbres_mov (consultorio_id, tipo, bolsa, cantidad, referencia, detalle, usuario_nombre)
                   VALUES (?, 'ajuste', 'comprado', ?, 'plataforma', ?, ?)")
        ->execute([$cid, $cantidad, mb_substr($motivo, 0, 255), $quien]);
}

/* ------------------------------------------------------------------ *
 *  Compra de paquetes (Mercado Pago de la plataforma)
 * ------------------------------------------------------------------ */

/** Crea la compra y la preferencia de Checkout Pro. Devuelve la URL de pago. */
function timbres_iniciar_compra(int $cid, int $ixPaquete, string $quien, string $email): string
{
    asegurar_cfdi();
    if (!cfdi_venta_activa()) throw new RuntimeException('La venta de timbres aún no está abierta.');
    if (!mp_configurado())    throw new RuntimeException('Los pagos en línea no están disponibles en este momento.');
    $paq = timbres_paquetes()[$ixPaquete] ?? null;
    if (!$paq) throw new RuntimeException('Ese paquete ya no existe. Recarga la página.');

    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO timbres_compras (consultorio_id, timbres, monto, token, usuario_nombre) VALUES (?, ?, ?, ?, ?)')
        ->execute([$cid, $paq['timbres'], $paq['precio'], $token, $quien ?: null]);
    $id = (int) db()->lastInsertId();

    $payload = [
        'items' => [[
            'id'          => 'timbres-' . $paq['timbres'],
            'title'       => 'Paquete de ' . number_format($paq['timbres']) . ' timbres CFDI · ' . APP_NAME,
            'description' => 'Timbres de facturación electrónica de prepago. No caducan.',
            'quantity'    => 1,
            'unit_price'  => (float) $paq['precio'],
            'currency_id' => 'MXN',
        ]],
        'external_reference' => 'timbres:' . $id,
        'notification_url'   => url_absoluta('/timbres/webhook'),
        'back_urls' => [
            'success' => url_absoluta('/timbres/retorno?t=' . $token),
            'pending' => url_absoluta('/timbres/retorno?t=' . $token),
            'failure' => url_absoluta('/timbres/retorno?t=' . $token),
        ],
        'auto_return'          => 'approved',
        'statement_descriptor' => 'MEDIAGENDA TIMBRES',
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $payload['payer']['email'] = $email;

    $pref = mp_request('POST', '/checkout/preferences', $payload);
    $url = mp_es_sandbox() ? ($pref['sandbox_init_point'] ?? $pref['init_point'] ?? '') : ($pref['init_point'] ?? '');
    if ($url === '') throw new RuntimeException('Mercado Pago no devolvió la página de pago.');
    db()->prepare('UPDATE timbres_compras SET mp_pref_id = ? WHERE id = ?')->execute([(string) ($pref['id'] ?? ''), $id]);
    return $url;
}

/**
 * Consulta el pago a Mercado Pago (nunca se confía en el aviso) y, si está
 * aprobado y cuadra el importe, acredita los timbres. Idempotente.
 * Devuelve 'pagado' | 'pendiente' | 'rechazado' | 'ignorado'.
 */
function timbres_confirmar_pago(string $pagoId): string
{
    asegurar_cfdi();
    $p = mp_request('GET', '/v1/payments/' . rawurlencode($pagoId));
    $ref = (string) ($p['external_reference'] ?? '');
    if (!preg_match('/^timbres:(\d+)$/', $ref, $m)) return 'ignorado';
    $st = db()->prepare('SELECT * FROM timbres_compras WHERE id = ?');
    $st->execute([(int) $m[1]]);
    if (!$c = $st->fetch()) return 'ignorado';

    $estado = (string) ($p['status'] ?? '');
    if ($estado === 'rejected' || $estado === 'cancelled') {
        db()->prepare("UPDATE timbres_compras SET estado = 'rechazado', mp_pago_id = ? WHERE id = ? AND estado = 'pendiente'")
            ->execute([$pagoId, $c['id']]);
        return 'rechazado';
    }
    if ($estado !== 'approved') return 'pendiente';
    if (strtoupper((string) ($p['currency_id'] ?? '')) !== 'MXN'
        || (float) ($p['transaction_amount'] ?? 0) + 0.01 < (float) $c['monto']) {
        auditar('timbres_pago_no_cuadra', 'timbres', (int) $c['id'], 'pago ' . $pagoId, (int) $c['consultorio_id'], ['nombre' => 'Mercado Pago']);
        return 'ignorado';
    }

    $up = db()->prepare("UPDATE timbres_compras SET estado = 'pagado', mp_pago_id = ?, pagado_en = NOW() WHERE id = ? AND estado <> 'pagado'");
    $up->execute([$pagoId, $c['id']]);
    if ($up->rowCount() === 1) {
        db()->prepare("INSERT INTO timbres_mov (consultorio_id, tipo, bolsa, cantidad, referencia, detalle, usuario_nombre)
                       VALUES (?, 'compra', 'comprado', ?, ?, ?, ?)")
            ->execute([$c['consultorio_id'], (int) $c['timbres'], 'compra:' . $c['id'],
                       'Paquete de ' . number_format($c['timbres']) . ' timbres · $' . number_format($c['monto'], 2), $c['usuario_nombre']]);
        auditar('timbres_compra', 'timbres', (int) $c['id'], $c['timbres'] . ' timbres · $' . $c['monto'], (int) $c['consultorio_id'], ['nombre' => 'Mercado Pago']);
    }
    return 'pagado';
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
