<?php
/**
 * Recordar por WhatsApp desde el número propio (gratis, sin saldo).
 * Lista las citas de un día (mañana por omisión) y abre WhatsApp una por una
 * con el mensaje ya escrito; cada una queda marcada como "enviada" para que
 * la recepción sepa por dónde va. Usa el WhatsApp que esté abierto en esa
 * computadora o celular (el número del consultorio o del doctor).
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');
require_modulo('whatsapp');

$tid = (int) tenant_id();
// Columna de la marca manual (migración perezosa).
try {
    if (!db()->query("SHOW COLUMNS FROM citas LIKE 'recordatorio_wa_manual_en'")->fetch()) {
        db()->exec('ALTER TABLE citas ADD COLUMN recordatorio_wa_manual_en DATETIME NULL');
    }
} catch (Throwable $e) { /* sin permisos de DDL: la lista funciona igual, sin marca */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'marcar') {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    try {
        db()->prepare('UPDATE citas SET recordatorio_wa_manual_en = NOW() WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
    } catch (Throwable $e) {}
    auditar('recordatorio_wa_manual', 'cita', $id);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

$fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fecha'] ?? '') ? $_GET['fecha'] : date('Y-m-d', strtotime('+1 day'));
$where = 'c.consultorio_id = ? AND c.fecha = ? AND c.estado IN (\'programada\',\'confirmada\')';
$params = [$tid, $fecha];
$u = current_user();
if (($u['rol'] ?? '') === 'medico') { $where .= ' AND c.medico_id = ?'; $params[] = (int) $u['id']; }

$cols = 'c.id, c.hora, c.estado, p.nombre, p.apellidos, p.telefono, u.nombre med_nombre';
try { db()->query('SELECT recordatorio_wa_manual_en FROM citas LIMIT 0'); $cols .= ', c.recordatorio_wa_manual_en manual_en'; } catch (Throwable $e) {}
$st = db()->prepare("SELECT $cols FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id WHERE $where ORDER BY c.hora");
$st->execute($params);
$citas = $st->fetchAll();

$conTel = array_filter($citas, fn ($c) => tel_e164($c['telefono'] ?? '') !== '');
$enviadas = count(array_filter($citas, fn ($c) => !empty($c['manual_en'])));

$titulo = t('Recordar por WhatsApp');
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-whatsapp text-success"></i> <?= et('Recordar por WhatsApp') ?></h1>
        <div class="small text-muted"><?= et('Se abre WhatsApp con el mensaje listo; solo le das enviar. Sale de tu propio número y no gasta saldo.') ?></div>
    </div>
    <form class="d-flex gap-2 align-items-center">
        <a class="btn btn-sm btn-outline-secondary" href="?fecha=<?= e(date('Y-m-d', strtotime($fecha . ' -1 day'))) ?>">‹</a>
        <input type="date" name="fecha" value="<?= e($fecha) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
        <a class="btn btn-sm btn-outline-secondary" href="?fecha=<?= e(date('Y-m-d', strtotime($fecha . ' +1 day'))) ?>">›</a>
    </form>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><strong><?= count($citas) ?></strong> <?= et('citas el') ?> <?= e(fmt_fecha($fecha)) ?> · <span id="rcCuenta"><?= $enviadas ?></span> <?= et('enviadas') ?></span>
        <?php if ($conTel): ?>
        <button class="btn btn-success btn-sm" id="rcSiguiente"><i class="bi bi-send"></i> <?= et('Enviar la siguiente') ?></button>
        <?php endif; ?>
    </div>
    <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th><?= et('Hora') ?></th><th><?= et('Paciente') ?></th><th class="d-none d-md-table-cell"><?= et('Médico') ?></th><th><?= et('Teléfono') ?></th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php if (!$citas): ?><tr><td colspan="5" class="text-center text-muted py-4"><?= et('No hay citas por recordar ese día.') ?></td></tr><?php endif; ?>
        <?php foreach ($citas as $c):
            $wa = wa_link($c['telefono'], mensaje_recordatorio($c['nombre'] . ' ' . $c['apellidos'], fmt_fecha($c['fecha'] ?? $fecha), fmt_hora($c['hora']), cita_enlace((int) $c['id'])));
            $ok = !empty($c['manual_en']); ?>
            <tr data-rc="<?= (int) $c['id'] ?>" data-enviada="<?= $ok ? 1 : 0 ?>">
                <td class="fw-semibold"><?= e(fmt_hora($c['hora'])) ?></td>
                <td><?= e($c['nombre'] . ' ' . $c['apellidos']) ?><?php if ($c['estado'] === 'confirmada'): ?> <span class="badge text-bg-success-subtle text-success"><?= et('confirmada') ?></span><?php endif; ?></td>
                <td class="small d-none d-md-table-cell"><?= e($c['med_nombre'] ?? '') ?></td>
                <td class="small"><?= e($c['telefono'] ?: '—') ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($wa): ?>
                    <span class="rc-ok text-success small me-2" <?= $ok ? '' : 'hidden' ?>><i class="bi bi-check2-all"></i> <?= et('Enviada') ?></span>
                    <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm <?= $ok ? 'btn-outline-success' : 'btn-success' ?> rc-btn"><i class="bi bi-whatsapp"></i> <?= $ok ? et('Otra vez') : et('Enviar') ?></a>
                    <?php else: ?>
                    <span class="small text-muted"><?= et('Sin teléfono') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<p class="small text-muted mt-3"><i class="bi bi-info-circle"></i>
    <?= et('El mensaje se toma de Configuración → Recordatorios e incluye el enlace para que el paciente confirme o cancele con un clic.') ?>
    <?php if (has_role('admin')): ?><a href="<?= BASE_URL ?>/mensajes/index"><?= et('¿Prefieres que se envíen solos?') ?></a><?php endif; ?></p>

<script>
(function () {
    var csrf = <?= json_encode(csrf_token()) ?>, cuenta = document.getElementById('rcCuenta');
    function marcar(tr) {
        if (tr.dataset.enviada === '1') return;
        tr.dataset.enviada = '1';
        tr.querySelector('.rc-ok').hidden = false;
        var b = tr.querySelector('.rc-btn'); b.classList.replace('btn-success', 'btn-outline-success'); b.lastChild.textContent = ' <?= e(t('Otra vez')) ?>';
        cuenta.textContent = +cuenta.textContent + 1;
        var f = new FormData(); f.append('accion', 'marcar'); f.append('id', tr.dataset.rc); f.append('csrf', csrf);
        fetch(location.pathname, { method: 'POST', body: f, credentials: 'same-origin' });
    }
    document.querySelectorAll('.rc-btn').forEach(function (a) {
        a.addEventListener('click', function () { marcar(a.closest('tr')); });
    });
    var sig = document.getElementById('rcSiguiente');
    if (sig) sig.addEventListener('click', function () {
        var tr = document.querySelector('tr[data-enviada="0"] .rc-btn');
        if (!tr) { sig.disabled = true; sig.textContent = '<?= e(t('¡Listo, todas enviadas!')) ?>'; return; }
        window.open(tr.href, '_blank', 'noopener');
        marcar(tr.closest('tr'));
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
