<?php
/**
 * Reporte de Consulta Externa para la DGIS (GIIS-B015).
 *
 * No se escriben aquí las variables de la guía: cambian de versión. El dueño
 * sube el DICCIONARIO DE DATOS vigente (CSV) en /platform/giis y asigna a cada
 * variable un campo de MediAgenda (o un valor fijo), con su formato y su
 * equivalencia de valores (p. ej. sexo M=H, F=M). Con ese mapeo cada
 * consultorio genera su reporte en Expediente → Reporte DGIS, con las
 * validaciones de la guía (obligatoriedad y longitud).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/catalogos.php';

/** Campos de MediAgenda disponibles para mapear. */
function giis_fuentes(): array
{
    return [
        ''                  => '— Sin asignar —',
        'constante'         => 'Valor fijo (escríbelo en "Valor")',
        'vacio'             => 'Vacío',
        'clues'             => 'CLUES del consultorio',
        'consulta_id'       => 'Folio interno de la consulta',
        'consulta_fecha'    => 'Fecha de la consulta',
        'consulta_hora'     => 'Hora de la consulta',
        'primera_vez'       => 'Primera vez en el consultorio (1) / subsecuente (0)',
        'pac_curp'          => 'Paciente: CURP',
        'pac_curp_motivo'   => 'Paciente: motivo de no tener CURP',
        'pac_nombre'        => 'Paciente: nombre(s)',
        'pac_primer_ap'     => 'Paciente: primer apellido',
        'pac_segundo_ap'    => 'Paciente: segundo apellido',
        'pac_nacimiento'    => 'Paciente: fecha de nacimiento',
        'pac_edad'          => 'Paciente: edad en años cumplidos',
        'pac_sexo'          => 'Paciente: sexo (M masculino, F femenino, O otro)',
        'pac_ent_nac'       => 'Paciente: entidad de nacimiento (INEGI o NE)',
        'pac_nacionalidad'  => 'Paciente: nacionalidad (ISO alfa-3)',
        'pac_res_ent'       => 'Paciente: entidad de residencia (2 dígitos)',
        'pac_res_mun'       => 'Paciente: municipio de residencia (3 dígitos)',
        'pac_res_loc'       => 'Paciente: localidad de residencia (4 dígitos)',
        'peso'              => 'Peso (kg)',
        'talla'             => 'Talla (cm)',
        'imc'               => 'IMC',
        'ta_sis'            => 'Tensión arterial sistólica',
        'ta_dia'            => 'Tensión arterial diastólica',
        'temperatura'       => 'Temperatura (°C)',
        'fc'                => 'Frecuencia cardiaca',
        'fr'                => 'Frecuencia respiratoria',
        'saturacion'        => 'Saturación de oxígeno',
        'glucosa'           => 'Glucosa capilar',
        'dx1'               => 'Diagnóstico principal (CIE-10)',
        'dx2'               => 'Diagnóstico 2 (CIE-10)',
        'dx3'               => 'Diagnóstico 3 (CIE-10)',
        'dx_texto'          => 'Diagnóstico (texto)',
        'motivo'            => 'Motivo de consulta',
        'pronostico'        => 'Pronóstico',
        'med_nombre'        => 'Médico: nombre',
        'med_cedula'        => 'Médico: cédula profesional',
        'med_especialidad'  => 'Médico: especialidad',
    ];
}

function giis_diccionario(): array { return json_decode(plataforma_cfg('giis_b015_dic'), true) ?: []; }
function giis_mapeo(): array { return json_decode(plataforma_cfg('giis_b015_map'), true) ?: []; }

