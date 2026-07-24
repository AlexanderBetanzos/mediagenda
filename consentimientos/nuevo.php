<?php
/**
 * Consentimiento informado: se redacta, el paciente (o tutor) y el médico
 * firman en pantalla (canvas) y se guarda. Luego se imprime desde ver.php.
 * Clínico transversal: sirve a cualquier especialidad.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
ensure_consentimientos_table();

$u   = current_user();
$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);

$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }
$pacNombre = trim($pac['nombre'] . ' ' . ($pac['apellidos'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    verify_csrf();
    $titulo   = trim($_POST['titulo'] ?? '');
    $contenido = trim($_POST['contenido'] ?? '');
    $firmante = trim($_POST['firmante'] ?? '') ?: $pacNombre;
    $fPac = $_POST['firma_paciente'] ?? '';
    $fMed = $_POST['firma_medico'] ?? '';

    // Solo se aceptan firmas como imagen PNG en data URI (evita basura).
    $okPng = fn($s) => is_string($s) && strpos($s, 'data:image/png;base64,') === 0 && strlen($s) < 400000;
    if (!$okPng($fPac)) $fPac = null;
    if (!$okPng($fMed)) $fMed = null;

    if ($titulo === '') {
        flash('Ponle un título al consentimiento.', 'warning');
    } elseif (!$fPac) {
        flash('Falta la firma del paciente.', 'warning');
    } else {
        db()->prepare('INSERT INTO consentimientos
            (consultorio_id, paciente_id, medico_id, titulo, contenido, firma_paciente, firma_medico, firmante, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([tenant_id(), $pid, $u['id'], mb_substr($titulo,0,180), $contenido ?: null, $fPac, $fMed, mb_substr($firmante,0,160), $u['id']]);
        $cid = (int) db()->lastInsertId();
        auditar('crear', 'consentimiento', $cid, $titulo . ' · Paciente #' . $pid);
        flash('Consentimiento firmado y guardado.');
        redirect('/consentimientos/ver?id=' . $cid . '&print=1');
    }
}

$plantillas = consentimientos_plantillas();
$titulo = t('Consentimiento informado');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-vector-pen text-brand"></i> <?= et('Consentimiento informado') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i> <?= et('Volver al paciente') ?></a>
</div>
<p class="text-muted"><?= et('Paciente:') ?> <strong><?= e($pacNombre) ?></strong></p>

<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<form method="post" id="formConsent">
    <?= csrf_field() ?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">
    <input type="hidden" name="firma_paciente" id="firmaPacienteInput">
    <input type="hidden" name="firma_medico" id="firmaMedicoInput">

    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label"><?= et('Título') ?> *</label>
                <input type="text" name="titulo" id="cTitulo" class="form-control" required maxlength="180" value="">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Usar plantilla') ?></label>
                <select id="cPlantilla" class="form-select">
                    <option value=""><?= et('— elegir —') ?></option>
                    <?php foreach (array_keys($plantillas) as $k): ?><option value="<?= e($k) ?>"><?= e($k) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label"><?= et('Texto del consentimiento') ?></label>
                <textarea name="contenido" id="cContenido" class="form-control" rows="8"></textarea>
                <div class="form-text"><?= et('Edítalo libremente. Se sustituyen {paciente} y {consultorio}.') ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Firma') ?> — <?= et('nombre de quien firma') ?></label>
                <input type="text" name="firmante" class="form-control" maxlength="160" value="<?= e($pacNombre) ?>">
                <div class="form-text"><?= et('Si es menor de edad, el nombre del padre/madre o tutor.') ?></div>
            </div>
        </div>
    </div></div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <label class="form-label fw-semibold"><?= et('Firma del paciente / tutor') ?> *</label>
                <canvas class="firma-canvas" data-target="firmaPacienteInput" width="500" height="180"></canvas>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2 firma-clear"><i class="bi bi-eraser"></i> <?= et('Limpiar') ?></button>
            </div></div>
        </div>
        <div class="col-md-6">
            <div class="card"><div class="card-body">
                <label class="form-label fw-semibold"><?= et('Firma del médico') ?></label>
                <canvas class="firma-canvas" data-target="firmaMedicoInput" width="500" height="180"></canvas>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-2 firma-clear"><i class="bi bi-eraser"></i> <?= et('Limpiar') ?></button>
            </div></div>
        </div>
    </div>

    <div class="text-end">
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Firmar y guardar') ?></button>
    </div>
</form>

<style>
.firma-canvas { width:100%; max-width:100%; height:180px; border:1px dashed rgba(127,127,127,.5); border-radius:10px; background:#fff; touch-action:none; cursor:crosshair; }
</style>
<script>
(function () {
    var PLANT = <?= json_encode($plantillas, JSON_UNESCAPED_UNICODE) ?>;
    var pacNombre = <?= json_encode($pacNombre) ?>;
    var consNombre = <?= json_encode(marca_nombre()) ?>;
    var sel = document.getElementById('cPlantilla');
    sel.addEventListener('change', function () {
        var k = sel.value; if (!k || !PLANT[k]) return;
        if (!document.getElementById('cTitulo').value) document.getElementById('cTitulo').value = k;
        var txt = PLANT[k].replace(/\{paciente\}/g, pacNombre).replace(/\{consultorio\}/g, consNombre);
        document.getElementById('cContenido').value = txt;
    });

    // Firma en canvas (mouse + touch). Guarda el trazo en el hidden al terminar.
    document.querySelectorAll('.firma-canvas').forEach(function (cv) {
        var ctx = cv.getContext('2d');
        // Ajusta la resolución interna al tamaño mostrado (nitidez).
        function fit() {
            var r = cv.getBoundingClientRect();
            cv.width = r.width; cv.height = r.height;
            ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        }
        fit();
        var drawing = false, dirty = false, target = document.getElementById(cv.dataset.target);
        function pos(e) {
            var r = cv.getBoundingClientRect();
            var t = e.touches ? e.touches[0] : e;
            return { x: t.clientX - r.left, y: t.clientY - r.top };
        }
        function start(e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
        function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); dirty = true; e.preventDefault(); }
        function end() { if (!drawing) return; drawing = false; if (dirty && target) target.value = cv.toDataURL('image/png'); }
        cv.addEventListener('mousedown', start); cv.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
        cv.addEventListener('touchstart', start, { passive: false });
        cv.addEventListener('touchmove', move, { passive: false });
        cv.addEventListener('touchend', end);
        cv.closest('.card').querySelector('.firma-clear').addEventListener('click', function () {
            ctx.clearRect(0, 0, cv.width, cv.height); dirty = false; if (target) target.value = '';
        });
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
