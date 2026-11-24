<?php
/**
 * Giros: la interfaz que elige cada consultorio al contratar.
 *
 * Un mismo MediOS sirve a un médico general, a un dentista, a una psicóloga o
 * a una clínica veterinaria; lo que cambia es cómo se HABLA (vocabulario),
 * qué herramientas van al frente (accesos de su especialidad) y, en
 * veterinaria, qué datos lleva el expediente (especie, raza, propietario).
 *
 * Se guarda en la configuración del consultorio (cfg 'giro'); se elige en el
 * registro y se cambia en Configuración. 'medico' es el comportamiento de
 * siempre, así que un consultorio que nunca eligió no nota nada.
 *
 * El vocabulario se aplica dentro de t(): todo texto que ya pasa por el
 * traductor se adapta solo. Va por PALABRA completa (no por subcadena) para
 * que "Consulta → Sesión" no convierta "Consultar" en "Sesiónr".
 */

/** Catálogo de giros. */
function giros(): array
{
    static $g = null;
    if ($g !== null) return $g;

    // Accesos de especialidad: [ruta del módulo por paciente, etiqueta, icono].
    $A = [
        'dental'       => ['/dental/index', 'Expediente dental', 'bi-clipboard2-heart'],
        'odontograma'  => ['/dental/carta', 'Carta dental', 'bi-emoji-smile'],
        'psicologia'   => ['/psicologia/index', 'Psicología', 'bi-chat-heart'],
        'psiquiatria'  => ['/psiquiatria/index', 'Psiquiatría', 'bi-person-heart'],
        'nutricion'    => ['/nutricion/index', 'Nutrición', 'bi-egg-fried'],
        'crecimiento'  => ['/crecimiento/index', 'Crecimiento', 'bi-graph-up-arrow'],
        'oftalmologia' => ['/oftalmologia/index', 'Oftalmología', 'bi-eye'],
        'optica'       => ['/optica/graduacion', 'Graduación', 'bi-eyeglasses'],
        'dermatologia' => ['/dermatologia/index', 'Dermatología', 'bi-bandaid'],
        'estetica'     => ['/estetica/index', 'Medicina estética', 'bi-magic'],
        'cirugia'      => ['/cirugia/index', 'Cirugía', 'bi-scissors'],
        'cardiologia'  => ['/cardiologia/index', 'Cardiología', 'bi-heart-pulse'],
        'vacunas'      => ['/vacunacion/paciente', 'Vacunas', 'bi-shield-plus'],
        'fisioterapia' => ['/fisioterapia/index', 'Fisioterapia', 'bi-person-walking'],
        'mapacorporal' => ['/mapacorporal/index', 'Mapa corporal 3D', 'bi-person-bounding-box'],
    ];

    $vet = [
        // Pacientes → mascotas (con su género: "la mascota", "nueva mascota").
        'Nuevo paciente' => 'Nueva mascota', 'nuevo paciente' => 'nueva mascota',
        'Nuevos pacientes' => 'Nuevas mascotas', 'nuevos pacientes' => 'nuevas mascotas',
        'Pacientes nuevos' => 'Mascotas nuevas', 'pacientes nuevos' => 'mascotas nuevas',
        'primer paciente' => 'primera mascota', 'Primer paciente' => 'Primera mascota',
        'Paciente registrado' => 'Mascota registrada', 'Paciente actualizado' => 'Mascota actualizada',
        'Paciente eliminado' => 'Mascota eliminada',
        'Portal del paciente' => 'Portal del tutor', 'portal del paciente' => 'portal del tutor',
        'Portal de pacientes' => 'Portal de tutores',
        // Propietario / dueño → tutor (así se dice hoy en las veterinarias de LATAM).
        'Nombre del propietario' => 'Nombre del tutor', 'Apellidos del propietario' => 'Apellidos del tutor',
        'Teléfono del propietario' => 'Teléfono del tutor', 'Correo del propietario' => 'Correo del tutor',
        'Portal del propietario' => 'Portal del tutor', 'del propietario' => 'del tutor', 'al propietario' => 'al tutor',
        'el propietario' => 'el tutor', 'Propietario' => 'Tutor', 'propietario' => 'tutor', 'Propietarios' => 'Tutores', 'propietarios' => 'tutores',
        'del dueño' => 'del tutor', 'al dueño' => 'al tutor', 'el dueño' => 'el tutor', 'Dueño' => 'Tutor', 'dueño' => 'tutor', 'dueños' => 'tutores',
        'del paciente' => 'de la mascota', 'Del paciente' => 'De la mascota',
        'al paciente' => 'a la mascota', 'el paciente' => 'la mascota', 'El paciente' => 'La mascota',
        'un paciente' => 'una mascota', 'Un paciente' => 'Una mascota', 'este paciente' => 'esta mascota',
        'Este paciente' => 'Esta mascota', 'los pacientes' => 'las mascotas', 'Los pacientes' => 'Las mascotas',
        'Paciente' => 'Mascota', 'paciente' => 'mascota', 'Pacientes' => 'Mascotas', 'pacientes' => 'mascotas',
        // Médicos → veterinarios.
        'Médicos' => 'Veterinarios', 'médicos' => 'veterinarios', 'Médico' => 'Veterinario', 'médico' => 'veterinario',
        'Nuevo médico' => 'Nuevo veterinario',
        // Consultorio → clínica (con su género).
        'Nombre del consultorio' => 'Nombre de la clínica', 'Datos del consultorio' => 'Datos de la clínica',
        'del consultorio' => 'de la clínica', 'Del consultorio' => 'De la clínica', 'al consultorio' => 'a la clínica',
        'el consultorio' => 'la clínica', 'El consultorio' => 'La clínica', 'este consultorio' => 'esta clínica',
        'Este consultorio' => 'Esta clínica', 'un consultorio' => 'una clínica', 'nuestro consultorio' => 'nuestra clínica',
        'Tu consultorio' => 'Tu clínica', 'tu consultorio' => 'tu clínica',
        'Consultorio' => 'Clínica', 'consultorio' => 'clínica', 'Consultorios' => 'Clínicas', 'consultorios' => 'clínicas',
        // Validaciones del formulario (el campo "Apellidos" es del propietario).
        'Los apellidos son obligatorios.' => 'Los apellidos del tutor son obligatorios.',
        'El nombre es obligatorio.' => 'El nombre de la mascota es obligatorio.',
    ];

    $sesion = [
        'Nueva consulta' => 'Nueva sesión', 'nueva consulta' => 'nueva sesión',
        'Consultas' => 'Sesiones', 'consultas' => 'sesiones', 'Consulta' => 'Sesión', 'consulta' => 'sesión',
    ];

    return $g = [
        'medico' => ['nombre' => 'Medicina general', 'icono' => 'bi-heart-pulse', 'desc' => 'Consultorio médico, de cualquier especialidad.',
                     'staff' => 'Médicos', 'accesos' => ['mapacorporal', 'crecimiento'], 'vocab' => []],
        'dental' => ['nombre' => 'Odontología', 'icono' => 'bi-emoji-smile', 'desc' => 'Carta dental, fotos clínicas, periodontograma y plan de tratamiento.',
                     'staff' => 'Odontólogos', 'accesos' => ['dental', 'odontograma'], 'tipo' => 'dental',
                     'vocab' => ['Médicos' => 'Odontólogos', 'Médico' => 'Odontólogo', 'Nuevo médico' => 'Nuevo odontólogo',
                                 'médicos' => 'odontólogos']],
        'psicologia' => ['nombre' => 'Psicología', 'icono' => 'bi-chat-heart', 'desc' => 'Habla de sesiones, con la nota psicológica a un clic.',
                     'staff' => 'Psicólogos', 'accesos' => ['psicologia'],
                     'vocab' => $sesion + ['Médicos' => 'Psicólogos', 'Médico' => 'Psicólogo', 'Nuevo médico' => 'Nuevo psicólogo',
                                           'médicos' => 'psicólogos']],
        // Clínica de salud mental: psicólogos y psiquiatras en la misma agenda.
        // Se dice "tratante" porque el equipo no es solo de médicos.
        'psiquiatria' => ['nombre' => 'Psiquiatría y salud mental', 'icono' => 'bi-person-heart',
                     'desc' => 'Nota psiquiátrica con examen mental, riesgo suicida, escalas y control de psicofármacos; psicología a un clic.',
                     'staff' => 'Tratantes', 'accesos' => ['psiquiatria', 'psicologia'],
                     'vocab' => ['Médicos' => 'Tratantes', 'Médico' => 'Tratante', 'Nuevo médico' => 'Nuevo tratante',
                                 'médicos' => 'tratantes',
                                 // En salud mental se dice internamiento / programa residencial.
                                 'Hospitalización' => 'Internamiento', 'Hospitalizados' => 'Internados',
                                 'Hospitalizado' => 'Internado', 'hospitalizado' => 'internado', 'Ese paciente ya está hospitalizado.' => 'Ese paciente ya está internado.',
                                 'No hay pacientes hospitalizados.' => 'No hay pacientes internados.']],
        'nutricion' => ['nombre' => 'Nutrición', 'icono' => 'bi-egg-fried', 'desc' => 'Valoración nutricional y curvas de crecimiento al frente.',
                     'staff' => 'Nutriólogos', 'accesos' => ['nutricion', 'crecimiento'],
                     'vocab' => ['Médicos' => 'Nutriólogos', 'Médico' => 'Nutriólogo', 'Nuevo médico' => 'Nuevo nutriólogo',
                                 'médicos' => 'nutriólogos']],
        'pediatria' => ['nombre' => 'Pediatría', 'icono' => 'bi-balloon-heart', 'desc' => 'Curvas de crecimiento a un clic en cada expediente.',
                     'staff' => 'Pediatras', 'accesos' => ['vacunas', 'crecimiento'],
                     'vocab' => ['Médicos' => 'Pediatras', 'Nuevo médico' => 'Nuevo pediatra']],
        'oftalmologia' => ['nombre' => 'Oftalmología y óptica', 'icono' => 'bi-eye', 'desc' => 'Examen oftalmológico y graduación al frente.',
                     'staff' => 'Oftalmólogos', 'accesos' => ['oftalmologia', 'optica'],
                     'vocab' => []],
        'estetica' => ['nombre' => 'Dermatología y estética', 'icono' => 'bi-magic', 'desc' => 'Dermatología, estética y mapa corporal al frente.',
                     'staff' => 'Médicos', 'accesos' => ['dermatologia', 'estetica'], 'vocab' => []],
        'fisioterapia' => ['nombre' => 'Fisioterapia y rehabilitación', 'icono' => 'bi-person-walking', 'desc' => 'Historia clínica, notas SOAP y planes de ejercicio en casa.',
                     'staff' => 'Fisioterapeutas', 'accesos' => ['fisioterapia', 'mapacorporal'],
                     'vocab' => $sesion + ['Médicos' => 'Fisioterapeutas', 'Médico' => 'Fisioterapeuta', 'Nuevo médico' => 'Nuevo fisioterapeuta',
                                           'médicos' => 'fisioterapeutas']],
        'veterinaria' => ['nombre' => 'Veterinaria', 'icono' => 'bi-house-heart', 'desc' => 'Mascotas con especie, raza y tutor; vacunas y recordatorios le llegan al tutor.',
                     'staff' => 'Veterinarios', 'accesos' => ['vacunas', 'cirugia', 'dermatologia'], 'vocab' => $vet],
        'clinica' => ['nombre' => 'Clínica u hospital', 'icono' => 'bi-hospital', 'desc' => 'Varias especialidades: todas las herramientas a la mano.',
                     'staff' => 'Médicos', 'accesos' => [], 'vocab' => []],
    ] + ['_accesos' => $A];
}

