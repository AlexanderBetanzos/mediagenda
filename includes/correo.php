<?php
/**
 * Envío de correos transaccionales (confirmación de cita, recordatorios, etc.).
 * Prefiere SMTP autenticado (includes/smtp.php) si está configurado — es lo que
 * realmente entrega en hosting compartido — y cae a la función mail() de PHP si
 * no. El remitente (CORREO_FROM) debe ser del dominio del sitio para SPF/DKIM.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/smtp.php';

/** Ruta del archivo de bitácora de correos (gitignored). */
function correo_log_path(): string
{
    return __DIR__ . '/../logs/correo.log';
}

/** Registra un intento de envío para poder diagnosticar por qué "no llegan". */
function correo_log(string $para, string $asunto, bool $ok, string $extra = ''): void
{
    try {
        $dir = dirname(correo_log_path());
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $linea = sprintf("[%s] %s | to=%s | asunto=%s%s\n",
            date('Y-m-d H:i:s'),
            $ok ? 'OK ' : 'FALLO',
            $para,
            $asunto,
            $extra !== '' ? ' | ' . $extra : ''
        );
        @file_put_contents(correo_log_path(), $linea, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) { /* el log nunca debe romper un envío */ }
}

/** Envía un correo HTML. Usa SMTP si está configurado; si no, mail(). */
function enviar_correo(string $para, string $asunto, string $html): bool
{
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        correo_log($para, $asunto, false, 'destinatario inválido');
        return false;
    }

    // El dominio del remitente define el Message-ID y el envelope sender.
    $dominio   = substr(strrchr(CORREO_FROM, '@'), 1) ?: 'localhost';
    $asuntoEnc = '=?UTF-8?B?' . base64_encode($asunto) . '?=';

    // Cabeceras comunes (SMTP las manda en DATA; mail() como string).
    $cab = [
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/html; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
        'From'                      => CORREO_FROM_NAME . ' <' . CORREO_FROM . '>',
        'Reply-To'                  => CORREO_FROM,
        'Date'                      => date('r'),
        'Message-ID'                => '<' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
        'X-Mailer'                  => APP_NAME,
    ];

    // 1) SMTP autenticado: es lo que entrega de verdad en hosting compartido.
    if (smtp_configurado()) {
        try {
            $r = smtp_enviar($para, $asuntoEnc, $html, $cab);
            correo_log($para, $asunto, $r['ok'], $r['ok'] ? 'SMTP' : 'SMTP falló: ' . _smtp_ultimo_error($r['log']));
            if ($r['ok']) return true;
            // Si el SMTP falla, seguimos a mail() como último recurso.
        } catch (Throwable $e) {
            correo_log($para, $asunto, false, 'SMTP excepción: ' . $e->getMessage());
        }
    }

    // 2) Fallback: mail() de PHP. El 5º parámetro (-f) fija el envelope sender /
    // Return-Path; sin él muchos hosts mandan "de" el usuario de Apache y rompe
    // SPF (correo a spam o rechazado).
    $headers = [];
    foreach ($cab as $k => $v) $headers[] = $k . ': ' . $v;
    $headers = implode("\r\n", $headers);
    $params  = filter_var(CORREO_FROM, FILTER_VALIDATE_EMAIL) ? '-f' . CORREO_FROM : '';

    try {
        $ok = @mail($para, $asuntoEnc, $html, $headers, $params);
        correo_log($para, $asunto, (bool) $ok, $ok ? 'mail()' : 'mail() devolvió false');
        return (bool) $ok;
    } catch (Throwable $e) {
        correo_log($para, $asunto, false, 'mail() excepción: ' . $e->getMessage());
        return false;
    }
}

/** Extrae la línea de error ("! ...") de la transcripción SMTP para el log. */
function _smtp_ultimo_error(string $log): string
{
    foreach (array_reverse(explode("\n", $log)) as $l) {
        if (strncmp($l, '! ', 2) === 0) return substr($l, 2);
    }
    return 'sin detalle';
}

/** Aclara u oscurece un color hex por un factor (1 = igual, <1 oscurece). */
function correo_tono(string $hex, float $factor): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (strlen($hex) !== 6) return '#' . $hex;
    $c = [];
    foreach ([0, 2, 4] as $i) {
        $v = (int) round(hexdec(substr($hex, $i, 2)) * $factor);
        $c[] = max(0, min(255, $v));
    }
    return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
}

