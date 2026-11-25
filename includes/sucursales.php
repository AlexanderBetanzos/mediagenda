<?php
/**
 * Sucursales y recursos (agenda por consultorio, cuarto, sillón o equipo).
 *
 *  - Un consultorio puede tener varias SUCURSALES; cada franja del horario de
 *    un médico dice en qué sucursal atiende (medico_horarios.sucursal_id).
 *  - Cada sucursal tiene RECURSOS (consultorio 1, sillón dental 2, cuarto de
 *    infusión, ultrasonido…). Un recurso no se puede ocupar dos veces a la
 *    misma hora: la agenda lo valida y la agenda en línea solo ofrece horarios
 *    donde hay un recurso libre (y lo asigna sola).
 *  - Si un consultorio no da de alta sucursales ni recursos, todo funciona
 *    exactamente como antes (una sola sede, sin recursos).
 * Disponible en el plan Clínica y durante la prueba.
 */
require_once __DIR__ . '/functions.php';

function recurso_tipos(): array
{
    return ['consultorio' => 'Consultorio', 'sillon' => 'Sillón dental', 'cuarto' => 'Cuarto / cubículo', 'equipo' => 'Equipo (ultrasonido, láser…)', 'sala' => 'Sala de procedimientos'];
}

function asegurar_sucursales(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sucursales (
        id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL, nombre VARCHAR(120) NOT NULL,
        direccion VARCHAR(255) NULL, telefono VARCHAR(40) NULL, activa TINYINT(1) NOT NULL DEFAULT 1,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_c (consultorio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS recursos (
        id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL, sucursal_id INT NULL, nombre VARCHAR(120) NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'consultorio', en_linea TINYINT(1) NOT NULL DEFAULT 1, activo TINYINT(1) NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0, KEY idx_c (consultorio_id, sucursal_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try {
        foreach (['citas' => ['sucursal_id' => 'INT NULL', 'recurso_id' => 'INT NULL'], 'medico_horarios' => ['sucursal_id' => 'INT NULL']] as $t => $cols) {
            $ex = array_column($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(), 'Field');
            $add = [];
            foreach ($cols as $c => $d) if (!in_array($c, $ex, true)) $add[] = "ADD COLUMN $c $d";
            if ($add) $pdo->exec("ALTER TABLE $t " . implode(', ', $add));
        }
    } catch (Throwable $e) {}
}

function sucursales_permitidas(): bool
{
    $t = tenant();
    return $t && (($t['plan'] ?? '') === 'clinica' || ($t['estado'] ?? '') === 'trial' || es_superadmin());
}

/** Sucursales activas del consultorio. */
function sucursales(?int $tid = null): array
{
    asegurar_sucursales();
    $st = db()->prepare('SELECT * FROM sucursales WHERE consultorio_id = ? AND activa = 1 ORDER BY nombre');
    $st->execute([$tid ?? tenant_id()]);
    return $st->fetchAll();
}

/** Recursos activos (de una sucursal, o todos). */
function recursos(?int $sucursalId = null, bool $soloEnLinea = false): array
{
    asegurar_sucursales();
    $sql = 'SELECT r.*, s.nombre AS sucursal FROM recursos r LEFT JOIN sucursales s ON s.id = r.sucursal_id WHERE r.consultorio_id = ? AND r.activo = 1';
    $p = [tenant_id()];
    if ($sucursalId) { $sql .= ' AND (r.sucursal_id = ? OR r.sucursal_id IS NULL)'; $p[] = $sucursalId; }
    if ($soloEnLinea) $sql .= ' AND r.en_linea = 1';
    $st = db()->prepare($sql . ' ORDER BY r.orden, r.nombre');
    $st->execute($p);
    return $st->fetchAll();
}

/** Sucursal que se está viendo en el panel (0 = todas). */
function sucursal_actual(): int
{
    if (isset($_GET['sucursal'])) $_SESSION['sucursal_actual'] = (int) $_GET['sucursal'];
    $s = (int) ($_SESSION['sucursal_actual'] ?? 0);
    if ($s && !in_array($s, array_map(fn ($x) => (int) $x['id'], sucursales()), true)) $s = $_SESSION['sucursal_actual'] = 0;
    return $s;
}

/** ¿El recurso está libre en ese horario? (ignora la cita $excluir al editar) */
function recurso_libre(int $recursoId, string $fecha, string $hora, int $dur, int $excluir = 0): bool
{
    $ini = strtotime("$fecha $hora"); $fin = $ini + $dur * 60;
    $st = db()->prepare("SELECT hora, duracion FROM citas WHERE consultorio_id = ? AND recurso_id = ? AND fecha = ? AND id <> ?
                         AND estado NOT IN ('cancelada','no_asistio')");
    $st->execute([tenant_id(), $recursoId, $fecha, $excluir]);
    foreach ($st->fetchAll() as $c) {
        $ci = strtotime("$fecha {$c['hora']}"); $cf = $ci + (int) $c['duracion'] * 60;
        if ($ini < $cf && $fin > $ci) return false;
    }
    return true;
}

/** Primer recurso libre de la sucursal para ese horario (null si hay recursos y todos están ocupados; 0 si la sucursal no usa recursos). */
function recurso_asignar(?int $sucursalId, string $fecha, string $hora, int $dur): ?int
{
    $rs = recursos($sucursalId, true);
    if (!$rs) return 0;
    foreach ($rs as $r) if (recurso_libre((int) $r['id'], $fecha, $hora, $dur)) return (int) $r['id'];
    return null;
}

/** Sucursal donde atiende un médico ese día y hora, según su horario (null si no está definida). */
function sucursal_de_franja(int $medicoId, string $fecha, string $hora): ?int
{
    asegurar_sucursales();
    $st = db()->prepare('SELECT sucursal_id, hora_inicio, hora_fin FROM medico_horarios WHERE consultorio_id = ? AND medico_id = ? AND dia_semana = ?');
    $st->execute([tenant_id(), $medicoId, (int) date('w', strtotime($fecha))]);
    foreach ($st->fetchAll() as $f) {
        if ($hora >= substr($f['hora_inicio'], 0, 5) && $hora < substr($f['hora_fin'], 0, 5)) return $f['sucursal_id'] ? (int) $f['sucursal_id'] : null;
    }
    return null;
}

/** Nombre corto para mostrar. */
function recurso_nombre(?int $id): string
{
    if (!$id) return '';
    static $cache = [];
    if (!isset($cache[$id])) { $st = db()->prepare('SELECT nombre FROM recursos WHERE id = ?'); $st->execute([$id]); $cache[$id] = (string) $st->fetchColumn(); }
    return $cache[$id];
}

/**
 * Resuelve sucursal y recurso al guardar una cita desde el panel.
 * Devuelve [sucursal_id|null, recurso_id|null, error|''].
 */
function cita_resolver_recurso(array $c, int $excluir = 0): array
{
    asegurar_sucursales();
    $fecha = (string) $c['fecha']; $hora = substr((string) $c['hora'], 0, 5); $dur = (int) ($c['duracion'] ?? 30) ?: 30;
    $suc = (int) ($c['sucursal_id'] ?? 0) ?: sucursal_de_franja((int) $c['medico_id'], $fecha, $hora);
    $rec = (int) ($c['recurso_id'] ?? 0);
    if (in_array($c['estado'] ?? 'programada', ['cancelada', 'no_asistio'], true)) return [$suc ?: null, $rec ?: null, ''];
    if ($rec) {
        if (!pertenece_al_tenant('recursos', $rec)) return [$suc ?: null, null, t('Ese recurso no existe.')];
        if (!recurso_libre($rec, $fecha, $hora, $dur, $excluir)) return [$suc ?: null, $rec, sprintf(t('%s ya está ocupado a esa hora. Elige otro recurso u otro horario.'), recurso_nombre($rec))];
        return [$suc ?: null, $rec, ''];
    }
    // Sin recurso elegido: si la sucursal usa recursos, se asigna el primero libre.
    if (recursos($suc ?: null)) {
        foreach (recursos($suc ?: null) as $r) if (recurso_libre((int) $r['id'], $fecha, $hora, $dur, $excluir)) return [$suc ?: null, (int) $r['id'], ''];
        return [$suc ?: null, null, t('Todos los recursos de esa sucursal están ocupados a esa hora.')];
    }
    return [$suc ?: null, null, ''];
}
