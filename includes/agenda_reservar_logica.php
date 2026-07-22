<?php
/**
 * Lógica de la reserva en línea, compartida por /agenda/reservar y por el
 * micrositio del consultorio (/c/<slug>). CORRE ANTES DE IMPRIMIR NADA: crea el
 * paciente y la cita, y en un POST exitoso hace su propia salida (o deja
 * $agHecho listo para que el render muestre el "ya quedó agendada").
 *
 * Espera, ya definidos por quien lo incluye:
 *   $con  (fila de consultorios, con el tenant ya forzado)
 * Deja para el render:
 *   $agMedicos $agMedId $agFecha $agHuecos $agHecho $agError $agMaxDias $agSlug
 *
 * Requiere includes/correo.php para el comprobante (quien lo incluye lo carga).
 */

require_once __DIR__ . '/mercadopago.php';   // para saber si se puede cobrar

$agSlug   = $con['slug'];
$duracion = max(10, (int) cfg('agenda_online_duracion', '30'));
$agMaxDias = max(1, (int) cfg('agenda_online_dias', '30'));

// Precio de la cita para el render (0 = no se cobra por reservar). Solo cuenta
// si además el consultorio tiene Mercado Pago configurado.
$agPrecio = round((float) cfg('agenda_online_precio', '0'), 2);
$agCobra  = $agPrecio > 0 && mp_tenant_habilitado();

/* Médicos con horario configurado: sin horario no hay huecos que ofrecer.
   Solo rol 'medico' (el catálogo de /medicos), NO el admin/personal: el paciente
   agenda con un médico que atiende, no con el dueño administrador. */
$agMedicos = db()->prepare(
    "SELECT DISTINCT u.id, u.nombre, u.especialidad
     FROM usuarios u
     JOIN medico_horarios h ON h.medico_id = u.id AND h.consultorio_id = u.consultorio_id
     WHERE u.consultorio_id = ? AND u.activo = 1 AND u.rol = 'medico'
     ORDER BY u.nombre"
);
$agMedicos->execute([(int) $con['id']]);
$agMedicos = $agMedicos->fetchAll();

$agMedId = (int) ($_GET['m'] ?? $_POST['medico_id'] ?? 0);
if (!$agMedId && count($agMedicos) === 1) { $agMedId = (int) $agMedicos[0]['id']; }

$agFecha = (string) ($_GET['f'] ?? $_POST['fecha'] ?? '');
if ($agFecha === '' || !strtotime($agFecha)) { $agFecha = date('Y-m-d'); }
$agFecha = max($agFecha, date('Y-m-d'));
$agFecha = min($agFecha, date('Y-m-d', strtotime("+$agMaxDias days")));

$agHuecos = ($agMedId && $agFecha) ? agenda_huecos($agMedId, $agFecha, $duracion) : [];