/** Botón "a prueba de balas" (tabla) para que se vea bien hasta en Outlook. */
function correo_boton(string $texto, string $url, string $bg, string $fg = '#ffffff'): string
{
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:0 auto"><tr>'
        . '<td align="center" style="border-radius:12px;background:' . $bg . '">'
        . '<a href="' . e($url) . '" target="_blank" style="display:inline-block;padding:14px 30px;'
        . 'font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:' . $fg . ';'
        . 'text-decoration:none;border-radius:12px">' . e($texto) . '</a>'
        . '</td></tr></table>';
}

/**
 * Plantilla HTML branded (tabla, compatible con clientes de correo).
 * Encabezado con banda de color de marca, tarjeta blanca y pie discreto.
 *
 * @param string $eyebrow  Etiqueta pequeña sobre el título (opcional).
 */
function correo_layout(string $titulo, string $cuerpo, string $cta = '', string $ctaUrl = '', string $eyebrow = ''): string
{
    $marca   = e(marca_nombre());
    $acento  = color_acento();
    $acento2 = correo_tono($acento, 0.80);   // variante oscura para profundidad

    $boton = $cta !== '' && $ctaUrl !== ''
        ? '<div style="margin-top:26px">' . correo_boton($cta, $ctaUrl, $acento) . '</div>'
        : '';
    $eye = $eyebrow !== ''
        ? '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:' . $acento . ';margin-bottom:8px">' . e($eyebrow) . '</div>'
        : '';

    return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="color-scheme" content="light only"></head>'
        . '<body style="margin:0;padding:0;background:#eef2f8;'
        . 'font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2d3d">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . e($titulo) . '</div>'
        . '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="background:#eef2f8">'
        . '<tr><td align="center" style="padding:28px 16px">'
        . '<table role="presentation" width="600" border="0" cellpadding="0" cellspacing="0" '
        . 'style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;'
        . 'box-shadow:0 8px 30px rgba(31,45,80,.08)">'
        // Banda de encabezado con la marca
        . '<tr><td style="background:' . $acento . ';background-image:linear-gradient(135deg,' . $acento . ',' . $acento2 . ');'
        . 'padding:26px 32px;text-align:center">'
        . '<span style="font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:800;color:#ffffff;'
        . 'letter-spacing:.02em">' . $marca . '</span></td></tr>'
        // Cuerpo
        . '<tr><td style="padding:32px 32px 34px">'
        . $eye
        . '<h1 style="font-size:22px;line-height:1.25;margin:0 0 16px;color:#1f2d3d">' . e($titulo) . '</h1>'
        . '<div style="font-size:15px;line-height:1.65;color:#48566a">' . $cuerpo . '</div>'
        . $boton
        . '</td></tr>'
        . '</table>'
        // Pie
        . '<div style="max-width:600px;margin:16px auto 0;font-size:12px;line-height:1.6;color:#94a3b8;text-align:center">'
        . $marca . ' · Este es un correo automático, por favor no lo respondas.</div>'
        . '</td></tr></table></body></html>';
}

/** URL absoluta del sitio (para los enlaces de los correos). */
function correo_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'mediagenda.com.mx';
    return 'https://' . $host . BASE_URL . $path;
}

/** Correo de bienvenida al iniciar la prueba de 15 días. */
function correo_bienvenida_trial(string $email, string $nombre, int $dias): bool
{
    $cuerpo = 'Hola <strong>' . e($nombre) . '</strong>,<br><br>'
        . '¡Tu consultorio en <strong>' . e(marca_nombre()) . '</strong> ya está listo! '
        . 'Tienes <strong>' . $dias . ' días de prueba gratis</strong> con acceso completo a pacientes, '
        . 'citas, expediente, recetas, facturación y reportes.<br><br>'
        . 'Entra cuando quieras y empieza a digitalizar tu consultorio.';
    $html = correo_layout('¡Bienvenido a ' . marca_nombre() . '!', $cuerpo, 'Ir a mi panel', correo_url('/auth/login.php'));
    return enviar_correo($email, '¡Bienvenido a ' . marca_nombre() . '! Tu prueba de ' . $dias . ' días está activa', $html);
}

