<?php
/**
 * Importar pacientes desde otro sistema (Nimbo, Excel, Doctoralia…).
 *
 * Tres pasos sin salir de la pantalla:
 *   1. Subir el archivo (CSV; desde Excel: "Guardar como → CSV UTF-8").
 *   2. Revisar: cada columna del archivo se asigna sola a un campo de la
 *      ficha por su encabezado (nombre, apellido paterno, celular, correo…) y
 *      se puede corregir; se ve una vista previa de las primeras filas.
 *   3. Importar: no duplica (mismo correo, mismo teléfono o mismo nombre con
 *      la misma fecha de nacimiento) y reporta cuántos entraron y por qué se
 *      saltó cada fila.
 * El archivo se guarda temporalmente fuera de la web y se borra al terminar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('pacientes');
if (!has_role('admin')) { flash('Solo el administrador puede importar pacientes.', 'warning'); redirect('/pacientes/index'); }

/** Campos de la ficha que se pueden importar: clave => [etiqueta, sinónimos de encabezado]. */
$CAMPOS = [
    'nombre_completo'  => ['Nombre completo (se separa solo)', ['nombre completo', 'paciente', 'nombre del paciente', 'full name', 'name']],
    'nombre'           => ['Nombre(s)', ['nombre', 'nombres', 'first name', 'primer nombre']],
    'apellidos'        => ['Apellidos', ['apellidos', 'apellido', 'last name', 'surname']],
    'apellido_paterno' => ['Apellido paterno', ['apellido paterno', 'paterno', 'primer apellido']],
    'apellido_materno' => ['Apellido materno', ['apellido materno', 'materno', 'segundo apellido']],
    'fecha_nacimiento' => ['Fecha de nacimiento', ['fecha de nacimiento', 'nacimiento', 'f nacimiento', 'fecha nac', 'birthdate', 'birth date', 'dob', 'cumpleanos']],
    'sexo'             => ['Sexo', ['sexo', 'genero', 'gender', 'sex']],
    'telefono'         => ['Teléfono', ['telefono', 'celular', 'movil', 'tel', 'phone', 'mobile', 'whatsapp', 'telefono celular', 'telefono movil']],
    'email'            => ['Correo', ['correo', 'email', 'e mail', 'correo electronico', 'mail']],
    'direccion'        => ['Dirección', ['direccion', 'domicilio', 'address', 'calle']],
    'curp'             => ['CURP', ['curp']],
    'rfc'              => ['RFC', ['rfc']],
    'tipo_sangre'      => ['Tipo de sangre', ['tipo de sangre', 'grupo sanguineo', 'sangre']],
    'contacto_nombre'  => ['Contacto de emergencia', ['contacto de emergencia', 'contacto', 'responsable', 'tutor']],
    'contacto_telefono' => ['Teléfono de emergencia', ['telefono de emergencia', 'telefono contacto', 'tel emergencia']],
    'alergias'         => ['Alergias', ['alergias', 'alergia', 'allergies']],
    'antecedentes'     => ['Antecedentes', ['antecedentes', 'antecedentes personales', 'historial', 'antecedentes patologicos']],
    'enf_cronicas'     => ['Enfermedades crónicas', ['enfermedades cronicas', 'padecimientos', 'diagnostico', 'diagnosticos']],
    'notas'            => ['Notas', ['notas', 'observaciones', 'comentarios', 'notes']],
];
$norm = fn (string $s) => trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'ü' => 'u']))));

/** Lee un CSV a filas (detecta separador y codificación). */
function imp_leer(string $ruta, int $max = 20000): array
{
    $raw = (string) file_get_contents($ruta);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);                       // BOM de Excel
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $primera = strtok($raw, "\n") ?: '';
    $sep = ',';
    $cuenta = 0;
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

/** "15/03/1985", "1985-03-15", "15-mar-1985", serial de Excel… → Y-m-d o null. */
function imp_fecha(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    if (ctype_digit($v) && (int) $v > 1000 && (int) $v < 80000) return gmdate('Y-m-d', ((int) $v - 25569) * 86400);   // serial de Excel
    $meses = ['ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
              'jan' => 1, 'apr' => 4, 'aug' => 8, 'dec' => 12];
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $v, $m)) {
        // Formato mexicano día/mes/año; si el "mes" pasa de 12, venía al revés (mes/día).
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($mo > 12 && $d <= 12) [$d, $mo] = [$mo, $d];
    } elseif (preg_match('/^(\d{1,2})[-\/. ]([a-z]{3})[a-z]*[-\/. ,]+(\d{2,4})/i', strtolower($v), $m) && isset($meses[$m[2]])) {
        [$d, $mo, $y] = [(int) $m[1], $meses[$m[2]], (int) $m[3]];
    } else return null;
    if ($y < 100) $y += $y > (int) date('y') ? 1900 : 2000;
    return checkdate($mo, $d, $y) && $y >= 1900 && $y <= (int) date('Y') ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

