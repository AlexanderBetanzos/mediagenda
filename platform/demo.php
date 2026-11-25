<?php
/**
 * Consultorio DEMO con datos ficticios (para capturas del centro de ayuda,
 * demostraciones a prospectos y pruebas). Solo el dueño de la plataforma.
 *
 * - Todo es inventado: correos @example.com, teléfonos 55 0000 00xx, sin CURP.
 * - Recordatorios, encuestas y WhatsApp automáticos quedan APAGADOS para que
 *   nunca salga un mensaje real.
 * - La contraseña del administrador demo la escribe el dueño en el formulario.
 * - "Refrescar fechas" vuelve a poner citas hoy y mañana (para que la agenda
 *   siempre se vea viva).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ece.php';
require_once __DIR__ . '/../includes/encuestas.php';
require_once __DIR__ . '/../includes/recetas.php';
require_platform_super();
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];
const DEMO_SLUG = 'demo';

function demo_cid(): ?int
{
    $st = db()->prepare('SELECT id FROM consultorios WHERE slug = ?');
    $st->execute([DEMO_SLUG]);
    $v = $st->fetchColumn();
    return $v ? (int) $v : null;
}

/** Ejecuta $fn con el tenant demo activo y restaura la sesión. */
function demo_en_tenant(int $cid, callable $fn)
{
    $prev = $_SESSION['usuario'] ?? null;
    $_SESSION['usuario'] = ['id' => 0, 'nombre' => 'Demo', 'rol' => 'admin', 'consultorio_id' => $cid];
    cfg_all(true);
    try { return $fn(); }
    finally { if ($prev === null) unset($_SESSION['usuario']); else $_SESSION['usuario'] = $prev; cfg_all(true); }
}

