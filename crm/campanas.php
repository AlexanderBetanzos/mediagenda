<?php
/**
 * Campañas de WhatsApp (click-to-send): elige un segmento y un mensaje, y
 * genera la lista de pacientes con su enlace wa.me listo para enviar.
 * Sin API: el envío es manual, uno por uno (gratis y sin riesgo de bloqueo).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('crm');

$tid = tenant_id();
$seg = $_GET['seg'] ?? 'todos';
$msg = $_GET['msg'] ?? 'Hola {paciente}, le saluda {consultorio}. ';

$segmentos = [
    'todos'      => 'Todos los pacientes',
    'medico'     => 'Pacientes médicos',
    'dental'     => 'Pacientes dentales',
    'cumple_mes' => 'Cumpleaños de este mes',
];
if (!isset($segmentos[$seg])) $seg = 'todos';

$where  = ['consultorio_id = ?'];
$params = [$tid];
if ($seg === 'medico' || $seg === 'dental') { $where[] = 'tipo = ?'; $params[] = $seg; }
if ($seg === 'cumple_mes') { $where[] = 'fecha_nacimiento IS NOT NULL AND MONTH(fecha_nacimiento) = MONTH(CURDATE())'; }

$st = db()->prepare('SELECT id, nombre, apellidos, telefono FROM pacientes WHERE ' . implode(' AND ', $where) . ' ORDER BY apellidos, nombre');
$st->execute($params);
$pacientes = $st->fetchAll();

$conTel = array_filter($pacientes, fn($p) => trim((string) $p['telefono']) !== '');
$waOn   = modulo_activo('whatsapp');

$titulo = t('Campañas');
$activo = 'crm';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/crm/index"><?= et('CRM') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Campañas') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi bi-megaphone text-brand"></i> <?= et('Campaña de WhatsApp') ?></h1>

<?php if (!$waOn): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= et('El módulo de WhatsApp no está activo en tu plan; podrás ver la lista pero no los botones de envío.') ?></div>
<?php endif; ?>

<form class="card mb-4" method="get">
    <div class="card-body row g-3">
        <div class="col-md-4">
            <label class="form-label"><?= et('Segmento') ?></label>
            <select name="seg" class="form-select">
                <?php foreach ($segmentos as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $seg === $k ? 'selected' : '' ?>><?= et($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-8">
            <label class="form-label"><?= et('Mensaje') ?></label>
            <textarea name="msg" class="form-control" rows="3" maxlength="600"><?= e($msg) ?></textarea>
            <div class="form-text"><?= et('Marcadores:') ?> <code>{paciente}</code> <code>{consultorio}</code>.</div>
        </div>
        <div class="col-12"><button class="btn btn-primary"><i class="bi bi-search"></i> <?= et('Generar lista') ?></button></div>
    </div>
</form>

<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="badge bg-secondary"><?= count($pacientes) ?> <?= et('en el segmento') ?></span>
    <span class="badge bg-success"><?= count($conTel) ?> <?= et('con teléfono') ?></span>
    <?php if (count($pacientes) - count($conTel) > 0): ?><span class="badge bg-warning text-dark"><?= count($pacientes) - count($conTel) ?> <?= et('sin teléfono') ?></span><?php endif; ?>
</div>

<div class="card">
    <ul class="list-group list-group-flush">
        <?php if (!$pacientes): ?>
            <li class="list-group-item text-muted text-center py-4"><?= et('No hay pacientes en este segmento.') ?></li>
        <?php else: foreach ($pacientes as $p):
            $nombre = $p['nombre'] . ' ' . $p['apellidos'];
            $texto  = strtr($msg, ['{paciente}' => $p['nombre'], '{consultorio}' => marca_nombre()]);
            $wa     = ($waOn && trim((string) $p['telefono']) !== '') ? wa_link($p['telefono'], $texto) : ''; ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold"><?= e($nombre) ?></span>
                <span class="small text-muted ms-2"><?= e($p['telefono'] ?: t('sin teléfono')) ?></span>
            </div>
            <?php if ($wa): ?>
                <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp"></i> <?= et('Enviar') ?></a>
            <?php else: ?>
                <span class="badge bg-light text-muted border">—</span>
            <?php endif; ?>
        </li>
        <?php endforeach; endif; ?>
    </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
