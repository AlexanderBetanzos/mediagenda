<?php
/**
 * Ajustes de la plataforma (consola del dueño). Aquí viven las credenciales de
 * Mercado Pago con las que MediOS cobra las suscripciones a los
 * consultorios. NO son del consultorio: por eso no están en /configuracion.
 *
 * Precedencia: lo guardado aquí manda sobre el archivo de secretos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_platform();
require_once __DIR__ . '/../includes/mercadopago.php';
require_once __DIR__ . '/../includes/correo.php';
require_once __DIR__ . '/../includes/recordatorios.php';

$prueba  = null;   // resultado de "Probar conexión"
$correoPrueba = null;   // resultado de "Probar envío de correo"
$recResultado = null;   // resultado de "Enviar recordatorios ahora"
$errores = [];

/* El id de un admin de plataforma vive en `plataforma_admins`, no en `usuarios`:
   registrarlo como usuario_id en la bitácora apuntaría a otra persona. */
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? 'guardar');

    if ($accion === 'limpiar') {
        guardar_plataforma_cfg(['mp_access_token' => '', 'mp_public_key' => '']);
        auditar('plataforma_mp_limpiar', 'plataforma', null, null, null, $actor);
        flash('Credenciales de Mercado Pago borradas. Vuelve a mandar el archivo de secretos.', 'warning');
        redirect('/platform/ajustes');
    }

    if ($accion === 'guardar') {
        ['nuevos' => $nuevos, 'errores' => $errores] =
            mp_credenciales_desde_post($_POST, mp_access_token(), mp_public_key());

        if (!$errores && $nuevos) {
            guardar_plataforma_cfg($nuevos);
            auditar('plataforma_mp_guardar', 'plataforma', null, implode(', ', array_keys($nuevos)), null, $actor);
            flash('Credenciales guardadas.');
            redirect('/platform/ajustes');
        }
        if (!$errores && !$nuevos) {
            flash('No escribiste ninguna credencial nueva; no se cambió nada.', 'info');
            redirect('/platform/ajustes');
        }
    }

    if ($accion === 'probar') {
        try {
            $me = mp_request('GET', '/users/me');
            $prueba = ['ok' => true, 'datos' => $me];
        } catch (Throwable $e) {
            $prueba = ['ok' => false, 'mensaje' => $e->getMessage()];
        }
    }

    if ($accion === 'probar_correo') {
        $dest = trim((string) ($_POST['correo_dest'] ?? ''));
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            $correoPrueba = ['ok' => false, 'mensaje' => 'Escribe un correo válido para la prueba.'];
        } else {
            $cuerpo = 'Este es un <strong>correo de prueba</strong> de ' . e(APP_NAME) . '.<br><br>'
                . 'Si lo recibes, el envío de correos funciona. Si llegó a <strong>spam</strong>, '
                . 'revisa los registros SPF/DKIM del dominio del remitente.';
            $html      = correo_layout('Prueba de envío', $cuerpo);
            $asuntoEnc = '=?UTF-8?B?' . base64_encode('Prueba de envío · ' . APP_NAME) . '?=';
            $transcripcion = '';

            if (smtp_configurado()) {
                // Enviamos por SMTP directo para poder mostrar la conversación y
                // diagnosticar (conexión, TLS, autenticación, destinatario).
                $dom = substr(strrchr(CORREO_FROM, '@'), 1) ?: 'localhost';
                $cab = [
                    'MIME-Version' => '1.0', 'Content-Type' => 'text/html; charset=UTF-8',
                    'Content-Transfer-Encoding' => '8bit',
                    'From' => CORREO_FROM_NAME . ' <' . CORREO_FROM . '>', 'Reply-To' => CORREO_FROM,
                    'Date' => date('r'), 'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $dom . '>',
                    'X-Mailer' => APP_NAME,
                ];
                try {
                    $r = smtp_enviar($dest, $asuntoEnc, $html, $cab);
                    $ok = $r['ok']; $transcripcion = $r['log'];
                    correo_log($dest, 'Prueba de envío', $ok, $ok ? 'SMTP (prueba)' : 'SMTP prueba falló');
                } catch (Throwable $e) {
                    $ok = false; $transcripcion = '! Excepción: ' . $e->getMessage();
                }
                $via = 'SMTP (' . SMTP_HOST . ')';
            } else {
                $ok  = enviar_correo($dest, 'Prueba de envío · ' . APP_NAME, $html);
                $via = 'mail() de PHP';
            }

            auditar('plataforma_correo_prueba', 'plataforma', null,
                    $dest . ' vía ' . strip_tags($via) . ' → ' . ($ok ? 'ok' : 'fallo'), null, $actor);
            $correoPrueba = [
                'ok'      => $ok,
                'dest'    => $dest,
                'via'     => $via,
                'log'     => $transcripcion,
                'mensaje' => $ok
                    ? 'Enviado por ' . $via . '. Revisa la bandeja (y spam) de ' . $dest . '.'
                    : 'Falló el envío por ' . $via . '. Revisa el detalle abajo.',
            ];
        }
    }

    if ($accion === 'recordatorios_ahora') {
        // Dispara los recordatorios manualmente (mismo motor que el cron). Sirve
        // para verificar sin depender de que el cron/token estén configurados.
        $fecha = trim((string) ($_POST['rec_fecha'] ?? '')) ?: null;
        try {
            $r = recordatorios_enviar($fecha);
            auditar('plataforma_recordatorios_manual', 'plataforma', null,
                    "{$r['fecha']}: {$r['procesadas']} citas, {$r['correos']} correos", null, $actor);
            $recResultado = ['ok' => true] + $r;
        } catch (Throwable $e) {
            $recResultado = ['ok' => false, 'mensaje' => $e->getMessage()];
        }
    }
}

