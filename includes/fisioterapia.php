<?php
/**
 * Fisioterapia: lo que usa un fisioterapeuta todos los días.
 *
 *   - Historia clínica fisioterapéutica (valoración inicial, reevaluación y
 *     alta): dolor (EVA), banderas rojas, goniometría con rangos normales,
 *     fuerza muscular (Daniels 0-5), pruebas especiales por región, escalas
 *     funcionales (Oswestry, NDI, DASH, LEFS…), diagnóstico fisioterapéutico
 *     (CIF) y objetivos.
 *   - Notas de evolución SOAP por sesión, con EVA antes/después,
 *     intervenciones aplicadas y tolerancia.
 *   - Planes de ejercicio en casa armados desde una biblioteca editable, con
 *     dosificación, hoja imprimible, envío por WhatsApp y registro de apego
 *     desde el portal del paciente.
 *
 * Las tablas se crean solas (self-healing) como en los demás módulos.
 * Los campos tabulares (goniometría, fuerza, pruebas, escalas) se guardan en
 * JSON: son listas variables y nunca se consultan por columna.
 */

function ensure_fisio_tables(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [
        "CREATE TABLE IF NOT EXISTS fisio_valoraciones (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, tipo ENUM('inicial','reevaluacion','alta') NOT NULL DEFAULT 'inicial',
            referente VARCHAR(160), dx_medico VARCHAR(255), motivo TEXT, region VARCHAR(40), lado VARCHAR(12),
            inicio VARCHAR(80), mecanismo TEXT, ocupacion VARCHAR(160), antecedentes TEXT,
            banderas_rojas TEXT, banderas_amarillas TEXT,
            eva TINYINT, dolor_tipo VARCHAR(160), dolor_patron VARCHAR(160), agravantes TEXT, atenuantes TEXT, irradiacion VARCHAR(255),
            inspeccion TEXT, palpacion TEXT, marcha TEXT, neuro TEXT,
            rom LONGTEXT, fuerza LONGTEXT, pruebas LONGTEXT, escalas LONGTEXT,
            dx_fisio TEXT, deficiencias TEXT, limitaciones TEXT,
            obj_corto TEXT, obj_largo TEXT, plan TEXT, pronostico VARCHAR(20), sesiones_est SMALLINT, frecuencia VARCHAR(80),
            alta_motivo VARCHAR(40), notas TEXT, creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fv (consultorio_id, paciente_id, fecha)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS fisio_notas (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            fecha DATE NOT NULL, eva_pre TINYINT, eva_post TINYINT,
            subjetivo TEXT, objetivo TEXT, intervenciones TEXT, parametros TEXT, analisis TEXT, plan TEXT,
            tolerancia VARCHAR(12), creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fn (consultorio_id, paciente_id, fecha)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS fisio_ejercicios (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1,
            nombre VARCHAR(160) NOT NULL, region VARCHAR(40), tipo VARCHAR(40), instrucciones TEXT,
            series TINYINT, reps VARCHAR(20), segundos SMALLINT, imagen_url VARCHAR(500), video_url VARCHAR(500),
            activo TINYINT(1) NOT NULL DEFAULT 1, creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fe (consultorio_id, region)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS fisio_planes (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, paciente_id INT NOT NULL,
            nombre VARCHAR(160) NOT NULL, objetivo VARCHAR(255), fecha DATE NOT NULL, semanas TINYINT,
            frecuencia VARCHAR(80), indicaciones TEXT, portal TINYINT(1) NOT NULL DEFAULT 1,
            activo TINYINT(1) NOT NULL DEFAULT 1, creado_por INT,
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fp (consultorio_id, paciente_id)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS fisio_plan_items (
            id INT AUTO_INCREMENT PRIMARY KEY, plan_id INT NOT NULL, ejercicio_id INT,
            nombre VARCHAR(160) NOT NULL, instrucciones TEXT, series TINYINT, reps VARCHAR(20), segundos SMALLINT,
            carga VARCHAR(60), descanso VARCHAR(30), lado VARCHAR(12), notas VARCHAR(255),
            imagen_url VARCHAR(500), video_url VARCHAR(500), orden SMALLINT NOT NULL DEFAULT 0,
            INDEX idx_fpi (plan_id, orden)
        ) $opt",
        "CREATE TABLE IF NOT EXISTS fisio_plan_registro (
            id INT AUTO_INCREMENT PRIMARY KEY, consultorio_id INT NOT NULL DEFAULT 1, plan_id INT NOT NULL,
            paciente_id INT NOT NULL, fecha DATE NOT NULL, dolor TINYINT, comentario VARCHAR(255),
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fpr (plan_id, fecha), INDEX idx_fpr (consultorio_id, paciente_id, fecha)
        ) $opt",
    ];
    foreach ($sql as $q) {
        try { db()->exec($q); } catch (Throwable $e) { /* ya existe o sin permisos */ }
    }
}

/* --------------------------------------------------------------------
 *  Catálogos clínicos
 * ------------------------------------------------------------------ */

function fisio_regiones(): array
{
    return ['Cervical', 'Dorsal', 'Lumbar', 'Hombro', 'Codo', 'Muñeca y mano', 'Cadera', 'Rodilla', 'Tobillo y pie',
            'ATM', 'Neurológico', 'Respiratorio', 'Suelo pélvico', 'Deportivo', 'Geriátrico', 'Pediátrico', 'General'];
}

function fisio_tipos_valoracion(): array
{
    return ['inicial' => 'Valoración inicial', 'reevaluacion' => 'Reevaluación', 'alta' => 'Alta'];
}

function fisio_banderas_rojas(): array
{
    return ['Pérdida de peso inexplicable', 'Dolor nocturno que no cambia con la postura', 'Fiebre o malestar general',
            'Antecedente de cáncer', 'Traumatismo mayor reciente', 'Alteración de esfínteres / anestesia en silla de montar',
            'Déficit neurológico progresivo', 'Uso prolongado de corticoides / osteoporosis', 'Dolor torácico o disnea'];
}

function fisio_intervenciones(): array
{
    return ['Terapia manual', 'Movilización articular', 'Manipulación', 'Masoterapia', 'Liberación miofascial', 'Punción seca',
            'TENS', 'Corrientes interferenciales', 'Electroestimulación (EMS)', 'Ultrasonido', 'Láser', 'Magnetoterapia',
            'Diatermia / radiofrecuencia', 'Ondas de choque', 'Termoterapia', 'Crioterapia', 'Vendaje neuromuscular',
            'Vendaje funcional', 'Ejercicio terapéutico', 'Estiramientos', 'Propiocepción / equilibrio', 'Reeducación de la marcha',
            'Fisioterapia respiratoria', 'Hidroterapia', 'Educación en dolor', 'Revisión del plan en casa'];
}

/** Escalas funcionales: nombre => [máximo, sentido] (sentido 'menos' = menor es mejor). */
function fisio_escalas(): array
{
    return [
        'Oswestry (ODI) %' => [100, 'menos'], 'Roland-Morris' => [24, 'menos'], 'NDI (cuello) %' => [100, 'menos'],
        'DASH' => [100, 'menos'], 'QuickDASH' => [100, 'menos'], 'SPADI %' => [100, 'menos'],
        'LEFS' => [80, 'mas'], 'KOOS' => [100, 'mas'], 'WOMAC' => [96, 'menos'], 'Lysholm' => [100, 'mas'],
        'Kujala' => [100, 'mas'], 'VISA-A' => [100, 'mas'], 'VISA-P' => [100, 'mas'],
        'Berg' => [56, 'mas'], 'Tinetti' => [28, 'mas'], 'Barthel' => [100, 'mas'],
        'TUG (segundos)' => [0, 'menos'], 'Marcha 6 min (metros)' => [0, 'mas'], 'Tampa (kinesiofobia)' => [68, 'menos'],
        'PSFS' => [10, 'mas'],
    ];
}

/** Rangos de movimiento normales (AAOS), por región: [movimiento, grados]. */
function fisio_rom_normales(): array
{
    return [
        'Cervical'      => [['Flexión', 50], ['Extensión', 60], ['Inclinación lateral', 45], ['Rotación', 80]],
        'Dorsal'        => [['Rotación torácica', 45]],
        'Lumbar'        => [['Flexión', 60], ['Extensión', 25], ['Inclinación lateral', 25], ['Rotación', 30]],
        'Hombro'        => [['Flexión', 180], ['Extensión', 60], ['Abducción', 180], ['Aducción', 45], ['Rotación interna', 70], ['Rotación externa', 90]],
        'Codo'          => [['Flexión', 150], ['Extensión', 0], ['Pronación', 80], ['Supinación', 80]],
        'Muñeca y mano' => [['Flexión', 80], ['Extensión', 70], ['Desviación radial', 20], ['Desviación cubital', 30]],
        'Cadera'        => [['Flexión', 120], ['Extensión', 30], ['Abducción', 45], ['Aducción', 30], ['Rotación interna', 45], ['Rotación externa', 45]],
        'Rodilla'       => [['Flexión', 135], ['Extensión', 0]],
        'Tobillo y pie' => [['Dorsiflexión', 20], ['Plantiflexión', 50], ['Inversión', 35], ['Eversión', 15]],
    ];
}

/** Pruebas especiales sugeridas por región. */
function fisio_pruebas_region(): array
{
    return [
        'Cervical'      => ['Spurling', 'Distracción cervical', 'ULTT1 (mediano)', 'Flexión craneocervical', 'Flexión-rotación'],
        'Lumbar'        => ['Lasègue (SLR)', 'Lasègue cruzado', 'Slump', 'FABER (Patrick)', 'Gaenslen', 'Extensión en prono', 'Schober'],
        'Hombro'        => ['Neer', 'Hawkins-Kennedy', 'Jobe (lata vacía)', 'Patte', 'Gerber (lift-off)', 'Speed', 'Yergason',
                            'Aprehensión', 'Recolocación', 'Arco doloroso', 'Drop arm', "O'Brien"],
        'Codo'          => ['Cozen', 'Mill', 'Epicondilitis medial (golfista)', 'Tinel cubital', 'Estrés en valgo'],
        'Muñeca y mano' => ['Phalen', 'Tinel carpiano', 'Finkelstein', 'Allen', 'Watson'],
        'Cadera'        => ['FADIR', 'FABER', 'Thomas', 'Trendelenburg', 'Ober', 'Ely'],
        'Rodilla'       => ['Lachman', 'Cajón anterior', 'Cajón posterior', 'Pivot shift', 'McMurray', 'Apley', 'Thessaly',
                            'Bostezo en varo', 'Bostezo en valgo', 'Clarke'],
        'Tobillo y pie' => ['Cajón anterior de tobillo', 'Inclinación talar', 'Thompson', 'Squeeze test', 'Windlass'],
    ];
}

function fisio_grupos_musculares(): array
{
    return ['Flexores de cuello', 'Extensores de cuello', 'Flexores de hombro', 'Abductores de hombro', 'Rotadores externos de hombro',
            'Rotadores internos de hombro', 'Estabilizadores escapulares', 'Flexores de codo', 'Extensores de codo',
            'Extensores de muñeca', 'Flexores de muñeca', 'Prensión', 'Core / transverso abdominal', 'Extensores lumbares',
            'Flexores de cadera', 'Glúteo mayor', 'Glúteo medio', 'Aductores', 'Cuádriceps', 'Isquiotibiales',
            'Tibial anterior', 'Tríceps sural', 'Peroneos'];
}

/** Grados de fuerza (Daniels / MRC). */
function fisio_grados_fuerza(): array
{
    return ['0', '1', '2', '2+', '3-', '3', '3+', '4-', '4', '4+', '5'];
}

function fisio_tipos_ejercicio(): array
{
    return ['Movilidad', 'Estiramiento', 'Fortalecimiento', 'Control motor', 'Propiocepción', 'Funcional', 'Cardiovascular', 'Respiratorio'];
}

/** Color Bootstrap para un valor de EVA. */
function fisio_eva_color(?int $v): string
{
    if ($v === null) return 'secondary';
    if ($v <= 3) return 'success';
    if ($v <= 6) return 'warning';
    return 'danger';
}

/** Decodifica un campo JSON de lista (siempre devuelve array). */
function fisio_json(?string $s): array
{
    if (!$s) return [];
    $d = json_decode($s, true);
    return is_array($d) ? $d : [];
}

/**
 * Lee filas tabulares enviadas como arrays paralelos (campo[col][]) y devuelve
 * solo las que traen algo en la columna clave.
 */
function fisio_filas(string $campo, array $cols, string $clave): array
{
    $in = $_POST[$campo] ?? [];
    if (!is_array($in) || !isset($in[$clave]) || !is_array($in[$clave])) return [];
    $out = [];
    foreach ($in[$clave] as $i => $v) {
        if (trim((string) $v) === '') continue;
        $fila = [];
        foreach ($cols as $c) $fila[$c] = mb_substr(trim((string) ($in[$c][$i] ?? '')), 0, 160);
        $out[] = $fila;
    }
    return $out;
}

/** Texto de dosificación de un ejercicio: "3 × 12 · 30 s · 2 kg". */
function fisio_dosis(array $it): string
{
    $p = [];
    $s = (int) ($it['series'] ?? 0);
    $r = trim((string) ($it['reps'] ?? ''));
    if ($s && $r !== '') $p[] = $s . ' × ' . $r;
    elseif ($s) $p[] = $s . ' ' . t('series');
    elseif ($r !== '') $p[] = $r . ' ' . t('rep.');
    if (!empty($it['segundos'])) $p[] = (int) $it['segundos'] . ' s';
    if (!empty($it['carga'])) $p[] = $it['carga'];
    if (!empty($it['lado']) && $it['lado'] !== 'Ambos') $p[] = $it['lado'];
    return implode(' · ', $p);
}

/** Apego al plan: días registrados en los últimos $dias días. */
function fisio_apego(int $planId, int $dias = 14): array
{
    try {
        $st = db()->prepare('SELECT fecha, dolor, comentario FROM fisio_plan_registro WHERE plan_id = ? AND consultorio_id = ? AND fecha >= ? ORDER BY fecha');
        $st->execute([$planId, tenant_id(), date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'))]);
        $r = $st->fetchAll();
    } catch (Throwable $e) { $r = []; }
    return ['dias' => count($r), 'de' => $dias, 'registros' => $r];
}

/** URL de miniatura de YouTube para un enlace de video (o ''). */
function fisio_youtube_id(?string $url): string
{
    if (!$url) return '';
    return preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([\w-]{11})~', $url, $m) ? $m[1] : '';
}

/**
 * Siembra la biblioteca base de ejercicios del consultorio (una sola vez,
 * cuando está vacía). Son ejercicios clásicos de rehabilitación con
 * instrucciones para el paciente; el fisioterapeuta los edita o agrega los suyos.
 */
function fisio_sembrar_biblioteca(int $tid): void
{
    try {
        $n = db()->prepare('SELECT COUNT(*) FROM fisio_ejercicios WHERE consultorio_id = ?');
        $n->execute([$tid]);
        if ((int) $n->fetchColumn() > 0) return;
        $ins = db()->prepare('INSERT INTO fisio_ejercicios (consultorio_id, nombre, region, tipo, instrucciones, series, reps, segundos) VALUES (?,?,?,?,?,?,?,?)');
        foreach (fisio_biblioteca_base() as [$nom, $reg, $tipo, $ins_txt, $s, $r, $seg]) {
            $ins->execute([$tid, $nom, $reg, $tipo, $ins_txt, $s, $r, $seg]);
        }
    } catch (Throwable $e) { /* tabla aún no creada */ }
}

/** [nombre, región, tipo, instrucciones, series, repeticiones, segundos]. */
function fisio_biblioteca_base(): array
{
    return [
        // Cervical
        ['Retracción cervical (doble mentón)', 'Cervical', 'Control motor', 'Sentado con la espalda recta, lleva la barbilla hacia atrás como haciendo doble mentón, sin inclinar la cabeza. Sostén y regresa despacio.', 3, '10', 5],
        ['Isométricos cervicales', 'Cervical', 'Fortalecimiento', 'Coloca la mano en la frente y empuja la cabeza contra ella sin moverla. Repite hacia atrás y hacia cada lado.', 2, '5 por lado', 6],
        ['Estiramiento de trapecio superior', 'Cervical', 'Estiramiento', 'Sentado, sujeta el asiento con una mano e inclina la cabeza hacia el lado contrario, llevando la oreja al hombro. Sin dolor, solo tensión.', 3, '1 por lado', 30],
        ['Estiramiento de elevador de la escápula', 'Cervical', 'Estiramiento', 'Gira la cabeza 45° hacia un lado y lleva la nariz hacia la axila. Ayúdate suavemente con la mano.', 3, '1 por lado', 30],
        ['Flexión craneocervical en supino', 'Cervical', 'Control motor', 'Boca arriba con una toalla bajo la nuca, asiente suavemente con la cabeza ("sí") sin levantarla. Sostén.', 3, '10', 10],
        // Dorsal
        ['Gato-camello', 'Dorsal', 'Movilidad', 'En cuatro puntos, redondea la espalda llevando la mirada al ombligo y después arquéala llevando la mirada al frente. Movimiento lento.', 2, '10', null],
        ['Libro abierto (rotación torácica)', 'Dorsal', 'Movilidad', 'Acostado de lado con rodillas dobladas y brazos al frente, abre el brazo de arriba hacia el otro lado siguiéndolo con la mirada.', 2, '10 por lado', null],
        ['Extensión torácica sobre toalla', 'Dorsal', 'Movilidad', 'Boca arriba con una toalla enrollada a la altura de los omóplatos, manos detrás de la nuca, deja caer los codos y la cabeza hacia atrás.', 2, '10', 5],
        // Hombro
        ['Péndulo de Codman', 'Hombro', 'Movilidad', 'Inclinado hacia delante apoyado en una mesa, deja colgar el brazo relajado y haz círculos pequeños con el balanceo del cuerpo.', 2, '20 por sentido', null],
        ['Deslizamiento en pared', 'Hombro', 'Movilidad', 'De frente a la pared, desliza las manos hacia arriba lo más alto que puedas sin dolor y regresa despacio.', 3, '10', null],
        ['Rotación externa con banda', 'Hombro', 'Fortalecimiento', 'Codo pegado al cuerpo a 90°, con una toalla entre codo y costado. Lleva la mano hacia fuera estirando la banda y regresa lento.', 3, '12', null],
        ['Rotación interna con banda', 'Hombro', 'Fortalecimiento', 'Codo pegado al cuerpo a 90°. Lleva la mano hacia el abdomen estirando la banda y regresa lento.', 3, '12', null],
        ['Remo con banda', 'Hombro', 'Fortalecimiento', 'Sujeta la banda al frente y tira hacia atrás llevando los codos pegados al cuerpo y juntando los omóplatos.', 3, '12', null],
        ['Elevación en plano escapular', 'Hombro', 'Fortalecimiento', 'Con los pulgares hacia arriba, eleva los brazos en diagonal (30° al frente) hasta la altura de los hombros.', 3, '10', null],
        ['Retracción escapular', 'Hombro', 'Control motor', 'Sentado o de pie, junta los omóplatos hacia atrás y abajo sin subir los hombros. Sostén.', 3, '10', 5],
        ['Estiramiento de cápsula posterior', 'Hombro', 'Estiramiento', 'Lleva el brazo cruzado frente al pecho y empújalo suavemente con la otra mano a la altura del codo.', 3, '1', 30],
        // Codo / muñeca / mano
        ['Excéntricos de extensores de muñeca', 'Codo', 'Fortalecimiento', 'Antebrazo apoyado con la palma hacia abajo y un peso ligero. Sube la muñeca con ayuda de la otra mano y bájala sola, muy lento (3-4 s).', 3, '15', null],
        ['Estiramiento de extensores de muñeca', 'Codo', 'Estiramiento', 'Brazo estirado al frente con la palma hacia abajo. Con la otra mano dobla la muñeca hacia abajo.', 3, '1', 30],
        ['Estiramiento de flexores de muñeca', 'Muñeca y mano', 'Estiramiento', 'Brazo estirado con la palma hacia arriba. Con la otra mano lleva los dedos hacia abajo y atrás.', 3, '1', 30],
        ['Pronosupinación con martillo', 'Codo', 'Fortalecimiento', 'Codo a 90° apoyado, sujeta un martillo o botella por el extremo y gira la palma hacia arriba y hacia abajo lentamente.', 3, '10', null],
        ['Prensión con pelota', 'Muñeca y mano', 'Fortalecimiento', 'Aprieta una pelota blanda con toda la mano, sostén y suelta.', 3, '10', 5],
        ['Deslizamientos tendinosos', 'Muñeca y mano', 'Movilidad', 'Pasa por cada posición: mano abierta, gancho, puño completo, mesa y puño recto.', 2, '10', 3],
        // Lumbar / core
        ['Puente de glúteos', 'Lumbar', 'Fortalecimiento', 'Boca arriba con rodillas dobladas, aprieta glúteos y eleva la cadera hasta alinear rodillas, cadera y hombros. Baja lento.', 3, '12', 3],
        ['Bird-dog (cuadrupedia)', 'Lumbar', 'Control motor', 'En cuatro puntos con la espalda neutra, estira un brazo y la pierna contraria sin girar la cadera. Alterna.', 3, '8 por lado', 5],
        ['Plancha frontal', 'Lumbar', 'Fortalecimiento', 'Apoyado en antebrazos y puntas de los pies (o rodillas), mantén el cuerpo recto sin hundir la zona lumbar.', 3, '1', 20],
        ['Plancha lateral', 'Lumbar', 'Fortalecimiento', 'De lado apoyado en el antebrazo y las rodillas o los pies, eleva la cadera y mantén el cuerpo alineado.', 3, '1 por lado', 20],
        ['Dead bug', 'Lumbar', 'Control motor', 'Boca arriba con brazos al techo y caderas y rodillas a 90°. Baja un brazo y la pierna contraria sin despegar la zona lumbar.', 3, '8 por lado', null],
        ['Rodillas al pecho', 'Lumbar', 'Movilidad', 'Boca arriba, lleva ambas rodillas al pecho abrazándolas y balancéate suavemente.', 2, '10', 5],
        ['Extensión en prono (McKenzie)', 'Lumbar', 'Movilidad', 'Boca abajo, apoya las manos a la altura de los hombros y estira los brazos levantando el tronco; la cadera se queda en el piso.', 2, '10', 2],
        ['Activación del transverso abdominal', 'Lumbar', 'Control motor', 'Boca arriba con rodillas dobladas, al soltar el aire lleva el ombligo suavemente hacia dentro sin mover la espalda. Respira normal.', 3, '10', 10],
        ['Estiramiento de piriforme', 'Lumbar', 'Estiramiento', 'Boca arriba, cruza el tobillo sobre la rodilla contraria y tira de esa pierna hacia el pecho.', 3, '1 por lado', 30],
        // Cadera
        ['Almeja con banda', 'Cadera', 'Fortalecimiento', 'Acostado de lado con rodillas dobladas y banda arriba de las rodillas, abre la rodilla de arriba sin girar la pelvis.', 3, '15', null],
        ['Abducción de cadera de lado', 'Cadera', 'Fortalecimiento', 'Acostado de lado con la pierna de abajo doblada, eleva la de arriba estirada y ligeramente hacia atrás. Baja lento.', 3, '12', null],
        ['Estiramiento de flexores de cadera', 'Cadera', 'Estiramiento', 'En posición de caballero (una rodilla en el piso), mete la pelvis y lleva el cuerpo al frente hasta sentir el estiramiento en la ingle.', 3, '1 por lado', 30],
        ['Estiramiento de isquiotibiales', 'Cadera', 'Estiramiento', 'Boca arriba, con una toalla en el pie, eleva la pierna estirada hasta sentir tensión detrás del muslo.', 3, '1 por lado', 30],
        ['Caminata lateral con banda', 'Cadera', 'Fortalecimiento', 'Con la banda en los tobillos y semiflexión de rodillas, da pasos laterales controlados sin juntar los pies.', 3, '10 pasos por lado', null],
        // Rodilla
        ['Isométrico de cuádriceps', 'Rodilla', 'Fortalecimiento', 'Sentado con la pierna estirada y una toalla bajo la rodilla, aprieta el muslo empujando la toalla hacia el piso.', 3, '10', 5],
        ['Elevación de pierna recta', 'Rodilla', 'Fortalecimiento', 'Boca arriba con la otra rodilla doblada, tensa el muslo y eleva la pierna estirada hasta la altura de la otra rodilla.', 3, '10', 2],
        ['Deslizamiento de talón', 'Rodilla', 'Movilidad', 'Boca arriba, desliza el talón hacia la cadera doblando la rodilla lo más que puedas y regresa.', 3, '10', null],
        ['Extensión terminal de rodilla con banda', 'Rodilla', 'Fortalecimiento', 'De pie con la banda detrás de la rodilla, dobla un poco y estira la rodilla por completo apretando el muslo.', 3, '15', 2],
        ['Sentadilla a silla', 'Rodilla', 'Funcional', 'Pies al ancho de la cadera, siéntate despacio en la silla llevando la cadera atrás y levántate sin usar las manos.', 3, '10', null],
        ['Subir escalón (step-up)', 'Rodilla', 'Funcional', 'Sube a un escalón con una pierna manteniendo la rodilla alineada con el pie, y baja controlado.', 3, '10 por lado', null],
        ['Sentadilla isométrica en pared', 'Rodilla', 'Fortalecimiento', 'Espalda contra la pared, baja hasta que las rodillas queden a unos 60° y mantén la posición.', 4, '1', 30],
        // Tobillo / pie
        ['Bombeo de tobillo', 'Tobillo y pie', 'Movilidad', 'Con la pierna elevada, lleva la punta del pie hacia ti y después hacia abajo de forma continua.', 3, '20', null],
        ['Alfabeto con el tobillo', 'Tobillo y pie', 'Movilidad', 'Sentado con el pie en el aire, dibuja las letras del abecedario con el dedo gordo.', 2, '1 abecedario', null],
        ['Elevación de talones', 'Tobillo y pie', 'Fortalecimiento', 'De pie apoyado en una pared, sube en puntas de pie y baja despacio.', 3, '15', null],
        ['Excéntricos de gemelos (Alfredson)', 'Tobillo y pie', 'Fortalecimiento', 'En el borde de un escalón, sube con ambos pies y baja solo con el afectado, muy lento, hasta pasar el nivel del escalón.', 3, '15', null],
        ['Equilibrio monopodal', 'Tobillo y pie', 'Propiocepción', 'Párate en un solo pie cerca de un apoyo. Para progresar: ojos cerrados o superficie blanda.', 3, '1 por lado', 30],
        ['Estiramiento de gemelos en pared', 'Tobillo y pie', 'Estiramiento', 'Manos en la pared, pierna de atrás estirada con el talón en el piso; lleva la cadera al frente.', 3, '1 por lado', 30],
        ['Dorsiflexión con banda', 'Tobillo y pie', 'Fortalecimiento', 'Sentado con la banda sujeta al frente sobre el empeine, lleva la punta del pie hacia ti y regresa lento.', 3, '15', null],
        ['Recoger toalla con los dedos', 'Tobillo y pie', 'Fortalecimiento', 'Sentado con una toalla en el piso, arrúgala hacia ti usando solo los dedos del pie.', 3, '1', 60],
        // General
        ['Caminata', 'General', 'Cardiovascular', 'Camina a paso cómodo en terreno plano; deberías poder platicar mientras caminas.', 1, '1', 1200],
        ['Bicicleta estática', 'General', 'Cardiovascular', 'Sin resistencia o con resistencia ligera, ritmo constante.', 1, '1', 900],
        ['Respiración diafragmática', 'Respiratorio', 'Respiratorio', 'Una mano en el pecho y otra en el abdomen: inhala por la nariz inflando el abdomen y exhala lento por la boca.', 3, '10', null],
    ];
}

/** Ejercicios de un plan, en orden. */
function fisio_plan_items(int $planId): array
{
    $st = db()->prepare('SELECT * FROM fisio_plan_items WHERE plan_id = ? ORDER BY orden, id');
    $st->execute([$planId]);
    return $st->fetchAll();
}

/** Mensaje de WhatsApp con la rutina (y el enlace al portal si está activo). */
function fisio_plan_wa_mensaje(array $plan, array $pac): string
{
    $m = sprintf(t('Hola %s, esta es tu rutina de ejercicios "%s":'), $pac['nombre'] ?? '', $plan['nombre']) . "\n";
    if (!empty($plan['frecuencia'])) $m .= t('Frecuencia:') . ' ' . $plan['frecuencia'] . "\n";
    $m .= "\n";
    foreach (fisio_plan_items((int) $plan['id']) as $i => $it) {
        $d = fisio_dosis($it);
        $m .= ($i + 1) . '. ' . $it['nombre'] . ($d !== '' ? ' — ' . $d : '') . "\n";
    }
    if (!empty($plan['indicaciones'])) $m .= "\n" . $plan['indicaciones'] . "\n";
    if ($plan['portal'] && modulo_activo('portal')) {
        $m .= "\n" . t('Ve las instrucciones y marca cada día que la hagas en tu portal:') . ' ' . url_absoluta('/portal/ejercicios');
    }
    $m .= "\n" . t('Si algún ejercicio te provoca dolor fuerte, suspéndelo y avísanos.') . "\n— " . marca_nombre();
    return $m;
}
