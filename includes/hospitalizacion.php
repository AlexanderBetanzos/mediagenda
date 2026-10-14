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
