<?php
/**
 * Ajustes de la plataforma (consola del dueño). Aquí viven las credenciales de
 * Mercado Pago con las que MediAgenda cobra las suscripciones a los
 * consultorios. NO son del consultorio: por eso no están en /configuracion.
 *
 * Precedencia: lo guardado aquí manda sobre el archivo de secretos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_platform();
require_once __DIR__ . '/../includes/mercadopago.php';

$prueba  = null;   // resultado de "Probar conexión"
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
        // Campo vacío = no se toca. Así no hay que reescribir el token para
        // cambiar solo la public key, ni se borra nada por descuido.
        $nuevos = [];
        foreach (['mp_access_token' => 'Access Token', 'mp_public_key' => 'Public Key'] as $clave => $etiqueta) {
            $valor = trim((string) ($_POST[$clave] ?? ''));
            if ($valor === '') continue;
            if (!preg_match('/^(TEST|APP_USR)-[A-Za-z0-9._-]{10,}$/', $valor)) {
                $errores[] = "El $etiqueta no tiene el formato de Mercado Pago (TEST-… o APP_USR-…).";
                continue;
            }
            $nuevos[$clave] = $valor;
        }

        // Mezclar producción con pruebas rompe los cobros de forma silenciosa.
        $tokenFinal  = $nuevos['mp_access_token'] ?? plataforma_cfg('mp_access_token', MP_ACCESS_TOKEN);
        $publicFinal = $nuevos['mp_public_key']   ?? plataforma_cfg('mp_public_key',   MP_PUBLIC_KEY);
        if ($tokenFinal !== '' && $publicFinal !== ''
            && (strpos($tokenFinal, 'TEST-') === 0) !== (strpos($publicFinal, 'TEST-') === 0)) {
            $errores[] = 'Las dos credenciales deben ser del mismo entorno: ambas de pruebas (TEST-) o ambas productivas (APP_USR-).';
        }

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

<?php include __DIR__ . '/_foot.php'; ?>
