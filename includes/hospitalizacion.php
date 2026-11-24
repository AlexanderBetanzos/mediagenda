<?php
/**
 * Hospitalización con kardex.
 *
 * Una estancia (ingreso → alta) lleva sus indicaciones (medicamento, dosis,
 * vía y cada cuántas horas) y el kardex: las dosis programadas se CALCULAN
 * desde el inicio y la frecuencia; solo se guarda lo que pasó con cada una
 * (aplicada u omitida, quién y cuándo). Así suspender o cambiar una
 * indicación no deja dosis huérfanas en la base.
 */

function asegurar_hosp(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_estancias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        paciente_id INT NOT NULL,
        medico_id INT NULL,
        cama VARCHAR(60) NULL,
        motivo VARCHAR(255) NULL,
        diagnostico VARCHAR(255) NULL,
        ingreso DATETIME NOT NULL,
        estado VARCHAR(10) NOT NULL DEFAULT 'activa',
        alta_en DATETIME NULL,
        alta_notas TEXT NULL,
        creado_por INT NULL,
        INDEX idx_he (consultorio_id, estado),
        INDEX idx_he_pac (consultorio_id, paciente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_indicaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        estancia_id INT NOT NULL,
        medicamento VARCHAR(160) NOT NULL,
        dosis VARCHAR(80) NULL,
        via VARCHAR(40) NULL,
        frecuencia_horas DECIMAL(5,2) NULL,
        inicio DATETIME NOT NULL,
        fin DATETIME NULL,
        notas VARCHAR(255) NULL,
        suspendida_en DATETIME NULL,
        creado_por INT NULL,
        INDEX idx_hi (consultorio_id, estancia_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_aplicaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        estancia_id INT NOT NULL,
        indicacion_id INT NOT NULL,
        programada DATETIME NOT NULL,
        estado VARCHAR(10) NOT NULL,
        hecho_en DATETIME NOT NULL,
        hecho_por INT NULL,
        notas VARCHAR(255) NULL,
        UNIQUE KEY uq_ha (indicacion_id, programada),
        INDEX idx_ha (consultorio_id, estancia_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_notas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        estancia_id INT NOT NULL,
        texto TEXT NULL,
        temp VARCHAR(10) NULL,
        fc VARCHAR(10) NULL,
        fr VARCHAR(10) NULL,
        ta VARCHAR(15) NULL,
        creado_por INT NULL,
        creado_en DATETIME NOT NULL,
        INDEX idx_hn (consultorio_id, estancia_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    asegurar_hosp_residencial();
}

/* =====================================================================
 *  Programa residencial: cuartos con tarifa, cuenta del internado con
 *  cargos automáticos, visitas, bitácora por turno y actividades del día.
 * ===================================================================== */
function asegurar_hosp_residencial(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_cuartos (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL,
            nombre VARCHAR(80) NOT NULL, tipo VARCHAR(60) NULL,
            modalidad VARCHAR(12) NOT NULL DEFAULT 'noche',   -- noche | dia | hora | ninguna
            tarifa DECIMAL(10,2) NOT NULL DEFAULT 0, camas INT NOT NULL DEFAULT 1,
            descripcion VARCHAR(255) NULL, activo TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
            INDEX idx_hc (consultorio_id, activo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_visitas (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL, estancia_id INT NOT NULL,
            fecha DATETIME NOT NULL, visitante VARCHAR(120) NOT NULL, parentesco VARCHAR(60) NULL,
            notas VARCHAR(255) NULL, registrado_por INT NULL,
            INDEX idx_hv (consultorio_id, estancia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_actividades (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL,
            fecha DATE NOT NULL, hora TIME NULL, nombre VARCHAR(120) NOT NULL, tipo VARCHAR(40) NULL,
            responsable VARCHAR(120) NULL, notas VARCHAR(255) NULL, creado_por INT NULL,
            INDEX idx_hact (consultorio_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosp_asistencias (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL,
            actividad_id INT NOT NULL, estancia_id INT NOT NULL, asistio TINYINT(1) NOT NULL DEFAULT 1,
            nota VARCHAR(255) NULL, registrado_por INT NULL,
            UNIQUE KEY uq_hasis (actividad_id, estancia_id), INDEX idx_hasis (consultorio_id, estancia_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (!$pdo->query("SHOW COLUMNS FROM hosp_estancias LIKE 'cuarto_id'")->fetch()) {
            $pdo->exec("ALTER TABLE hosp_estancias
                ADD COLUMN IF NOT EXISTS cuarto_id INT NULL,
                ADD COLUMN IF NOT EXISTS modalidad VARCHAR(12) NULL,
                ADD COLUMN IF NOT EXISTS tarifa DECIMAL(10,2) NULL,
                ADD COLUMN IF NOT EXISTS factura_id INT NULL,
                ADD COLUMN IF NOT EXISTS cargado_hasta DATE NULL,
                ADD COLUMN IF NOT EXISTS alta_estimada DATE NULL,
                ADD COLUMN IF NOT EXISTS responsable VARCHAR(120) NULL,
                ADD COLUMN IF NOT EXISTS responsable_tel VARCHAR(40) NULL,
                ADD COLUMN IF NOT EXISTS responsable_parentesco VARCHAR(60) NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM hosp_notas LIKE 'turno'")->fetch()) {
            $pdo->exec("ALTER TABLE hosp_notas
                ADD COLUMN IF NOT EXISTS turno VARCHAR(12) NULL,
                ADD COLUMN IF NOT EXISTS tipo VARCHAR(16) NULL");
        }
    } catch (Throwable $e) { /* sin permisos de ALTER: el kardex sigue funcionando */ }
}

/** Cómo se cobra un cuarto. */
function hosp_modalidades(): array
{
    return [
        'noche'   => 'Por noche (internamiento 24 h)',
        'dia'     => 'Por día de asistencia (internamiento de día)',
        'hora'    => 'Por hora (se carga a mano)',
        'ninguna' => 'Sin cargo automático',
    ];
}

function hosp_turnos(): array { return ['matutino' => 'Matutino', 'vespertino' => 'Vespertino', 'nocturno' => 'Nocturno']; }
function hosp_tipos_nota(): array { return ['enfermeria' => 'Enfermería', 'medica' => 'Médica', 'psicologia' => 'Psicología', 'otra' => 'Otra']; }

/** Turno según la hora (7-15 matutino, 15-23 vespertino, resto nocturno). */
function hosp_turno_actual(): string
{
    $h = (int) date('G');
    return $h >= 7 && $h < 15 ? 'matutino' : ($h >= 15 && $h < 23 ? 'vespertino' : 'nocturno');
}

function hosp_cuartos(bool $soloActivos = true): array
{
    try {
        asegurar_hosp_residencial();
        $st = db()->prepare('SELECT * FROM hosp_cuartos WHERE consultorio_id = ?' . ($soloActivos ? ' AND activo = 1' : '') . ' ORDER BY orden, nombre');
        $st->execute([tenant_id()]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Camas ocupadas por cuarto: [cuarto_id => n]. */
function hosp_ocupacion(): array
{
    try {
        $st = db()->prepare("SELECT cuarto_id, COUNT(*) n FROM hosp_estancias WHERE consultorio_id = ? AND estado = 'activa' AND cuarto_id IS NOT NULL GROUP BY cuarto_id");
        $st->execute([tenant_id()]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (Throwable $e) { return []; }
}

/**
 * Cuenta del internado: una factura pendiente por estancia que va juntando
 * cargos. Se crea la primera vez que hace falta. Los abonos se registran en
 * esa factura (Facturación → abonos) y entran al corte de caja de quien cobra.
 */
function hosp_cuenta(array $e): int
{
    if (!empty($e['factura_id'])) {
        $st = db()->prepare('SELECT id FROM facturas WHERE id = ? AND consultorio_id = ?');
        $st->execute([(int) $e['factura_id'], tenant_id()]);
        if ($st->fetchColumn()) return (int) $e['factura_id'];
    }
    $pdo = db();
    $pdo->prepare("INSERT INTO facturas (consultorio_id, folio, paciente_id, medico_id, fecha, subtotal, descuento, total, estado, notas)
                   VALUES (?,?,?,?,?,0,0,0,'pendiente',?)")
        ->execute([tenant_id(), 'TMP-' . uniqid(), (int) $e['paciente_id'], $e['medico_id'] ?: null, date('Y-m-d', strtotime($e['ingreso'])),
                   t('Cuenta de internamiento') . ' · ' . t('estancia') . ' #' . (int) $e['id']]);
    $fid = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE facturas SET folio = ? WHERE id = ? AND consultorio_id = ?')
        ->execute(['F-' . date('Y') . '-' . str_pad((string) $fid, 4, '0', STR_PAD_LEFT), $fid, tenant_id()]);
    $pdo->prepare('UPDATE hosp_estancias SET factura_id = ? WHERE id = ? AND consultorio_id = ?')->execute([$fid, (int) $e['id'], tenant_id()]);
    return $fid;
}

/** Agrega un cargo a la cuenta y recalcula el total. */
function hosp_cargo(int $fid, string $desc, float $cant, float $precio): void
{
    $pdo = db();
    $imp = round($cant * $precio, 2);
    $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,?,?,?)')
        ->execute([$fid, mb_substr($desc, 0, 255), $cant, $precio, $imp]);
    hosp_recalcular($fid);
}

function hosp_recalcular(int $fid): void
{
    $pdo = db();
    $sub = $pdo->prepare('SELECT COALESCE(SUM(importe),0) FROM factura_items WHERE factura_id = ?');
    $sub->execute([$fid]);
    $sub = (float) $sub->fetchColumn();
    // Un cargo nuevo sobre una cuenta ya liquidada la vuelve a dejar con saldo.
    $pdo->prepare("UPDATE facturas SET subtotal = ?, total = GREATEST(0, ? - descuento),
                   estado = IF(estado = 'cancelada', estado, 'pendiente') WHERE id = ? AND consultorio_id = ?")
        ->execute([$sub, $sub, $fid, tenant_id()]);
    try { require_once __DIR__ . '/abonos.php'; if (function_exists('abonos_sincronizar')) abonos_sincronizar($fid); } catch (Throwable $e) {}
}

/**
 * Carga sola las noches / días de la estancia hasta hoy (o hasta el alta).
 *   noche: se cobra por noche, el día de ingreso cuenta y el de alta no
 *          (23 → 25 = 2 noches; ingreso y alta el mismo día = 1).
 *   dia:   cada día de asistencia, de ingreso a hoy/alta inclusive.
 * Idempotente: recuerda hasta qué fecha ya cargó (cargado_hasta).
 */
function hosp_cargar(array $e): int
{
    $mod = $e['modalidad'] ?? null;
    $tarifa = (float) ($e['tarifa'] ?? 0);
    if (!in_array($mod, ['noche', 'dia'], true) || $tarifa <= 0) return 0;
    $ini = date('Y-m-d', strtotime($e['ingreso']));
    $finRef = $e['estado'] === 'alta' && $e['alta_en'] ? date('Y-m-d', strtotime($e['alta_en'])) : date('Y-m-d');
    $hasta = $mod === 'noche' ? max($ini, date('Y-m-d', strtotime($finRef . ' -1 day'))) : $finRef;
    $desde = $e['cargado_hasta'] ? date('Y-m-d', strtotime($e['cargado_hasta'] . ' +1 day')) : $ini;
    if ($desde > $hasta) return 0;
    $fid = hosp_cuenta($e);
    $cuarto = '';
    if (!empty($e['cuarto_id'])) {
        $st = db()->prepare('SELECT nombre FROM hosp_cuartos WHERE id = ? AND consultorio_id = ?');
        $st->execute([(int) $e['cuarto_id'], tenant_id()]);
        $cuarto = (string) $st->fetchColumn();
    }
    $n = 0;
    $pdo = db();
    $it = $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,1,?,?)');
    for ($d = $desde; $d <= $hasta && $n < 400; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $it->execute([$fid, ($mod === 'noche' ? t('Estancia') : t('Internamiento de día')) . ($cuarto ? ' · ' . $cuarto : '') . ' · ' . fmt_fecha($d), $tarifa, $tarifa]);
        $n++;
    }
    $pdo->prepare('UPDATE hosp_estancias SET cargado_hasta = ? WHERE id = ? AND consultorio_id = ?')->execute([$hasta, (int) $e['id'], tenant_id()]);
    hosp_recalcular($fid);
    return $n;
}

/** Carga pendiente de todas las estancias activas (se llama al abrir el tablero). */
function hosp_cargar_activas(): void
{
    try {
        $st = db()->prepare("SELECT * FROM hosp_estancias WHERE consultorio_id = ? AND estado = 'activa' AND modalidad IN ('noche','dia') AND tarifa > 0");
        $st->execute([tenant_id()]);
        foreach ($st->fetchAll() as $e) hosp_cargar($e);
    } catch (Throwable $e) { /* sin columnas nuevas todavía */ }
}

/** Resumen de la cuenta: [total, abonado, saldo, items]. */
function hosp_cuenta_resumen(?int $fid): array
{
    if (!$fid) return ['total' => 0.0, 'abonado' => 0.0, 'saldo' => 0.0, 'items' => [], 'estado' => ''];
    $f = db()->prepare('SELECT * FROM facturas WHERE id = ? AND consultorio_id = ?');
    $f->execute([$fid, tenant_id()]);
    $f = $f->fetch();
    if (!$f) return ['total' => 0.0, 'abonado' => 0.0, 'saldo' => 0.0, 'items' => [], 'estado' => ''];
    $it = db()->prepare('SELECT * FROM factura_items WHERE factura_id = ? ORDER BY id');
    $it->execute([$fid]);
    $abonado = 0.0;
    try { require_once __DIR__ . '/abonos.php'; $abonado = (float) abonos_total($fid); } catch (Throwable $e) {}
    if ($f['estado'] === 'pagada' && $abonado <= 0) $abonado = (float) $f['total'];
    return ['total' => (float) $f['total'], 'abonado' => $abonado, 'saldo' => max(0, (float) $f['total'] - $abonado),
            'items' => $it->fetchAll(), 'estado' => $f['estado'], 'folio' => $f['folio']];
}

/** Vías de administración frecuentes (el campo acepta texto libre). */
function hosp_vias(): array
{
    return ['VO', 'IV', 'IM', 'SC', 'Tópica', 'Inhalada', 'Oftálmica', 'Ótica', 'Rectal', 'SL'];
}

/** Frecuencias frecuentes en horas => etiqueta. 0 = dosis única. */
function hosp_frecuencias(): array
{
    return [4 => 'c/4 h', 6 => 'c/6 h', 8 => 'c/8 h', 12 => 'c/12 h', 24 => 'c/24 h', 0 => t('Dosis única')];
}

/**
 * Dosis programadas de una indicación entre $desde y $hasta (timestamps),
 * respetando su inicio, fin y suspensión. Devuelve timestamps.
 */
function hosp_dosis(array $ind, int $desde, int $hasta): array
{
    $ini = strtotime($ind['inicio']);
    $tope = $hasta;
    foreach (['fin', 'suspendida_en'] as $k) if (!empty($ind[$k])) $tope = min($tope, strtotime($ind[$k]));
    $h = (float) ($ind['frecuencia_horas'] ?? 0);
    if ($h <= 0) return ($ini >= $desde && $ini <= $tope) ? [$ini] : [];
    $paso = (int) round($h * 3600);
    $out = [];
    $k = $ini >= $desde ? 0 : (int) ceil(($desde - $ini) / $paso);
    for ($t = $ini + $k * $paso; $t <= $tope && count($out) < 200; $t += $paso) $out[] = $t;
    return $out;
}

/** Aplicaciones registradas de una estancia, indexadas "indicacion|Y-m-d H:i:s". */
function hosp_aplicaciones(int $estanciaId): array
{
    $st = db()->prepare('SELECT a.*, u.nombre quien FROM hosp_aplicaciones a LEFT JOIN usuarios u ON u.id = a.hecho_por
                         WHERE a.consultorio_id = ? AND a.estancia_id = ?');
    $st->execute([tenant_id(), $estanciaId]);
    $out = [];
    foreach ($st->fetchAll() as $a) $out[$a['indicacion_id'] . '|' . $a['programada']] = $a;
    return $out;
}

/** Dosis vencidas sin registrar (de más de 30 min) en las estancias activas: [estancia_id => n]. */
function hosp_atrasadas(): array
{
    try {
        asegurar_hosp();
        $tid = (int) tenant_id();
        $st = db()->prepare("SELECT i.* FROM hosp_indicaciones i JOIN hosp_estancias e ON e.id = i.estancia_id
                             WHERE i.consultorio_id = ? AND e.estado = 'activa' AND i.suspendida_en IS NULL");
        $st->execute([$tid]);
        $inds = $st->fetchAll();
        if (!$inds) return [];
        $hechas = db()->prepare("SELECT CONCAT(indicacion_id, '|', programada) FROM hosp_aplicaciones WHERE consultorio_id = ? AND programada >= ?");
        $hechas->execute([$tid, date('Y-m-d H:i:s', time() - 86400)]);
        $hechas = array_flip($hechas->fetchAll(PDO::FETCH_COLUMN));
        $out = [];
        foreach ($inds as $i) {
            foreach (hosp_dosis($i, time() - 86400, time() - 1800) as $t) {
                if (!isset($hechas[$i['id'] . '|' . date('Y-m-d H:i:s', $t)])) $out[$i['estancia_id']] = ($out[$i['estancia_id']] ?? 0) + 1;
            }
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** "3 días" / "5 h" desde el ingreso. */
function hosp_tiempo(string $desde, ?string $hasta = null): string
{
    $s = max(0, ($hasta ? strtotime($hasta) : time()) - strtotime($desde));
    if ($s < 86400) return sprintf(t('%d h'), floor($s / 3600));
    $d = (int) floor($s / 86400);
    return $d === 1 ? t('1 día') : sprintf(t('%d días'), $d);
}
