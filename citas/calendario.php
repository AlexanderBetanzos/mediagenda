<?php
/**
 * Vista de calendario (FullCalendar) con arrastrar y soltar para reagendar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');

$u = current_user();
$medicoSel = $u['rol'] === 'medico' ? (string) $u['id'] : '';

$medicos = db()->prepare("SELECT id, nombre FROM usuarios WHERE rol='medico' AND activo=1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$titulo = 'Calendario';
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-calendar3 text-brand"></i> Calendario</h1>
    <div class="d-flex gap-2">
        <?php if ($u['rol'] !== 'medico'): ?>
        <select id="filtroMedico" class="form-select form-select-sm" style="width:auto">
            <option value="">Todos los médicos</option>
            <?php foreach ($medicos as $m): ?>
                <option value="<?= $m['id'] ?>"><?= e($m['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/citas/index" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-ul"></i> Lista</a>
        <a href="<?= BASE_URL ?>/citas/create" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Nueva cita</a>
    </div>
</div>

<div class="card"><div class="card-body">
    <div id="cal"></div>
    <p class="text-muted small mt-2 mb-0"><i class="bi bi-info-circle"></i> Arrastra una cita para reagendarla. Haz clic para editarla.</p>
</div></div>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
(function () {
    const BASE = <?= json_encode(BASE_URL) ?>;
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const medicoFijo = <?= json_encode($medicoSel) ?>;
    const filtro = document.getElementById('filtroMedico');

    function medico() { return medicoFijo || (filtro ? filtro.value : ''); }

    /** Formatea un Date local como Y-m-d y H:i:s (sin desfase UTC). */
    const pad = n => String(n).padStart(2, '0');
    function ymd(d){ return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()); }
    function his(d){ return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00'; }

    const cal = new FullCalendar.Calendar(document.getElementById('cal'), {
        locale: 'es',
        initialView: 'dayGridMonth',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
        buttonText: { today: 'Hoy', month: 'Mes', week: 'Semana', day: 'Día', list: 'Lista' },
        slotMinTime: '07:00:00', slotMaxTime: '21:00:00',
        nowIndicator: true, editable: true, eventStartEditable: true, eventDurationEditable: false,
        height: 'auto',
        events: function (info, ok, fail) {
            const qs = new URLSearchParams({ start: info.startStr, end: info.endStr, medico: medico() });
            fetch(BASE + '/citas/feed?' + qs).then(r => r.json()).then(ok).catch(fail);
        },
        eventDidMount: function (arg) {
            const p = arg.event.extendedProps;
            arg.el.title = p.estado + ' · ' + (p.medico || '') + (p.motivo ? ' · ' + p.motivo : '');
        },
        eventDrop: function (info) {
            const e = info.event;
            const body = new URLSearchParams({ csrf: CSRF, id: e.id, fecha: ymd(e.start), hora: his(e.start) });
            fetch(BASE + '/citas/mover', { method: 'POST', body })
                .then(r => r.json())
                .then(d => { if (!d.ok) { alert(d.error || 'No se pudo reagendar.'); info.revert(); } })
                .catch(() => { alert('Error de red al reagendar.'); info.revert(); });
        }
    });
    cal.render();
    if (filtro) filtro.addEventListener('change', () => cal.refetchEvents());
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