function imp_sexo(string $v): ?string
{
    $v = strtolower(trim($v));
    if ($v === '') return null;
    if (in_array($v, ['f', 'fem', 'femenino', 'mujer', 'female', 'w'], true)) return 'F';
    if (in_array($v, ['m', 'masc', 'masculino', 'hombre', 'male', 'h'], true)) return 'M';
    return 'O';
}

$tmpDir = sys_get_temp_dir();
$paso = 'subir';
$errores = [];
$filas = []; $map = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'subir') {
        $f = $_FILES['archivo'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) $errores[] = t('No se recibió el archivo.');
        elseif (in_array($ext, ['xlsx', 'xls'], true)) $errores[] = t('Guarda el archivo como CSV desde Excel (Archivo → Guardar como → CSV UTF-8) y súbelo de nuevo.');
        elseif (!in_array($ext, ['csv', 'txt', 'tsv'], true)) $errores[] = t('El archivo debe ser CSV.');
        elseif ($f['size'] > 15 * 1024 * 1024) $errores[] = t('El archivo pasa de 15 MB.');
        if (!$errores) {
            $tok = bin2hex(random_bytes(12));
            move_uploaded_file($f['tmp_name'], $tmpDir . '/imp_pac_' . $tok . '.csv');
            $_SESSION['imp_pac'] = ['tok' => $tok, 'nombre' => (string) $f['name'], 'tid' => tenant_id()];
            $paso = 'revisar';
        }
    } elseif (in_array($accion, ['importar', 'cancelar'], true)) {
        $paso = 'subir';
    }
}

$imp = $_SESSION['imp_pac'] ?? null;
$ruta = ($imp && (int) $imp['tid'] === (int) tenant_id()) ? $tmpDir . '/imp_pac_' . preg_replace('/[^a-f0-9]/', '', $imp['tok']) . '.csv' : null;
if ($ruta && !is_file($ruta)) { $ruta = null; unset($_SESSION['imp_pac']); }

if (($_POST['accion'] ?? '') === 'cancelar') {
    if ($ruta) @unlink($ruta);
    unset($_SESSION['imp_pac']);
    redirect('/pacientes/importar');
}

