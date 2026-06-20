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