/** Lee el diccionario de datos (CSV) de la guía. Devuelve la lista de variables. */
function giis_leer_diccionario(string $ruta): array
{
    $raw = (string) file_get_contents($ruta);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $primera = strtok($raw, "\n") ?: '';
    $sep = ','; $max = 0;
    foreach ([',', ';', "\t", '|'] as $c) { $n = substr_count($primera, $c); if ($n > $max) { $max = $n; $sep = $c; } }
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $enc = array_map('cat_norm', fgetcsv($fh, 0, $sep, '"', '\\') ?: []);
    $busca = function (array $sin) use ($enc) { foreach ($enc as $i => $h) foreach ($sin as $s) if ($h === $s || str_contains($h, $s)) return $i; return null; };
    $iVar = $busca(['variable', 'nombre del campo', 'campo', 'nombre corto']);
    $iDes = $busca(['descripcion', 'definicion', 'nombre largo']);
    $iTip = $busca(['tipo']);
    $iLon = $busca(['longitud', 'tamano', 'largo']);
    $iObl = $busca(['obligatori', 'requerid']);
    $iCat = $busca(['catalogo']);
    if ($iVar === null) throw new RuntimeException('No encontré la columna con el nombre de la variable. Encabezados: ' . implode(', ', array_slice($enc, 0, 12)));
    $vars = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        $v = trim((string) ($r[$iVar] ?? ''));
        if ($v === '') continue;
        $o = mb_strtolower(trim((string) ($iObl !== null ? ($r[$iObl] ?? '') : '')));
        $vars[] = [
            'var'  => mb_substr($v, 0, 80),
            'desc' => mb_substr(trim((string) ($iDes !== null ? ($r[$iDes] ?? '') : '')), 0, 300),
            'tipo' => mb_substr(trim((string) ($iTip !== null ? ($r[$iTip] ?? '') : '')), 0, 40),
            'lon'  => (int) preg_replace('/\D.*/', '', trim((string) ($iLon !== null ? ($r[$iLon] ?? '') : ''))),
            'obl'  => $o !== '' && (str_starts_with($o, 's') || str_starts_with($o, 'obl') || $o === 'x' || $o === '1' || $o === 'r'),
            'cat'  => mb_substr(trim((string) ($iCat !== null ? ($r[$iCat] ?? '') : '')), 0, 80),
        ];
    }
    fclose($fh);
    if (!$vars) throw new RuntimeException('El diccionario no trae variables.');
    return $vars;
}

