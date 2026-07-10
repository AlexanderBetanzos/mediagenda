<?php
/**
 * Historial del odontograma: cada guardado deja una foto completa del estado.
 * Se listan las versiones y se repinta la rejilla (solo lectura) con la que se
 * elija, para comparar la evolución del paciente.
 */
require_once __DIR__ . '/../includes/odontograma.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }

$pid = (int) ($_GET['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT nombre, apellidos FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }

$st = db()->prepare(
    'SELECT h.id, h.snapshot, h.motivo, h.creado_en, u.nombre AS usuario
     FROM odontograma_historial h
     LEFT JOIN usuarios u ON u.id = h.usuario_id
     WHERE h.paciente_id = ? AND h.consultorio_id = ?
     ORDER BY h.creado_en DESC, h.id DESC
     LIMIT 60'
);
$st->execute([$pid, tenant_id()]);
$versiones = $st->fetchAll();

// El JSON crudo se manda al cliente para repintar sin ir al servidor.
$snapshots = [];
foreach ($versiones as $v) {
    $d = json_decode($v['snapshot'], true) ?: [];
    $snapshots[$v['id']] = ['marcas' => $d['marcas'] ?? [], 'notas' => $d['notas'] ?? []];
}

$titulo = t('Historial del odontograma');
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($pac['nombre'].' '.$pac['apellidos']) ?></a></li>
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/odontograma/index?paciente_id=<?= $pid ?>"><?= et('Odontograma') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Historial') ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-clock-history text-brand"></i> <?= et('Historial del odontograma') ?></h1>
    <div class="no-print">
        <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> <?= et('Imprimir / PDF') ?></button>
        <a href="<?= BASE_URL ?>/odontograma/index?paciente_id=<?= $pid ?>" class="btn btn-primary btn-sm"><i class="bi bi-pencil"></i> <?= et('Editar odontograma') ?></a>
    </div>
</div>

<?php if (!$versiones): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-clock-history d-block mb-2" style="font-size:2rem;opacity:.4"></i>
    <?= et('Todavía no hay versiones guardadas.') ?>
</div></div>
<?php else: ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= et('Versiones') ?></div>
            <div class="list-group list-group-flush" id="versiones" style="max-height:520px;overflow-y:auto">
                <?php foreach ($versiones as $i => $v): ?>
                <button type="button" class="list-group-item list-group-item-action <?= $i === 0 ? 'active' : '' ?>" data-version="<?= $v['id'] ?>">
                    <div class="d-flex justify-content-between">
                        <strong><?= fmt_fecha($v['creado_en']) ?> <?= fmt_hora(substr($v['creado_en'], 11)) ?></strong>
                        <?php if ($i === 0): ?><span class="badge bg-light text-dark"><?= et('Actual') ?></span><?php endif; ?>
                    </div>
                    <div class="small">
                        <?= $v['motivo'] ? e($v['motivo']) : '—' ?>
                        <?php if ($v['usuario']): ?> · <?= e($v['usuario']) ?><?php endif; ?>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold" id="tituloVersion"></span>
                <span class="badge bg-secondary"><?= et('Solo lectura') ?></span>
            </div>
            <?php include __DIR__ . '/_grid.php'; ?>
            <div id="listaNotas" class="mt-3 small"></div>
        </div></div>
    </div>
</div>

<script>
(function () {
    var snapshots = <?= json_encode($snapshots, JSON_UNESCAPED_UNICODE) ?>;
    var T_NOTAS = <?= json_encode(t('Notas por diente')) ?>;
    function esc(s) { return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function mostrar(btn) {
        var snap = snapshots[btn.dataset.version] || { marcas: {}, notas: {} };
        window.odoPintar(snap.marcas, snap.notas);
        document.getElementById('tituloVersion').textContent = btn.querySelector('strong').textContent;

        var keys = Object.keys(snap.notas).sort((a, b) => a - b);
        var el = document.getElementById('listaNotas');
        if (!keys.length) { el.innerHTML = ''; return; }
        var h = '<div class="fw-semibold mb-1"><i class="bi bi-card-text"></i> ' + esc(T_NOTAS) + '</div><ul class="mb-0">';
        keys.forEach(d => { h += '<li><strong>' + d + ':</strong> ' + esc(snap.notas[d]) + '</li>'; });
        el.innerHTML = h + '</ul>';
    }

    document.querySelectorAll('#versiones [data-version]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#versiones [data-version]').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
            mostrar(b);
        });
    });

    /* La rejilla del historial no se edita. */
    document.querySelectorAll('.diente').forEach(el => el.style.cursor = 'default');
    mostrar(document.querySelector('#versiones [data-version]'));
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