/** Lista para selectores: clave => definición (sin la tabla interna de accesos). */
function giros_lista(): array
{
    $g = giros();
    unset($g['_accesos']);
    return $g;
}

/** Giro del consultorio activo ('medico' si nunca eligió). */
function giro_actual(): string
{
    $g = (string) cfg('giro', 'medico');
    return isset(giros_lista()[$g]) ? $g : 'medico';
}

function giro(): array { return giros_lista()[giro_actual()]; }

function es_veterinaria(): bool { return giro_actual() === 'veterinaria'; }

/** Accesos de especialidad del giro: lista de [ruta, etiqueta, icono]. */
function giro_accesos(): array
{
    $A = giros()['_accesos'];
    return array_values(array_filter(array_map(fn ($k) => $A[$k] ?? null, giro()['accesos'])));
}

/**
 * ¿Se ofrece este módulo de especialidad en el expediente? En veterinaria se
 * ocultan los que solo tienen sentido en humanos (prenatal, urología…).
 */
function giro_muestra_especialidad(string $modulo): bool
{
    if (!es_veterinaria()) return giro_aplica($modulo);   // p. ej. prenatal solo en medicina general/clínica
    return in_array($modulo, ['cirugia', 'dermatologia', 'cardiologia', 'laboratorio', 'imagenologia', 'oftalmologia', 'traumatologia'], true);
}

