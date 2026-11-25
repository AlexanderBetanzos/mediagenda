<?php
/**
 * Existencias del inventario / farmacia, por sucursal.
 *
 * El stock vive en LOTES (inventario_lotes): el total de un producto es la suma
 * de sus lotes. Cada lote pertenece a una sucursal (sucursal_id; NULL = sin
 * sucursal, que es todo lo de los consultorios de una sola sede). El catálogo
 * de productos y sus precios es uno para todo el consultorio; lo que cambia por
 * sede son las existencias.
 *
 * TODA entrada, salida o traspaso pasa por aquí: mueve los lotes y deja su
 * renglón en inventario_movimientos (la bitácora). Antes el punto de venta y
 * óptica solo escribían el movimiento y nunca bajaban los lotes, así que el
 * inventario no reflejaba lo vendido; inv_asegurar() lo concilia una vez.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/sucursales.php';

/** Columnas de sucursal y conciliación única de las ventas que no bajaron lotes. */
function inv_asegurar(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    try {
        $lot = array_column($pdo->query('SHOW COLUMNS FROM inventario_lotes')->fetchAll(), 'Field');
        if (!in_array('sucursal_id', $lot, true)) {
            $pdo->exec('ALTER TABLE inventario_lotes ADD COLUMN sucursal_id INT NULL, ADD INDEX idx_lote_suc (consultorio_id, sucursal_id, producto_id)');
        }
        $mov = array_column($pdo->query('SHOW COLUMNS FROM inventario_movimientos')->fetchAll(), 'Field');
        if (!in_array('sucursal_id', $mov, true)) {
            $pdo->exec('ALTER TABLE inventario_movimientos ADD COLUMN sucursal_id INT NULL, ADD COLUMN sucursal_destino_id INT NULL');
            inv_conciliar_ventas();   // solo la primera vez: cuando nace la columna
        }
    } catch (Throwable $e) {
        error_log('inv_asegurar: ' . $e->getMessage());
    }
}

/**
 * Las ventas del POS y los armazones de óptica registrados antes de este
 * cambio dejaron su movimiento de salida pero no bajaron los lotes. Se
 * descuentan ahora (FEFO), una sola vez, sin pasar de lo que hay.
 */