/* ── Importar ──────────────────────────────────────────────────────── */
if (($_POST['accion'] ?? '') === 'importar' && $ruta) {
    @set_time_limit(300);
    $filas = imp_leer($ruta);
    $enc = array_shift($filas) ?: [];
    $map = [];
    foreach ($enc as $i => $_) { $c = $_POST['map'][$i] ?? ''; if (isset($CAMPOS[$c])) $map[$i] = $c; }

    // Índices para no duplicar contra lo que ya existe en el consultorio.
    $tid = tenant_id();
    $ex = db()->prepare('SELECT nombre, apellidos, fecha_nacimiento, telefono, email FROM pacientes WHERE consultorio_id = ?');
    $ex->execute([$tid]);
    // Duplicado = mismo nombre completo Y (misma fecha de nacimiento, mismo correo o
    // mismo teléfono). Solo el teléfono o el correo no basta: en una familia lo comparten.
    $vistos = [];
    $tel = fn ($t) => substr(preg_replace('/\D/', '', (string) $t), -10);
    $marcar = function (array $p) use (&$vistos, $norm, $tel) {
        $vistos[$norm($p['nombre'] . ' ' . $p['apellidos'])][] = [(string) ($p['fecha_nacimiento'] ?? ''), strtolower((string) ($p['email'] ?? '')), $tel($p['telefono'] ?? '')];
    };
    $existe = function (array $p) use (&$vistos, $norm, $tel) {
        $k = $norm($p['nombre'] . ' ' . $p['apellidos']);
        $f = (string) ($p['fecha_nacimiento'] ?? ''); $e = strtolower((string) ($p['email'] ?? '')); $t = $tel($p['telefono'] ?? '');
        foreach ($vistos[$k] ?? [] as [$vf, $ve, $vt]) {
            if ($f === $vf) return $f === '' ? 'nombre' : 'nombre y fecha de nacimiento';
            if ($e !== '' && $e === $ve) return 'nombre y correo';
            if (strlen($t) === 10 && $t === $vt) return 'nombre y teléfono';
        }
        return null;
    };
    foreach ($ex as $r) $marcar($r);

    $ok = 0; $saltos = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($filas as $n => $r) {
            $v = [];
            foreach ($map as $i => $c) if (($r[$i] ?? '') !== '') $v[$c] = isset($v[$c]) ? $v[$c] . ' ' . $r[$i] : $r[$i];
            if (isset($v['apellido_paterno']) || isset($v['apellido_materno'])) {
                $v['apellidos'] = trim(($v['apellidos'] ?? '') . ' ' . ($v['apellido_paterno'] ?? '') . ' ' . ($v['apellido_materno'] ?? ''));
            }
            if (isset($v['nombre_completo']) && empty($v['nombre'])) {
                // "Apellidos, Nombre" o "Nombre Apellido1 Apellido2".
                if (str_contains($v['nombre_completo'], ',')) [$v['apellidos'], $v['nombre']] = array_map('trim', explode(',', $v['nombre_completo'], 2));
                else {
                    $pz = preg_split('/\s+/', trim($v['nombre_completo']));
                    $k = count($pz) >= 4 ? 2 : 1;
                    $v['nombre'] = implode(' ', array_slice($pz, 0, $k));
                    $v['apellidos'] = $v['apellidos'] ?? implode(' ', array_slice($pz, $k));
                }
            }
            $fila = $n + 2;   // +1 encabezado, +1 base 1 (como en Excel)
            if (trim($v['nombre'] ?? '') === '') { $saltos[] = [$fila, t('sin nombre')]; continue; }
            if (trim($v['apellidos'] ?? '') === '') $v['apellidos'] = '—';
            $post = $v;
            $post['fecha_nacimiento'] = isset($v['fecha_nacimiento']) ? imp_fecha($v['fecha_nacimiento']) : null;
            $post['sexo'] = isset($v['sexo']) ? imp_sexo($v['sexo']) : null;
            if (!empty($post['email']) && !filter_var($post['email'], FILTER_VALIDATE_EMAIL)) $post['email'] = null;
            $post['tipo_sangre'] = isset($v['tipo_sangre']) ? strtoupper(str_replace(' ', '', $v['tipo_sangre'])) : null;
            $campos = paciente_post_campos($post);
            if ($dup = $existe($campos)) { $saltos[] = [$fila, t('ya existe') . ' (' . t($dup) . ')']; continue; }
            $cols = array_keys($campos);
            $pdo->prepare('INSERT INTO pacientes (consultorio_id, ' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')
                ->execute(array_merge([$tid], array_values($campos)));
            $marcar($campos);
            $ok++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $errores[] = t('No se pudo importar') . ': ' . $e->getMessage();
        $ok = 0;
    }
    if (!$errores) {
        auditar('importar', 'paciente', 0, $ok . ' pacientes desde ' . ($imp['nombre'] ?? 'CSV'));
        @unlink($ruta);
        unset($_SESSION['imp_pac']);
        $paso = 'listo';
    } else $paso = 'revisar';
}

/* ── Revisar: asignación automática de columnas ─────────────────────── */
if ($paso === 'revisar' || ($paso === 'subir' && $ruta && $_SERVER['REQUEST_METHOD'] === 'GET')) {
    $paso = 'revisar';
    $filas = imp_leer($ruta, 200);
    $enc = $filas[0] ?? [];
    $total = max(0, count(imp_leer($ruta)) - 1);
    $usados = [];
    foreach ($enc as $i => $h) {
        $h = $norm($h); $mejor = '';
        foreach ($CAMPOS as $c => [, $sin]) {
            if (isset($usados[$c])) continue;
            if (in_array($h, $sin, true)) { $mejor = $c; break; }
        }
        if (!$mejor) foreach ($CAMPOS as $c => [, $sin]) {
            if (isset($usados[$c])) continue;
            foreach ($sin as $s) if (strlen($s) > 3 && str_contains($h, $s)) { $mejor = $c; break 2; }
        }
        // "Nombre" solo, con columnas de apellido aparte, es el nombre de pila; sin ellas, el completo.
        $map[$i] = $mejor;
        if ($mejor) $usados[$mejor] = 1;
    }
    if (in_array('nombre', $map, true) && !array_intersect(['apellidos', 'apellido_paterno', 'apellido_materno'], $map)) {
        $map[array_search('nombre', $map, true)] = 'nombre_completo';
    }
}

$titulo = t('Importar pacientes');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/index"><?= et('Pacientes') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Importar') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi bi-cloud-upload"></i> <?= et('Importar pacientes') ?></h1>

<?php foreach ($errores as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($paso === 'subir'): ?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card"><div class="card-body p-4">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="accion" value="subir">
                <label class="form-label fw-semibold"><?= et('Archivo de pacientes (CSV)') ?></label>
                <input type="file" name="archivo" class="form-control mb-3" accept=".csv,.txt,.tsv,.xlsx,.xls" required>
                <button class="btn btn-primary"><i class="bi bi-arrow-right"></i> <?= et('Revisar columnas') ?></button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-5">
        <div class="card"><div class="card-body">
            <div class="fw-semibold mb-2"><?= et('¿De dónde saco el archivo?') ?></div>
            <ol class="small mb-2 ps-3">
                <li><?= et('En tu sistema anterior (Nimbo, Doctoralia, Excel…) busca la opción de exportar pacientes.') ?></li>
                <li><?= et('Si te da un Excel, ábrelo y usa Archivo → Guardar como → CSV UTF-8.') ?></li>
                <li><?= et('Súbelo aquí: el sistema reconoce las columnas por su nombre y te deja corregirlas antes de importar.') ?></li>
            </ol>
            <div class="small text-muted"><?= et('No se duplican pacientes: si ya existe uno con el mismo nombre y la misma fecha de nacimiento, correo o teléfono, se salta.') ?></div>
            <hr>
            <div class="small mb-1"><i class="bi bi-box-arrow-in-down"></i> <?= et('¿También traes citas, historial o documentos?') ?> <a href="<?= BASE_URL ?>/migrar/index"><?= et('Migración completa') ?></a></div>
            <div class="small"><?= et('¿Prefieres que lo hagamos nosotros?') ?> <a href="<?= BASE_URL ?>/soporte/index"><?= et('Escríbenos') ?></a></div>
        </div></div>
    </div>
</div>

<?php elseif ($paso === 'revisar'): ?>
<form method="post">
    <?= csrf_field() ?><input type="hidden" name="accion" value="importar">
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-file-earmark-spreadsheet"></i> <strong><?= e($imp['nombre'] ?? '') ?></strong> · <?= number_format($total) ?> <?= et('filas') ?>.
            <?= et('Revisa a qué campo va cada columna; las que dejes en "No importar" se ignoran.') ?></span>
    </div>
    <div class="card mb-3">
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th><?= et('Columna del archivo') ?></th><th style="min-width:230px"><?= et('Campo en la ficha') ?></th><th><?= et('Ejemplos') ?></th></tr></thead>
            <tbody>
            <?php foreach ($enc as $i => $h): ?>
            <tr>
                <td class="fw-semibold"><?= e($h) ?></td>
                <td><select name="map[<?= $i ?>]" class="form-select form-select-sm <?= $map[$i] ? 'border-success' : '' ?>">
                    <option value=""><?= et('No importar') ?></option>
                    <?php foreach ($CAMPOS as $c => [$lbl]): ?><option value="<?= $c ?>" <?= $map[$i] === $c ? 'selected' : '' ?>><?= et($lbl) ?></option><?php endforeach; ?>
                </select></td>
                <td class="small text-muted text-truncate" style="max-width:340px"><?= e(implode(' · ', array_filter(array_map(fn ($r) => $r[$i] ?? '', array_slice($filas, 1, 3))))) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <div class="d-flex justify-content-between">
        <button type="submit" name="accion" value="cancelar" class="btn btn-light" formnovalidate><?= et('Cancelar') ?></button>
        <button class="btn btn-primary"><i class="bi bi-cloud-upload"></i> <?= sprintf(et('Importar %s pacientes'), number_format($total)) ?></button>
    </div>
</form>

<?php else: /* listo */ ?>
<div class="card"><div class="card-body p-4 text-center">
    <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
    <h2 class="h4 mt-2"><?= sprintf(et('%s pacientes importados'), number_format($ok)) ?></h2>
    <?php if ($saltos): ?><p class="text-muted"><?= sprintf(et('%d filas se saltaron:'), count($saltos)) ?></p>
    <div class="text-start mx-auto small" style="max-width:520px;max-height:260px;overflow:auto">
        <?php foreach (array_slice($saltos, 0, 300) as [$f, $why]): ?><div><?= et('Fila') ?> <?= $f ?>: <?= e($why) ?></div><?php endforeach; ?>
    </div><?php endif; ?>
    <a href="<?= BASE_URL ?>/pacientes/index" class="btn btn-primary mt-3"><?= et('Ver pacientes') ?></a>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
