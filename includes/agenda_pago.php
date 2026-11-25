<?php
/**
 * Pago al agendar en línea (anticipo o total) — reduce las faltas.
 *
 * Modos (cfg agenda_pago_modo):
 *   opcional  el paciente puede pagar en línea o en el consultorio (como antes)
 *   anticipo  debe pagar un anticipo (porcentaje o monto) para asegurar su lugar
 *   total     debe pagar la consulta completa para asegurar su lugar
 * Con anticipo/total la cita queda APARTADA y el paciente tiene N minutos
 * (agenda_pago_minutos) para pagar; si no paga, el horario se libera solo.
 * Aplica a todos o solo a pacientes nuevos (agenda_pago_aplica).
 *
 * Reembolso: si el paciente cancela con al menos agenda_reembolso_horas de
 * anticipación (0 = nunca automático), se le devuelve por Mercado Pago.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mercadopago.php';
require_once __DIR__ . '/cobros.php';

function asegurar_agenda_pago(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $cols = array_column(db()->query('SHOW COLUMNS FROM citas')->fetchAll(), 'Field');
        $add = [];
        foreach (['pago_requerido' => 'TINYINT(1) NOT NULL DEFAULT 0', 'pago_vence' => 'DATETIME NULL', 'pago_estado' => 'VARCHAR(12) NULL', 'pago_monto' => 'DECIMAL(10,2) NULL'] as $c => $d) {
            if (!in_array($c, $cols, true)) $add[] = "ADD COLUMN $c $d";
        }
        if ($add) db()->exec('ALTER TABLE citas ' . implode(', ', $add));
        if (!db()->query("SHOW COLUMNS FROM cobros LIKE 'reembolso_en'")->fetch()) {
            db()->exec('ALTER TABLE cobros ADD COLUMN reembolso_en DATETIME NULL, ADD COLUMN reembolso_id VARCHAR(64) NULL');
        }
    } catch (Throwable $e) {}
}

function agenda_pago_modo(): string
{
    $m = (string) cfg('agenda_pago_modo', 'opcional');
    return in_array($m, ['opcional', 'anticipo', 'total'], true) ? $m : 'opcional';
}

/**
 * ¿Cuánto y si es obligatorio, para este paciente? null = no hay cobro.
 * @return array{monto:float, obligatorio:bool, precio:float}|null
 */
function agenda_pago_calcular(float $precio, ?int $pacienteId): ?array
{
    if ($precio <= 0 || !mp_tenant_habilitado()) return null;
    $modo = agenda_pago_modo();
    if ($modo === 'opcional') return ['monto' => $precio, 'obligatorio' => false, 'precio' => $precio];
    if (cfg('agenda_pago_aplica', 'todos') === 'nuevos' && $pacienteId) {
        $st = db()->prepare("SELECT COUNT(*) FROM citas WHERE consultorio_id = ? AND paciente_id = ? AND estado = 'atendida'");
        $st->execute([tenant_id(), $pacienteId]);
        if ((int) $st->fetchColumn() > 0) return ['monto' => $precio, 'obligatorio' => false, 'precio' => $precio];
    }
    if ($modo === 'total') return ['monto' => $precio, 'obligatorio' => true, 'precio' => $precio];
    $v = (float) cfg('agenda_anticipo_valor', '30');
    $monto = cfg('agenda_anticipo_tipo', 'porcentaje') === 'monto' ? min($v, $precio) : round($precio * max(1, min(100, $v)) / 100, 2);
    return ['monto' => max(1, $monto), 'obligatorio' => true, 'precio' => $precio];
}

function agenda_pago_minutos(): int { return max(10, min(1440, (int) cfg('agenda_pago_minutos', '30'))); }

/** Marca la cita recién creada como apartada esperando pago. */
function agenda_pago_apartar(int $citaId, float $monto): void
{
    asegurar_agenda_pago();
    db()->prepare("UPDATE citas SET pago_requerido = 1, pago_estado = 'pendiente', pago_monto = ?, pago_vence = ? WHERE id = ? AND consultorio_id = ?")
        ->execute([$monto, date('Y-m-d H:i:s', time() + agenda_pago_minutos() * 60), $citaId, tenant_id()]);
}

