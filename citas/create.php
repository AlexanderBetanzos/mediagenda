<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');

$errores = [];
$c = ['paciente_id' => $_GET['paciente_id'] ?? ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $c = $_POST;

    if (empty($c['paciente_id']) || !pertenece_al_tenant('pacientes', (int) $c['paciente_id'])) $errores[] = 'Selecciona un paciente.';
    if (empty($c['medico_id'])   || !pertenece_al_tenant('usuarios', (int) $c['medico_id']))    $errores[] = 'Selecciona un médico.';
    if (empty($c['fecha']))       $errores[] = 'Indica la fecha.';
    if (empty($c['hora']))        $errores[] = 'Indica la hora.';

    if (!$errores) {
        // Citas recurrentes: genera una serie a partir de la primera fecha.
        $intervalos = ['semanal' => '+1 week', 'quincenal' => '+2 weeks', 'mensual' => '+1 month'];
        $repetir = $_POST['repetir'] ?? 'no';
        $reps    = max(1, min(52, (int) ($_POST['repeticiones'] ?? 1)));
        if (!isset($intervalos[$repetir])) { $reps = 1; }

        $stmt = db()->prepare(
            'INSERT INTO citas (consultorio_id, paciente_id, medico_id, fecha, hora, duracion, tipo, motivo, estado, notas, modalidad)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $fecha = $c['fecha'];
        $creadas = 0;
        $primera = null;
        for ($i = 0; $i < $reps; $i++) {
            $stmt->execute([
                tenant_id(),
                (int) $c['paciente_id'], (int) $c['medico_id'], $fecha, $c['hora'],
                (int) ($c['duracion'] ?: 30), $c['tipo'] ?? 'medica',
                trim($c['motivo'] ?? '') ?: null, $c['estado'] ?? 'programada',
                trim($c['notas'] ?? '') ?: null,
                ($c['modalidad'] ?? '') === 'en_linea' ? 'en_linea' : 'presencial',
            ]);
            if ($i === 0) { $primera = (int) db()->lastInsertId(); }
            $creadas++;
            $fecha = date('Y-m-d', strtotime($fecha . ' ' . $intervalos[$repetir]));
        }
        auditar('crear', 'cita', $primera, $creadas > 1 ? "serie de $creadas citas" : null);
        flash($creadas > 1 ? "Se agendaron $creadas citas." : 'Cita agendada correctamente.');
        redirect('/citas/index?desde=' . urlencode($c['fecha']));
    }
}

$mostrar_recurrencia = true;
$titulo = t('Nueva cita');
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/citas/index"><?= et('Citas') ?></a></li>
        <li class="breadcrumb-item active"><?= et('Nueva') ?></li>
    </ol>
</nav>
<h1 class="h3 mb-3 d-flex align-items-center gap-2"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?> <?= ayuda_btn('citas.nueva') ?></h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="cita-form">
    <?= csrf_field() ?>
    <div class="cita-grid">
        <section class="pf-sec"><?php include __DIR__ . '/_form.php'; ?></section>
        <?php /* Resumen en vivo: lo que va a quedar agendado, antes de guardar. */ ?>
        <aside class="cita-side">
            <div class="pf-card cita-ticket">
                <div class="cita-tk-lbl"><i class="bi bi-calendar-heart"></i> <?= et('Resumen de la cita') ?></div>
                <div class="cita-tk-fecha" id="ctFecha">—</div>
                <div class="cita-tk-hora" id="ctHora"></div>
                <div class="cita-tk-row"><i class="bi bi-person"></i><span id="ctPac"><?= et('Sin paciente') ?></span></div>
                <div class="cita-tk-row"><i class="bi bi-person-vcard"></i><span id="ctMed">—</span></div>
                <div class="cita-tk-row"><i class="bi bi-camera-video" id="ctModIc"></i><span id="ctMod">—</span></div>
                <div class="cita-tk-row" id="ctMotRow" hidden><i class="bi bi-chat-left-text"></i><span id="ctMot"></span></div>
                <div class="small mt-3 cita-tk-tip"><i class="bi bi-whatsapp"></i> <?= et('Queda como programada. Desde la agenda le mandas el recordatorio por WhatsApp para que confirme en un clic.') ?></div>
                <button class="btn btn-primary btn-lg w-100 mt-3"><i class="bi bi-check-lg"></i> <?= et('Agendar') ?></button>
                <a href="<?= BASE_URL ?>/citas/index" class="btn btn-link w-100 mt-1 text-muted"><?= et('Cancelar') ?></a>
            </div>
        </aside>
    </div>
</form>

<style>
.cita-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 1.25rem; align-items: start; }
@media (max-width: 991.98px) { .cita-grid { grid-template-columns: 1fr; } }
.cita-side { position: sticky; top: 90px; }
.pf-card, .pf-sec { background: var(--bs-body-bg); border-radius: 18px; padding: 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 10px 28px rgba(15,30,60,.06); }
body.ui-glass .pf-card, body.ui-glass .pf-sec { background: var(--g-glass-2, #fff); box-shadow: var(--g-shadow-sm); }
.cita-ticket { background: linear-gradient(160deg, color-mix(in srgb, var(--brand) 12%, var(--bs-body-bg)), var(--bs-body-bg)) !important; }
.cita-tk-lbl { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--brand); }
.cita-tk-fecha { font-size: 1.25rem; font-weight: 750; letter-spacing: -.02em; margin-top: .6rem; text-transform: capitalize; }
.cita-tk-hora { font-size: 2.1rem; font-weight: 800; letter-spacing: -.04em; line-height: 1.1; color: var(--brand); font-variant-numeric: tabular-nums; }
.cita-tk-row { display: flex; gap: .55rem; align-items: center; padding: .45rem 0; border-top: 1px dashed rgba(127,127,127,.22); font-size: .9rem; }
.cita-tk-row:first-of-type { margin-top: .6rem; }
.cita-tk-row .bi { color: var(--brand); }
.cita-tk-tip { opacity: .75; } .cita-tk-tip .bi { color: #16a34a; }
/* Separadores del formulario (Repetición) como encabezado de sección */
.cita-form .col-12:has(> hr.my-1) span { display: inline-flex; align-items: center; gap: .4rem; color: var(--brand) !important; }
</style>
<script>
(function () {
    var f = document.querySelector('.cita-form');
    if (!f) return;
    var val = function (n) { var el = f.elements[n]; return el ? el.value : ''; };
    var txt = function (n) { var el = f.elements[n]; return el && el.tagName === 'SELECT' && el.selectedIndex >= 0 && el.value ? el.options[el.selectedIndex].text : ''; };
    function pinta() {
        var fe = val('fecha');
        document.getElementById('ctFecha').textContent = fe ? new Date(fe + 'T12:00').toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' }) : '—';
        var h = val('hora'), d = parseInt(val('duracion') || '0', 10);
        document.getElementById('ctHora').textContent = h ? h.slice(0, 5) + (d ? ' · ' + d + ' min' : '') : '';
        document.getElementById('ctPac').textContent = txt('paciente_id') || <?= json_encode(t('Sin paciente'), JSON_UNESCAPED_UNICODE) ?>;
        document.getElementById('ctMed').textContent = txt('medico_id') || '—';
        var mod = val('modalidad');
        document.getElementById('ctMod').textContent = mod === 'en_linea' ? <?= json_encode(t('Videoconsulta'), JSON_UNESCAPED_UNICODE) ?> : <?= json_encode(t('Presencial'), JSON_UNESCAPED_UNICODE) ?>;
        document.getElementById('ctModIc').className = 'bi ' + (mod === 'en_linea' ? 'bi-camera-video' : 'bi-building');
        var mo = val('motivo').trim();
        document.getElementById('ctMotRow').hidden = !mo;
        document.getElementById('ctMot').textContent = mo;
    }
    f.addEventListener('input', pinta); f.addEventListener('change', pinta); pinta();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
