<?php
/**
 * Herramientas para migrar desde otro sistema (Nimbo, Doctoralia, Excel…):
 * lectura de CSV, fechas y horas en cualquier formato, y búsqueda del
 * paciente y del médico a los que pertenece cada fila o archivo.
 */
require_once __DIR__ . '/functions.php';

/** Texto en minúsculas, sin acentos ni signos, espacios simples. */
function mig_norm(string $s): string
{
    $s = strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Lee un CSV a filas (detecta separador, BOM y codificación de Excel). */
function mig_leer(string $ruta, int $max = 50000): array
{
    $raw = (string) file_get_contents($ruta);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $primera = strtok($raw, "\n") ?: '';
    $sep = ','; $cuenta = 0;
    foreach ([',', ';', "\t", '|'] as $c) { $n = substr_count($primera, $c); if ($n > $cuenta) { $cuenta = $n; $sep = $c; } }
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $filas = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false && count($filas) < $max + 1) {
        if ($r === [null] || !array_filter($r, fn ($x) => trim((string) $x) !== '')) continue;
        $filas[] = array_map(fn ($x) => trim((string) $x), $r);
    }
    fclose($fh);
    return $filas;
}

/** Fecha en casi cualquier formato (incluye serial de Excel y "15-mar-2025") → Y-m-d. */
function mig_fecha(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    if (preg_match('/^\d+(\.\d+)?$/', $v) && (float) $v > 1000 && (float) $v < 80000) return gmdate('Y-m-d', (int) (((float) $v - 25569) * 86400));
    $meses = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
              'jan' => 1, 'apr' => 4, 'aug' => 8, 'dec' => 12];
    $l = mig_norm($v);
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $v, $m)) {
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($mo > 12 && $d <= 12) [$d, $mo] = [$mo, $d];
    } elseif (preg_match('/^(\d{1,2}) (?:de )?([a-z]{3})[a-z]* (?:de )?(\d{2,4})/', $l, $m) && isset($meses[$m[2]])) {
        [$d, $mo, $y] = [(int) $m[1], $meses[$m[2]], (int) $m[3]];
    } else return null;
    if ($y < 100) $y += 2000;
    return checkdate($mo, $d, $y) && $y >= 1900 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** Hora "9:30", "09:30 pm", "21:00:00", "9.30 hrs" o fracción de día de Excel → H:i:s. */
function mig_hora(string $v): ?string
{
    $v = strtolower(trim($v));
    if ($v === '') return null;
    if (preg_match('/^0?\.\d+$/', $v)) { $min = (int) round((float) $v * 1440); return sprintf('%02d:%02d:00', intdiv($min, 60) % 24, $min % 60); }
    if (!preg_match('/(\d{1,2})[:.h](\d{2})(?::(\d{2}))?\s*(a\.?\s?m\.?|p\.?\s?m\.?)?/', $v, $m)) {
        if (preg_match('/^(\d{1,2})\s*(a\.?\s?m\.?|p\.?\s?m\.?)$/', $v, $m2)) $m = [0, $m2[1], '00', '', $m2[2]];
        else return null;
    }
    $h = (int) $m[1]; $i = (int) $m[2];
    $ap = str_replace(['.', ' '], '', $m[4] ?? '');
    if ($ap === 'pm' && $h < 12) $h += 12;
    if ($ap === 'am' && $h === 12) $h = 0;
    return $h < 24 && $i < 60 ? sprintf('%02d:%02d:00', $h, $i) : null;
}

/** "2025-03-15 09:30", "15/03/2025 9:30 am"… → [Y-m-d, H:i:s|null]. */
function mig_fecha_hora(string $v): array
{
    $v = trim($v);
    $f = mig_fecha($v);
    if (preg_match('/^\d+\.\d+$/', $v)) return [$f, mig_hora('0.' . explode('.', $v)[1])];
    $resto = preg_replace('/^\S+\s*/', '', $v);   // lo que sigue a la fecha
    return [$f, $resto !== '' && $resto !== $v ? mig_hora($resto) : null];
}

/** Teléfono a sus últimos 10 dígitos. */
function mig_tel(?string $t): string { return substr(preg_replace('/\D/', '', (string) $t), -10); }

/**
 * Índice de pacientes del consultorio para encontrar a quién pertenece una
 * fila: por CURP, por correo, por teléfono o por nombre completo (en ese orden
 * de confianza). Teléfono y correo solo cuentan si apuntan a UN paciente:
 * en una familia se comparten.
 */
final class MigPacientes
{
    private array $curp = [], $email = [], $tel = [], $nombre = [], $lista = [];

    public function __construct()
    {
        $st = db()->prepare('SELECT id, nombre, apellidos, curp, email, telefono FROM pacientes WHERE consultorio_id = ?');
        $st->execute([tenant_id()]);
        foreach ($st as $p) $this->agregar($p);
    }

    public function agregar(array $p): void
    {
        $id = (int) $p['id'];
        if (!empty($p['curp'])) $this->curp[strtoupper(trim($p['curp']))] = $id;
        if (!empty($p['email'])) $this->email[strtolower(trim($p['email']))][] = $id;
        if (strlen($t = mig_tel($p['telefono'] ?? '')) === 10) $this->tel[$t][] = $id;
        $n = mig_norm(($p['nombre'] ?? '') . ' ' . ($p['apellidos'] ?? ''));
        if ($n !== '') { $this->nombre[$n][] = $id; $this->lista[$id] = $n; }
    }