/** Libera los horarios apartados que no se pagaron a tiempo (del consultorio activo o de todos). */
function agenda_liberar_vencidas(?int $tid = null): int
{
    asegurar_agenda_pago();
    $w = $tid ? ' AND c.consultorio_id = ' . (int) $tid : '';
    $st = db()->query("SELECT c.id, c.consultorio_id FROM citas c WHERE c.pago_requerido = 1 AND c.pago_estado = 'pendiente'
                       AND c.estado = 'programada' AND c.pago_vence < '" . date('Y-m-d H:i:s') . "'$w LIMIT 500");
    $n = 0;
    foreach ($st->fetchAll() as $c) {
        $up = db()->prepare("UPDATE citas SET estado = 'cancelada', cancelada_en = NOW(), cancelada_por = 'consultorio', pago_estado = 'vencido',
                             notas = CONCAT(COALESCE(notas,''), '\n[Liberada: no se pagó el anticipo a tiempo]') WHERE id = ? AND estado = 'programada' AND pago_estado = 'pendiente'");
        $up->execute([$c['id']]);
        if ($up->rowCount()) {
            db()->prepare("UPDATE cobros SET estado = 'cancelado' WHERE cita_id = ? AND estado = 'pendiente'")->execute([$c['id']]);
            auditar('cita_liberada_sin_pago', 'cita', (int) $c['id'], null, (int) $c['consultorio_id'], ['nombre' => 'Sistema']);
            $n++;
        }
    }
    return $n;
}

/** Devuelve por Mercado Pago lo pagado en un cobro (requiere tenant forzado). */
function cobro_reembolsar(array $cobro, string $motivo): bool
{
    asegurar_agenda_pago();
    if ($cobro['estado'] !== 'pagado' || empty($cobro['mp_payment_id']) || !empty($cobro['reembolso_en'])) return false;
    try {
        $r = mp_tenant_request('POST', '/v1/payments/' . rawurlencode($cobro['mp_payment_id']) . '/refunds', ['amount' => round((float) $cobro['monto'], 2)]);
        db()->prepare('UPDATE cobros SET reembolso_en = NOW(), reembolso_id = ? WHERE id = ?')->execute([(string) ($r['id'] ?? ''), $cobro['id']]);
        if (!empty($cobro['cita_id'])) db()->prepare("UPDATE citas SET pago_estado = 'reembolsado' WHERE id = ?")->execute([$cobro['cita_id']]);
        auditar('cobro_reembolsado', 'cobro', (int) $cobro['id'], $motivo, (int) $cobro['consultorio_id'], ['nombre' => 'Sistema']);
        return true;
    } catch (Throwable $e) {
        auditar('cobro_reembolso_error', 'cobro', (int) $cobro['id'], mb_substr($e->getMessage(), 0, 200), (int) $cobro['consultorio_id'], ['nombre' => 'Sistema']);
        return false;
    }
}

/**
 * Después de que un cobro de cita se pagó: marca el pago en la cita. Si el
 * pago llegó tarde y el horario ya se había liberado, lo reactiva si sigue
 * libre; si alguien más lo tomó, reembolsa al paciente.
 */
function agenda_pago_recibido(array $cobro): void
{
    asegurar_agenda_pago();
    if (empty($cobro['cita_id'])) return;
    $st = db()->prepare('SELECT * FROM citas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$cobro['cita_id'], $cobro['consultorio_id']]);
    $c = $st->fetch();
    if (!$c) return;
    db()->prepare("UPDATE citas SET pago_estado = 'pagado', pago_monto = ? WHERE id = ?")->execute([$cobro['monto'], $c['id']]);
    if ($c['estado'] === 'cancelada' && ($c['pago_estado'] ?? '') === 'vencido') {
        if (agenda_hora_disponible((int) $c['medico_id'], $c['fecha'], substr($c['hora'], 0, 5), (int) $c['duracion'])) {
            db()->prepare("UPDATE citas SET estado = 'confirmada', confirmada_en = NOW(), cancelada_en = NULL, cancelada_por = NULL WHERE id = ?")->execute([$c['id']]);
            auditar('cita_reactivada_pago', 'cita', (int) $c['id'], null, (int) $c['consultorio_id'], ['nombre' => 'Sistema']);
        } else {
            $st = db()->prepare('SELECT * FROM cobros WHERE id = ?'); $st->execute([$cobro['id']]);
            cobro_reembolsar($st->fetch(), 'Pago tardío: el horario ya estaba ocupado');
        }
    }
}

/** Política que ve el paciente antes de pagar. */
function agenda_pago_politica(): string
{
    $h = (int) cfg('agenda_reembolso_horas', '24');
    $txt = trim((string) cfg('agenda_pago_politica', ''));
    if ($txt !== '') return $txt;
    return $h > 0
        ? sprintf(t('Si cancelas con al menos %d horas de anticipación, te devolvemos tu pago automáticamente. Después de ese plazo, el pago no es reembolsable.'), $h)
        : t('El pago no es reembolsable si no asistes o cancelas; comunícate con el consultorio si tienes un imprevisto.');
}
