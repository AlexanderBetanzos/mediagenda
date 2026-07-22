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

$prueba  = null;   // resultado de "Probar conexión"
$correoPrueba = null;   // resultado de "Probar envío de correo"
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
            $html = correo_layout('Prueba de envío', $cuerpo);
            $ok   = enviar_correo($dest, 'Prueba de envío · ' . APP_NAME, $html);
            auditar('plataforma_correo_prueba', 'plataforma', null, $dest . ' → ' . ($ok ? 'ok' : 'fallo'), null, $actor);
            $correoPrueba = [
                'ok'      => $ok,
                'dest'    => $dest,
                'mensaje' => $ok
                    ? 'PHP mail() aceptó el correo. Revisa la bandeja (y spam) de ' . $dest . '.'
                    : 'PHP mail() devolvió false: el servidor no aceptó el correo. Revisa la bitácora abajo.',
            ];
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
                <?php endif; ?>

                <p class="text-muted small mb-3">
                    Los correos salen con <code>mail()</code> de PHP, desde
                    <strong><?= e(CORREO_FROM_NAME) ?> &lt;<?= e(CORREO_FROM) ?>&gt;</strong>.
                    Manda una prueba para confirmar que el hosting los entrega.
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
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-check"></i> Si no llegan los correos</div>
            <div class="card-body small">
                <ol class="ps-3 mb-2">
                    <li class="mb-2"><strong>Manda una prueba</strong> aquí. Si dice “aceptó”
                        pero no llega, casi siempre es entregabilidad (spam/SPF), no el código.</li>
                    <li class="mb-2"><strong>Revisa spam</strong> en el correo de destino.</li>
                    <li class="mb-2"><strong>SPF/DKIM del dominio</strong>
                        <code><?= e(substr(strrchr(CORREO_FROM, '@'), 1)) ?></code>:
                        el dominio debe autorizar al servidor que envía. En el DNS del dominio,
                        agrega el registro SPF que indique tu hosting y activa DKIM.</li>
                    <li class="mb-2"><strong>El remitente debe ser del dominio del sitio</strong>
                        (ya lo es: <code><?= e(CORREO_FROM) ?></code>). Enviar “a nombre de”
                        Gmail/otros dominios se rechaza.</li>
                    <li><strong>Correo del paciente:</strong> en el agendado en línea el correo es
                        opcional; sin él no hay a quién enviar el comprobante.</li>
                </ol>
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle"></i>
                    Para volumen alto o máxima entrega, conviene un SMTP dedicado
                    (Hostinger/SendGrid/Amazon SES). Puedo integrarlo si lo necesitas.
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_foot.php'; ?>
