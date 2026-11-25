<?php
/**
 * Importar CITAS o HISTORIAL (consultas pasadas) desde otro sistema por CSV.
 *   ?tipo=citas      → agenda (futuras como programadas; pasadas como atendidas)
 *   ?tipo=historial  → consultas en el expediente de cada paciente
 * Mismo flujo que la importación de pacientes: subir → revisar columnas → importar.
 * Cada fila se liga al paciente por CURP, nombre, correo o teléfono; si no
 * existe, se puede crear. No duplica (misma cita o misma consulta).
 */
require_once __DIR__ . '/../includes/migrar.php';
require_login();
require_role('admin');

$tipo = ($_GET['tipo'] ?? $_POST['tipo'] ?? 'citas') === 'historial' ? 'historial' : 'citas';
$esCitas = $tipo === 'citas';
$aqui = '/migrar/csv?tipo=' . $tipo;

$PAC = [
    'nombre_completo' => ['Paciente (nombre completo)', ['paciente', 'nombre completo', 'nombre del paciente', 'patient', 'full name']],
    'nombre'          => ['Nombre(s) del paciente', ['nombre', 'nombres', 'first name']],
    'apellidos'       => ['Apellidos del paciente', ['apellidos', 'apellido', 'last name']],
    'curp'            => ['CURP', ['curp']],
    'telefono'        => ['Teléfono', ['telefono', 'celular', 'movil', 'phone', 'whatsapp']],
    'email'           => ['Correo', ['correo', 'email', 'e mail', 'correo electronico']],
];
$CAMPOS = $PAC + ($esCitas ? [
    'fecha_hora' => ['Fecha y hora juntas', ['fecha y hora', 'inicio', 'start', 'fecha hora', 'date time']],
    'fecha'      => ['Fecha', ['fecha', 'dia', 'fecha de la cita', 'date']],
    'hora'       => ['Hora', ['hora', 'hora de inicio', 'time']],
    'duracion'   => ['Duración (min)', ['duracion', 'minutos', 'duration']],
    'medico'     => ['Médico', ['medico', 'doctor', 'profesional', 'especialista', 'tratante']],
    'motivo'     => ['Motivo / servicio', ['motivo', 'servicio', 'tipo de cita', 'concepto', 'reason']],
    'estado'     => ['Estado', ['estado', 'estatus', 'status']],
    'notas'      => ['Notas', ['notas', 'observaciones', 'comentarios']],
] : [
    'fecha_hora'   => ['Fecha y hora juntas', ['fecha y hora', 'fecha hora', 'date time']],
    'fecha'        => ['Fecha de la consulta', ['fecha', 'fecha de consulta', 'fecha de atencion', 'date']],
    'medico'       => ['Médico', ['medico', 'doctor', 'profesional', 'tratante']],
    'motivo'       => ['Motivo de consulta', ['motivo', 'motivo de consulta', 'padecimiento actual', 'subjetivo']],
    'exploracion'  => ['Exploración física', ['exploracion', 'exploracion fisica', 'objetivo']],
    'diagnostico'  => ['Diagnóstico', ['diagnostico', 'diagnosticos', 'impresion diagnostica', 'analisis']],
    'tratamiento'  => ['Tratamiento / plan', ['tratamiento', 'plan', 'indicaciones']],
    'receta'       => ['Receta', ['receta', 'medicamentos', 'prescripcion']],
    'notas'        => ['Notas / nota completa', ['notas', 'nota', 'nota clinica', 'evolucion', 'observaciones']],
    'peso'         => ['Peso (kg)', ['peso', 'weight']],
    'estatura'     => ['Estatura (cm)', ['estatura', 'talla', 'altura', 'height']],
    'presion'      => ['Presión arterial', ['presion', 'presion arterial', 'ta', 'tension arterial']],
    'temperatura'  => ['Temperatura (°C)', ['temperatura', 'temp']],
]);

