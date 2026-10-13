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
        'odontograma'  => ['/odontograma/index', 'Odontograma', 'bi-emoji-smile'],
        'psicologia'   => ['/psicologia/index', 'Psicología', 'bi-chat-heart'],
        'nutricion'    => ['/nutricion/index', 'Nutrición', 'bi-egg-fried'],
        'crecimiento'  => ['/crecimiento/index', 'Crecimiento', 'bi-graph-up-arrow'],
        'oftalmologia' => ['/oftalmologia/index', 'Oftalmología', 'bi-eye'],
        'optica'       => ['/optica/graduacion', 'Graduación', 'bi-eyeglasses'],
        'dermatologia' => ['/dermatologia/index', 'Dermatología', 'bi-bandaid'],
        'estetica'     => ['/estetica/index', 'Medicina estética', 'bi-magic'],
        'cirugia'      => ['/cirugia/index', 'Cirugía', 'bi-scissors'],
        'cardiologia'  => ['/cardiologia/index', 'Cardiología', 'bi-heart-pulse'],
    ];

    $vet = [
        // Pacientes → mascotas (con su género: "la mascota", "nueva mascota").
        'Nuevo paciente' => 'Nueva mascota', 'nuevo paciente' => 'nueva mascota',
        'Nuevos pacientes' => 'Nuevas mascotas', 'nuevos pacientes' => 'nuevas mascotas',
        'Pacientes nuevos' => 'Mascotas nuevas', 'pacientes nuevos' => 'mascotas nuevas',
        'primer paciente' => 'primera mascota', 'Primer paciente' => 'Primera mascota',
        'Paciente registrado' => 'Mascota registrada', 'Paciente actualizado' => 'Mascota actualizada',
        'Paciente eliminado' => 'Mascota eliminada',
        'Portal del paciente' => 'Portal del propietario', 'portal del paciente' => 'portal del propietario',
        'Portal de pacientes' => 'Portal de propietarios',
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
        'Los apellidos son obligatorios.' => 'Los apellidos del propietario son obligatorios.',
        'El nombre es obligatorio.' => 'El nombre de la mascota es obligatorio.',
    ];

    $sesion = [
        'Nueva consulta' => 'Nueva sesión', 'nueva consulta' => 'nueva sesión',
        'Consultas' => 'Sesiones', 'consultas' => 'sesiones', 'Consulta' => 'Sesión', 'consulta' => 'sesión',
    ];

    return $g = [
        'medico' => ['nombre' => 'Medicina general', 'icono' => 'bi-heart-pulse', 'desc' => 'Consultorio médico, de cualquier especialidad.',
                     'staff' => 'Médicos', 'accesos' => ['crecimiento'], 'vocab' => []],
        'dental' => ['nombre' => 'Odontología', 'icono' => 'bi-emoji-smile', 'desc' => 'Odontograma al frente y pacientes dentales por omisión.',
                     'staff' => 'Odontólogos', 'accesos' => ['odontograma'], 'tipo' => 'dental',
                     'vocab' => ['Médicos' => 'Odontólogos', 'Médico' => 'Odontólogo', 'Nuevo médico' => 'Nuevo odontólogo',
                                 'médicos' => 'odontólogos']],
        'psicologia' => ['nombre' => 'Psicología', 'icono' => 'bi-chat-heart', 'desc' => 'Habla de sesiones, con la nota psicológica a un clic.',
                     'staff' => 'Psicólogos', 'accesos' => ['psicologia'],
                     'vocab' => $sesion + ['Médicos' => 'Psicólogos', 'Médico' => 'Psicólogo', 'Nuevo médico' => 'Nuevo psicólogo',
                                           'médicos' => 'psicólogos']],
        'nutricion' => ['nombre' => 'Nutrición', 'icono' => 'bi-egg-fried', 'desc' => 'Valoración nutricional y curvas de crecimiento al frente.',
                     'staff' => 'Nutriólogos', 'accesos' => ['nutricion', 'crecimiento'],
                     'vocab' => ['Médicos' => 'Nutriólogos', 'Médico' => 'Nutriólogo', 'Nuevo médico' => 'Nuevo nutriólogo',
                                 'médicos' => 'nutriólogos']],
        'pediatria' => ['nombre' => 'Pediatría', 'icono' => 'bi-balloon-heart', 'desc' => 'Curvas de crecimiento a un clic en cada expediente.',
                     'staff' => 'Pediatras', 'accesos' => ['crecimiento'],
                     'vocab' => ['Médicos' => 'Pediatras', 'Nuevo médico' => 'Nuevo pediatra']],
        'oftalmologia' => ['nombre' => 'Oftalmología y óptica', 'icono' => 'bi-eye', 'desc' => 'Examen oftalmológico y graduación al frente.',
                     'staff' => 'Oftalmólogos', 'accesos' => ['oftalmologia', 'optica'],
                     'vocab' => []],
        'estetica' => ['nombre' => 'Dermatología y estética', 'icono' => 'bi-magic', 'desc' => 'Dermatología, estética y mapa corporal al frente.',
                     'staff' => 'Médicos', 'accesos' => ['dermatologia', 'estetica'], 'vocab' => []],
        'veterinaria' => ['nombre' => 'Veterinaria', 'icono' => 'bi-house-heart', 'desc' => 'Mascotas con especie, raza y propietario; el recordatorio le llega al dueño.',
                     'staff' => 'Veterinarios', 'accesos' => ['cirugia', 'dermatologia'], 'vocab' => $vet],
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
    if (!es_veterinaria()) return true;
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
