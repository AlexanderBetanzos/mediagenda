<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mercadopago.php';
require_role('admin');

$temas = ['dark' => 'Oscuro', 'light' => 'Claro', 'auto' => 'Automático (según el sistema)'];
$zonas = [
    'America/Mexico_City', 'America/Tijuana', 'America/Cancun', 'America/Monterrey',
    'America/Bogota', 'America/Lima', 'America/Santiago', 'America/Buenos_Aires',
    'America/New_York', 'America/Los_Angeles', 'Europe/Madrid', 'UTC',
];
$formatos = [
    'd/m/Y' => 'dd/mm/aaaa  (31/12/2026)',
    'm/d/Y' => 'mm/dd/aaaa  (12/31/2026)',
    'Y-m-d' => 'aaaa-mm-dd  (2026-12-31)',
    'd M Y' => 'dd Mmm aaaa (31 Dec 2026)',
];
$monedas = ['MXN', 'USD', 'EUR', 'COP', 'ARS', 'CLP', 'PEN'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Color de acento: solo se acepta #rrggbb válido.
    $acento = trim($_POST['color_acento'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $acento)) {
        $acento = cfg('color_acento', '#1f6b73');
    }

    $tema = $_POST['tema_default'] ?? 'dark';
    if (!isset($temas[$tema])) $tema = 'dark';

    // Logo: si suben un archivo, gana sobre la URL escrita.
    $logo = trim($_POST['marca_logo'] ?? '');
    if (!empty($_FILES['marca_logo_file']['name'])) {
        $f = $_FILES['marca_logo_file'];
        $perm = ['jpg'=>1,'jpeg'=>1,'png'=>1,'gif'=>1,'webp'=>1];
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('No se pudo subir el logo. Inténtalo de nuevo.', 'danger');
        } elseif (!isset($perm[$ext]) || strpos((string) mime_content_type($f['tmp_name']), 'image/') !== 0) {
            flash('El logo debe ser una imagen PNG, JPG, WEBP o GIF.', 'warning');
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            flash('El logo supera el máximo de 2 MB.', 'warning');
        } else {
            $dir = __DIR__ . '/../assets/logos';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $nombre = 'logo_' . tenant_id() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre)) {
                $logo = BASE_URL . '/assets/logos/' . $nombre;
            } else {
                flash('No se pudo guardar el logo en el servidor.', 'danger');
            }
        }
    }

    guardar_cfg([
        // Marca
        'marca_nombre'  => trim($_POST['marca_nombre'] ?? '') ?: 'MediAgenda',
        'marca_lema'    => trim($_POST['marca_lema'] ?? ''),
        'marca_logo'    => $logo,
        // Página pública (micrositio /c/<slug>)
        'web_titular'   => trim($_POST['web_titular'] ?? ''),
        'web_acerca'    => trim($_POST['web_acerca'] ?? ''),
        'web_foto'      => trim($_POST['web_foto'] ?? ''),
        // Apariencia
        'tema_default'  => $tema,
        'color_acento'  => $acento,
        // Datos del consultorio
        'razon_social'  => trim($_POST['razon_social'] ?? ''),
        'direccion'     => trim($_POST['direccion'] ?? ''),
        'telefono'      => trim($_POST['telefono'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'rfc'           => trim($_POST['rfc'] ?? ''),
        // Regional
        'moneda'        => in_array($_POST['moneda'] ?? '', $monedas, true) ? $_POST['moneda'] : 'MXN',
        'zona_horaria'  => in_array($_POST['zona_horaria'] ?? '', $zonas, true) ? $_POST['zona_horaria'] : 'America/Mexico_City',
        'formato_fecha' => isset($formatos[$_POST['formato_fecha'] ?? '']) ? $_POST['formato_fecha'] : 'd/m/Y',
        'idioma_default' => in_array($_POST['idioma_default'] ?? '', ['es','en'], true) ? $_POST['idioma_default'] : 'es',
        // Recordatorios / WhatsApp
        'pais_lada'              => preg_replace('/\D/', '', $_POST['pais_lada'] ?? '') ?: '52',
        'recordatorio_plantilla' => trim($_POST['recordatorio_plantilla'] ?? ''),
        'recordatorio_auto'      => !empty($_POST['recordatorio_auto']) ? '1' : '0',
        'agenda_online'          => !empty($_POST['agenda_online']) ? '1' : '0',
        'agenda_online_dias'     => (string) max(1, min(180, (int) ($_POST['agenda_online_dias'] ?? 30))),
        'agenda_online_duracion' => (string) max(10, min(180, (int) ($_POST['agenda_online_duracion'] ?? 30))),
        'agenda_online_aviso'    => trim($_POST['agenda_online_aviso'] ?? ''),
        'agenda_online_precio'   => (string) max(0, round((float) ($_POST['agenda_online_precio'] ?? 0), 2)),
    ]);
    /* Pago en línea: credenciales de Mercado Pago DEL CONSULTORIO, con las que
       cobra a sus propios pacientes. Un campo vacío no borra el que ya había. */
    ['nuevos' => $mpNuevos, 'errores' => $mpErrores] =
        mp_credenciales_desde_post($_POST, mp_tenant_access_token(), mp_tenant_public_key());

    if ($mpErrores) {
        foreach ($mpErrores as $err) { flash($err, 'danger'); }
    } else {
        $mpNuevos['mp_pago_habilitado'] = !empty($_POST['mp_pago_habilitado']) ? '1' : '0';
        guardar_cfg($mpNuevos);
        if (array_diff_key($mpNuevos, ['mp_pago_habilitado' => 1])) {
            auditar('config_mp_editar', 'configuracion', null, 'credenciales de pago en línea');
        }
    }

    auditar('config_editar');
    flash('Configuración guardada correctamente.');
    redirect('/configuracion/index');
}

$titulo = t('Configuración');
$activo = 'configuracion';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-gear text-brand"></i> <?= et('Configuración') ?></h1>
<p class="text-muted"><?= et('Personaliza esta instalación para tu consultorio. Estos ajustes aplican a todo el sistema.') ?></p>

<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <!-- Apariencia -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-palette text-brand"></i> <?= et('Apariencia') ?></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= et('Tema por defecto') ?></label>
                <select name="tema_default" class="form-select">
                    <?php foreach ($temas as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= cfg('tema_default', 'dark') === $k ? 'selected' : '' ?>><?= et($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Color de acento') ?></label>
                <div class="input-group">
                    <input type="color" name="color_acento" class="form-control form-control-color" value="<?= e(color_acento()) ?>" title="Color de marca">
                    <input type="text" class="form-control" value="<?= e(color_acento()) ?>" readonly>
                </div>
                <div class="form-text"><?= et('Se usa en botones y acentos de la interfaz.') ?></div>
            </div>
        </div>
    </div>

    <!-- Marca / white-label -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-stars text-brand"></i> <?= et('Marca') ?></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label"><?= et('Nombre del consultorio') ?></label>
                <input type="text" name="marca_nombre" class="form-control" value="<?= e(cfg('marca_nombre', 'MediAgenda')) ?>" maxlength="60">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Lema / descripción') ?></label>
                <input type="text" name="marca_lema" class="form-control" value="<?= e(cfg('marca_lema')) ?>" maxlength="120">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Subir logo') ?> <span class="text-muted"><?= et('(opcional)') ?></span></label>
                <input type="file" name="marca_logo_file" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif">
                <div class="form-text"><?= et('PNG, JPG, WEBP o GIF · máx. 2 MB. Reemplaza el ícono junto al nombre.') ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('o pega una URL') ?> <span class="text-muted"><?= et('(opcional)') ?></span></label>
                <input type="text" name="marca_logo" class="form-control" value="<?= e(cfg('marca_logo')) ?>" placeholder="https://…">
                <?php if (cfg('marca_logo')): ?>
                <div class="mt-2"><img src="<?= e(cfg('marca_logo')) ?>" alt="logo" style="max-height:40px;max-width:160px" class="border rounded p-1 bg-white"></div>
                <?php endif; ?>
            </div>

            <?php $slugTen = tenant()['slug'] ?? ''; ?>
            <?php if ($slugTen): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <div class="small fw-semibold mb-1">
                        <i class="bi bi-globe"></i> <?= et('Tu página pública') ?>
                    </div>
                    <input type="text" class="form-control form-control-sm font-monospace" readonly
                           onclick="this.select()" value="<?= e(url_absoluta('/c/' . $slugTen)) ?>">
                    <div class="form-text mb-0">
                        <?= et('Es la cara de tu consultorio: marca, servicios, equipo y contacto. Compártela en tu Google Maps, Instagram o firma de correo. Se ve aunque no tengas la agenda en línea activada.') ?>
                        <a href="<?= e(url_absoluta('/c/' . $slugTen)) ?>" target="_blank" rel="noopener"><?= et('Verla') ?> <i class="bi bi-box-arrow-up-right"></i></a>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?= et('Titular de la portada') ?></label>
                    <input type="text" name="web_titular" class="form-control" maxlength="120"
                           placeholder="<?= e(t('Tu salud en las mejores manos')) ?>"
                           value="<?= e(cfg('web_titular')) ?>">
                    <div class="form-text"><?= et('La frase grande del inicio. Si la dejas vacía se usa el nombre del consultorio.') ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= et('Foto de portada') ?> <span class="text-muted"><?= et('(URL, opcional)') ?></span></label>
                    <input type="text" name="web_foto" class="form-control" placeholder="https://…" value="<?= e(cfg('web_foto')) ?>">
                    <div class="form-text"><?= et('Una foto de tu consultorio detrás del titular. Sin ella se usa un degradado con tu color.') ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label"><?= et('Sobre el consultorio') ?></label>
                    <textarea name="web_acerca" class="form-control" rows="3" maxlength="600"
                              placeholder="<?= e(t('Cuéntale al paciente quiénes son, desde cuándo atienden y qué los distingue.')) ?>"><?= e(cfg('web_acerca')) ?></textarea>
                    <div class="form-text"><?= et('Aparece como sección "Sobre nosotros". Los servicios, el equipo y el horario se muestran solos.') ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Datos del consultorio -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-hospital text-brand"></i> <?= et('Datos del consultorio') ?></div>
        <div class="card-body row g-3">
            <div class="col-md-8">
                <label class="form-label"><?= et('Razón social') ?></label>
                <input type="text" name="razon_social" class="form-control" value="<?= e(cfg('razon_social')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('RFC / Id. fiscal') ?></label>
                <input type="text" name="rfc" class="form-control" value="<?= e(cfg('rfc')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label"><?= et('Dirección') ?></label>
                <input type="text" name="direccion" class="form-control" value="<?= e(cfg('direccion')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Teléfono') ?></label>
                <input type="text" name="telefono" class="form-control" value="<?= e(cfg('telefono')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Correo de contacto') ?></label>
                <input type="email" name="email" class="form-control" value="<?= e(cfg('email')) ?>">
            </div>
            <div class="col-12"><div class="form-text"><?= et('Estos datos aparecen en recetas y facturas impresas.') ?></div></div>
        </div>
    </div>

    <!-- Regional -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-globe-americas text-brand"></i> <?= et('Regional') ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= et('Moneda') ?></label>
                <select name="moneda" class="form-select">
                    <?php foreach ($monedas as $m): ?>
                        <option value="<?= $m ?>" <?= moneda() === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Zona horaria') ?></label>
                <select name="zona_horaria" class="form-select">
                    <?php foreach ($zonas as $z): ?>
                        <option value="<?= $z ?>" <?= cfg('zona_horaria', 'America/Mexico_City') === $z ? 'selected' : '' ?>><?= $z ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Formato de fecha') ?></label>
                <select name="formato_fecha" class="form-select">
                    <?php foreach ($formatos as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= cfg('formato_fecha', 'd/m/Y') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Idioma por defecto') ?></label>
                <select name="idioma_default" class="form-select">
                    <?php foreach (['es'=>'Español','en'=>'English'] as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= cfg('idioma_default', 'es') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= et('Cada usuario puede cambiarlo desde el menú de idioma.') ?></div>
            </div>
        </div>
    </div>

    <!-- Recordatorios automáticos (correo) -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-bell text-brand"></i> <?= et('Recordatorios automáticos') ?></div>
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="recordatorio_auto" name="recordatorio_auto" value="1" <?= cfg('recordatorio_auto', '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="recordatorio_auto"><?= et('Enviar recordatorio por') ?> <strong><?= et('correo') ?></strong> <?= et('a los pacientes con cita al día siguiente') ?></label>
            </div>
            <div class="form-text"><?= et('Se envía una vez al día') ?> (<?= et('requiere el cron') ?> <code>cron/recordatorios.php</code>).</div>
        </div>
    </div>

    <?php if (modulo_activo('agenda_online')): ?>
    <!-- Agenda en línea (página pública de reservas) -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-calendar-plus text-brand"></i> <?= et('Agenda en línea') ?></div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="agenda_online" name="agenda_online" value="1"
                       <?= cfg('agenda_online', '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="agenda_online">
                    <?= et('Permitir que los pacientes agenden solos desde internet') ?>
                </label>
            </div>

            <?php $slugTen = tenant()['slug'] ?? ''; ?>
            <?php if (cfg('agenda_online', '0') === '1' && $slugTen): ?>
            <div class="alert alert-info py-2">
                <div class="small fw-semibold mb-1"><i class="bi bi-link-45deg"></i> <?= et('Comparte este enlace con tus pacientes') ?></div>
                <input type="text" class="form-control form-control-sm font-monospace" readonly
                       onclick="this.select()" value="<?= e(agenda_online_url($slugTen)) ?>">
                <div class="form-text mb-0"><?= et('Ponlo en tu Google Maps, en tu Instagram o en tu firma de correo.') ?></div>
            </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label"><?= et('Se puede reservar hasta con') ?></label>
                    <div class="input-group">
                        <input type="number" min="1" max="180" name="agenda_online_dias" class="form-control"
                               value="<?= e(cfg('agenda_online_dias', '30')) ?>">
                        <span class="input-group-text"><?= et('días') ?></span>
                    </div>
                    <div class="form-text"><?= et('de anticipación.') ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= et('Duración de cada cita') ?></label>
                    <div class="input-group">
                        <input type="number" min="10" max="180" step="5" name="agenda_online_duracion" class="form-control"
                               value="<?= e(cfg('agenda_online_duracion', '30')) ?>">
                        <span class="input-group-text">min</span>
                    </div>
                    <div class="form-text"><?= et('Define el tamaño de los huecos que se ofrecen.') ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= et('Costo de la cita') ?></label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" min="0" step="0.01" name="agenda_online_precio" class="form-control"
                               value="<?= e(cfg('agenda_online_precio', '0')) ?>">
                    </div>
                    <div class="form-text">
                        <?= et('0 = no se cobra por reservar.') ?>
                        <?php if (!mp_tenant_habilitado()): ?>
                            <span class="text-warning"><?= et('Requiere activar el pago en línea (más abajo).') ?></span>
                        <?php else: ?>
                            <?= et('El paciente podrá pagar al agendar.') ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label"><?= et('Aviso al paciente') ?></label>
                    <input name="agenda_online_aviso" class="form-control" maxlength="255"
                           placeholder="<?= e(t('Ej. Llega 10 minutos antes')) ?>"
                           value="<?= e(cfg('agenda_online_aviso')) ?>">
                </div>
            </div>

            <div class="form-text mt-2">
                <i class="bi bi-info-circle"></i>
                <?= et('Solo se ofrecen huecos reales: salen del horario de cada médico, menos sus bloqueos y menos las citas ya tomadas. Si un médico no tiene horario configurado, no aparece.') ?>
                <a href="<?= BASE_URL ?>/citas/horarios"><?= et('Configurar horarios') ?></a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (modulo_activo('whatsapp')): ?>
    <!-- Recordatorios / WhatsApp -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-whatsapp text-brand"></i> <?= et('Recordatorios por WhatsApp') ?></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label"><?= et('Lada del país') ?></label>
                <div class="input-group">
                    <span class="input-group-text">+</span>
                    <input type="text" name="pais_lada" class="form-control" value="<?= e(cfg('pais_lada', '52')) ?>" maxlength="4" placeholder="52">
                </div>
                <div class="form-text"><?= et('Se antepone a teléfonos locales (México = 52).') ?></div>
            </div>
            <div class="col-12">
                <label class="form-label"><?= et('Plantilla del mensaje') ?></label>
                <textarea name="recordatorio_plantilla" class="form-control" rows="3" maxlength="500"><?= e(cfg('recordatorio_plantilla', 'Hola {paciente}, le recordamos su cita en {consultorio} el {fecha} a las {hora}. Por favor confirme su asistencia. ¡Gracias!')) ?></textarea>
                <div class="form-text">
                    <?= et('Marcadores:') ?> <code>{paciente}</code> <code>{consultorio}</code> <code>{fecha}</code> <code>{hora}</code> <code>{enlace}</code>.
                    <br><?= et('El {enlace} deja que el paciente confirme o cancele con un clic. Si no lo pones, se agrega al final igualmente.') ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pago en línea: credenciales del propio consultorio -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-credit-card text-brand"></i> <?= et('Pago en línea — Mercado Pago') ?></span>
            <?php if (mp_tenant_habilitado() && mp_tenant_es_sandbox()): ?>
                <span class="badge bg-warning text-dark"><?= et('Modo pruebas') ?></span>
            <?php elseif (mp_tenant_habilitado()): ?>
                <span class="badge bg-success"><?= et('Producción') ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="text-muted">
                <?= et('Conecta tu cuenta de Mercado Pago para cobrar en línea a tus pacientes. El dinero cae en TU cuenta.') ?>
                <?= et('Obtén tus credenciales en') ?>
                <a href="https://www.mercadopago.com.mx/developers/panel/app" target="_blank" rel="noopener"><?= et('tu panel de desarrollador') ?></a>.
                <?= et('Usa credenciales de prueba (TEST-) para probar y de producción (APP_USR-) para cobrar de verdad.') ?>
            </p>

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="mpHabilitado"
                       name="mp_pago_habilitado" value="1" <?= cfg('mp_pago_habilitado', '0') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="mpHabilitado"><?= et('Habilitar pago en línea para mis pacientes') ?></label>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= et('Access Token') ?> <span class="text-muted small">(<?= et('privado') ?>)</span></label>
                <input type="text" name="mp_access_token" class="form-control font-monospace" autocomplete="off"
                       value="<?= e(mp_tenant_access_token()) ?>" placeholder="APP_USR-… o TEST-…">
                <div class="form-text"><?= et('No lo compartas. Se usa para crear los cobros desde el servidor.') ?></div>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= et('Public Key') ?> <span class="text-muted small">(<?= et('frontend, requerida') ?>)</span></label>
                <input type="text" name="mp_public_key" class="form-control font-monospace" autocomplete="off"
                       value="<?= e(mp_tenant_public_key()) ?>" placeholder="APP_USR-… o TEST-…">
                <div class="form-text"><?= et('Necesaria para mostrar el formulario de pago en el sitio.') ?></div>
            </div>

            <?php if (mp_tenant_habilitado()): ?>
            <div class="alert alert-success mb-0 py-2">
                <i class="bi bi-check-circle"></i>
                <?= et('Credenciales listas. El botón de pago para el paciente se conecta en la siguiente entrega.') ?>
            </div>
            <?php elseif (cfg('mp_pago_habilitado', '0') === '1'): ?>
            <div class="alert alert-warning mb-0 py-2">
                <i class="bi bi-exclamation-triangle"></i>
                <?= et('Activaste el pago en línea pero falta alguna credencial.') ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-end mb-4">
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar configuración') ?></button>
    </div>
</form>

<script>
/* Sincroniza el texto del color con el selector. */
document.querySelector('input[name="color_acento"]').addEventListener('input', function () {
    this.nextElementSibling.value = this.value;
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
