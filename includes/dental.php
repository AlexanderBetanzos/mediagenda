<?php
/**
 * Expediente dental: lo que rodea al odontograma en un consultorio dental.
 *
 *   - Historia clínica odontológica (una por paciente, se actualiza):
 *     antecedentes médicos de riesgo, hábitos, higiene, ATM, tejidos
 *     blandos, oclusión (Angle, overjet/overbite), índice de placa y riesgo
 *     de caries.
 *   - Fotos clínicas: serie fotográfica estándar (intraorales, extraorales,
 *     radiografías) por fecha y etapa del tratamiento, con comparador
 *     antes/después. Los archivos viven en `archivos` (mismo resguardo que
 *     el resto del expediente).
 *   - Periodontograma: sondaje en 6 sitios por diente, margen gingival,
 *     sangrado, placa, movilidad y furca, con sus índices.
 *   - Notas de evolución por procedimiento (pieza, anestesia, materiales);
 *     si el procedimiento estaba planeado en el odontograma, se marca como
 *     realizado ahí mismo.
 */
require_once __DIR__ . '/odontograma.php';

function ensure_dental_tables(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    foreach ([
        "CREATE TABLE IF NOT EXISTS dental_historias (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            motivo TEXT, riesgos TEXT, alergias VARCHAR(255), medicamentos TEXT, antecedentes TEXT,
            habitos TEXT, cepillado VARCHAR(20), hilo VARCHAR(20), enjuague VARCHAR(20), ultima_visita VARCHAR(80),
            atm TEXT, apertura VARCHAR(10), extraoral TEXT, tejidos TEXT,
            molar_der VARCHAR(10), molar_izq VARCHAR(10), canina_der VARCHAR(10), canina_izq VARCHAR(10),
            overjet VARCHAR(10), overbite VARCHAR(10), oclusion_notas TEXT,
            placa_pct TINYINT, riesgo_caries VARCHAR(10), observaciones TEXT,
            actualizado_por INT, actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dh (consultorio_id, paciente_id)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS dental_fotos (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            archivo_id INT NOT NULL, fecha DATE NOT NULL, vista VARCHAR(40), etapa VARCHAR(20), diente VARCHAR(20),
            notas VARCHAR(255), creado_por INT, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_df (consultorio_id, paciente_id, fecha)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS dental_perio (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, datos LONGTEXT, bop_pct DECIMAL(5,1), placa_pct DECIMAL(5,1), ps_media DECIMAL(4,2),
            sitios4 SMALLINT, sitios6 SMALLINT, diagnostico VARCHAR(255), notas TEXT, creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_dp (consultorio_id, paciente_id, fecha)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS dental_evolucion (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, dientes VARCHAR(120), caras VARCHAR(20), procedimiento VARCHAR(160) NOT NULL,
            anestesia VARCHAR(80), cartuchos VARCHAR(10), materiales TEXT, notas TEXT, indicaciones TEXT, proxima VARCHAR(255),
            creado_por INT, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_de (consultorio_id, paciente_id, fecha)
        ) $opt",
    ] as $q) {
        try { db()->exec($q); } catch (Throwable $e) { /* ya existe */ }
    }
}

/* ------------------------------ Catálogos ------------------------------ */

/** Antecedentes médicos que cambian la conducta en el sillón. */
function dental_riesgos(): array
{
    return ['Alergia a anestésicos', 'Alergia a penicilina', 'Alergia al látex', 'Anticoagulantes / antiagregantes',
            'Diabetes', 'Hipertensión', 'Cardiopatía / endocarditis previa', 'Prótesis valvular o articular',
            'Bifosfonatos / antirresortivos', 'Embarazo', 'Lactancia', 'Asma', 'Epilepsia', 'VIH / hepatitis',
            'Radioterapia de cabeza y cuello', 'Trastorno de coagulación', 'Tabaquismo'];
}

function dental_habitos(): array
{
    return ['Bruxismo', 'Apretamiento', 'Onicofagia', 'Respirador bucal', 'Succión digital', 'Morder objetos',
            'Interposición lingual', 'Consumo frecuente de azúcar', 'Bebidas ácidas', 'Alcohol'];
}

/** Serie fotográfica dental estándar. */
function dental_vistas(): array
{
    return [
        'Intraorales' => ['Frontal en oclusión', 'Lateral derecha', 'Lateral izquierda', 'Oclusal superior', 'Oclusal inferior', 'Detalle de pieza'],
        'Extraorales' => ['Frente en reposo', 'Sonrisa', 'Perfil derecho', 'Perfil izquierdo', '3/4'],
        'Radiografías' => ['Panorámica', 'Periapical', 'Aleta de mordida', 'Lateral de cráneo', 'Tomografía (corte)'],
        'Otras' => ['Modelo / escaneo', 'Guía de color', 'Otra'],
    ];
}

function dental_etapas(): array
{
    return ['inicial' => 'Inicial (antes)', 'progreso' => 'En tratamiento', 'final' => 'Final (después)', 'control' => 'Control'];
}

function dental_procedimientos(): array
{
    return ['Valoración / diagnóstico', 'Profilaxis', 'Detartraje / raspado', 'Raspado y alisado radicular', 'Aplicación de flúor',
            'Sellador de fosetas', 'Obturación con resina', 'Obturación con amalgama', 'Incrustación', 'Endodoncia',
            'Pulpotomía', 'Extracción simple', 'Extracción quirúrgica / tercer molar', 'Colocación de implante',
            'Corona', 'Puente fijo', 'Prótesis removible', 'Prótesis total', 'Blanqueamiento', 'Carilla',
            'Ajuste de ortodoncia', 'Cementado de brackets', 'Retiro de brackets', 'Guarda oclusal', 'Toma de impresiones / escaneo',
            'Radiografía', 'Retiro de puntos', 'Urgencia / control de dolor'];
}

/** Procedimiento de la nota → tratamiento del odontograma (para marcarlo realizado). */
function dental_proc_a_odo(string $proc): ?string
{
    $m = ['Obturación con resina' => 'obturacion', 'Obturación con amalgama' => 'obturacion', 'Incrustación' => 'obturacion',
          'Sellador de fosetas' => 'sellante', 'Endodoncia' => 'endodoncia', 'Corona' => 'corona',
          'Extracción simple' => 'extraccion', 'Extracción quirúrgica / tercer molar' => 'extraccion',
          'Colocación de implante' => 'implante', 'Puente fijo' => 'protesis', 'Prótesis removible' => 'protesis', 'Prótesis total' => 'protesis'];
    return $m[$proc] ?? null;
}

/** Fotos del paciente, con datos del archivo. */
function dental_fotos(int $pid): array
{
    $st = db()->prepare('SELECT f.*, a.mime, a.nombre_original FROM dental_fotos f JOIN archivos a ON a.id = f.archivo_id AND a.consultorio_id = f.consultorio_id
                         WHERE f.paciente_id = ? AND f.consultorio_id = ? ORDER BY f.fecha DESC, f.id DESC');
    $st->execute([$pid, tenant_id()]);
    return $st->fetchAll();
}

function dental_foto_url(int $archivoId): string
{
    return BASE_URL . '/pacientes/archivo?id=' . $archivoId . '&ver=1';
}

/**
 * Normaliza $_FILES['campo'] múltiple a una lista de archivos individuales.
 */
function dental_files_lista(string $campo): array
{
    $f = $_FILES[$campo] ?? null;
    if (!$f || !is_array($f['name'] ?? null)) return $f ? [$f] : [];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
                  'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
}

/* ---------------------------- Periodontograma ---------------------------- */

/** Sitios por diente: vestibular (distal, medio, mesial) y lingual/palatino igual. */
function perio_sitios(): array { return ['vD', 'vM', 'vMe', 'lD', 'lM', 'lMe']; }

/**
 * Índices del periodontograma. $d = [diente => ['ps'=>[6], 'mg'=>[6], 'bop'=>[6], 'pl'=>[6], 'mov', 'furca', 'aus']].
 * NIC (nivel de inserción) = PS + MG, donde MG es positivo en recesión.
 */
function perio_indices(array $d): array
{
    $sitios = $sang = $plac = $s4 = $s6 = 0; $sumPs = 0; $nPs = 0; $nicMax = 0;
    foreach ($d as $t) {
        if (!empty($t['aus'])) continue;
        for ($i = 0; $i < 6; $i++) {
            $sitios++;
            $ps = $t['ps'][$i] ?? '';
            if ($ps !== '' && is_numeric($ps)) {
                $ps = (float) $ps; $sumPs += $ps; $nPs++;
                if ($ps >= 4) $s4++;
                if ($ps >= 6) $s6++;
                $mg = $t['mg'][$i] ?? '';
                $nic = $ps + (is_numeric($mg) ? (float) $mg : 0);
                $nicMax = max($nicMax, $nic);
            }
            if (!empty($t['bop'][$i])) $sang++;
            if (!empty($t['pl'][$i])) $plac++;
        }
    }
    return [
        'bop'   => $sitios ? round($sang * 100 / $sitios, 1) : null,
        'placa' => $sitios ? round($plac * 100 / $sitios, 1) : null,
        'ps'    => $nPs ? round($sumPs / $nPs, 2) : null,
        's4' => $s4, 's6' => $s6, 'nic_max' => $nicMax, 'dientes' => count(array_filter($d, fn ($t) => empty($t['aus']))),
    ];
}
