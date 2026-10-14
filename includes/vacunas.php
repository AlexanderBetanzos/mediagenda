<?php
/**
 * Vacunación: catálogo por consultorio, cartilla por paciente y próximas dosis.
 *
 * - vacunas_catalogo: las vacunas que maneja la clínica, con su intervalo de
 *   refuerzo en días (opcional) para calcular sola la próxima dosis. En
 *   veterinaria cada vacuna va por especie (Perro, Gato o Todas).
 * - vacunas_aplicadas: cada aplicación con dosis, lote, quién la aplicó y la
 *   fecha de la próxima. De ahí salen la cartilla y la lista de pendientes.
 *
 * El catálogo inicial es un punto de partida, no un esquema oficial: cada
 * clínica lo ajusta (nombres e intervalos) a su práctica y a la normativa.
 */

function asegurar_vacunas(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS vacunas_catalogo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        nombre VARCHAR(120) NOT NULL,
        especie VARCHAR(40) NOT NULL DEFAULT '',
        refuerzo_dias INT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_vc_tenant (consultorio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS vacunas_aplicadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        paciente_id INT NOT NULL,
        vacuna_id INT NULL,
        nombre VARCHAR(120) NOT NULL,
        dosis VARCHAR(40) NULL,
        lote VARCHAR(60) NULL,
        fecha DATE NOT NULL,
        proxima DATE NULL,
        aplicada_por INT NULL,
        notas VARCHAR(255) NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_va_pac (consultorio_id, paciente_id),
        INDEX idx_va_prox (consultorio_id, proxima)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* Catálogo inicial la primera vez (según la interfaz del consultorio). */
    $tid = (int) tenant_id();
    $n = $pdo->prepare('SELECT COUNT(*) FROM vacunas_catalogo WHERE consultorio_id = ?');
    $n->execute([$tid]);
    if ((int) $n->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO vacunas_catalogo (consultorio_id, nombre, especie, refuerzo_dias) VALUES (?,?,?,?)');
    if (function_exists('es_veterinaria') && es_veterinaria()) {
        $base = [
            ['Parvovirus (cachorro)', 'Perro', 21], ['Polivalente / Múltiple', 'Perro', 365], ['Rabia', 'Perro', 365],
            ['Bordetella (tos de las perreras)', 'Perro', 365], ['Leptospirosis', 'Perro', 365],
            ['Triple felina', 'Gato', 365], ['Leucemia felina', 'Gato', 365], ['Rabia', 'Gato', 365],
            ['Desparasitación interna', 'Todas', 90], ['Desparasitación externa', 'Todas', 30],
        ];
    } else {
        $base = [
            ['BCG', '', null], ['Hepatitis B', '', null], ['Hexavalente', '', null], ['Rotavirus', '', null],
            ['Neumocócica conjugada', '', null], ['Influenza', '', 365], ['SRP (sarampión, rubéola y parotiditis)', '', null],
            ['DPT', '', null], ['Hepatitis A', '', null], ['Varicela', '', null], ['VPH', '', null],
            ['Td (tétanos y difteria)', '', 3650], ['COVID-19', '', null],
        ];
    }
    foreach ($base as [$nom, $esp, $ref]) $ins->execute([$tid, $nom, $esp, $ref]);
}

/** Catálogo activo (o todo, para administrarlo). */
function vacunas_catalogo(bool $soloActivas = true): array
{
    asegurar_vacunas();
    $st = db()->prepare('SELECT * FROM vacunas_catalogo WHERE consultorio_id = ?' . ($soloActivas ? ' AND activo = 1' : '') . ' ORDER BY especie, nombre');
    $st->execute([tenant_id()]);
    return $st->fetchAll();
}

/** Próxima dosis: fecha + refuerzo (días). null si la vacuna no tiene refuerzo. */
function vacuna_proxima(string $fecha, ?int $refuerzoDias): ?string
{
    if (!$refuerzoDias || $refuerzoDias <= 0) return null;
    return date('Y-m-d', strtotime($fecha . " +$refuerzoDias days"));
}

/** Intervalo legible: 365 → "cada año", 21 → "cada 21 días". */
function vacuna_intervalo(?int $d): string
{
    if (!$d) return t('Sin refuerzo');
    if ($d % 365 === 0) return $d === 365 ? t('Cada año') : sprintf(t('Cada %d años'), $d / 365);
    if ($d % 30 === 0)  return $d === 30 ? t('Cada mes') : sprintf(t('Cada %d meses'), $d / 30);
    return sprintf(t('Cada %d días'), $d);
}

/** Mensaje de WhatsApp para recordar una vacuna (sin emojis, como los demás). */
function vacuna_wa_mensaje(array $p, string $vacuna, string $fecha): string
{
    $marca = marca_nombre();
    $vet = function_exists('es_veterinaria') && es_veterinaria();
    $vencida = $fecha < date('Y-m-d');
    $msg = $vet
        ? "¡Hola! Te escribimos de *{$marca}*. A *{$p['nombre']}* " . ($vencida ? 'ya le toca (desde el ' . fmt_fecha($fecha) . ')' : 'le toca el ' . fmt_fecha($fecha)) . " su *{$vacuna}*."
        : '¡Hola ' . trim(explode(' ', (string) $p['nombre'])[0]) . "! Te escribimos de *{$marca}*. Te recordamos que "
          . ($vencida ? 'ya te toca (desde el ' . fmt_fecha($fecha) . ')' : 'te toca el ' . fmt_fecha($fecha)) . " tu vacuna *{$vacuna}*.";
    if (function_exists('micrositio_visible') && micrositio_visible() && agenda_online_activa()) {
        $msg .= "\n\nAgenda tu cita aquí: " . micrositio_url();
    } else {
        $msg .= "\n\nResponde este mensaje para agendar.";
    }
    return $msg;
}
