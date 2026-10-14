<?php
/**
 * Abonos (pagos parciales) de un cobro/factura.
 *
 * - Cada abono guarda monto, método, quién lo cobró y cuándo: entra en el
 *   cierre de caja de esa persona el día que se cobró (ver cortes.php).
 * - Cuando la suma de abonos cubre el total, la factura pasa a 'pagada'. Esa
 *   factura NO se marca con cobrado_en: su dinero ya entró por los abonos y
 *   así no se cuenta dos veces en la caja.
 * - "Por cobrar" en todo el sistema es el SALDO: total − abonos.
 */

function asegurar_abonos(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS factura_pagos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        factura_id INT NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        metodo VARCHAR(40) NULL,
        fecha DATE NOT NULL,
        notas VARCHAR(255) NULL,
        cobrado_por INT NULL,
        cobrado_en DATETIME NOT NULL,
        INDEX idx_fp_fac (consultorio_id, factura_id),
        INDEX idx_fp_cobro (consultorio_id, cobrado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Abonos de una factura (más reciente primero). */
function abonos_de(int $facturaId): array
{
    try {
        asegurar_abonos();
        $st = db()->prepare('SELECT a.*, u.nombre quien FROM factura_pagos a LEFT JOIN usuarios u ON u.id = a.cobrado_por
                             WHERE a.consultorio_id = ? AND a.factura_id = ? ORDER BY a.cobrado_en DESC, a.id DESC');
        $st->execute([tenant_id(), $facturaId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function abonos_total(int $facturaId): float
{
    return (float) array_sum(array_column(abonos_de($facturaId), 'monto'));
}

/**
 * Registra un abono. Si con él se cubre el total, la factura queda pagada
 * (con el método del último abono, o "Mixto" si hubo varios). Devuelve el
 * saldo que queda.
 */
function registrar_abono(int $facturaId, float $monto, string $metodo, ?string $fecha = null, string $notas = ''): float
{
    asegurar_abonos();
    $pdo = db();
    $tid = (int) tenant_id();
    $st = $pdo->prepare('SELECT total, estado FROM facturas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$facturaId, $tid]);
    $f = $st->fetch();
    if (!$f || $f['estado'] === 'cancelada') return 0.0;

    $saldo = max(0, round((float) $f['total'] - abonos_total($facturaId), 2));
    $monto = min(round($monto, 2), $saldo);
    if ($monto <= 0) return $saldo;

    $pdo->prepare('INSERT INTO factura_pagos (consultorio_id, factura_id, monto, metodo, fecha, notas, cobrado_por, cobrado_en)
                   VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$tid, $facturaId, $monto, $metodo ?: null, $fecha ?: date('Y-m-d'), mb_substr($notas, 0, 255) ?: null, (int) (current_user()['id'] ?? 0) ?: null]);
    auditar('abono', 'factura', $facturaId, fmt_money($monto) . ' · ' . $metodo);

    $saldo = max(0, round($saldo - $monto, 2));
    if ($saldo <= 0.004) {
        $metodos = array_unique(array_filter(array_column(abonos_de($facturaId), 'metodo')));
        $pdo->prepare("UPDATE facturas SET estado = 'pagada', metodo_pago = ? WHERE id = ? AND consultorio_id = ?")
            ->execute([count($metodos) > 1 ? 'Mixto' : ($metodos[0] ?? $metodo), $facturaId, $tid]);
    }
    return $saldo;
}

/**
 * Expresión SQL del saldo de una factura (alias $a) para sumar "por cobrar".
 * Si la tabla de abonos aún no existe, cae al total.
 */
function sql_saldo_factura(string $a = 'f'): string
{
    static $hay = null;
    if ($hay === null) {
        try { $hay = (bool) db()->query("SHOW TABLES LIKE 'factura_pagos'")->fetch(); } catch (Throwable $e) { $hay = false; }
    }
    return $hay
        ? "($a.total - COALESCE((SELECT SUM(fp.monto) FROM factura_pagos fp WHERE fp.factura_id = $a.id AND fp.consultorio_id = $a.consultorio_id), 0))"
        : "$a.total";
}