$medicos = mig_medicos();
$tmp = sys_get_temp_dir();
$skey = 'mig_' . $tipo;
$errores = [];
$paso = 'subir';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'subir') {
        $f = $_FILES['archivo'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) $errores[] = t('No se recibió el archivo.');
        elseif (in_array($ext, ['xlsx', 'xls'], true)) $errores[] = t('Guarda el archivo como CSV desde Excel (Archivo → Guardar como → CSV UTF-8) y súbelo de nuevo.');
        elseif (!in_array($ext, ['csv', 'txt', 'tsv'], true)) $errores[] = t('El archivo debe ser CSV.');
        elseif ($f['size'] > 30 * 1024 * 1024) $errores[] = t('El archivo pasa de 30 MB.');
        if (!$errores) {
            $tok = bin2hex(random_bytes(12));
            move_uploaded_file($f['tmp_name'], "$tmp/{$skey}_$tok.csv");
            $_SESSION[$skey] = ['tok' => $tok, 'nombre' => (string) $f['name'], 'tid' => tenant_id()];
        }
    }
}
$imp = $_SESSION[$skey] ?? null;
$ruta = ($imp && (int) $imp['tid'] === (int) tenant_id()) ? "$tmp/{$skey}_" . preg_replace('/[^a-f0-9]/', '', $imp['tok']) . '.csv' : null;
if ($ruta && !is_file($ruta)) { $ruta = null; unset($_SESSION[$skey]); }
if (($_POST['accion'] ?? '') === 'cancelar') { if ($ruta) @unlink($ruta); unset($_SESSION[$skey]); redirect($aqui); }

