<?php
/**
 * Envío de correos transaccionales (bienvenida, confirmación de pago).
 * Usa la función mail() de PHP (disponible en Hostinger). El remitente se
 * define en config (CORREO_FROM) y debe ser del dominio del sitio para una
 * buena entrega (SPF/DKIM).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/functions.php';

/** Envía un correo HTML. Devuelve true si mail() lo aceptó. */
function enviar_correo(string $para, string $asunto, string $html): bool
{
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) return false;
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . CORREO_FROM_NAME . ' <' . CORREO_FROM . '>',
        'Reply-To: ' . CORREO_FROM,
    ]);
    $asuntoEnc = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    try {
        return @mail($para, $asuntoEnc, $html, $headers);
    } catch (Throwable $e) {
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

/** Comprobante de una cita agendada por el propio paciente (agenda en línea). */
function correo_cita_agendada(string $email, string $nombre, string $fecha, string $hora,
                              ?string $medico, string $enlace): bool
{
    $cuerpo = 'Hola <strong>' . e($nombre) . '</strong>,<br><br>'
        . 'Tu cita en <strong>' . e(marca_nombre()) . '</strong> quedó agendada:'
        . '<br><br><div style="background:#f4f7fb;border-radius:10px;padding:16px;font-size:15px">'
        . '📅 <strong>' . e($fecha) . '</strong><br>🕐 <strong>' . e($hora) . '</strong>'
        . ($medico ? '<br>👩‍⚕️ ' . e($medico) : '')
        . '</div><br>'
        . '<a href="' . e($enlace) . '" style="display:inline-block;background:#eef1f5;color:#334155;'
        . 'text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:600">Ver o cancelar mi cita</a>'
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
