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

    guardar_cfg([
        // Marca
        'marca_nombre'  => trim($_POST['marca_nombre'] ?? '') ?: 'MediAgenda',
        'marca_lema'    => trim($_POST['marca_lema'] ?? ''),
        'marca_logo'    => trim($_POST['marca_logo'] ?? ''),
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
        // Recordatorios / WhatsApp
        'pais_lada'              => preg_replace('/\D/', '', $_POST['pais_lada'] ?? '') ?: '52',
        'recordatorio_plantilla' => trim($_POST['recordatorio_plantilla'] ?? ''),
        'recordatorio_auto'      => !empty($_POST['recordatorio_auto']) ? '1' : '0',
    ]);
    auditar('config_editar');
    flash('Configuración guardada correctamente.');
    redirect('/configuracion/index');
}

$titulo = 'Configuración';
$activo = 'configuracion';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-gear text-brand"></i> Configuración</h1>
<p class="text-muted">Personaliza esta instalación para tu consultorio. Estos ajustes aplican a todo el sistema.</p>

<form method="post">
    <?= csrf_field() ?>

    <!-- Apariencia -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-palette text-brand"></i> Apariencia</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">Tema por defecto</label>
                <select name="tema_default" class="form-select">
                    <?php foreach ($temas as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= cfg('tema_default', 'dark') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Cada usuario puede cambiarlo desde el menú de tema (<i class="bi bi-circle-half"></i>) en la barra superior.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Color de acento</label>
                <div class="input-group">
                    <input type="color" name="color_acento" class="form-control form-control-color" value="<?= e(color_acento()) ?>" title="Color de marca">
                    <input type="text" class="form-control" value="<?= e(color_acento()) ?>" readonly>
                </div>
                <div class="form-text">Se usa en botones y acentos de la interfaz.</div>
            </div>
        </div>
    </div>

    <!-- Marca / white-label -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-stars text-brand"></i> Marca</div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">Nombre del consultorio</label>
                <input type="text" name="marca_nombre" class="form-control" value="<?= e(cfg('marca_nombre', 'MediAgenda')) ?>" maxlength="60">
            </div>
            <div class="col-md-6">
                <label class="form-label">Lema / descripción</label>
                <input type="text" name="marca_lema" class="form-control" value="<?= e(cfg('marca_lema')) ?>" maxlength="120">
            </div>
            <div class="col-12">
                <label class="form-label">URL del logo <span class="text-muted">(opcional)</span></label>
                <input type="text" name="marca_logo" class="form-control" value="<?= e(cfg('marca_logo')) ?>" placeholder="https://… o /consultorios/assets/img/logo.png">
                <div class="form-text">Si lo defines, reemplaza el ícono junto al nombre en la barra superior.</div>
            </div>
        </div>
    </div>

    <!-- Datos del consultorio -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-hospital text-brand"></i> Datos del consultorio</div>
        <div class="card-body row g-3">
            <div class="col-md-8">
                <label class="form-label">Razón social</label>
                <input type="text" name="razon_social" class="form-control" value="<?= e(cfg('razon_social')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">RFC / Id. fiscal</label>
                <input type="text" name="rfc" class="form-control" value="<?= e(cfg('rfc')) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Dirección</label>
                <input type="text" name="direccion" class="form-control" value="<?= e(cfg('direccion')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Teléfono</label>
                <input type="text" name="telefono" class="form-control" value="<?= e(cfg('telefono')) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Correo de contacto</label>
                <input type="email" name="email" class="form-control" value="<?= e(cfg('email')) ?>">
            </div>
            <div class="col-12"><div class="form-text">Estos datos aparecen en recetas y facturas impresas.</div></div>
        </div>
    </div>

    <!-- Regional -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-globe-americas text-brand"></i> Regional</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Moneda</label>
                <select name="moneda" class="form-select">
                    <?php foreach ($monedas as $m): ?>
                        <option value="<?= $m ?>" <?= moneda() === $m ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Zona horaria</label>
                <select name="zona_horaria" class="form-select">
                    <?php foreach ($zonas as $z): ?>
                        <option value="<?= $z ?>" <?= cfg('zona_horaria', 'America/Mexico_City') === $z ? 'selected' : '' ?>><?= $z ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Formato de fecha</label>
                <select name="formato_fecha" class="form-select">
                    <?php foreach ($formatos as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= cfg('formato_fecha', 'd/m/Y') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Recordatorios automáticos (correo) -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-bell text-brand"></i> Recordatorios automáticos</div>
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="recordatorio_auto" name="recordatorio_auto" value="1" <?= cfg('recordatorio_auto', '1') === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="recordatorio_auto">Enviar recordatorio por <strong>correo</strong> a los pacientes con cita al día siguiente</label>
            </div>
            <div class="form-text">Se envía una vez al día (requiere el cron <code>cron/recordatorios.php</code> activo en el servidor).</div>
        </div>
    </div>

    <?php if (modulo_activo('whatsapp')): ?>
    <!-- Recordatorios / WhatsApp -->
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-whatsapp text-brand"></i> Recordatorios por WhatsApp</div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Lada del país</label>
                <div class="input-group">
                    <span class="input-group-text">+</span>
                    <input type="text" name="pais_lada" class="form-control" value="<?= e(cfg('pais_lada', '52')) ?>" maxlength="4" placeholder="52">
                </div>
                <div class="form-text">Se antepone a teléfonos locales (México = 52).</div>
            </div>
            <div class="col-12">
                <label class="form-label">Plantilla del mensaje</label>
                <textarea name="recordatorio_plantilla" class="form-control" rows="3" maxlength="500"><?= e(cfg('recordatorio_plantilla', 'Hola {paciente}, le recordamos su cita en {consultorio} el {fecha} a las {hora}. Por favor confirme su asistencia. ¡Gracias!')) ?></textarea>
                <div class="form-text">Marcadores disponibles: <code>{paciente}</code> <code>{consultorio}</code> <code>{fecha}</code> <code>{hora}</code>.</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="text-end mb-4">
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar configuración</button>
    </div>
</form>

<script>
/* Sincroniza el texto del color con el selector. */
document.querySelector('input[name="color_acento"]').addEventListener('input', function () {
    this.nextElementSibling.value = this.value;
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
