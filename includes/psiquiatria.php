<?php
/**
 * Psiquiatría: catálogos, tablas e interpretación de escalas.
 *
 * Lo que distingue a la consulta psiquiátrica de la psicológica y que aquí se
 * estructura (en vez de dejarlo en texto libre):
 *   - Examen mental por dominios (apariencia, afecto, pensamiento, juicio…).
 *   - Tamizaje de riesgo suicida tipo C-SSRS con nivel calculado.
 *   - Escalas: PHQ-9, GAD-7, HAM-D, YMRS y AUDIT, con su evolución.
 *   - Diagnóstico CIE-10 (capítulo F).
 *   - Psicofármacos: inicio, ajustes de dosis y suspensión con su motivo, más
 *     el monitoreo que pide cada familia (litemia, BH con clozapina, perfil
 *     metabólico con antipsicóticos…).
 * Las tablas se crean solas la primera vez (self-healing, como el resto).
 */

function ensure_psiq_tables(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS psiq_notas (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, tipo VARCHAR(20) NOT NULL DEFAULT 'seguimiento',
            motivo TEXT, padecimiento TEXT, antecedentes TEXT,
            examen TEXT, examen_obs TEXT,
            cssrs TEXT, riesgo_suicida VARCHAR(10) DEFAULT NULL, riesgo_hetero VARCHAR(10) DEFAULT NULL, factores TEXT,
            phq9 TINYINT DEFAULT NULL, gad7 TINYINT DEFAULT NULL, hamd TINYINT DEFAULT NULL, ymrs TINYINT DEFAULT NULL, audit TINYINT DEFAULT NULL,
            dx_codigo VARCHAR(20) DEFAULT NULL, dx_texto VARCHAR(255) DEFAULT NULL, dx_secundarios TEXT,
            plan TEXT, proxima VARCHAR(80) DEFAULT NULL,
            creado_por INT DEFAULT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_psiq_notas (consultorio_id, paciente_id, fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db()->exec("CREATE TABLE IF NOT EXISTS psiq_meds (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            farmaco VARCHAR(120) NOT NULL, clase VARCHAR(60) DEFAULT NULL,
            dosis VARCHAR(60) DEFAULT NULL, frecuencia VARCHAR(80) DEFAULT NULL, indicacion VARCHAR(160) DEFAULT NULL,
            inicio DATE NOT NULL, fin DATE DEFAULT NULL, motivo_fin VARCHAR(160) DEFAULT NULL,
            efectos VARCHAR(255) DEFAULT NULL, anterior_id INT DEFAULT NULL,
            creado_por INT DEFAULT NULL, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_psiq_meds (consultorio_id, paciente_id, fin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ya existen o sin permisos: la página lo dirá al guardar */ }
}

function psiq_tipos_nota(): array
{
    return ['inicial' => 'Valoración inicial', 'seguimiento' => 'Seguimiento', 'urgencia' => 'Urgencia / crisis', 'interconsulta' => 'Interconsulta'];
}

/** Examen mental: dominio => [etiqueta, opciones]. La primera opción es la "normal". */
function psiq_examen_mental(): array
{
    return [
        'apariencia'   => ['Apariencia', ['Adecuada', 'Descuidada', 'Desaliñada', 'Bizarra']],
        'actitud'      => ['Actitud', ['Cooperador', 'Reservado', 'Suspicaz', 'Hostil', 'Demandante', 'Indiferente']],
        'conciencia'   => ['Conciencia', ['Alerta', 'Somnoliento', 'Obnubilado', 'Estuporoso']],
        'orientacion'  => ['Orientación', ['Orientado en las 3 esferas', 'Desorientado en tiempo', 'Desorientado en lugar', 'Desorientado en persona']],
        'atencion'     => ['Atención / concentración', ['Conservada', 'Disminuida', 'Distraible', 'Hipervigilante']],
        'psicomotor'   => ['Psicomotricidad', ['Sin alteraciones', 'Inquietud', 'Agitación', 'Retardo psicomotor', 'Catatonia']],
        'lenguaje'     => ['Lenguaje', ['Normal', 'Taquilalia / presión del habla', 'Bradilalia', 'Hipofonía', 'Disártrico', 'Mutismo']],
        'afecto'       => ['Afecto', ['Eutímico', 'Deprimido', 'Ansioso', 'Irritable', 'Expansivo / eufórico', 'Aplanado', 'Lábil', 'Incongruente']],
        'curso'        => ['Curso del pensamiento', ['Coherente y congruente', 'Circunstancial', 'Tangencial', 'Fuga de ideas', 'Disgregado', 'Bloqueos', 'Pobreza de pensamiento']],
        'contenido'    => ['Contenido del pensamiento', ['Sin alteraciones', 'Preocupaciones / rumiación', 'Ideas de culpa o minusvalía', 'Obsesiones', 'Ideas sobrevaloradas', 'Ideas delirantes', 'Ideación suicida', 'Ideación heteroagresiva']],
        'senso'        => ['Sensopercepción', ['Sin alteraciones', 'Alucinaciones auditivas', 'Alucinaciones visuales', 'Otras alucinaciones', 'Ilusiones', 'Despersonalización / desrealización']],
        'memoria'      => ['Memoria', ['Conservada', 'Alteración reciente', 'Alteración remota']],
        'juicio'       => ['Juicio', ['Conservado', 'Parcialmente conservado', 'Desviado']],
        'insight'      => ['Introspección (insight)', ['Presente', 'Parcial', 'Ausente']],
    ];
}

/**
 * Tamizaje de riesgo suicida (estructura de la versión de tamizaje de la
 * C-SSRS). Las 1-5 son del último mes; la 6, de los últimos 3 meses.
 */
function psiq_cssrs_preguntas(): array
{
    return [
        1 => '¿Ha deseado estar muerto(a) o poder dormirse y no despertar?',
        2 => '¿Ha tenido realmente pensamientos de quitarse la vida?',
        3 => '¿Ha pensado en cómo lo haría (un método)?',
        4 => '¿Ha tenido estos pensamientos con alguna intención de llevarlos a cabo?',
        5 => '¿Ha empezado a planear o ya planeó los detalles? ¿Piensa llevar a cabo ese plan?',
        6 => 'En los últimos 3 meses, ¿ha hecho algo, empezado a hacer algo o se ha preparado para terminar con su vida?',
    ];
}

/** Nivel de riesgo según las respuestas (claves 1-6 con true/false). */
function psiq_cssrs_nivel(array $r): ?string
{
    if (!$r) return null;
    if (!empty($r[4]) || !empty($r[5]) || !empty($r[6])) return 'alto';
    if (!empty($r[3])) return 'moderado';
    if (!empty($r[1]) || !empty($r[2])) return 'bajo';
    return 'ninguno';
}

function psiq_riesgos(): array { return ['ninguno' => 'Sin riesgo', 'bajo' => 'Bajo', 'moderado' => 'Moderado', 'alto' => 'Alto']; }

function psiq_riesgo_color(?string $r): string
{
    return ['ninguno' => 'success', 'bajo' => 'info', 'moderado' => 'warning', 'alto' => 'danger'][$r ?? ''] ?? 'secondary';
}

/** Escalas: clave => [nombre, qué mide, máximo, función de nivel]. */
function psiq_escalas(): array
{
    return [
        'phq9' => ['PHQ-9', 'Depresión', 27],
        'gad7' => ['GAD-7', 'Ansiedad', 21],
        'hamd' => ['HAM-D 17', 'Depresión (heteroaplicada)', 52],
        'ymrs' => ['YMRS', 'Manía', 60],
        'audit' => ['AUDIT', 'Consumo de alcohol', 40],
    ];
}

/** Interpretación de cada escala → [etiqueta, color]. */
function psiq_escala_nivel(string $k, $v): array
{
    if ($v === null || $v === '') return ['—', 'secondary'];
    $v = (int) $v;
    switch ($k) {
        case 'phq9': return phq9_nivel($v);
        case 'gad7': return gad7_nivel($v);
        case 'hamd':
            if ($v <= 7)  return ['Normal', 'success'];
            if ($v <= 13) return ['Leve', 'info'];
            if ($v <= 18) return ['Moderada', 'warning'];
            if ($v <= 22) return ['Grave', 'danger'];
            return ['Muy grave', 'danger'];
        case 'ymrs':
            if ($v <= 12) return ['Sin manía', 'success'];
            if ($v <= 19) return ['Hipomanía', 'info'];
            if ($v <= 25) return ['Manía moderada', 'warning'];
            return ['Manía grave', 'danger'];
        case 'audit':
            if ($v <= 7)  return ['Bajo riesgo', 'success'];
            if ($v <= 15) return ['Consumo de riesgo', 'info'];
            if ($v <= 19) return ['Consumo perjudicial', 'warning'];
            return ['Probable dependencia', 'danger'];
    }
    return ['—', 'secondary'];
}

/** Reactivos de PHQ-9 y GAD-7 para la calculadora (0-3 cada uno). */
function psiq_items(): array
{
    return [
        'phq9' => ['Poco interés o placer en hacer las cosas', 'Se ha sentido decaído(a), deprimido(a) o sin esperanza',
                   'Problemas para dormir o dormir demasiado', 'Cansancio o poca energía', 'Poco apetito o comer en exceso',
                   'Sentirse mal consigo mismo(a), o sentir que es un fracaso', 'Dificultad para concentrarse',
                   'Moverse o hablar tan lento que otros lo notan, o lo contrario: inquietud', 'Pensamientos de que estaría mejor muerto(a) o de hacerse daño'],
        'gad7' => ['Sentirse nervioso(a), ansioso(a) o con los nervios de punta', 'No poder dejar de preocuparse o controlar la preocupación',
                   'Preocuparse demasiado por diferentes cosas', 'Dificultad para relajarse', 'Estar tan inquieto(a) que es difícil quedarse quieto(a)',
                   'Molestarse o irritarse fácilmente', 'Sentir miedo, como si algo terrible fuera a pasar'],
    ];
}

/** Diagnósticos CIE-10 frecuentes en la consulta psiquiátrica (capítulo F). */
function psiq_cie10(): array
{
    return [
        'F32.0' => 'Episodio depresivo leve', 'F32.1' => 'Episodio depresivo moderado', 'F32.2' => 'Episodio depresivo grave sin síntomas psicóticos',
        'F32.3' => 'Episodio depresivo grave con síntomas psicóticos', 'F33.1' => 'Trastorno depresivo recurrente, episodio moderado',
        'F34.1' => 'Distimia', 'F31.1' => 'Trastorno bipolar, episodio maníaco sin síntomas psicóticos', 'F31.3' => 'Trastorno bipolar, episodio depresivo',
        'F31.7' => 'Trastorno bipolar, en remisión', 'F41.0' => 'Trastorno de pánico', 'F41.1' => 'Trastorno de ansiedad generalizada',
        'F41.2' => 'Trastorno mixto ansioso-depresivo', 'F40.1' => 'Fobia social', 'F42' => 'Trastorno obsesivo-compulsivo',
        'F43.1' => 'Trastorno de estrés postraumático', 'F43.2' => 'Trastorno de adaptación', 'F20.0' => 'Esquizofrenia paranoide',
        'F25' => 'Trastorno esquizoafectivo', 'F22' => 'Trastorno delirante', 'F23' => 'Trastorno psicótico agudo y transitorio',
        'F10.2' => 'Trastorno por consumo de alcohol (dependencia)', 'F12.2' => 'Dependencia de cannabinoides', 'F14.2' => 'Dependencia de cocaína',
        'F15.2' => 'Dependencia de estimulantes (incl. metanfetamina)', 'F17.2' => 'Dependencia de tabaco', 'F50.0' => 'Anorexia nerviosa',
        'F50.2' => 'Bulimia nerviosa', 'F51.0' => 'Insomnio no orgánico', 'F60.3' => 'Trastorno de inestabilidad emocional (límite)',
        'F90.0' => 'Trastorno por déficit de atención con hiperactividad', 'F84.0' => 'Trastorno del espectro autista',
        'F03' => 'Demencia no especificada', 'F05' => 'Delirium', 'F45.0' => 'Trastorno de somatización',
    ];
}

/**
 * Psicofármacos frecuentes: nombre => [clase, controlado]. "Controlado" marca
 * los que en México requieren receta especial o retenida (COFEPRIS).
 */
function psiq_farmacos(): array
{
    $c = [];
    foreach (['Sertralina', 'Fluoxetina', 'Escitalopram', 'Citalopram', 'Paroxetina', 'Fluvoxamina'] as $f) $c[$f] = ['ISRS', false];
    foreach (['Venlafaxina', 'Desvenlafaxina', 'Duloxetina'] as $f) $c[$f] = ['IRSN', false];
    foreach (['Bupropión', 'Mirtazapina', 'Trazodona', 'Vortioxetina', 'Agomelatina'] as $f) $c[$f] = ['Otro antidepresivo', false];
    foreach (['Amitriptilina', 'Imipramina', 'Clomipramina'] as $f) $c[$f] = ['Tricíclico', false];
    $c['Litio (carbonato)'] = ['Litio', false];
    foreach (['Ácido valproico / valproato', 'Carbamazepina', 'Oxcarbazepina', 'Lamotrigina', 'Topiramato'] as $f) $c[$f] = ['Anticonvulsivo / estabilizador', false];
    foreach (['Quetiapina', 'Olanzapina', 'Risperidona', 'Paliperidona', 'Aripiprazol', 'Ziprasidona', 'Lurasidona', 'Amisulprida'] as $f) $c[$f] = ['Antipsicótico atípico', false];
    $c['Clozapina'] = ['Clozapina', false];
    foreach (['Haloperidol', 'Levomepromazina', 'Perfenazina'] as $f) $c[$f] = ['Antipsicótico típico', false];
    foreach (['Clonazepam', 'Alprazolam', 'Lorazepam', 'Diazepam', 'Bromazepam'] as $f) $c[$f] = ['Benzodiacepina', true];
    $c['Zolpidem'] = ['Hipnótico', true];
    foreach (['Metilfenidato', 'Lisdexanfetamina'] as $f) $c[$f] = ['Estimulante', true];
    $c['Atomoxetina'] = ['No estimulante (TDAH)', false];
    foreach (['Pregabalina', 'Gabapentina', 'Buspirona', 'Hidroxicina'] as $f) $c[$f] = ['Ansiolítico no benzodiacepínico', false];
    foreach (['Naltrexona', 'Disulfiram', 'Acamprosato'] as $f) $c[$f] = ['Adicciones', false];
    $c['Biperideno'] = ['Anticolinérgico', false];
    $c['Propranolol'] = ['Betabloqueador', false];
    return $c;
}

/** Clase de un fármaco escrito a mano (busca por inicio del nombre, sin acentos). */
function psiq_clase_de(string $farmaco): ?string
{
    $n = fn ($s) => mb_strtolower(strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    $f = $n(trim($farmaco));
    if ($f === '') return null;
    foreach (psiq_farmacos() as $nom => [$clase]) {
        $base = $n(preg_split('/[\s\/(]+/', $nom)[0]);
        if (str_starts_with($f, $base) || str_starts_with($base, $f)) return $clase;
    }
    return null;
}

function psiq_es_controlado(string $farmaco): bool
{
    $clase = psiq_clase_de($farmaco);
    return in_array($clase, ['Benzodiacepina', 'Hipnótico', 'Estimulante'], true);
}

/**
 * Monitoreo que pide cada familia: clase => [lista de estudios / vigilancia].
 * Es una guía de referencia; el criterio clínico manda.
 */
function psiq_monitoreo(): array
{
    $metab = 'Peso, IMC y perímetro abdominal; glucosa y perfil de lípidos al inicio, a las 12 semanas y luego cada año; TA.';
    return [
        'Litio' => ['Litemia 5-7 días después de cada ajuste y luego cada 3-6 meses (mantenimiento habitual 0.6-1.0 mEq/L).',
                    'Creatinina / TFG, TSH y calcio al inicio y cada 6-12 meses.', 'Vigilar temblor, poliuria, diarrea; ojo con AINEs, IECA y diuréticos.'],
        'Anticonvulsivo / estabilizador' => ['Valproato: niveles séricos, PFH, BH con plaquetas; evitar en mujeres en edad fértil sin anticoncepción.',
                    'Carbamazepina: niveles, BH, sodio y PFH; interacciones por inducción enzimática.', 'Oxcarbazepina: sodio (hiponatremia).',
                    'Lamotrigina: titulación lenta; suspender ante exantema (riesgo de Stevens-Johnson).'],
        'Clozapina' => ['BH con neutrófilos semanal las primeras 18 semanas, después según protocolo.', 'Vigilar miocarditis (primeras 4-8 semanas), estreñimiento grave, sialorrea y convulsiones.', $metab],
        'Antipsicótico atípico' => [$metab, 'Prolactina si hay síntomas (sobre todo risperidona/paliperidona).', 'ECG si hay factores de riesgo de QT largo (ziprasidona).', 'Síntomas extrapiramidales y acatisia.'],
        'Antipsicótico típico' => ['Síntomas extrapiramidales, acatisia y discinesia tardía (escala AIMS periódica).', 'ECG si hay riesgo de QT largo.', 'Prolactina si hay síntomas.'],
        'Tricíclico' => ['ECG basal en mayores de 40 o con cardiopatía.', 'Efectos anticolinérgicos e hipotensión ortostática; letal en sobredosis: dispensar cantidades limitadas si hay riesgo suicida.'],
        'ISRS' => ['Vigilar activación, ideación suicida en menores de 25 años las primeras semanas.', 'Sodio en adultos mayores (hiponatremia).', 'Citalopram/escitalopram en dosis altas: ECG (QT).'],
        'IRSN' => ['TA (venlafaxina en dosis altas).', 'Duloxetina: PFH si hay consumo de alcohol o hepatopatía.', 'Síndrome de discontinuación: retirar gradualmente.'],
        'Otro antidepresivo' => ['Bupropión: evitar con antecedente de convulsiones o trastorno de la conducta alimentaria.', 'Mirtazapina: peso.', 'Agomelatina: PFH al inicio y a las 3, 6, 12 y 24 semanas.'],
        'Benzodiacepina' => ['Medicamento controlado: receta especial/retenida.', 'Riesgo de tolerancia y dependencia: plan de retiro gradual; evitar con alcohol y opioides.', 'Caídas y sedación en adultos mayores.'],
        'Hipnótico' => ['Medicamento controlado.', 'Uso a corto plazo; conductas complejas del sueño.'],
        'Estimulante' => ['Medicamento controlado.', 'TA, frecuencia cardiaca, peso y talla (en menores) en cada visita.', 'Insomnio, hiporexia; riesgo de uso indebido.'],
        'No estimulante (TDAH)' => ['TA y frecuencia cardiaca; PFH si hay síntomas.'],
        'Adicciones' => ['Naltrexona: PFH; no administrar con opioides.', 'Disulfiram: PFH; reacción grave con alcohol (informar al paciente).'],
    ];
}

/** Medicación activa del paciente. */
function psiq_meds_activas(int $pid): array
{
    try {
        $st = db()->prepare('SELECT * FROM psiq_meds WHERE paciente_id = ? AND consultorio_id = ? AND fin IS NULL ORDER BY inicio, id');
        $st->execute([$pid, tenant_id()]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Decodifica una columna JSON con respaldo a arreglo vacío. */
function psiq_json(?string $s): array
{
    $v = $s ? json_decode($s, true) : null;
    return is_array($v) ? $v : [];
}