/** Citas de hoy, mañana y la semana pasada (borra las de demo anteriores). */
function demo_citas(int $cid, array $meds, array $pacs): void
{
    $pdo = db();
    $pdo->prepare("DELETE FROM citas WHERE consultorio_id = ? AND (notas LIKE '[demo]%' OR origen = 'demo')")->execute([$cid]);
    $ins = $pdo->prepare("INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, motivo, estado, notas, token)
                          VALUES (?,?,?,?,?,30,?,?, '[demo]', ?)");
    $hoy = date('Y-m-d'); $man = date('Y-m-d', strtotime('+1 day'));
    $plan = [
        [$hoy, '09:00', 0, 0, 'Consulta de seguimiento', 'atendida'], [$hoy, '09:30', 1, 1, 'Dolor de garganta', 'atendida'],
        [$hoy, '10:30', 2, 0, 'Control de presión', 'confirmada'], [$hoy, '11:00', 3, 1, 'Primera vez', 'confirmada'],
        [$hoy, '12:00', 4, 0, 'Revisión de estudios', 'programada'], [$hoy, '13:00', 5, 1, 'Certificado médico', 'programada'],
        [$man, '09:00', 6, 0, 'Consulta general', 'confirmada'], [$man, '10:00', 7, 1, 'Seguimiento diabetes', 'programada'],
        [$man, '11:30', 8, 0, 'Chequeo anual', 'programada'], [$man, '16:00', 9, 1, 'Videoconsulta', 'programada'],
    ];
    for ($d = 1; $d <= 6; $d++) $plan[] = [date('Y-m-d', strtotime("-$d days")), sprintf('%02d:00', 9 + $d), ($d + 3) % count($pacs), $d % 2, 'Consulta general', $d === 3 ? 'no_asistio' : 'atendida'];
    foreach ($plan as [$f, $h, $p, $m, $mot, $est]) $ins->execute([$cid, $pacs[$p], $meds[$m], $f, $h, $mot, $est, bin2hex(random_bytes(16))]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear') {
            if (demo_cid()) throw new RuntimeException('El consultorio demo ya existe.');
            $email = trim((string) ($_POST['email'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escribe un correo válido para el administrador demo.');
            if ($e = password_politica($pass, $email)) throw new RuntimeException($e);
            $chk = db()->prepare('SELECT 1 FROM usuarios WHERE email = ?'); $chk->execute([$email]);
            if ($chk->fetchColumn()) throw new RuntimeException('Ese correo ya tiene una cuenta. Usa otro (por ejemplo demo@mediagenda.com.mx).');
            $pdo = db();
            $pdo->prepare("INSERT INTO consultorios (nombre, slug, email, telefono, plan, estado, trial_inicio, trial_fin) VALUES (?,?,?,?, 'clinica', 'activa', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 YEAR))")
                ->execute(['Clínica Demo MediAgenda', DEMO_SLUG, $email, '951 000 0000']);
            $cid = (int) $pdo->lastInsertId();
            $resumen = demo_en_tenant($cid, function () use ($cid, $email, $pass, $pdo) {
                $ok = [];
                asegurar_esquema_medicos();   // médicos sin acceso (email y contraseña NULL)
                $pdo->prepare("INSERT INTO usuarios (consultorio_id, nombre, email, password_hash, rol, telefono, activo) VALUES (?,?,?,?, 'admin', ?, 1)")
                    ->execute([$cid, 'Administración Demo', $email, password_hash($pass, PASSWORD_DEFAULT), '5500000000']);
                guardar_cfg(['marca_nombre' => 'Clínica Demo MediAgenda', 'marca_lema' => 'Medicina familiar · datos de demostración', 'tema_default' => 'light',
                    'color_acento' => '#0f8dbf', 'moneda' => 'MXN', 'zona_horaria' => 'America/Mexico_City', 'formato_fecha' => 'd/m/Y', 'giro' => 'medico',
                    'direccion' => 'Av. Ejemplo 123, Col. Centro, Oaxaca, Oax.', 'telefono' => '951 000 0000', 'email' => 'contacto@example.com',
                    'agenda_online' => '1', 'agenda_online_precio' => '500', 'recordatorio_auto' => '0', 'encuesta_auto' => '0', 'wa_incluido_auto' => '0',
                    'web_titular' => 'Tu salud, en buenas manos', 'web_acerca' => 'Consultorio de demostración de MediAgenda. Todos los datos son ficticios.']);
                // Médicos con horario
                $meds = [];
                foreach ([['Dra. Laura Méndez Ortiz', 'Medicina familiar'], ['Dr. Carlos Ruiz Herrera', 'Medicina interna']] as $i => [$n, $esp]) {
                    $pdo->prepare("INSERT INTO usuarios (consultorio_id, nombre, email, password_hash, rol, especialidad, telefono, activo) VALUES (?,?,NULL,NULL,'medico',?,?,1)")
                        ->execute([$cid, $n, $esp, '550000000' . ($i + 1)]);
                    $meds[] = $mid = (int) $pdo->lastInsertId();
                    try { $pdo->prepare('UPDATE usuarios SET cedula = ? WHERE id = ?')->execute(['DEMO-00' . ($i + 1), $mid]); } catch (Throwable $e) {}
                    $h = $pdo->prepare('INSERT INTO medico_horarios (consultorio_id, medico_id, dia_semana, hora_inicio, hora_fin) VALUES (?,?,?,?,?)');
                    for ($d = 1; $d <= 6; $d++) { $h->execute([$cid, $mid, $d, '09:00', '14:00']); if ($d < 6) $h->execute([$cid, $mid, $d, '16:00', '19:00']); }
                }
                $ok[] = '2 médicos con horario';
                // Servicios
                try {
                    $s = $pdo->prepare('INSERT INTO servicios (consultorio_id, nombre, categoria, precio, duracion_min) VALUES (?,?,?,?,?)');
                    foreach ([['Consulta general', 'Consulta', 500, 30], ['Consulta de seguimiento', 'Consulta', 400, 20], ['Certificado médico', 'Trámites', 250, 15], ['Electrocardiograma', 'Estudios', 600, 30]] as $r) $s->execute(array_merge([$cid], $r));
                    $ok[] = '4 servicios';
                } catch (Throwable $e) {}
                // Pacientes
                asegurar_identificacion();
                $pacs = [];
                $lista = [['Ana', 'Torres', 'Vega', 'F', '1988-04-12', 'Penicilina', 'Hipotiroidismo', 'O+'], ['Juan', 'Pérez', 'Soto', 'M', '1975-09-30', '', 'Hipertensión arterial', 'A+'],
                    ['María', 'González', 'Ruiz', 'F', '1992-01-22', 'Sulfas', '', 'O+'], ['Carlos', 'Ramírez', 'Luna', 'M', '1969-06-05', '', 'Diabetes tipo 2', 'B+'],
                    ['Lucía', 'Hernández', 'Mora', 'F', '2001-11-14', '', '', 'O-'], ['Pedro', 'Castillo', 'Díaz', 'M', '1983-03-18', 'AINEs', '', 'A-'],
                    ['Sofía', 'Martínez', 'Cruz', 'F', '2015-07-09', '', 'Asma', 'O+'], ['Diego', 'Flores', 'Reyes', 'M', '1958-12-01', '', 'Diabetes tipo 2, dislipidemia', 'AB+'],
                    ['Valeria', 'Ortiz', 'Silva', 'F', '1996-05-27', '', '', 'B-'], ['Miguel Ángel', 'Rojas', 'Peña', 'M', '1979-08-16', 'Látex', '', 'O+'],
                    ['Fernanda', 'Navarro', 'Gil', 'F', '1987-02-03', '', 'Migraña', 'A+'], ['Roberto', 'Aguilar', 'Campos', 'M', '1964-10-20', '', 'Hipertensión arterial', 'O+']];
                foreach ($lista as $i => [$n, $a1, $a2, $sx, $fn, $al, $cr, $ts]) {
                    $pdo->prepare("INSERT INTO pacientes (consultorio_id, nombre, apellidos, fecha_nacimiento, sexo, telefono, email, direccion, tipo, tipo_sangre, alergias, enf_cronicas)
                                   VALUES (?,?,?,?,?,?,?,?, 'medico', ?,?,?)")
                        ->execute([$cid, $n, "$a1 $a2", $fn, $sx, '55000000' . str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT), 'paciente' . ($i + 1) . '@example.com',
                                   'Calle Ficticia ' . (10 + $i) . ', Oaxaca', $ts, $al ?: null, $cr ?: null]);
                    $pacs[] = $pid = (int) $pdo->lastInsertId();
                    try { $pdo->prepare("UPDATE pacientes SET primer_apellido=?, segundo_apellido=?, entidad_nacimiento='20', nacionalidad='MEX', curp_motivo='no_la_tiene', res_entidad='20', consent_datos_en=NOW(), consent_compartir=1 WHERE id=?")->execute([$a1, $a2, $pid]); } catch (Throwable $e) {}
                }
                $ok[] = '12 pacientes';
                demo_citas($cid, $meds, $pacs);
                $ok[] = 'citas de hoy, mañana y la semana pasada';
                // Consultas (con CIE-10, signos y pronóstico), selladas
                asegurar_ece();
                $dx = [['J02.9 Faringitis aguda, no especificada', 'J02.9', 'Amoxicilina 500 mg c/8 h por 7 días. Paracetamol si hay fiebre.', 'Bueno'],
                       ['I10 Hipertensión esencial (primaria)', 'I10', 'Losartán 50 mg c/24 h. Dieta baja en sodio. Control en 1 mes.', 'Bueno para la vida, reservado para la función'],
                       ['E11.9 Diabetes mellitus tipo 2, sin complicaciones', 'E11.9', 'Metformina 850 mg c/12 h. Plan de alimentación y caminata diaria.', 'Bueno con apego'],
                       ['J45.9 Asma, no especificada', 'J45.9', 'Salbutamol inhalado según necesidad. Evitar desencadenantes.', 'Bueno'],
                       ['G43.9 Migraña, no especificada', 'G43.9', 'Naproxeno 550 mg al inicio del dolor. Diario de cefaleas.', 'Bueno'],
                       ['Z00.0 Examen médico general', 'Z00.0', 'Estudios de laboratorio de rutina. Vacunación al día.', 'Bueno']];
                $ci = $pdo->prepare('INSERT INTO consultas (consultorio_id, paciente_id, medico_id, fecha, motivo, exploracion, diagnostico, tratamiento, pronostico, peso, estatura, presion, temperatura, frecuencia_cardiaca, saturacion, notas)
                                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $nCons = 0;
                foreach ([[1, 0, 0], [1, 1, 30], [3, 2, 10], [7, 2, 45], [6, 3, 20], [10, 4, 15], [0, 5, 60], [11, 1, 5]] as $k => [$p, $d, $dias]) {
                    [$txt, $cod, $tx, $pr] = $dx[$d];
                    $ci->execute([$cid, $pacs[$p], $meds[$k % 2], date('Y-m-d H:i:s', strtotime("-$dias days 10:00")), 'Consulta ' . ($dias ? 'de seguimiento' : 'de hoy'),
                        'Paciente consciente, orientado, buena hidratación. Exploración por aparatos sin alteraciones relevantes.', $txt, $tx, $pr,
                        60 + $k * 3.5, 158 + $k * 2, (110 + $k * 3) . '/' . (70 + $k), 36.6, 72 + $k, 97, 'Datos de demostración.']);
                    $cons = (int) $pdo->lastInsertId();
                    try { $pdo->prepare('UPDATE consultas SET cie10 = ? WHERE id = ?')->execute([$cod, $cons]); } catch (Throwable $e) {}
                    ece_sellar($cons);
                    $nCons++;
                }
                $ok[] = "$nCons consultas selladas";
                // Recetas
                try {
                    asegurar_recetas();
                    foreach ([[1, 0, 'J02.9 Faringitis aguda', [['Amoxicilina 500 mg', '1 cápsula', 'Oral', 'cada 8 horas', '7 días'], ['Paracetamol 500 mg', '1 tableta', 'Oral', 'cada 8 horas si hay fiebre', '3 días']]],
                              [3, 1, 'E11.9 Diabetes mellitus tipo 2', [['Metformina 850 mg', '1 tableta', 'Oral', 'cada 12 horas', '30 días']]],
                              [11, 1, 'I10 Hipertensión esencial', [['Losartán 50 mg', '1 tableta', 'Oral', 'cada 24 horas', '30 días']]],
                              [6, 0, 'J45.9 Asma', [['Salbutamol inhalador 100 mcg', '2 disparos', 'Inhalada', 'si hay falta de aire', 'continuo']]]] as [$p, $m, $dg, $items]) {
                        $pdo->prepare('INSERT INTO recetas (consultorio_id, paciente_id, medico_id, diagnostico, indicaciones) VALUES (?,?,?,?,?)')
                            ->execute([$cid, $pacs[$p], $meds[$m], $dg, 'Tomar abundantes líquidos. Regresar si los síntomas empeoran.']);
                        $rid = (int) $pdo->lastInsertId();
                        $it = $pdo->prepare('INSERT INTO receta_items (receta_id, medicamento, dosis, via, frecuencia, duracion) VALUES (?,?,?,?,?,?)');
                        foreach ($items as $x) $it->execute(array_merge([$rid], $x));
                        receta_token($rid, $cid);
                    }
                    $ok[] = '4 recetas';
                } catch (Throwable $e) { $ok[] = 'recetas: ' . $e->getMessage(); }
                // Cobros
                try {
                    $f = $pdo->prepare('INSERT INTO facturas (consultorio_id, folio, paciente_id, medico_id, fecha, subtotal, descuento, total, estado, metodo_pago) VALUES (?,?,?,?,?,?,0,?,?,?)');
                    $fi = $pdo->prepare('INSERT INTO factura_items (factura_id, descripcion, cantidad, precio, importe) VALUES (?,?,1,?,?)');
                    foreach ([[0, 500, 'pagada', 'Efectivo'], [1, 400, 'pagada', 'Tarjeta'], [3, 500, 'pagada', 'Transferencia'], [5, 250, 'pendiente', null], [7, 1100, 'pagada', 'Tarjeta'], [9, 500, 'pendiente', null]] as $k => [$p, $tot, $est, $met]) {
                        $f->execute([$cid, 'DEMO-' . str_pad((string) ($k + 1), 4, '0', STR_PAD_LEFT) . '-' . $cid, $pacs[$p], $meds[$k % 2], date('Y-m-d', strtotime('-' . $k . ' days')), $tot, $tot, $est, $met]);
                        $fi->execute([(int) $pdo->lastInsertId(), $tot >= 1000 ? 'Consulta general + electrocardiograma' : 'Consulta', $tot, $tot]);
                    }
                    $ok[] = '6 cobros';
                } catch (Throwable $e) { $ok[] = 'cobros: ' . $e->getMessage(); }
                // Reseñas con encuesta
                try {
                    asegurar_encuestas();
                    $at = $pdo->prepare("SELECT id, paciente_id, medico_id FROM citas WHERE consultorio_id = ? AND estado = 'atendida' ORDER BY fecha DESC LIMIT 6");
                    $at->execute([$cid]);
                    $com = ['Muy amable la doctora, me explicó todo con calma.', 'Excelente atención y puntuales.', 'Todo bien, solo tardaron un poco en recibirme.', 'Muy recomendable, instalaciones limpias.', 'Me resolvieron el problema rápido.', 'La espera fue larga.'];
                    foreach ($at->fetchAll() as $k => $c) {
                        $est = [5, 5, 4, 5, 4, 3][$k] ?? 5;
                        $pdo->prepare("INSERT INTO resenas (consultorio_id, cita_id, paciente_id, medico_id, token, estrellas, comentario, estado, invitada_en, respondida_en, puntualidad, trato, explicacion, instalaciones, nps, enviada_en, enviada_por)
                                       VALUES (?,?,?,?,?,?,?, 'publicada', NOW(), NOW(), ?,?,?,?,?, NOW(), 'correo')")
                            ->execute([$cid, $c['id'], $c['paciente_id'], $c['medico_id'], bin2hex(random_bytes(16)), $est, $com[$k], max(2, $est - ($k === 5 ? 2 : 0)), $est, $est, 5, [10, 9, 8, 10, 9, 5][$k]]);
                    }
                    $ok[] = 'reseñas con encuesta';
                } catch (Throwable $e) { $ok[] = 'reseñas: ' . $e->getMessage(); }
                return $ok;
            });
            auditar('demo_creado', 'consultorio', $cid, implode(', ', $resumen), null, $actor);
            flash('Consultorio demo creado: ' . implode(', ', $resumen) . '.');
        } elseif ($accion === 'refrescar') {
            $cid = demo_cid();
            if (!$cid) throw new RuntimeException('Primero crea el consultorio demo.');
            demo_en_tenant($cid, function () use ($cid) {
                $m = db()->prepare("SELECT id FROM usuarios WHERE consultorio_id = ? AND rol = 'medico' ORDER BY id"); $m->execute([$cid]);
                $p = db()->prepare('SELECT id FROM pacientes WHERE consultorio_id = ? ORDER BY id'); $p->execute([$cid]);
                $meds = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN)); $pacs = array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN));
                if (count($meds) < 2 || count($pacs) < 10) throw new RuntimeException('Faltan médicos o pacientes demo.');
                demo_citas($cid, $meds, $pacs);
            });
            flash('Citas demo movidas a hoy y mañana.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'danger'); }
    redirect('/platform/demo');
}

$cid = demo_cid();
$titulo = 'Consultorio demo';
$platNav = 'demo';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-easel"></i> Consultorio demo</h1>
<p class="text-muted">Un consultorio con datos <b>ficticios</b> para capturas del centro de ayuda, demostraciones a prospectos y pruebas. Nunca envía recordatorios, encuestas ni WhatsApp.</p>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if (!$cid): ?>
<form method="post" class="card" style="max-width:560px" autocomplete="off"><div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="accion" value="crear">
    <p class="small">Se crea "Clínica Demo MediAgenda" (/c/<?= DEMO_SLUG ?>) en plan Clínica, con 2 médicos, 12 pacientes, citas, consultas selladas, recetas, cobros y reseñas.</p>
    <div class="mb-2"><label class="form-label">Correo del administrador demo</label><input type="email" name="email" class="form-control" value="demo@mediagenda.com.mx" required></div>
    <div class="mb-3"><label class="form-label">Contraseña (escríbela tú; 10+ caracteres con letras y números)</label><input type="password" name="password" class="form-control" required minlength="10" autocomplete="new-password"></div>
    <button class="btn btn-primary"><i class="bi bi-magic"></i> Crear consultorio demo</button>
</div></form>
<?php else: ?>
<div class="card" style="max-width:640px"><div class="card-body">
    <p class="mb-2"><i class="bi bi-check-circle text-success"></i> El consultorio demo existe (id <?= $cid ?>). Entra en <a href="<?= BASE_URL ?>/auth/login" target="_blank">/auth/login</a> con el correo demo, o desde la lista de consultorios con el botón de entrar.</p>
    <p class="small text-muted">Página pública: <a href="<?= BASE_URL ?>/c/<?= DEMO_SLUG ?>" target="_blank">/c/<?= DEMO_SLUG ?></a></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="refrescar"><button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Refrescar fechas (citas hoy y mañana)</button></form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
