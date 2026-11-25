<?php
/**
 * Créditos de prepago que los consultorios compran a la plataforma:
 *
 *   recurso 'timbre'    → timbres CFDI (una factura timbrada = 1)
 *   recurso 'whatsapp'  → mensajes de WhatsApp enviados desde el número de la
 *                          plataforma (un mensaje entregado a Meta = 1)
 *
 * Cada recurso tiene dos bolsas:
 *   · incluido: lo que trae el plan cada mes (no se acumula).
 *   · comprado: paquetes pagados con Mercado Pago de la PLATAFORMA (no caducan).
 * Al gastar se usa primero lo incluido del mes y luego lo comprado.
 *
 * Ajustes en plataforma_config (pantalla /platform/timbres):
 *   <pref>_venta_activa   '1' = se pueden comprar paquetes
 *   <pref>_paquetes       JSON [{"cantidad":50,"precio":149}, …]  (MXN, IVA incluido)
 *   <pref>_incluidos      JSON {"prueba":0,"basico":0,"profesional":0,"clinica":30}
 * con <pref> = 'timbres' | 'wa'. (La venta de timbres conserva su clave
 * histórica 'cfdi_venta_activa'.)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mercadopago.php';

const TIMBRES_AVISO_BAJO = 10;

/** Definición de cada recurso. */
function credito_def(string $r): array
{
    $defs = [
        'timbre' => [
            'nombre' => 'timbres', 'uno' => 'timbre', 'pref' => 'timbres', 'venta' => 'cfdi_venta_activa',
            'titulo' => 'Paquete de %s timbres CFDI', 'aviso' => TIMBRES_AVISO_BAJO, 'pagina' => '/timbres/index',
            'paquetes' => [[50, 149], [100, 249], [250, 549], [500, 949]],
            'incluidos' => ['prueba' => 0, 'basico' => 0, 'profesional' => 0, 'clinica' => 30],
        ],
        'whatsapp' => [
            'nombre' => 'mensajes', 'uno' => 'mensaje', 'pref' => 'wa', 'venta' => 'wa_venta_activa',
            'titulo' => 'Paquete de %s mensajes de WhatsApp', 'aviso' => 20, 'pagina' => '/mensajes/index',
            'paquetes' => [[100, 79], [300, 199], [1000, 549]],
            'incluidos' => ['prueba' => 20, 'basico' => 0, 'profesional' => 100, 'clinica' => 300],
        ],
    ];
    if (!isset($defs[$r])) throw new InvalidArgumentException('Recurso desconocido: ' . $r);
    return $defs[$r] + ['clave' => $r];
}

/* ------------------------------------------------------------------ *
 *  Tablas (migración perezosa)
 * ------------------------------------------------------------------ */