    /** @return array{0:?int,1:string} [id, cómo se encontró] */
    public function buscar(array $v): array
    {
        $curp = strtoupper(trim($v['curp'] ?? ''));
        if ($curp !== '' && isset($this->curp[$curp])) return [$this->curp[$curp], 'CURP'];
        $nom = mig_norm($v['nombre_completo'] ?? trim(($v['nombre'] ?? '') . ' ' . ($v['apellidos'] ?? '')));
        $ids = $nom !== '' ? ($this->nombre[$nom] ?? []) : [];
        if (count($ids) === 1) return [$ids[0], 'nombre'];
        $e = strtolower(trim($v['email'] ?? ''));
        $t = mig_tel($v['telefono'] ?? '');
        if ($ids) {   // homónimos: desempata con correo o teléfono
            foreach ($ids as $id) {
                if ($e !== '' && in_array($id, $this->email[$e] ?? [], true)) return [$id, 'nombre y correo'];
                if (strlen($t) === 10 && in_array($id, $this->tel[$t] ?? [], true)) return [$id, 'nombre y teléfono'];
            }
            return [null, 'hay varios pacientes con ese nombre'];
        }
        if ($e !== '' && count($this->email[$e] ?? []) === 1) return [$this->email[$e][0], 'correo'];
        if (strlen($t) === 10 && count($this->tel[$t] ?? []) === 1) return [$this->tel[$t][0], 'teléfono'];
        // Nombre con orden distinto ("Pérez López Ana" vs "Ana Pérez López").
        if ($nom !== '') {
            $pal = explode(' ', $nom); sort($pal); $clave = implode(' ', $pal);
            $hits = [];
            foreach ($this->lista as $id => $n) { $q = explode(' ', $n); sort($q); if (implode(' ', $q) === $clave) $hits[] = $id; }
            if (count($hits) === 1) return [$hits[0], 'nombre'];
        }
        return [null, 'no se encontró al paciente'];
    }

    /** Busca un paciente cuyo nombre completo (o CURP) aparezca dentro de un texto (ruta de archivo). */
    public function enTexto(string $texto): array
    {
        if (preg_match('/[A-Z][AEIOUX][A-Z]{2}\d{6}[HMX][A-Z]{5}[A-Z\d]\d/', strtoupper($texto), $m) && isset($this->curp[$m[0]])) {
            return [$this->curp[$m[0]], 'CURP'];
        }
        $t = ' ' . mig_norm($texto) . ' ';
        $mejor = null; $largo = 0; $empate = false;
        foreach ($this->lista as $id => $n) {
            if (substr_count($n, ' ') < 1) continue;   // un solo nombre es muy ambiguo
            if (str_contains($t, ' ' . $n . ' ')) {
                if (strlen($n) > $largo) { $mejor = $id; $largo = strlen($n); $empate = false; }
                elseif (strlen($n) === $largo && $id !== $mejor) $empate = true;
            }
        }
        if ($mejor && !$empate) return [$mejor, 'nombre'];
        return [null, $empate ? 'coincide con varios pacientes' : 'no se encontró al paciente'];
    }
}

/** Médicos del consultorio: nombre normalizado → id. Encuentra "Dra. Laura Méndez" como "Laura Mendez". */
function mig_medicos(): array
{
    $st = db()->prepare("SELECT id, nombre FROM usuarios WHERE consultorio_id = ? AND activo = 1 AND rol IN ('medico','admin') ORDER BY rol = 'medico' DESC, nombre");
    $st->execute([tenant_id()]);
    return $st->fetchAll();
}

function mig_medico_id(string $texto, array $medicos, int $porOmision): int
{
    $t = trim(preg_replace('/^(dr|dra|doctor|doctora|lic|psic|mtro|mtra)\b\.?\s*/', '', mig_norm($texto)));
    if ($t === '') return $porOmision;
    foreach ($medicos as $m) if (mig_norm($m['nombre']) === $t) return (int) $m['id'];
    foreach ($medicos as $m) {
        $n = mig_norm($m['nombre']);
        if (str_contains($n, $t) || str_contains($t, $n)) return (int) $m['id'];
    }
    return $porOmision;
}

/** Asigna cada encabezado a un campo por sinónimos. $campos = clave => [etiqueta, sinónimos[]]. */
function mig_auto_map(array $enc, array $campos): array
{
    $map = []; $usados = [];
    foreach ($enc as $i => $h) {
        $h = mig_norm($h); $mejor = '';
        foreach ($campos as $c => [, $sin]) { if (!isset($usados[$c]) && in_array($h, $sin, true)) { $mejor = $c; break; } }
        if (!$mejor) foreach ($campos as $c => [, $sin]) {
            if (isset($usados[$c])) continue;
            foreach ($sin as $s) if (strlen($s) > 3 && str_contains($h, $s)) { $mejor = $c; break 2; }
        }
        $map[$i] = $mejor;
        if ($mejor) $usados[$mejor] = 1;
    }
    if (in_array('nombre', $map, true) && !in_array('apellidos', $map, true)) $map[array_search('nombre', $map, true)] = 'nombre_completo';
    return $map;
}
