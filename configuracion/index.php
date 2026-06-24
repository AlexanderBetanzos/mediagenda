<?php
require_once __DIR__ . '/../includes/functions.php';
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
        $acento = cfg('color_acento', '#0b6fb8');
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
    ]);
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
                <div class="form-text"><?= et('Marcadores:') ?> <code>{paciente}</code> <code>{consultorio}</code> <code>{fecha}</code> <code>{hora}</code>.</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
