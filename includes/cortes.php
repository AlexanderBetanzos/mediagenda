<?php
/**
 * Cierre de caja real — réplica de los cortes de Khronos, adaptada a MediOS.
 *
 * Un cierre no es un reporte (eso es /corte/index): es un acto. Quien cierra
 * declara con qué fondo abrió, lo que sacó de la caja y el efectivo que contó;
 * el sistema calcula lo que DEBERÍA haber y guarda la diferencia. Una vez
 * cerrado no se edita, queda en la auditoría y se avisa al dueño por correo.
 *
 * Dos alcances:
 *  - 'propio':  lo que cobró UNA cuenta (facturas.cobrado_por) desde su último
 *               cierre propio. Cada recepcionista/médico cierra lo suyo.
 *  - 'general': todo el consultorio desde el último cierre general. Solo admin.
 *
 * El periodo no es "el día": va del último cierre de ese alcance hasta ahora.
 * Así un cobro hecho después de cerrar no se pierde: cae en el siguiente
 * cierre, y dos turnos el mismo día se relevan solos.
 *
 * Relevo de caja: el fondo inicial se sugiere con lo que DEJÓ en la caja el
 * último cierre propio de cualquier cuenta (puede haber entregado el resto al
 * dueño). Se guarda de qué cierre se recibió.
 *
 * Solo cuentan los cobros con cobrado_en (registrados desde que existe esta
 * función): los anteriores siguen en el reporte por fechas de /corte/index.
 */

