<?php
/**
 * Envío de correos transaccionales (bienvenida, confirmación de pago).
 * Usa la función mail() de PHP (disponible en Hostinger). El remitente se
 * define en config (CORREO_FROM) y debe ser del dominio del sitio para una
 * buena entrega (SPF/DKIM).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

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

/** Envía un correo HTML. Devuelve true si mail() lo aceptó. */
function enviar_correo(string $para, string $asunto, string $html): bool
{
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        correo_log($para, $asunto, false, 'destinatario inválido');
        return false;
    }

    // El dominio del remitente define el Message-ID y el envelope sender.
    $dominio = substr(strrchr(CORREO_FROM, '@'), 1) ?: 'localhost';

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . CORREO_FROM_NAME . ' <' . CORREO_FROM . '>',
        'Reply-To: ' . CORREO_FROM,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $dominio . '>',
        'X-Mailer: ' . APP_NAME,
    ]);
    $asuntoEnc = '=?UTF-8?B?' . base64_encode($asunto) . '?=';

    // 5º parámetro (-f): fija el envelope sender / Return-Path. Sin esto muchos
    // hosts mandan el correo "de" el usuario de Apache (www-data@servidor), lo
    // que rompe SPF y hace que el correo caiga en spam o se rechace. Es la causa
    // más común de "no me llega ningún correo". Solo se pasa si el From es válido.
    $params = filter_var(CORREO_FROM, FILTER_VALIDATE_EMAIL) ? '-f' . CORREO_FROM : '';

    try {
        $ok = @mail($para, $asuntoEnc, $html, $headers, $params);
        correo_log($para, $asunto, (bool) $ok, $ok ? '' : 'mail() devolvió false');
        return (bool) $ok;
    } catch (Throwable $e) {
        correo_log($para, $asunto, false, 'excepción: ' . $e->getMessage());
        return false;
    }
}

/** Plantilla HTML básica y branded para los correos. */
function correo_layout(string $titulo, string $cuerpo, string $cta = '', string $ctaUrl = ''): string
{
    $marca  = e(marca_nombre());
    $acento = color_acento();
    $boton  = $cta !== '' && $ctaUrl !== ''
        ? '<a href="' . e($ctaUrl) . '" style="display:inline-block;background:' . e($acento) . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600">' . e($cta) . '</a>'
        : '';
    return '<!doctype html><html><body style="margin:0;background:#f4f7fb;font-family:Inter,Arial,sans-serif;color:#1f2d3d">'
        . '<div style="max-width:520px;margin:0 auto;padding:24px">'
        . '<div style="text-align:center;padding:8px 0 16px"><span style="font-size:20px;font-weight:700;color:' . e($acento) . '">' . $marca . '</span></div>'
        . '<div style="background:#fff;border:1px solid #e9eef5;border-radius:14px;padding:28px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">' . e($titulo) . '</h1>'
        . '<div style="font-size:15px;line-height:1.6;color:#41506a">' . $cuerpo . '</div>'
        . ($boton ? '<div style="margin-top:22px">' . $boton . '</div>' : '')
        . '</div>'
        . '<p style="text-align:center;color:#8294ad;font-size:12px;margin-top:16px">' . $marca . ' · Este es un correo automático, no respondas a este mensaje.</p>'
        . '</div></body></html>';
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
                                 ?string $medico, ?string $enlace = null): bool
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
                              ?string $medico, string $enlace, string $portalUrl = '', bool $portalNuevo = false): bool
{
    $acento = color_acento();

    // CTA principal: el Portal. Es lo que el paciente pidió — un lugar donde
    // registrarse, entrar y ver TODAS sus citas, no solo esta.
    $portalBloque = '';
    if ($portalUrl !== '') {
        $texto = $portalNuevo
            ? 'Crea tu acceso al portal y consulta tus citas cuando quieras.'
            : 'Entra a tu portal para ver todas tus citas.';
        $btn = $portalNuevo ? 'Crear mi acceso al portal' : 'Ver mis citas en el portal';
        $portalBloque =
            '<div style="font-size:14px;color:#41506a;margin-bottom:8px">' . e($texto) . '</div>'
            . '<a href="' . e($portalUrl) . '" style="display:inline-block;background:' . e($acento) . ';color:#fff;'
            . 'text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">' . e($btn) . '</a>'
            . '<div style="margin:18px 0;border-top:1px solid #eef1f5"></div>';
    }

    $cuerpo = 'Hola <strong>' . e($nombre) . '</strong>,<br><br>'
        . 'Tu cita en <strong>' . e(marca_nombre()) . '</strong> quedó agendada:'
        . '<br><br><div style="background:#f4f7fb;border-radius:10px;padding:16px;font-size:15px">'
        . '📅 <strong>' . e($fecha) . '</strong><br>🕐 <strong>' . e($hora) . '</strong>'
        . ($medico ? '<br>👩‍⚕️ ' . e($medico) : '')
        . '</div><br>'
        . $portalBloque
        . '<a href="' . e($enlace) . '" style="display:inline-block;background:#eef1f5;color:#334155;'
        . 'text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Ver o cancelar esta cita</a>'
        . '<div style="font-size:13px;color:#64748b;margin-top:10px">Guarda este correo: desde ese enlace '
        . 'puedes cancelar si te surge algo.</div>';

    $html = correo_layout('Tu cita quedó agendada', $cuerpo);
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
