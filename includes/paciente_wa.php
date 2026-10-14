<?php
/**
 * Mensaje de WhatsApp para el paciente (réplica de member_wa_message de
 * Khronos): saludo, su próxima cita con el enlace para confirmar, cómo entrar
 * a su portal (ver citas, recetas y archivos) y la página del consultorio para
 * agendar en línea. Un solo texto para la ficha y su vista previa.
 *
 * Sin emojis a propósito: WhatsApp Desktop en Windows los muestra como "?"
 * cuando llegan por liga wa.me. *Negritas* con asteriscos (formato de WhatsApp).
 *
 * Portal: si el paciente ya tiene acceso, va el enlace de entrada y su
 * usuario (correo). Si no, y tiene correo, va un enlace de un solo uso para
 * que él mismo cree su contraseña (portal/activar, el mismo del correo de
 * confirmación); el token se genera aquí solo si aún no existe.
 */
function paciente_wa_mensaje(array $p): string
{
    $pdo   = db();
    $tid   = (int) tenant_id();
    $marca = marca_nombre();
    $vet   = function_exists('es_veterinaria') && es_veterinaria();
    $nom1  = trim(explode(' ', trim((string) $p['nombre']))[0] ?? '');

    $msg = $vet
        ? "¡Hola! Te saludamos de *{$marca}* sobre *{$p['nombre']}*.\n"
        : "¡Hola {$nom1}! Te saludamos de *{$marca}*.\n";

    /* Próxima cita, con el enlace para confirmar o cancelar en un clic. */
    try {
        $st = $pdo->prepare("SELECT c.id, c.fecha, c.hora, u.nombre medico FROM citas c LEFT JOIN usuarios u ON u.id = c.medico_id
                             WHERE c.consultorio_id = ? AND c.paciente_id = ? AND c.estado IN ('programada','confirmada')
                               AND c.fecha >= CURDATE() ORDER BY c.fecha, c.hora LIMIT 1");
        $st->execute([$tid, (int) $p['id']]);
        if ($c = $st->fetch()) {
            $msg .= "\n*" . ($vet ? 'Su próxima cita' : 'Tu próxima cita') . "*\n"
                  . fmt_fecha($c['fecha']) . ' a las ' . fmt_hora($c['hora'])
                  . ($c['medico'] ? ' con ' . $c['medico'] : '') . "\n"
                  . "Confirma o cancela aquí: " . cita_enlace((int) $c['id']) . "\n";
        }
    } catch (Throwable $e) { /* sin citas: se omite la sección */ }

    /* Portal del paciente (si el plan lo incluye). */
    $slug = (string) (tenant()['slug'] ?? '');
    if (modulo_activo('portal')) {
        $ve = $vet ? 'sus citas, recetas, vacunas y estudios' : 'tus citas, tus recetas, tus vacunas y tus estudios';
        if (!empty($p['portal_activo'])) {
            $msg .= "\n*" . ($vet ? 'Portal del tutor' : 'Tu portal de paciente') . "*\n"
                  . "Ahí ves {$ve}:\n"
                  . url_absoluta('/portal/login' . ($slug !== '' ? '?c=' . rawurlencode($slug) : '')) . "\n"
                  . "Usuario: {$p['email']}\n"
                  . "Contraseña: la que elegiste (si la olvidaste, pídela en recepción)\n";
        } elseif (!empty($p['email'])) {
            $token = (string) ($p['portal_token'] ?? '');
            if ($token === '') {
                $token = bin2hex(random_bytes(24));
                try {
                    $pdo->prepare('UPDATE pacientes SET portal_token = ? WHERE id = ? AND consultorio_id = ? AND portal_token IS NULL')
                        ->execute([$token, (int) $p['id'], $tid]);
                } catch (Throwable $e) { $token = ''; }
            }
            if ($token !== '') {
                $msg .= "\n*" . ($vet ? 'Portal del tutor' : 'Tu portal de paciente') . "*\n"
                      . "Crea tu contraseña aquí y consulta {$ve} cuando quieras:\n"
                      . url_absoluta('/portal/activar?t=' . $token) . "\n"
                      . "Tu usuario será tu correo: {$p['email']}\n";
            }
        }
    }

    /* Página del consultorio: servicios, equipo y agenda en línea. */
    if (function_exists('micrositio_visible') && micrositio_visible()) {
        $msg .= "\n*" . (agenda_online_activa() ? 'Agenda tu próxima cita en línea' : 'Conoce nuestros servicios') . "*\n"
              . micrositio_url() . "\n";
    }

    $msg .= "\n¡Te esperamos!";
    return $msg;
}

/** Texto del mensaje en HTML para la vista previa (negritas y ligas activas). */
function wa_vista_previa_html(string $msg): string
{
    $h = preg_replace_callback('~https?://[^\s<]+~', fn ($m) => '<a href="' . $m[0] . '" target="_blank" rel="noopener">' . $m[0] . '</a>', e($msg));
    return nl2br(preg_replace('~\*([^*\n]+)\*~', '<b>$1</b>', $h));
}