/** Columnas y tabla necesarias (sin migración manual). */
function asegurar_cortes(): void
{
    static $hecho = false;
    if ($hecho) return;
    $pdo = db();
    try {
        // Solo si falta: un ALTER en cada carga del dashboard bloquearía la tabla de facturas.
        if (!$pdo->query("SHOW COLUMNS FROM facturas LIKE 'cobrado_en'")->fetch())
        $pdo->exec("ALTER TABLE facturas ADD COLUMN IF NOT EXISTS cobrado_por INT NULL DEFAULT NULL,
                                         ADD COLUMN IF NOT EXISTS cobrado_en DATETIME NULL DEFAULT NULL");
        if (!$pdo->query("SHOW INDEX FROM facturas WHERE Key_name = 'idx_fac_cobro'")->fetch())
            $pdo->exec("ALTER TABLE facturas ADD INDEX idx_fac_cobro (consultorio_id, cobrado_en)");
    } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS cortes_caja (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        alcance VARCHAR(10) NOT NULL DEFAULT 'propio',
        usuario_id INT NULL,
        usuario_nombre VARCHAR(120) NOT NULL DEFAULT '',
        usuario_rol VARCHAR(20) NOT NULL DEFAULT '',
        desde DATETIME NULL,
        hasta DATETIME NOT NULL,
        fondo_inicial DECIMAL(10,2) NOT NULL DEFAULT 0,
        recibido_de_corte_id INT NULL,
        efectivo_cobrado DECIMAL(10,2) NOT NULL DEFAULT 0,
        retiros DECIMAL(10,2) NOT NULL DEFAULT 0,
        egresos_efectivo DECIMAL(10,2) NOT NULL DEFAULT 0,
        efectivo_esperado DECIMAL(10,2) NOT NULL DEFAULT 0,
        efectivo_contado DECIMAL(10,2) NOT NULL DEFAULT 0,
        dejado_en_caja DECIMAL(10,2) NULL,
        diferencia DECIMAL(10,2) NOT NULL DEFAULT 0,
        tarjeta_esperado DECIMAL(10,2) NOT NULL DEFAULT 0,
        tarjeta_verificado DECIMAL(10,2) NULL,
        transf_esperado DECIMAL(10,2) NOT NULL DEFAULT 0,
        transf_verificado DECIMAL(10,2) NULL,
        total_cobrado DECIMAL(10,2) NOT NULL DEFAULT 0,
        num_cobros INT NOT NULL DEFAULT 0,
        desglose TEXT NULL,
        notas VARCHAR(500) NULL,
        cerrado_en DATETIME NOT NULL,
        INDEX idx_cc_tenant (consultorio_id, cerrado_en),
        INDEX idx_cc_usuario (consultorio_id, alcance, usuario_id, cerrado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hecho = true;
}

/**
 * Anota quién cobró y cuándo. Se llama al crear una factura ya pagada y al
 * pasar una a 'pagada'. Si ya tenía cobro registrado no se pisa (marcarla
 * pagada dos veces no la mueve de cierre).
 */
function registrar_cobro(int $factura_id): void
{
    asegurar_cortes();
    $u = current_user();
    db()->prepare("UPDATE facturas SET cobrado_por = ?, cobrado_en = NOW()
                   WHERE id = ? AND consultorio_id = ? AND estado = 'pagada' AND cobrado_en IS NULL")
        ->execute([(int) ($u['id'] ?? 0) ?: null, $factura_id, tenant_id()]);
}

/** Grupo fijo del método de pago (texto libre en facturas/egresos). */
function cierre_metodo(?string $m): string
{
    $m = mb_strtolower(trim((string) $m));
    if ($m === '' || str_contains($m, 'efec')) return 'efectivo';
    if (str_contains($m, 'tarj')) return 'tarjeta';
    if (str_contains($m, 'transf') || str_contains($m, 'spei')) return 'transferencia';
    return 'otro';
}

function cierre_metodos(): array
{
    return ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia', 'otro' => 'Otro / en línea'];
}

/** Último cierre de un alcance (propio de $usuarioId, o general). */
function cierre_ultimo(string $alcance, ?int $usuarioId = null): ?array
{
    asegurar_cortes();
    $sql = "SELECT * FROM cortes_caja WHERE consultorio_id = ? AND alcance = ?";
    $p   = [tenant_id(), $alcance];
    if ($alcance === 'propio') { $sql .= " AND usuario_id = ?"; $p[] = $usuarioId; }
    $st = db()->prepare($sql . " ORDER BY cerrado_en DESC, id DESC LIMIT 1");
    $st->execute($p);
    return $st->fetch() ?: null;
}

/** Cierre propio más reciente de CUALQUIER cuenta: de ahí se recibe la caja. */
function cierre_relevo(): ?array
{
    asegurar_cortes();
    $st = db()->prepare("SELECT * FROM cortes_caja WHERE consultorio_id = ? AND alcance = 'propio'
                         ORDER BY cerrado_en DESC, id DESC LIMIT 1");
    $st->execute([tenant_id()]);
    return $st->fetch() ?: null;
}

/**
 * Lo que entra en el próximo cierre de un alcance: cobros y egresos en
 * efectivo desde el último cierre hasta ahora, con totales por método.
 */
function cierre_pendiente(string $alcance, ?int $usuarioId = null): array
{
    asegurar_cortes();
    $pdo    = db();
    $tid    = (int) tenant_id();
    $ultimo = cierre_ultimo($alcance, $usuarioId);
    $desde  = $ultimo['cerrado_en'] ?? null;

    $sql = "SELECT f.id, f.folio, f.total, f.metodo_pago, f.cobrado_en, f.notas, p.nombre, p.apellidos, u.nombre cobrador
            FROM facturas f JOIN pacientes p ON p.id = f.paciente_id
            LEFT JOIN usuarios u ON u.id = f.cobrado_por
            WHERE f.consultorio_id = ? AND f.estado = 'pagada' AND f.cobrado_en IS NOT NULL";
    $p = [$tid];
    if ($desde) { $sql .= " AND f.cobrado_en > ?"; $p[] = $desde; }
    if ($alcance === 'propio') { $sql .= " AND f.cobrado_por = ?"; $p[] = $usuarioId; }
    $st = $pdo->prepare($sql . " ORDER BY f.cobrado_en DESC, f.id DESC");
    $st->execute($p);
    $cobros = $st->fetchAll();

    /* Abonos (pagos parciales): cada uno entra en la caja de quien lo cobró. */
    try {
        require_once __DIR__ . '/abonos.php';
        asegurar_abonos();
        $sql = "SELECT f.id, CONCAT(f.folio, ' · abono') folio, a.monto total, a.metodo metodo_pago, a.cobrado_en, f.notas,
                       p.nombre, p.apellidos, u.nombre cobrador
                FROM factura_pagos a JOIN facturas f ON f.id = a.factura_id JOIN pacientes p ON p.id = f.paciente_id
                LEFT JOIN usuarios u ON u.id = a.cobrado_por
                WHERE a.consultorio_id = ?";
        $p = [$tid];
        if ($desde) { $sql .= " AND a.cobrado_en > ?"; $p[] = $desde; }
        if ($alcance === 'propio') { $sql .= " AND a.cobrado_por = ?"; $p[] = $usuarioId; }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $cobros = array_merge($cobros, $st->fetchAll());
        usort($cobros, fn ($x, $y) => strcmp((string) $y['cobrado_en'], (string) $x['cobrado_en']));
    } catch (Throwable $e) {}

    $porMetodo = array_fill_keys(array_keys(cierre_metodos()), 0.0);
    $total = 0.0;
    foreach ($cobros as $c) {
        $porMetodo[cierre_metodo($c['metodo_pago'])] += (float) $c['total'];
        $total += (float) $c['total'];
    }

    /* Egresos pagados en efectivo en el periodo: salen de la caja. En el
       cierre propio, solo los que registró esa cuenta. */
    $egresos = 0.0;
    try {
        $sql = "SELECT metodo_pago, monto FROM egresos WHERE consultorio_id = ?";
        $p = [$tid];
        if ($desde) { $sql .= " AND creado_en > ?"; $p[] = $desde; }
        else        { $sql .= " AND fecha = CURDATE()"; }
        if ($alcance === 'propio') { $sql .= " AND usuario_id = ?"; $p[] = $usuarioId; }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        foreach ($st->fetchAll() as $e) {
            if (cierre_metodo($e['metodo_pago']) === 'efectivo') $egresos += (float) $e['monto'];
        }
    } catch (Throwable $e) { /* el módulo de egresos aún no crea su tabla */ }

    return [
        'desde'     => $desde,
        'ultimo'    => $ultimo,
        'cobros'    => $cobros,
        'num'       => count($cobros),
        'total'     => $total,
        'porMetodo' => $porMetodo,
        'egresos'   => $egresos,
    ];
}

/** Lo que quedó en la caja al cerrar: el fondo de quien sigue. */
function cierre_dejado(array $c): float
{
    return $c['dejado_en_caja'] !== null ? (float) $c['dejado_en_caja'] : (float) $c['efectivo_contado'];
}

/** Estado de hoy para la franja del dashboard: cerrado, pendiente o sin cobros. */
function cierre_estado_hoy(string $alcance, ?int $usuarioId = null): array
{
    $pend   = cierre_pendiente($alcance, $usuarioId);
    $ultimo = $pend['ultimo'];
    $hoy    = $ultimo && substr($ultimo['cerrado_en'], 0, 10) === date('Y-m-d');
    return [
        'cerrado_hoy' => $hoy ? $ultimo : null,
        'num'         => $pend['num'],
        'total'       => $pend['total'],
    ];
}

/** Correo al dueño (admins del consultorio) con el resumen del cierre. */
function cierre_avisar(array $c): void
{
    require_once __DIR__ . '/correo.php';
    try {
        $st = db()->prepare("SELECT email FROM usuarios WHERE consultorio_id = ? AND rol = 'admin' AND activo = 1
                             AND email IS NOT NULL AND email <> ''");
        $st->execute([tenant_id()]);
        $para = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $para = []; }
    if (!$para) return;

    $dif   = (float) $c['diferencia'];
    $tono  = abs($dif) < 0.005 ? '#16a34a' : ($dif < 0 ? '#dc2626' : '#d97706');
    $txDif = abs($dif) < 0.005 ? 'Cuadró exacto' : ($dif < 0 ? 'Faltante de ' . fmt_money(-$dif) : 'Sobrante de ' . fmt_money($dif));
    $fila  = fn ($k, $v) => '<tr><td style="padding:6px 0;color:#64748b">' . e($k) . '</td><td style="padding:6px 0;text-align:right;font-weight:600">' . $v . '</td></tr>';

    $cuerpo = '<strong>' . e($c['usuario_nombre']) . '</strong> cerró ' . ($c['alcance'] === 'general' ? 'el corte general' : 'su corte')
        . ' el ' . e(date('d/m/Y H:i', strtotime($c['cerrado_en']))) . '.<br><br>'
        . '<div style="font-size:20px;font-weight:800;color:' . $tono . '">' . e($txDif) . '</div><br>'
        . '<table width="100%" style="font-size:14px;border-collapse:collapse">'
        . $fila('Cobrado (' . (int) $c['num_cobros'] . ' cobros)', fmt_money($c['total_cobrado']))
        . $fila('Fondo inicial', fmt_money($c['fondo_inicial']))
        . $fila('Efectivo cobrado', fmt_money($c['efectivo_cobrado']))
        . $fila('Retiros y egresos en efectivo', '−' . fmt_money((float) $c['retiros'] + (float) $c['egresos_efectivo']))
        . $fila('Efectivo esperado', fmt_money($c['efectivo_esperado']))
        . $fila('Efectivo contado', fmt_money($c['efectivo_contado']))
        . ($c['dejado_en_caja'] !== null ? $fila('Dejado en caja', fmt_money($c['dejado_en_caja'])) : '')
        . '</table>'
        . ($c['notas'] ? '<br><em>“' . e($c['notas']) . '”</em>' : '');

    $html = correo_layout('Cierre de caja · ' . $txDif, $cuerpo, 'Ver el cierre', correo_url('/corte/cierre?id=' . (int) $c['id']), 'Corte de caja');
    foreach ($para as $email) enviar_correo($email, 'Cierre de caja: ' . $c['usuario_nombre'] . ' · ' . $txDif, $html);
}