/** Recordatorio de cita próxima. */
function correo_recordatorio_cita(string $email, string $nombre, string $fecha, string $hora,
                                 ?string $medico, ?string $enlace = null, string $folio = ''): bool
{
    // El botón es lo que convierte un recordatorio en una confirmación. Sin él,
    // el paciente lee el correo, piensa "ok" y no pasa nada: la falta se
    // descubre cuando el hueco ya se perdió.
    $botones = $enlace
        ? '<br><table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0">'
          . '<tr><td style="padding-right:8px">'
          . '<a href="' . e($enlace) . '" style="display:inline-block;background:#22c55e;color:#fff;'
          . 'text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Confirmar asistencia</a>'
          . '</td><td>'
          . '<a href="' . e($enlace) . '" style="display:inline-block;background:#eef1f5;color:#334155;'
          . 'text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">No puedo asistir</a>'
          . '</td></tr></table>'
          . '<div style="font-size:13px;color:#64748b">Si no puedes venir, avísanos desde aquí: '
          . 'así podemos ofrecer tu horario a otro paciente.</div>'
        : '<br>Si no puedes asistir, por favor avísanos para reagendar. ¡Te esperamos!';

    $cuerpo = 'Hola <strong>' . e($nombre) . '</strong>,<br><br>'
        . 'Te recordamos tu próxima cita en <strong>' . e(marca_nombre()) . '</strong>:'
        . '<br><br><div style="background:#f4f7fb;border-radius:10px;padding:16px;font-size:15px">'
        . ($folio !== '' ? '<span style="color:#8a97a8;font-size:13px">Folio ' . e($folio) . '</span><br>' : '')
        . '📅 <strong>' . e($fecha) . '</strong><br>🕐 <strong>' . e($hora) . '</strong>'
        . ($medico ? '<br>👩‍⚕️ ' . e($medico) : '')
        . '</div>' . $botones;

    $html = correo_layout('Recordatorio de tu cita', $cuerpo);
    return enviar_correo($email, 'Recordatorio de tu cita en ' . marca_nombre() . ' · ' . $fecha, $html);
}

/**
 * Comprobante de una cita agendada por el propio paciente (agenda en línea).
 *
 * @param string $enlace     URL para ver/cancelar esta cita (token de la cita).
 * @param string $portalUrl  URL al Portal del paciente (activar o iniciar sesión). '' si el consultorio no incluye portal.
 * @param bool   $portalNuevo true si el paciente aún NO tiene acceso (enlace de registro); false si ya lo tiene (login).
 */