/** Valor de una fuente para una consulta. */
function giis_valor(string $f, array $c, array $m): string
{
    $cie = array_values(array_filter(array_map('trim', explode(',', (string) ($c['cie10'] ?? '')))));
    [$sis, $dia] = array_pad(array_map('trim', explode('/', (string) ($c['presion'] ?? ''), 2)), 2, '');
    $fechaFmt = fn ($d) => $d ? date($m['fmt'] ?: 'Y-m-d', strtotime($d)) : '';
    $num = fn ($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.');
    $v = match ($f) {
        'constante'        => (string) ($m['valor'] ?? ''),
        'clues'            => (string) cfg('clues'),
        'consulta_id'      => (string) $c['id'],
        'consulta_fecha'   => $fechaFmt($c['fecha']),
        'consulta_hora'    => date('H:i', strtotime($c['fecha'])),
        'primera_vez'      => (string) (int) $c['primera_vez'],
        'pac_curp'         => (string) ($c['curp'] ?? ''),
        'pac_curp_motivo'  => (string) ($c['curp_motivo'] ?? ''),
        'pac_nombre'       => (string) $c['pac_nombre'],
        'pac_primer_ap'    => (string) ($c['primer_apellido'] ?? ''),
        'pac_segundo_ap'   => (string) ($c['segundo_apellido'] ?? ''),
        'pac_nacimiento'   => $fechaFmt($c['fecha_nacimiento'] ?? null),
        'pac_edad'         => !empty($c['fecha_nacimiento']) ? (string) (new DateTime($c['fecha_nacimiento']))->diff(new DateTime(substr($c['fecha'], 0, 10)))->y : '',
        'pac_sexo'         => (string) ($c['sexo'] ?? ''),
        'pac_ent_nac'      => (string) ($c['entidad_nacimiento'] ?? ''),
        'pac_nacionalidad' => (string) ($c['nacionalidad'] ?? ''),
        'pac_res_ent'      => (string) ($c['res_entidad'] ?? ''),
        'pac_res_mun'      => !empty($c['res_municipio']) ? substr($c['res_municipio'], 2, 3) : '',
        'pac_res_loc'      => !empty($c['res_localidad']) ? substr($c['res_localidad'], 5, 4) : '',
        'peso'             => $num($c['peso'] ?? null),
        'talla'            => $num($c['estatura'] ?? null),
        'imc'              => !empty($c['peso']) && !empty($c['estatura']) ? $num($c['peso'] / pow($c['estatura'] / 100, 2)) : '',
        'ta_sis'           => preg_replace('/\D/', '', $sis),
        'ta_dia'           => preg_replace('/\D/', '', $dia),
        'temperatura'      => $num($c['temperatura'] ?? null),
        'fc'               => (string) ($c['frecuencia_cardiaca'] ?? ''),
        'fr'               => (string) ($c['frecuencia_respiratoria'] ?? ''),
        'saturacion'       => (string) ($c['saturacion'] ?? ''),
        'glucosa'          => $num($c['glucosa'] ?? null),
        'dx1'              => str_replace('.', '', $cie[0] ?? ''),
        'dx2'              => str_replace('.', '', $cie[1] ?? ''),
        'dx3'              => str_replace('.', '', $cie[2] ?? ''),
        'dx_texto'         => (string) ($c['diagnostico'] ?? ''),
        'motivo'           => (string) ($c['motivo'] ?? ''),
        'pronostico'       => (string) ($c['pronostico'] ?? ''),
        'med_nombre'       => (string) $c['med_nombre'],
        'med_cedula'       => (string) ($c['med_cedula'] ?? ''),
        'med_especialidad' => (string) ($c['med_especialidad'] ?? ''),
        default            => '',
    };
    // Equivalencias "M=H, F=M" (valor del sistema = valor de la guía).
    if (!empty($m['mapa']) && $v !== '') {
        foreach (preg_split('/[,;\n]+/', $m['mapa']) as $par) {
            [$a, $b] = array_pad(array_map('trim', explode('=', $par, 2)), 2, null);
            if ($a !== null && $b !== null && strcasecmp($a, $v) === 0) { $v = $b; break; }
        }
    }
    if (!empty($m['mayus'])) $v = mb_strtoupper($v);
    return preg_replace('/[\r\n\t|]+/', ' ', $v);
}

/**
 * Genera las filas del reporte de un consultorio y un periodo.
 * @return array{enc:array, filas:array, errores:array, total:int}
 */
function giis_reporte(int $tid, string $desde, string $hasta, int $maxErrores = 300): array
{
    $dic = giis_diccionario(); $map = giis_mapeo();
    if (!$dic) throw new RuntimeException('La plataforma aún no carga el diccionario de la GIIS-B015.');
    asegurar_identificacion();
    $sql = "SELECT co.*, p.nombre pac_nombre, p.curp, p.fecha_nacimiento, p.sexo, p.primer_apellido, p.segundo_apellido, p.entidad_nacimiento,
                   p.nacionalidad, p.res_entidad, p.res_municipio, p.res_localidad, p.curp_motivo,
                   u.nombre med_nombre, u.cedula med_cedula, u.especialidad med_especialidad,
                   (SELECT COUNT(*) FROM consultas c2 WHERE c2.consultorio_id = co.consultorio_id AND c2.paciente_id = co.paciente_id AND c2.fecha < co.fecha) = 0 AS primera_vez
            FROM consultas co JOIN pacientes p ON p.id = co.paciente_id JOIN usuarios u ON u.id = co.medico_id
            WHERE co.consultorio_id = ? AND co.fecha >= ? AND co.fecha < ? ORDER BY co.fecha";
    $st = db()->prepare($sql);
    $st->execute([$tid, $desde . ' 00:00:00', date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00']);
    $enc = array_column($dic, 'var');
    $filas = []; $errores = [];
    foreach ($st as $c) {
        $f = [];
        foreach ($dic as $d) {
            $m = $map[$d['var']] ?? ['fuente' => ''];
            $val = $m['fuente'] ? giis_valor($m['fuente'], $c, $m) : '';
            if ($d['lon'] > 0 && mb_strlen($val) > $d['lon']) {
                if (count($errores) < $maxErrores) $errores[] = ['consulta' => (int) $c['id'], 'var' => $d['var'], 'msg' => 'pasa de ' . $d['lon'] . ' caracteres'];
                $val = mb_substr($val, 0, $d['lon']);
            }
            if ($d['obl'] && $val === '' && count($errores) < $maxErrores) $errores[] = ['consulta' => (int) $c['id'], 'var' => $d['var'], 'msg' => 'obligatoria y vacía'];
            $f[] = $val;
        }
        $filas[] = $f;
    }
    return ['enc' => $enc, 'filas' => $filas, 'errores' => $errores, 'total' => count($filas)];
}
