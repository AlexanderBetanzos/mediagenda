<?php
/**
 * Cobros en línea: petición de pago con token público, pagada con la cuenta de
 * Mercado Pago del consultorio. Al confirmarse, si el cobro venía de un
 * presupuesto, se registra el abono correspondiente.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mercadopago.php';

/** Crea un cobro pendiente y devuelve su id. */
function cobro_crear(int $paciente_id, float $monto, string $concepto, ?int $presupuesto_id = null): int
{
    db()->prepare(
        'INSERT INTO cobros (consultorio_id, paciente_id, presupuesto_id, token, concepto, monto, creado_por)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        tenant_id(), $paciente_id, $presupuesto_id ?: null,
        bin2hex(random_bytes(16)),
        mb_substr(trim($concepto), 0, 160),
        round($monto, 2),
        current_user()['id'] ?? null,
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Busca un cobro por su token público. No filtra por tenant a propósito: el
 * token ES la credencial y el consultorio se deduce de la fila encontrada.
 * El llamador debe hacer tenant_forzar() con lo que devuelve.
 */
function cobro_por_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM cobros WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** Cobro por id, dentro del consultorio activo. */
function cobro_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM cobros WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    return $st->fetch() ?: null;
}

/** URL pública que se comparte con el paciente. */
function cobro_url(array $cobro): string
{
    return url_absoluta('/pago/index?t=' . $cobro['token']);
}

/**
 * Marca el cobro como pagado y, si nació de un presupuesto, registra el abono.
 *
 * Es idempotente: Mercado Pago reintenta el webhook varias veces por el mismo
 * pago, y sin esto el paciente aparecería abonando dos o tres veces. La
 * condición `estado = 'pendiente'` en el UPDATE es la que garantiza que solo
 * un intento gane, incluso si dos llegan a la vez.
 *
 * @return bool true si este intento fue el que lo marcó (y registró el abono).
 */
function cobro_marcar_pagado(array $cobro, string $payment_id): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            "UPDATE cobros SET estado = 'pagado', mp_payment_id = ?, pagado_en = NOW()
             WHERE id = ? AND estado = 'pendiente'"
        );
        $upd->execute([$payment_id, (int) $cobro['id']]);
        if ($upd->rowCount() === 0) {   // ya estaba pagado o cancelado
            $pdo->commit();
            return false;
        }

        if ($cobro['presupuesto_id']) {
            $pdo->prepare(
                'INSERT INTO presupuesto_pagos
                 (consultorio_id, presupuesto_id, fecha, monto, metodo, referencia, notas, usuario_id, cobro_id)
                 VALUES (?,?,?,?,?,?,?,NULL,?)'
            )->execute([
                (int) $cobro['consultorio_id'], (int) $cobro['presupuesto_id'], date('Y-m-d'),
                $cobro['monto'], 'Mercado Pago', $payment_id, 'Pago en línea', (int) $cobro['id'],
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Consulta el pago en Mercado Pago y actualiza el cobro si está aprobado.
 * La fuente de verdad es siempre la API, nunca el cuerpo de la notificación:
 * el webhook es público y cualquiera podría inventarse un aviso.
 *
 * @return string 'pagado' | 'ignorado' | 'no_aprobado'
 */
function cobro_confirmar_pago(string $payment_id): string
{
    $pago = mp_tenant_request('GET', '/v1/payments/' . rawurlencode($payment_id));

    if (($pago['status'] ?? '') !== 'approved') {
        return 'no_aprobado';
    }
    if (!preg_match('/^cobro:(\d+)$/', (string) ($pago['external_reference'] ?? ''), $m)) {
        return 'ignorado';
    }
    $cobro = cobro_por_id((int) $m[1]);
    if (!$cobro) {
        return 'ignorado';   // el cobro es de otro consultorio: no lo tocamos
    }

    // El importe cobrado debe coincidir con el pedido, por si alguien manipuló
    // la preferencia antes de pagar.
    if (round((float) ($pago['transaction_amount'] ?? 0), 2) !== round((float) $cobro['monto'], 2)) {
        return 'ignorado';
    }

    return cobro_marcar_pagado($cobro, (string) $pago['id']) ? 'pagado' : 'ignorado';
}