/** Aplica el vocabulario del giro a un texto en español (lo llama t()). */
function giro_vocab(string $s): string
{
    static $re = [], $cache = [];
    $g = giro_actual();
    $map = giros_lista()[$g]['vocab'] ?? [];
    if (!$map || $s === '') return $s;
    if (isset($cache[$g][$s])) return $cache[$g][$s];

    if (!isset($re[$g])) {
        $claves = array_keys($map);
        usort($claves, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // frases largas primero
        $re[$g] = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn ($k) => preg_quote($k, '/'), $claves)) . ')(?![\p{L}\p{N}])/u';
    }
    $out = preg_replace_callback($re[$g], fn ($m) => $map[$m[1]] ?? $m[1], $s);
    if (count($cache[$g] ?? []) < 2000) $cache[$g][$s] = $out ?? $s;
    return $out ?? $s;
}

/* --------------------------------------------------------------------
 *  Veterinaria: datos propios del expediente de una mascota.
 * ------------------------------------------------------------------ */

function especies_vet(): array
{
    return ['Perro', 'Gato', 'Ave', 'Conejo', 'Roedor', 'Hurón', 'Reptil', 'Caballo', 'Bovino', 'Otro'];
}

/** Columnas de mascota en pacientes (sin migración manual; solo si faltan). */
function asegurar_campos_veterinaria(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    try {
        $pdo = db();
        if ($pdo->query("SHOW COLUMNS FROM pacientes LIKE 'especie'")->fetch()) return;
        $pdo->exec("ALTER TABLE pacientes
            ADD COLUMN IF NOT EXISTS especie     VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS raza        VARCHAR(80)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS color       VARCHAR(60)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS propietario VARCHAR(120) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS microchip   VARCHAR(40)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS esterilizado TINYINT(1)  DEFAULT NULL");
    } catch (Throwable $e) { /* sin permisos de ALTER: el formulario guarda lo demás */ }
}

/** "Perro · Labrador · Macho" para mostrar bajo el nombre de la mascota. */
function mascota_resumen(array $p): string
{
    $sexo = ['M' => t('Macho'), 'F' => t('Hembra')][$p['sexo'] ?? ''] ?? '';
    return implode(' · ', array_filter([$p['especie'] ?? '', $p['raza'] ?? '', $sexo]));
}

/** Nombre completo del propietario (nombre + apellidos de la ficha). */
function mascota_propietario(array $p): string
{
    return trim(($p['propietario'] ?? '') . ' ' . ($p['apellidos'] ?? ''));
}

/**
 * Qué tan relevante es un módulo para el giro del consultorio:
 *   'frente' → es de su especialidad: al frente en menú y ficha.
 *   'mas'    → puede servirle a veces: plegado en "Más herramientas".
 *   'nunca'  → no tiene sentido (Óptica o Prenatal en una veterinaria,
 *              Laboratorio en psicología): no se muestra en ningún lado.
 * Un módulo que no está en la tabla es general y va al frente.
 */
function giro_nivel(string $modulo, ?string $giro = null): string
{
    $g = $giro ?? giro_actual();
    $tabla = [
        //                frente                                                         nunca
        'optica'       => [['oftalmologia', 'clinica'],                                     ['psiquiatria', 'veterinaria', 'psicologia', 'dental', 'nutricion', 'fisioterapia']],
        'imagenologia' => [['medico', 'clinica', 'veterinaria'],                            ['psiquiatria', 'psicologia', 'nutricion']],
        'laboratorio'  => [['medico', 'clinica', 'veterinaria', 'nutricion', 'pediatria', 'estetica', 'psiquiatria'], ['psicologia']],
        'psiquiatria'  => [['psiquiatria', 'clinica', 'medico'],                             ['veterinaria', 'dental', 'oftalmologia', 'estetica', 'fisioterapia', 'nutricion']],
        'farmacia'     => [['medico', 'clinica', 'veterinaria', 'dental', 'estetica', 'nutricion', 'oftalmologia', 'pediatria'], []],
        'odontograma'  => [['dental', 'clinica'],                                           ['psiquiatria', 'veterinaria', 'psicologia', 'oftalmologia', 'nutricion', 'pediatria', 'estetica', 'fisioterapia']],
        'crecimiento'  => [['pediatria', 'nutricion', 'medico', 'clinica'],                 ['psiquiatria', 'veterinaria', 'psicologia', 'oftalmologia', 'dental', 'estetica', 'fisioterapia']],
        'prenatal'     => [['medico', 'clinica'],                                           ['psiquiatria', 'veterinaria', 'psicologia', 'oftalmologia', 'dental', 'nutricion', 'pediatria', 'estetica', 'fisioterapia']],
        'hospitalizacion' => [['clinica', 'veterinaria', 'psiquiatria'],                                    ['psicologia', 'nutricion', 'oftalmologia', 'dental', 'estetica', 'fisioterapia']],
        'mapacorporal' => [['medico', 'clinica', 'estetica', 'fisioterapia'],               ['psiquiatria', 'veterinaria', 'psicologia', 'oftalmologia', 'dental']],
    ];
    if (!isset($tabla[$modulo])) return 'frente';
    [$frente, $nunca] = $tabla[$modulo];
    return in_array($g, $frente, true) ? 'frente' : (in_array($g, $nunca, true) ? 'nunca' : 'mas');
}

/**
 * ¿Se usa la distinción de paciente/cita "Médico / Dental"? Viene de cuando el
 * sistema era para médicos y dentistas: solo tiene sentido en odontología y
 * clínicas, o en medicina general si ya hay pacientes dentales. En psiquiatría,
 * psicología, nutrición… se oculta y el tipo se asigna solo.
 */
function giro_usa_tipo_dental(): bool
{
    static $cache = [];
    $g = giro_actual();
    if (in_array($g, ['dental', 'clinica'], true)) return true;
    if ($g !== 'medico') return false;
    $tid = (int) tenant_id();
    if (!isset($cache[$tid])) {
        try {
            $st = db()->prepare("SELECT 1 FROM pacientes WHERE consultorio_id = ? AND tipo = 'dental' LIMIT 1");
            $st->execute([$tid]);
            $cache[$tid] = (bool) $st->fetchColumn();
        } catch (Throwable $e) { $cache[$tid] = false; }
    }
    return $cache[$tid];
}

/** Tipo por defecto del giro ('medico' | 'dental'). */
function giro_tipo_paciente(): string { return (giro()['tipo'] ?? 'medico') === 'dental' ? 'dental' : 'medico'; }

/** ¿El módulo se muestra (al frente o en "más") para este giro? */
function giro_aplica(string $modulo): bool { return giro_nivel($modulo) !== 'nunca'; }

/** ¿Este módulo va al frente del menú? (si no, a "Más herramientas"). */
function giro_modulo_al_frente(string $modulo): bool { return giro_nivel($modulo) === 'frente'; }