/* ── Importar ─────────────────────────────────────────────────────────── */
if (($_POST['accion'] ?? '') === 'importar' && $ruta) {
    @set_time_limit(600);
    $filas = mig_leer($ruta);
    $enc = array_shift($filas) ?: [];
    $map = [];
    foreach ($enc as $i => $_) { $c = $_POST['map'][$i] ?? ''; if (isset($CAMPOS[$c])) $map[$i] = $c; }
    $medDef = (int) ($_POST['medico_def'] ?? 0);
    if (!in_array($medDef, array_map(fn ($m) => (int) $m['id'], $medicos), true)) $medDef = (int) ($medicos[0]['id'] ?? 0);
    $crear = !empty($_POST['crear']);
    $tid = (int) tenant_id();
    $hoy = date('Y-m-d');

    $pacs = new MigPacientes();
    $pdo = db();
    $ok = 0; $nuevos = 0; $saltos = [];
    $dupCita = $pdo->prepare('SELECT 1 FROM citas WHERE consultorio_id = ? AND paciente_id = ? AND fecha = ? AND hora = ? LIMIT 1');
    $dupCons = $pdo->prepare('SELECT 1 FROM consultas WHERE consultorio_id = ? AND paciente_id = ? AND DATE(fecha) = ? AND COALESCE(motivo,\'\') = ? LIMIT 1');
    $pdo->beginTransaction();
    try {
        foreach ($filas as $n => $r) {
            $fila = $n + 2;
            $v = [];
            foreach ($map as $i => $c) if (($r[$i] ?? '') !== '') $v[$c] = isset($v[$c]) ? $v[$c] . ' ' . $r[$i] : $r[$i];

            // Fecha y hora
            [$fecha, $hora] = isset($v['fecha_hora']) ? mig_fecha_hora($v['fecha_hora']) : [null, null];
            if (isset($v['fecha'])) { [$f2, $h2] = mig_fecha_hora($v['fecha']); $fecha = $f2 ?: $fecha; $hora = $hora ?: $h2; }
            if (isset($v['hora'])) $hora = mig_hora($v['hora']) ?: $hora;
            if (!$fecha) { $saltos[] = [$fila, t('sin fecha válida')]; continue; }
            if ($esCitas && !$hora) { $saltos[] = [$fila, t('sin hora válida')]; continue; }

            // Paciente
            [$pid, $como] = $pacs->buscar($v);
            if (!$pid) {
                $nom = trim($v['nombre'] ?? ''); $ape = trim($v['apellidos'] ?? '');
                if ($nom === '' && !empty($v['nombre_completo'])) {
                    $pz = preg_split('/\s+/', trim($v['nombre_completo']));
                    $k = count($pz) >= 4 ? 2 : 1;
                    $nom = implode(' ', array_slice($pz, 0, $k)); $ape = implode(' ', array_slice($pz, $k));
                }
                if (!$crear || $nom === '' || $como === 'hay varios pacientes con ese nombre') { $saltos[] = [$fila, t($como)]; continue; }
                $email = filter_var($v['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $v['email'] : null;
                $pdo->prepare('INSERT INTO pacientes (consultorio_id, nombre, apellidos, telefono, email, curp) VALUES (?,?,?,?,?,?)')
                    ->execute([$tid, $nom, $ape !== '' ? $ape : '—', $v['telefono'] ?? null, $email, !empty($v['curp']) ? strtoupper($v['curp']) : null]);
                $pid = (int) $pdo->lastInsertId();
                $pacs->agregar(['id' => $pid, 'nombre' => $nom, 'apellidos' => $ape, 'curp' => $v['curp'] ?? '', 'email' => $email, 'telefono' => $v['telefono'] ?? '']);
                $nuevos++;
            }
            $med = mig_medico_id($v['medico'] ?? '', $medicos, $medDef);
            if (!$med) { $saltos[] = [$fila, t('no hay médico para asignar')]; continue; }

            if ($esCitas) {
                $dupCita->execute([$tid, $pid, $fecha, $hora]);
                if ($dupCita->fetchColumn()) { $saltos[] = [$fila, t('la cita ya existe')]; continue; }
                $es = mig_norm($v['estado'] ?? '');
                $estado = match (true) {
                    (bool) preg_match('/cancel/', $es)                     => 'cancelada',
                    (bool) preg_match('/no asist|no show|falt|no vino/', $es) => 'no_asistio',
                    (bool) preg_match('/confirm/', $es)                    => $fecha < $hoy ? 'atendida' : 'confirmada',
                    (bool) preg_match('/atend|complet|finaliz|realiz/', $es) => 'atendida',
                    default => $fecha < $hoy ? 'atendida' : 'programada',
                };
                $dur = max(5, min(480, (int) ($v['duracion'] ?? 30) ?: 30));
                $pdo->prepare('INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, motivo, estado, notas) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$tid, $pid, $med, $fecha, $hora, $dur, mb_substr($v['motivo'] ?? '', 0, 255) ?: null, $estado,
                               trim(($v['notas'] ?? '') . "\n[" . t('Importada') . ']')]);
            } else {
                $motivo = mb_substr($v['motivo'] ?? '', 0, 255);
                $dupCons->execute([$tid, $pid, $fecha, $motivo]);
                if ($dupCons->fetchColumn()) { $saltos[] = [$fila, t('la consulta ya existe')]; continue; }
                $num = fn ($k) => isset($v[$k]) && is_numeric(str_replace(',', '.', preg_replace('/[^\d.,]/', '', $v[$k]))) ? (float) str_replace(',', '.', preg_replace('/[^\d.,]/', '', $v[$k])) : null;
                $est = $num('estatura'); if ($est !== null && $est < 3) $est *= 100;   // metros → cm
                $pdo->prepare('INSERT INTO consultas (consultorio_id, paciente_id, medico_id, fecha, motivo, exploracion, diagnostico, tratamiento, receta, peso, estatura, presion, temperatura, notas)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$tid, $pid, $med, $fecha . ' ' . ($hora ?: '12:00:00'), $motivo ?: null, $v['exploracion'] ?? null, $v['diagnostico'] ?? null,
                               $v['tratamiento'] ?? null, $v['receta'] ?? null, $num('peso'), $est, isset($v['presion']) ? mb_substr($v['presion'], 0, 20) : null,
                               $num('temperatura'), trim(($v['notas'] ?? '') . "\n[" . t('Importada de') . ' ' . ($imp['nombre'] ?? 'CSV') . ']')]);
            }
            $ok++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $errores[] = t('No se pudo importar') . ': ' . $e->getMessage();
        $ok = 0;
    }
    if (!$errores) {
        auditar('importar', $esCitas ? 'cita' : 'consulta', 0, "$ok " . ($esCitas ? 'citas' : 'consultas') . ", $nuevos pacientes nuevos desde " . ($imp['nombre'] ?? 'CSV'));
        @unlink($ruta);
        unset($_SESSION[$skey]);
        $paso = 'listo';
    }
}

/* ── Revisar ──────────────────────────────────────────────────────────── */
if ($paso !== 'listo' && $ruta) {
    $paso = 'revisar';
    $filas = mig_leer($ruta, 200);
    $enc = $filas[0] ?? [];
    $total = max(0, count(mig_leer($ruta)) - 1);
    $map = mig_auto_map($enc, $CAMPOS);
}

$titulo = $esCitas ? t('Importar citas') : t('Importar historial clínico');
$activo = 'migrar';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/migrar/index"><?= et('Migrar desde otro sistema') ?></a></li>
    <li class="breadcrumb-item active"><?= e($titulo) ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi <?= $esCitas ? 'bi-calendar-plus' : 'bi-journal-medical' ?>"></i> <?= e($titulo) ?></h1>
<?php foreach ($errores as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($paso === 'subir'): ?>
<div class="row g-4">
    <div class="col-lg-7"><div class="card"><div class="card-body p-4">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="accion" value="subir"><input type="hidden" name="tipo" value="<?= e($tipo) ?>">
            <label class="form-label fw-semibold"><?= $esCitas ? et('Archivo de citas (CSV)') : et('Archivo de consultas o notas (CSV)') ?></label>
            <input type="file" name="archivo" class="form-control mb-3" accept=".csv,.txt,.tsv,.xlsx,.xls" required>
            <button class="btn btn-primary"><i class="bi bi-arrow-right"></i> <?= et('Revisar columnas') ?></button>
        </form>
    </div></div></div>
    <div class="col-lg-5"><div class="card"><div class="card-body small">
        <div class="fw-semibold mb-2"><?= et('Antes de empezar') ?></div>
        <ol class="ps-3 mb-2">
            <li><?= et('Importa primero a tus pacientes, para que cada fila se ligue al expediente correcto.') ?></li>
            <li><?= et('Da de alta a tus médicos en Personal con el mismo nombre que en tu sistema anterior.') ?></li>
            <li><?= $esCitas ? et('Las citas futuras entran como programadas y las pasadas como atendidas (o canceladas, si así venían).') : et('Cada fila se vuelve una consulta en el expediente del paciente, con su fecha original.') ?></li>
        </ol>
        <div class="text-muted"><?= et('No se duplica nada: si la misma cita o consulta ya existe, se salta.') ?></div>
    </div></div></div>
</div>

<?php elseif ($paso === 'revisar'): ?>
<form method="post">
    <?= csrf_field() ?><input type="hidden" name="accion" value="importar"><input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <div class="alert alert-info"><i class="bi bi-file-earmark-spreadsheet"></i> <strong><?= e($imp['nombre'] ?? '') ?></strong> · <?= number_format($total) ?> <?= et('filas') ?>.
        <?= et('Revisa a qué campo va cada columna; las que dejes en "No importar" se ignoran.') ?></div>
    <div class="card mb-3"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th><?= et('Columna del archivo') ?></th><th style="min-width:230px"><?= et('Va a') ?></th><th><?= et('Ejemplos') ?></th></tr></thead>
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
    </table></div></div>
    <div class="card mb-3"><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label"><?= et('Médico cuando la fila no trae uno (o no se reconoce)') ?></label>
            <select name="medico_def" class="form-select"><?php foreach ($medicos as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6 d-flex align-items-end"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="crear" value="1" id="crear" checked>
            <label class="form-check-label" for="crear"><?= et('Si el paciente no existe, crearlo con su nombre y teléfono') ?></label></div></div>
    </div></div>
    <div class="d-flex justify-content-between">
        <button type="submit" name="accion" value="cancelar" class="btn btn-light" formnovalidate><?= et('Cancelar') ?></button>
        <button class="btn btn-primary"><i class="bi bi-cloud-upload"></i> <?= sprintf(et('Importar %s filas'), number_format($total)) ?></button>
    </div>
</form>

<?php else: ?>
<div class="card"><div class="card-body p-4 text-center">
    <i class="bi bi-check-circle-fill text-success" style="font-size:2.6rem"></i>
    <h2 class="h4 mt-2"><?= sprintf($esCitas ? et('%s citas importadas') : et('%s consultas importadas'), number_format($ok)) ?></h2>
    <?php if ($nuevos): ?><p class="mb-1"><?= sprintf(et('Se crearon %d pacientes que no existían.'), $nuevos) ?></p><?php endif; ?>
    <?php if ($saltos): ?><p class="text-muted"><?= sprintf(et('%d filas se saltaron:'), count($saltos)) ?></p>
    <div class="text-start mx-auto small" style="max-width:520px;max-height:260px;overflow:auto">
        <?php foreach (array_slice($saltos, 0, 400) as [$f, $why]): ?><div><?= et('Fila') ?> <?= $f ?>: <?= e($why) ?></div><?php endforeach; ?>
    </div><?php endif; ?>
    <a href="<?= BASE_URL ?>/migrar/index" class="btn btn-primary mt-3"><?= et('Volver a migración') ?></a>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