function asegurar_creditos(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS timbres_mov (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id  INT NOT NULL,
        recurso         VARCHAR(10) NOT NULL DEFAULT 'timbre',
        tipo            VARCHAR(20) NOT NULL,          -- compra | consumo | devolucion | ajuste
        bolsa           VARCHAR(10) NOT NULL,          -- incluido | comprado
        cantidad        INT NOT NULL,                  -- + entra, - sale
        referencia      VARCHAR(60) NULL,
        detalle         VARCHAR(255) NULL,
        usuario_nombre  VARCHAR(120) NULL,
        creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cons (consultorio_id, recurso, bolsa, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS timbres_compras (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id  INT NOT NULL,
        recurso         VARCHAR(10) NOT NULL DEFAULT 'timbre',
        timbres         INT NOT NULL,                  -- cantidad comprada (timbres o mensajes)
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
    // Tablas creadas antes de que existiera WhatsApp: sin columna recurso.
    foreach (['timbres_mov', 'timbres_compras'] as $t) {
        if (!$pdo->query("SHOW COLUMNS FROM $t LIKE 'recurso'")->fetch()) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN recurso VARCHAR(10) NOT NULL DEFAULT 'timbre' AFTER consultorio_id");
        }
    }
}

/* ------------------------------------------------------------------ *
 *  Ajustes
 * ------------------------------------------------------------------ */
function credito_venta_activa(string $r): bool { return plataforma_cfg(credito_def($r)['venta']) === '1'; }

/** Paquetes a la venta [{cantidad, precio}], de menor a mayor. */
function credito_paquetes(string $r): array
{
    $d = credito_def($r);
    $p = json_decode(plataforma_cfg($d['pref'] . '_paquetes'), true);
    if (!is_array($p) || !$p) $p = array_map(fn ($x) => ['cantidad' => $x[0], 'precio' => $x[1]], $d['paquetes']);
    $p = array_values(array_filter(array_map(fn ($x) => [
        'cantidad' => (int) ($x['cantidad'] ?? $x['timbres'] ?? 0),
        'precio'   => round((float) ($x['precio'] ?? 0), 2),
    ], $p), fn ($x) => $x['cantidad'] > 0 && $x['precio'] > 0));
    usort($p, fn ($a, $b) => $a['cantidad'] <=> $b['cantidad']);
    return $p;
}

/** Incluidos al mes: ['prueba' => n, '<plan>' => n, …]. */
function credito_incluidos(string $r): array
{
    $d = credito_def($r);
    $j = json_decode(plataforma_cfg($d['pref'] . '_incluidos'), true);
    if (!is_array($j)) $j = $d['incluidos'];
    $out = ['prueba' => max(0, (int) ($j['prueba'] ?? $d['incluidos']['prueba'] ?? 0))];
    foreach (planes_mp() as $k => $_) $out[$k] = max(0, (int) ($j[$k] ?? $d['incluidos'][$k] ?? 0));
    return $out;
}

function credito_consultorio(int $cid): ?array
{
    $st = db()->prepare('SELECT id, nombre, plan, estado, email FROM consultorios WHERE id = ?');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

/* ------------------------------------------------------------------ *
 *  Saldo
 * ------------------------------------------------------------------ */
function credito_saldo(int $cid, string $r): array
{
    asegurar_creditos();
    $c = credito_consultorio($cid);
    $inc = credito_incluidos($r);
    $mes = 0;
    if ($c && $c['estado'] === 'activa') $mes = $inc[$c['plan']] ?? 0;
    elseif ($c && $c['estado'] === 'trial') $mes = $inc['prueba'];

    $st = db()->prepare("SELECT COALESCE(-SUM(cantidad),0) FROM timbres_mov
                         WHERE consultorio_id = ? AND recurso = ? AND bolsa = 'incluido' AND creado_en >= ?");
    $st->execute([$cid, $r, date('Y-m-01 00:00:00')]);
    $usados = max(0, (int) $st->fetchColumn());

    $st = db()->prepare("SELECT COALESCE(SUM(cantidad),0) FROM timbres_mov WHERE consultorio_id = ? AND recurso = ? AND bolsa = 'comprado'");
    $st->execute([$cid, $r]);
    $comprados = max(0, (int) $st->fetchColumn());

    $restan = max(0, $mes - $usados);
    return [
        'incluidos_mes'    => $mes,
        'incluidos_usados' => min($usados, $mes),
        'incluidos_restan' => $restan,
        'comprados'        => $comprados,
        'total'            => $restan + $comprados,
        'renuevan'         => date('Y-m-01', strtotime('first day of next month')),
    ];
}

/** Gasta 1 (primero lo incluido del mes). Devuelve el id del movimiento. */
function credito_consumir(int $cid, string $r, string $referencia, string $detalle, string $quien = ''): int
{
    asegurar_creditos();
    $d = credito_def($r);
    $pdo = db();
    $lock = 'credito_' . $r . '_' . $cid;
    $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 10)');
    try {
        $s = credito_saldo($cid, $r);
        if ($s['total'] < 1) throw new RuntimeException('Ya no tienes ' . $d['nombre'] . '. Compra un paquete para continuar.');
        $bolsa = $s['incluidos_restan'] > 0 ? 'incluido' : 'comprado';
        $pdo->prepare("INSERT INTO timbres_mov (consultorio_id, recurso, tipo, bolsa, cantidad, referencia, detalle, usuario_nombre)
                       VALUES (?, ?, 'consumo', ?, -1, ?, ?, ?)")
            ->execute([$cid, $r, $bolsa, mb_substr($referencia, 0, 60), mb_substr($detalle, 0, 255), $quien ?: null]);
        return (int) $pdo->lastInsertId();
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}

/** Regresa lo gastado en un consumo que no llegó a usarse (rechazo del PAC o de Meta). */
function credito_devolver(int $movId, string $motivo): void
{
    asegurar_creditos();
    $st = db()->prepare("SELECT * FROM timbres_mov WHERE id = ? AND tipo = 'consumo'");
    $st->execute([$movId]);
    if (!$m = $st->fetch()) return;
    $ya = db()->prepare("SELECT 1 FROM timbres_mov WHERE tipo = 'devolucion' AND referencia = ?");
    $ya->execute(['mov:' . $movId]);
    if ($ya->fetchColumn()) return;
    db()->prepare("INSERT INTO timbres_mov (consultorio_id, recurso, tipo, bolsa, cantidad, referencia, detalle)
                   VALUES (?, ?, 'devolucion', ?, 1, ?, ?)")
        ->execute([$m['consultorio_id'], $m['recurso'], $m['bolsa'], 'mov:' . $movId, mb_substr($motivo, 0, 255)]);
}

/** Ajuste manual desde la plataforma (cortesía o corrección), a la bolsa comprada. */
function credito_ajustar(int $cid, string $r, int $cantidad, string $motivo, string $quien): void
{
    asegurar_creditos();
    if ($cantidad === 0) return;
    if ($cantidad < 0 && credito_saldo($cid, $r)['comprados'] + $cantidad < 0) {
        throw new RuntimeException('No puedes quitar más de lo que tiene comprado.');
    }
    db()->prepare("INSERT INTO timbres_mov (consultorio_id, recurso, tipo, bolsa, cantidad, referencia, detalle, usuario_nombre)
                   VALUES (?, ?, 'ajuste', 'comprado', ?, 'plataforma', ?, ?)")
        ->execute([$cid, $r, $cantidad, mb_substr($motivo, 0, 255), $quien]);
}

/* ------------------------------------------------------------------ *
 *  Compra de paquetes (Mercado Pago de la plataforma)
 * ------------------------------------------------------------------ */
function credito_iniciar_compra(int $cid, string $r, int $ix, string $quien, string $email): string
{
    asegurar_creditos();
    $d = credito_def($r);
    if (!credito_venta_activa($r)) throw new RuntimeException('La venta de paquetes aún no está abierta.');
    if (!mp_configurado())         throw new RuntimeException('Los pagos en línea no están disponibles en este momento.');
    $paq = credito_paquetes($r)[$ix] ?? null;
    if (!$paq) throw new RuntimeException('Ese paquete ya no existe. Recarga la página.');

    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO timbres_compras (consultorio_id, recurso, timbres, monto, token, usuario_nombre) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$cid, $r, $paq['cantidad'], $paq['precio'], $token, $quien ?: null]);
    $id = (int) db()->lastInsertId();

    $payload = [
        'items' => [[
            'id'          => $r . '-' . $paq['cantidad'],
            'title'       => sprintf($d['titulo'], number_format($paq['cantidad'])) . ' · ' . APP_NAME,
            'description' => 'Prepago, no caduca.',
            'quantity'    => 1,
            'unit_price'  => (float) $paq['precio'],
            'currency_id' => 'MXN',
        ]],
        'external_reference' => 'creditos:' . $id,
        'notification_url'   => url_absoluta('/timbres/webhook'),
        'back_urls' => [
            'success' => url_absoluta('/timbres/retorno?t=' . $token),
            'pending' => url_absoluta('/timbres/retorno?t=' . $token),
            'failure' => url_absoluta('/timbres/retorno?t=' . $token),
        ],
        'auto_return'          => 'approved',
        'statement_descriptor' => 'MEDIAGENDA',
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $payload['payer']['email'] = $email;

    $pref = mp_request('POST', '/checkout/preferences', $payload);
    $url = mp_es_sandbox() ? ($pref['sandbox_init_point'] ?? $pref['init_point'] ?? '') : ($pref['init_point'] ?? '');
    if ($url === '') throw new RuntimeException('Mercado Pago no devolvió la página de pago.');
    db()->prepare('UPDATE timbres_compras SET mp_pref_id = ? WHERE id = ?')->execute([(string) ($pref['id'] ?? ''), $id]);
    return $url;
}

/**
 * Consulta el pago a Mercado Pago y, si está aprobado y cuadra el importe,
 * acredita la compra. Idempotente. 'pagado' | 'pendiente' | 'rechazado' | 'ignorado'.
 */
function credito_confirmar_pago(string $pagoId): string
{
    asegurar_creditos();
    $p = mp_request('GET', '/v1/payments/' . rawurlencode($pagoId));
    if (!preg_match('/^(?:timbres|creditos):(\d+)$/', (string) ($p['external_reference'] ?? ''), $m)) return 'ignorado';
    $st = db()->prepare('SELECT * FROM timbres_compras WHERE id = ?');
    $st->execute([(int) $m[1]]);
    if (!$c = $st->fetch()) return 'ignorado';
    $d = credito_def($c['recurso'] ?: 'timbre');

    $estado = (string) ($p['status'] ?? '');
    if ($estado === 'rejected' || $estado === 'cancelled') {
        db()->prepare("UPDATE timbres_compras SET estado = 'rechazado', mp_pago_id = ? WHERE id = ? AND estado = 'pendiente'")
            ->execute([$pagoId, $c['id']]);
        return 'rechazado';
    }
    if ($estado !== 'approved') return 'pendiente';
    if (strtoupper((string) ($p['currency_id'] ?? '')) !== 'MXN'
        || (float) ($p['transaction_amount'] ?? 0) + 0.01 < (float) $c['monto']) {
        auditar('credito_pago_no_cuadra', $d['clave'], (int) $c['id'], 'pago ' . $pagoId, (int) $c['consultorio_id'], ['nombre' => 'Mercado Pago']);
        return 'ignorado';
    }

    $up = db()->prepare("UPDATE timbres_compras SET estado = 'pagado', mp_pago_id = ?, pagado_en = NOW() WHERE id = ? AND estado <> 'pagado'");
    $up->execute([$pagoId, $c['id']]);
    if ($up->rowCount() === 1) {
        $det = sprintf($d['titulo'], number_format($c['timbres'])) . ' · $' . number_format($c['monto'], 2);
        db()->prepare("INSERT INTO timbres_mov (consultorio_id, recurso, tipo, bolsa, cantidad, referencia, detalle, usuario_nombre)
                       VALUES (?, ?, 'compra', 'comprado', ?, ?, ?, ?)")
            ->execute([$c['consultorio_id'], $d['clave'], (int) $c['timbres'], 'compra:' . $c['id'], $det, $c['usuario_nombre']]);
        auditar('credito_compra', $d['clave'], (int) $c['id'], $det, (int) $c['consultorio_id'], ['nombre' => 'Mercado Pago']);
    }
    return 'pagado';
}

/* ------------------------------------------------------------------ *
 *  Atajos de timbres (los usa la pantalla de facturación)
 * ------------------------------------------------------------------ */
function cfdi_venta_activa(): bool { return credito_venta_activa('timbre'); }
function timbres_cancelar_cobra(): bool { return plataforma_cfg('timbres_cancelar_cobra') === '1'; }
function timbres_saldo(int $cid): array { return credito_saldo($cid, 'timbre'); }
function timbres_consumir(int $cid, string $ref, string $det, string $quien = ''): int { return credito_consumir($cid, 'timbre', $ref, $det, $quien); }
function timbres_devolver(int $movId, string $motivo): void { credito_devolver($movId, $motivo); }