function correo_cita_agendada(string $email, string $nombre, string $fecha, string $hora,
                              ?string $medico, string $enlace, string $portalUrl = '', bool $portalNuevo = false,
                              string $folio = ''): bool
{
    $acento  = color_acento();
    $suave   = correo_tono($acento, 0.96);   // fondo tenue con el tinte de marca
    $nom1    = trim(explode(' ', trim($nombre))[0]) ?: $nombre;

    // Insignia "Cita confirmada" + folio a la derecha para citarlo fácil.
    $badge = '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 18px"><tr>'
        . '<td style="vertical-align:middle">'
        . '<span style="display:inline-block;background:#e7f7ee;border-radius:999px;padding:7px 16px;font-size:13px;font-weight:700;color:#1a7f47">'
        . '&#10003;&nbsp; Cita confirmada</span></td>'
        . ($folio !== ''
            ? '<td align="right" style="vertical-align:middle;font-size:13px;color:#8a97a8">Folio&nbsp;<strong style="color:#1f2d3d;font-family:monospace">' . e($folio) . '</strong></td>'
            : '')
        . '</tr></table>';

    // Tarjeta tipo "ticket" con fecha, hora y (si hay) médico.
    $filaMedico = $medico
        ? '<tr><td style="padding:14px 22px 16px;border-top:1px dashed #dbe3ef">'
          . '<div style="font-size:12px;color:#8a97a8;text-transform:uppercase;letter-spacing:.05em">Te atiende</div>'
          . '<div style="font-size:16px;font-weight:700;color:#1f2d3d;margin-top:2px">' . e($medico) . '</div>'
          . '</td></tr>'
        : '';

    $ticket = '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" '
        . 'style="background:' . $suave . ';border:1px solid ' . correo_tono($acento, 0.90) . ';'
        . 'border-radius:14px;margin:6px 0 8px">'
        . '<tr>'
        . '<td width="50%" style="padding:18px 22px;vertical-align:top">'
        . '<div style="font-size:12px;color:#8a97a8;text-transform:uppercase;letter-spacing:.05em">&#128197;&nbsp; Fecha</div>'
        . '<div style="font-size:17px;font-weight:800;color:#1f2d3d;margin-top:4px;text-transform:capitalize">' . e($fecha) . '</div>'
        . '</td>'
        . '<td width="50%" style="padding:18px 22px;vertical-align:top;border-left:1px solid ' . correo_tono($acento, 0.90) . '">'
        . '<div style="font-size:12px;color:#8a97a8;text-transform:uppercase;letter-spacing:.05em">&#128336;&nbsp; Hora</div>'
        . '<div style="font-size:17px;font-weight:800;color:#1f2d3d;margin-top:4px">' . e($hora) . '</div>'
        . '</td>'
        . '</tr>'
        . $filaMedico
        . '</table>';

    // CTA principal: el Portal — donde el paciente se registra, entra y ve TODAS
    // sus citas. Es el corazón de lo que se pidió.
    $portalBloque = '';
    if ($portalUrl !== '') {
        $btn   = $portalNuevo ? 'Crear mi acceso al portal' : 'Ver mis citas en el portal';
        $texto = $portalNuevo
            ? 'Crea tu acceso en un minuto y consulta tus citas, recetas y estudios cuando quieras.'
            : 'Entra a tu portal para ver todas tus citas en un solo lugar.';
        $portalBloque =
            '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" '
            . 'style="background:#f7f9fc;border:1px solid #e9eef5;border-radius:14px;margin:22px 0 4px">'
            . '<tr><td style="padding:20px 22px;text-align:center">'
            . '<div style="font-size:15px;font-weight:700;color:#1f2d3d;margin-bottom:4px">Tu portal de paciente</div>'
            . '<div style="font-size:14px;color:#5a6a80;line-height:1.55;margin-bottom:16px">' . e($texto) . '</div>'
            . correo_boton($btn, $portalUrl, $acento)
            . '</td></tr></table>';
    }

    // Secundario: ver o cancelar esta cita.
    $verCancelar =
        '<div style="text-align:center;margin-top:22px">'
        . correo_boton('Ver o cancelar esta cita', $enlace, '#eef1f5', '#334155')
        . '</div>'
        . '<div style="font-size:13px;color:#8a97a8;text-align:center;margin-top:10px">'
        . 'Guarda este correo: si te surge algo, puedes cancelar desde ese botón.</div>';

    $cuerpo = $badge
        . 'Hola <strong>' . e($nom1) . '</strong>, tu cita en <strong>' . e(marca_nombre())
        . '</strong> quedó agendada. Aquí están los detalles:'
        . $ticket
        . $portalBloque
        . $verCancelar;

    $html = correo_layout('¡Tu cita quedó agendada!', $cuerpo, '', '', 'Comprobante de cita');
    return enviar_correo($email, 'Tu cita en ' . marca_nombre() . ' · ' . $fecha, $html);
}

/** Correo de confirmación cuando se activa la suscripción de pago. */
function correo_suscripcion_activa(string $email, string $nombre, string $plan, ?string $proximo): bool
{
    $cuerpo = 'Hola <strong>' . e($nombre) . '</strong>,<br><br>'
        . 'Tu suscripción al <strong>Plan ' . e($plan) . '</strong> está activa. ¡Gracias por confiar en '
        . e(marca_nombre()) . '!<br><br>'
        . ($proximo ? 'Tu próximo cobro será el <strong>' . e(fmt_fecha($proximo)) . '</strong>.<br><br>' : '')
        . 'Puedes ver o cancelar tu suscripción en cualquier momento desde "Mi suscripción".';
    $html = correo_layout('Suscripción activada', $cuerpo, 'Ir a mi panel', correo_url('/dashboard.php'));
    return enviar_correo($email, 'Tu suscripción a ' . marca_nombre() . ' está activa', $html);
}