/* Últimas líneas de la bitácora de correos, para diagnosticar entregas. */
$correoLog = '';
if (is_file(correo_log_path())) {
    $lineas = @file(correo_log_path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $correoLog = implode("\n", array_slice($lineas, -15));
}

$token   = mp_access_token();
$public  = mp_public_key();
$enDB    = plataforma_cfg('mp_access_token') !== '' || plataforma_cfg('mp_public_key') !== '';
$sandbox = mp_es_sandbox();

$titulo  = 'Ajustes';
$platNav = 'ajustes';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-gear"></i> Ajustes de la plataforma</h1>
<p class="text-muted">Credenciales con las que <strong><?= e(APP_NAME) ?></strong> cobra las suscripciones a los consultorios.</p>

<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>

<?php if ($errores): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<?php if ($prueba !== null): ?>
    <?php if ($prueba['ok']): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i> <strong>Conexión correcta.</strong>
        Cuenta <code><?= e($prueba['datos']['nickname'] ?? '—') ?></code>
        · <?= e($prueba['datos']['email'] ?? '') ?>
        · país <?= e($prueba['datos']['site_id'] ?? '—') ?>
    </div>
    <?php else: ?>
    <div class="alert alert-danger">
        <i class="bi bi-x-circle"></i> <strong>No se pudo conectar.</strong> <?= e($prueba['mensaje']) ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-credit-card"></i> Mercado Pago</span>
                <?php if (!mp_configurado()): ?>
                    <span class="badge bg-secondary">Sin configurar</span>
                <?php elseif ($sandbox): ?>
                    <span class="badge bg-warning text-dark">Modo pruebas</span>
                <?php else: ?>
                    <span class="badge bg-success">Producción</span>
                <?php endif; ?>
            </div>
            <form method="post" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="guardar">

                <div class="mb-3">
                    <label class="form-label">Access Token</label>
                    <input type="password" name="mp_access_token" class="form-control" autocomplete="off"
                           placeholder="<?= $token !== '' ? e(secreto_enmascarado($token)) : 'APP_USR-… o TEST-…' ?>">
                    <div class="form-text">
                        <?php if ($token !== ''): ?>
                            Hay uno guardado. Déjalo vacío para conservarlo; escribe uno nuevo para reemplazarlo.
                        <?php else: ?>
                            Panel de Mercado Pago → Tus integraciones → Credenciales.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Public Key</label>
                    <input type="text" name="mp_public_key" class="form-control" autocomplete="off"
                           placeholder="<?= $public !== '' ? e(secreto_enmascarado($public)) : 'APP_USR-… o TEST-…' ?>">
                    <div class="form-text">Se usa en el navegador para pintar el formulario de tarjeta. No es secreta.</div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button>
                    <button class="btn btn-outline-secondary" name="accion" value="probar" <?= mp_configurado() ? '' : 'disabled' ?>>
                        <i class="bi bi-plug"></i> Probar conexión
                    </button>
                    <?php if ($enDB): ?>
                    <button class="btn btn-outline-danger ms-auto" name="accion" value="limpiar"
                            onclick="return confirm('¿Borrar las credenciales guardadas? Los cobros dejarán de funcionar hasta que pongas otras (o las del archivo de secretos).')">
                        <i class="bi bi-trash"></i> Borrar
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle"></i> De dónde salen hoy</div>
            <div class="card-body small">
                <p>
                    <?php if ($enDB): ?>
                        De <strong>esta pantalla</strong> (guardadas en la base de datos).
                    <?php elseif (mp_configurado()): ?>
                        Del <strong>archivo de secretos</strong> (<code>mediagenda_secrets.php</code>),
                        fuera de <code>public_html</code>. Lo que guardes aquí tendrá prioridad sobre él.
                    <?php else: ?>
                        No hay credenciales en ningún lado: <strong>los pagos están deshabilitados</strong>.
                        El resto del sistema funciona igual.
                    <?php endif; ?>
                </p>
                <hr>
                <p class="text-muted mb-2">
                    <i class="bi bi-shield-lock"></i>
                    Estas credenciales son <strong>tuyas</strong>, no del consultorio. Por eso no aparecen
                    en <code>/configuracion</code>: esa pantalla la ve el administrador de cada consultorio,
                    y con tu Access Token podría cobrar a tu nombre.
                </p>
                <p class="text-muted mb-0">
                    Guardadas aquí quedan en texto plano en la base. Si te preocupa un volcado de la base,
                    déjalas en el archivo de secretos y usa esta pantalla solo para consultarlas.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- ── Diagnóstico de correo ──────────────────────────────────────────── -->
<div class="row g-3 mt-1">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-envelope-paper"></i> Envío de correos</div>
            <div class="card-body">
                <?php if ($correoPrueba !== null): ?>
                    <div class="alert alert-<?= $correoPrueba['ok'] ? 'success' : 'danger' ?> py-2">
                        <i class="bi bi-<?= $correoPrueba['ok'] ? 'check-circle' : 'x-circle' ?>"></i>
                        <?= e($correoPrueba['mensaje']) ?>
                    </div>
                    <?php if (!empty($correoPrueba['log'])): ?>
                        <div class="small fw-semibold mb-1">Conversación SMTP</div>
                        <pre class="small bg-body-secondary rounded p-2 mb-3" style="max-height:220px;overflow:auto"><?= e($correoPrueba['log']) ?></pre>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="text-muted small mb-3">
                    Transporte activo:
                    <?php if (smtp_configurado()): ?>
                        <span class="badge bg-success">SMTP</span> <code><?= e(SMTP_HOST) ?></code>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark">mail() de PHP</span>
                        — poco fiable en hosting compartido. Configura SMTP en los secretos.
                    <?php endif; ?>
                    <br>Remitente: <strong><?= e(CORREO_FROM_NAME) ?> &lt;<?= e(CORREO_FROM) ?>&gt;</strong>.
                    Manda una prueba para confirmar que el hosting entrega.
                </p>

                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="probar_correo">
                    <div class="col-sm-8">
                        <label class="form-label small">Enviar prueba a</label>
                        <input type="email" name="correo_dest" class="form-control" required
                               placeholder="tucorreo@ejemplo.com"
                               value="<?= e($correoPrueba['dest'] ?? platform_admin()['email'] ?? '') ?>">
                    </div>
                    <div class="col-sm-4">
                        <button class="btn btn-primary w-100"><i class="bi bi-send"></i> Enviar prueba</button>
                    </div>
                </form>

                <?php if ($correoLog !== ''): ?>
                <hr>
                <div class="small fw-semibold mb-1">Bitácora (últimos envíos)</div>
                <pre class="small bg-body-secondary rounded p-2 mb-0" style="max-height:220px;overflow:auto"><?= e($correoLog) ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-alarm"></i> Recordatorios de cita</div>
            <div class="card-body">
                <?php if ($recResultado !== null): ?>
                    <?php if ($recResultado['ok']): ?>
                        <div class="alert alert-success py-2 small">
                            <i class="bi bi-check-circle"></i>
                            <?= (int) $recResultado['procesadas'] ?> citas procesadas ·
                            <strong><?= (int) $recResultado['correos'] ?> correos enviados</strong>
                            para el <?= e($recResultado['fecha']) ?>.
                            <?php if ((int) $recResultado['procesadas'] === 0): ?>
                                <br>No había citas pendientes de recordatorio ese día.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger py-2 small">
                            <i class="bi bi-x-circle"></i> <?= e($recResultado['mensaje']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="text-muted small mb-2">
                    Normalmente los envía el cron una vez al día
                    (<code>cron/recordatorios.php</code>). Aquí puedes dispararlos
                    a mano para verificar. No duplica: cada cita se marca al enviarse.
                </p>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="recordatorios_ahora">
                    <div class="col-7">
                        <label class="form-label small">Citas del día</label>
                        <input type="date" name="rec_fecha" class="form-control form-control-sm"
                               value="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>">
                    </div>
                    <div class="col-5">
                        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-send"></i> Enviar ahora</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-shield-check"></i> Si no llegan los correos</div>
            <div class="card-body small">
                <ol class="ps-3 mb-2">
                    <?php if (!smtp_configurado()): ?>
                    <li class="mb-2"><strong>Configura SMTP</strong> (lo más importante):
                        <code>mail()</code> de PHP casi nunca entrega en hosting compartido.
                        Crea un buzón del dominio y define <code>SMTP_HOST/PORT/USER/PASS</code>
                        en <code>mediagenda_secrets.php</code> (hay ejemplo en
                        <code>secrets.sample.php</code>).</li>
                    <?php endif; ?>
                    <li class="mb-2"><strong>Manda una prueba</strong> aquí y mira la conversación
                        SMTP: dice exactamente dónde falla (conexión, TLS, usuario/contraseña).</li>
                    <li class="mb-2"><strong>Revisa spam</strong> en el correo de destino.</li>
                    <li class="mb-2"><strong>SPF/DKIM del dominio</strong>
                        <code><?= e(substr(strrchr(CORREO_FROM, '@'), 1)) ?></code>:
                        el dominio debe autorizar al servidor que envía. En el DNS agrega el
                        registro SPF de tu hosting y activa DKIM.</li>
                    <li class="mb-2"><strong>El remitente debe ser del dominio del sitio</strong>
                        (ya lo es: <code><?= e(CORREO_FROM) ?></code>) y coincidir con el buzón SMTP.</li>
                    <li><strong>Correo del paciente:</strong> en el agendado en línea el correo es
                        opcional; sin él no hay a quién enviar el comprobante.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_foot.php'; ?>