// Días de la semana que atiende el médico (0=domingo), para pintar el calendario.
$agDiasLab = [];
if ($agMedId) {
    $st = db()->prepare('SELECT DISTINCT dia_semana FROM medico_horarios
                         WHERE medico_id = ? AND consultorio_id = ?');
    $st->execute([$agMedId, tenant_id()]);
    $agDiasLab = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

$agHecho = null;
$agError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'reservar') {
    // Trampa para robots: campo que un humano nunca ve ni llena.
    if (trim((string) ($_POST['website'] ?? '')) !== '') { http_response_code(400); exit; }

    $nombre    = trim((string) ($_POST['nombre'] ?? ''));
    $apellidos = trim((string) ($_POST['apellidos'] ?? ''));
    $tel       = preg_replace('/\D/', '', (string) ($_POST['telefono'] ?? '')); // solo dígitos
    $email     = trim((string) ($_POST['email'] ?? ''));
    $motivo    = trim((string) ($_POST['motivo'] ?? ''));
    $hora      = (string) ($_POST['hora'] ?? '');

    if ($nombre === '' || $apellidos === '' || $tel === '') {
        $agError = t('Necesitamos tu nombre, apellidos y teléfono.');
    } elseif (strlen($tel) !== 10) {
        $agError = t('El teléfono debe tener exactamente 10 dígitos (solo números).');
    } elseif (!agenda_hora_disponible($agMedId, $agFecha, $hora, $duracion)) {
        // Se revalida SOLO por conflicto real (otra cita a esa hora), no por
        // "ya pasó la hora": si el paciente tardó en llenar el formulario, su
        // horario no debe invalidarse por eso.
        $agError = t('Ese horario acaba de ocuparse. Elige otro, por favor.');
        $agHuecos = agenda_huecos($agMedId, $agFecha, $duracion);
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // ¿Ya es paciente? Por teléfono o correo, para no duplicarlo.
            $q = $pdo->prepare(
                'SELECT id FROM pacientes
                 WHERE consultorio_id = ? AND (telefono = ? OR (email <> "" AND email = ?))
                 LIMIT 1'
            );
            $q->execute([(int) $con['id'], $tel, $email]);
            $pac = $q->fetchColumn() ?: null;

            if (!$pac) {
                $pdo->prepare(
                    'INSERT INTO pacientes (consultorio_id, nombre, apellidos, telefono, email)
                     VALUES (?,?,?,?,?)'
                )->execute([(int) $con['id'], mb_substr($nombre, 0, 120), mb_substr($apellidos, 0, 120),
                            mb_substr($tel, 0, 10), mb_substr($email, 0, 150) ?: null]);
                $pac = (int) $pdo->lastInsertId();
            }

            $token = bin2hex(random_bytes(16));
            $pdo->prepare(
                "INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion,
                                    motivo, estado, origen, token)
                 VALUES (?,?,?,?,?,?,?,'programada','online',?)"
            )->execute([(int) $con['id'], (int) $pac, $agMedId, $agFecha, $hora, $duracion,
                        mb_substr($motivo, 0, 255) ?: null, $token]);
            $citaId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $agError = t('No pudimos guardar tu cita. Inténtalo de nuevo.');
        }

        if (!$agError) {
            auditar('cita_online', 'cita', $citaId, $agFecha . ' ' . $hora, (int) $con['id'],
                    ['nombre' => $nombre . ' ' . $apellidos]);

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $med = '';
                foreach ($agMedicos as $m) { if ((int) $m['id'] === $agMedId) $med = $m['nombre']; }

                // Enlace al Portal del paciente: si el consultorio incluye el
                // módulo, el paciente puede crear su acceso (o entrar si ya lo
                // tiene) para ver todas sus citas. Va en try/catch: la columna
                // portal_token puede no existir aún en BD, y eso no debe romper
                // el correo ni la reserva.
                $portalUrl = ''; $portalNuevo = false;
                if (modulo_activo_en((int) $con['id'], 'portal')) {
                    try {
                        $ya = db()->prepare('SELECT portal_activo FROM pacientes WHERE id = ?');
                        $ya->execute([(int) $pac]);
                        if ((int) $ya->fetchColumn() === 1) {
                            // Ya tiene acceso: lo mandamos al login de su clínica.
                            $portalUrl = url_absoluta('/portal/login?c=' . rawurlencode($agSlug));
                        } else {
                            $ptok = bin2hex(random_bytes(24));
                            db()->prepare('UPDATE pacientes SET portal_token = ? WHERE id = ?')
                                ->execute([$ptok, (int) $pac]);
                            $portalUrl   = url_absoluta('/portal/activar?t=' . $ptok);
                            $portalNuevo = true;
                        }
                    } catch (Throwable $e) { $portalUrl = ''; }
                }

                correo_cita_agendada($email, $nombre . ' ' . $apellidos, fmt_fecha($agFecha),
                                     fmt_hora($hora), $med, url_absoluta('/agenda/confirmar?t=' . $token),
                                     $portalUrl, $portalNuevo);
            }

            // Pago en línea de la reserva (opcional). Si el consultorio cobra por
            // reservar y tiene Mercado Pago configurado, se genera el cobro. Va en
            // try/catch: la cita YA está agendada, y que el pago falle no debe
            // deshacerla — el paciente pagará en el consultorio.
            if ($agCobra) {
                try {
                    require_once __DIR__ . '/cobros.php';
                    cobro_crear((int) $pac, $agPrecio,
                                t('Reserva de cita') . ' · ' . fmt_fecha($agFecha) . ' ' . fmt_hora($hora),
                                null, $citaId);
                } catch (Throwable $e) { /* pago no disponible: la cita queda igual */ }
            }

            // Post-Redirect-Get: se redirige a la pantalla de "listo" (GET) para
            // que REFRESCAR no reenvíe el formulario ni duplique la cita. Es la
            // causa de que "cada refresh falle".
            redirect('/agenda/reservar?c=' . rawurlencode($agSlug) . '&ok=' . $token);
        }
    }
}