function inv_conciliar_ventas(): void
{
    $pdo = db();
    $st = $pdo->query("SELECT consultorio_id, producto_id, SUM(cantidad) AS n FROM inventario_movimientos
                       WHERE tipo = 'salida' AND (motivo LIKE 'Venta POS %' OR motivo LIKE 'Orden de óptica %')
                       GROUP BY consultorio_id, producto_id");
    foreach ($st->fetchAll() as $r) {
        inv_descontar_lotes((int) $r['consultorio_id'], (int) $r['producto_id'], (int) $r['n'], null);
    }
}

/** ¿Este consultorio lleva el inventario por sucursal? (tiene sucursales dadas de alta) */
function inv_por_sucursal(): bool
{
    return sucursales_permitidas() && count(sucursales()) > 0;
}

/** Sucursal válida del consultorio, o null. */
function inv_sucursal_valida($id): ?int
{
    $id = (int) $id;
    if (!$id) return null;
    foreach (sucursales() as $s) if ((int) $s['id'] === $id) return $id;
    return null;
}

/** Existencias de un producto (de una sucursal, o de todas si $suc es null). */
function inv_stock(int $pid, ?int $suc = null): int
{
    inv_asegurar();
    $sql = 'SELECT COALESCE(SUM(cantidad),0) FROM inventario_lotes WHERE consultorio_id = ? AND producto_id = ?';
    $p = [tenant_id(), $pid];
    if ($suc) { $sql .= ' AND sucursal_id = ?'; $p[] = $suc; }
    $st = db()->prepare($sql);
    $st->execute($p);
    return (int) $st->fetchColumn();
}

/** Existencias de todos los productos: [producto_id => [sucursal_id|0 => cantidad]]. */
function inv_stock_por_sucursal(): array
{
    inv_asegurar();
    $st = db()->prepare('SELECT producto_id, COALESCE(sucursal_id,0) AS s, SUM(cantidad) AS n
                         FROM inventario_lotes WHERE consultorio_id = ? GROUP BY producto_id, COALESCE(sucursal_id,0)');
    $st->execute([tenant_id()]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int) $r['producto_id']][(int) $r['s']] = (int) $r['n'];
    return $out;
}

/** Baja $cant de los lotes por FEFO (primero lo que caduca antes). Devuelve lo que sí pudo bajar. */
function inv_descontar_lotes(int $tid, int $pid, int $cant, ?int $suc): int
{
    $sql = 'SELECT id, cantidad FROM inventario_lotes WHERE consultorio_id = ? AND producto_id = ? AND cantidad > 0';
    $p = [$tid, $pid];
    if ($suc) { $sql .= ' AND sucursal_id = ?'; $p[] = $suc; }
    $st = db()->prepare($sql . ' ORDER BY caducidad IS NULL, caducidad ASC, id ASC');
    $st->execute($p);
    $upd = db()->prepare('UPDATE inventario_lotes SET cantidad = cantidad - ? WHERE id = ?');
    $hecho = 0;
    foreach ($st->fetchAll() as $l) {
        if ($hecho >= $cant) break;
        $usa = min($cant - $hecho, (int) $l['cantidad']);
        $upd->execute([$usa, $l['id']]);
        $hecho += $usa;
    }
    return $hecho;
}

/** Suma a un lote de la sucursal (lo crea si no existe uno igual). */
function inv_sumar_lote(int $pid, int $cant, ?int $suc, ?string $lote, ?string $cad): void
{
    $tid = tenant_id();
    $q = db()->prepare('SELECT id FROM inventario_lotes WHERE consultorio_id = ? AND producto_id = ?
                        AND (sucursal_id <=> ?) AND (lote <=> ?) AND (caducidad <=> ?) LIMIT 1');
    $q->execute([$tid, $pid, $suc, $lote, $cad]);
    if ($lid = $q->fetchColumn()) {
        db()->prepare('UPDATE inventario_lotes SET cantidad = cantidad + ? WHERE id = ?')->execute([$cant, $lid]);
    } else {
        db()->prepare('INSERT INTO inventario_lotes (consultorio_id, producto_id, sucursal_id, lote, caducidad, cantidad) VALUES (?,?,?,?,?,?)')
            ->execute([$tid, $pid, $suc, $lote, $cad, $cant]);
    }
}

function inv_movimiento(int $pid, string $tipo, int $cant, ?int $suc, ?string $motivo, array $extra = []): void
{
    db()->prepare('INSERT INTO inventario_movimientos (consultorio_id, producto_id, tipo, cantidad, motivo, proveedor, costo, usuario_id, sucursal_id, sucursal_destino_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([tenant_id(), $pid, $tipo, $cant, $motivo, $extra['proveedor'] ?? null, $extra['costo'] ?? null,
                   (int) (current_user()['id'] ?? 0) ?: null, $suc, $extra['destino'] ?? null]);
}

/** Entrada de mercancía a una sucursal. */
function inv_entrada(int $pid, int $cant, ?int $suc, ?string $lote = null, ?string $cad = null, ?string $motivo = null, ?string $prov = null, ?float $costo = null): void
{
    inv_asegurar();
    inv_sumar_lote($pid, $cant, $suc, $lote, $cad);
    inv_movimiento($pid, 'entrada', $cant, $suc, $motivo, ['proveedor' => $prov, 'costo' => $costo]);
}

/**
 * Salida (uso, venta, merma) de una sucursal; con $suc null, de cualquiera.
 * Con $estricta lanza si no alcanza; si no, baja lo que haya y registra la
 * salida completa (lo usa óptica: el armazón ya salió físicamente).
 */
function inv_salida(int $pid, int $cant, ?int $suc, ?string $motivo, bool $estricta = true): void
{
    inv_asegurar();
    if ($estricta && inv_stock($pid, $suc) < $cant) {
        throw new RuntimeException(sprintf(t('Stock insuficiente: hay %d disponibles.'), inv_stock($pid, $suc)));
    }
    inv_descontar_lotes(tenant_id(), $pid, $cant, $suc);
    inv_movimiento($pid, 'salida', $cant, $suc, $motivo);
}

/**
 * Traspaso entre sucursales: los lotes salen del origen (FEFO) y entran al
 * destino con el mismo número de lote y caducidad.
 */
function inv_traspaso(int $pid, int $cant, int $origen, int $destino, ?string $motivo): void
{
    inv_asegurar();
    if ($origen === $destino) throw new RuntimeException(t('El origen y el destino deben ser distintos.'));
    if (inv_stock($pid, $origen) < $cant) {
        throw new RuntimeException(sprintf(t('Stock insuficiente en el origen: hay %d disponibles.'), inv_stock($pid, $origen)));
    }
    $tid = tenant_id();
    $st = db()->prepare('SELECT id, lote, caducidad, cantidad FROM inventario_lotes WHERE consultorio_id = ? AND producto_id = ? AND sucursal_id = ? AND cantidad > 0
                         ORDER BY caducidad IS NULL, caducidad ASC, id ASC');
    $st->execute([$tid, $pid, $origen]);
    $upd = db()->prepare('UPDATE inventario_lotes SET cantidad = cantidad - ? WHERE id = ?');
    $resta = $cant;
    foreach ($st->fetchAll() as $l) {
        if ($resta <= 0) break;
        $usa = min($resta, (int) $l['cantidad']);
        $upd->execute([$usa, $l['id']]);
        inv_sumar_lote($pid, $usa, $destino, $l['lote'], $l['caducidad']);
        $resta -= $usa;
    }
    inv_movimiento($pid, 'ajuste', $cant, $origen, $motivo ?: t('Traspaso entre sucursales'), ['destino' => $destino]);
}

/** Piezas que quedaron sin sucursal (de antes de dar de alta sedes). */
function inv_sin_sucursal(): int
{
    inv_asegurar();
    $st = db()->prepare('SELECT COALESCE(SUM(cantidad),0) FROM inventario_lotes WHERE consultorio_id = ? AND sucursal_id IS NULL');
    $st->execute([tenant_id()]);
    return (int) $st->fetchColumn();
}

/** Asigna todo lo que no tiene sucursal a una (una sola vez, al empezar a usar sedes). */
function inv_asignar_sin_sucursal(int $suc): int
{
    inv_asegurar();
    $st = db()->prepare('UPDATE inventario_lotes SET sucursal_id = ? WHERE consultorio_id = ? AND sucursal_id IS NULL');
    $st->execute([$suc, tenant_id()]);
    return $st->rowCount();
}

/** Nombre de una sucursal para mostrar. */
function inv_sucursal_nombre(?int $id): string
{
    if (!$id) return t('Sin sucursal');
    foreach (sucursales() as $s) if ((int) $s['id'] === $id) return $s['nombre'];
    return t('Sucursal dada de baja');
}
