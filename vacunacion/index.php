<?php
/**
 * Vacunación: quién tiene vacunas vencidas o por tocar (con recordatorio por
 * WhatsApp en un clic) y el catálogo de vacunas de la clínica.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vacunas.php';
require_login();
require_modulo('expediente');
asegurar_vacunas();

$pdo = db();
$tid = (int) tenant_id();
$vet = es_veterinaria();
$esAdmin = has_role('admin');
$tab = ($_GET['tab'] ?? '') === 'catalogo' && $esAdmin ? 'catalogo' : 'pendientes';

/* ── Catálogo (solo admin) ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdmin) {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    if ($acc === 'cat_guardar') {
        $id  = (int) ($_POST['id'] ?? 0);
        $nom = trim((string) ($_POST['nombre'] ?? ''));
        $esp = trim((string) ($_POST['especie'] ?? ''));
        $ref = (int) ($_POST['refuerzo_dias'] ?? 0) ?: null;
        if ($nom === '') { flash(t('Escribe el nombre de la vacuna.'), 'warning'); }
        elseif ($id) {
            $pdo->prepare('UPDATE vacunas_catalogo SET nombre = ?, especie = ?, refuerzo_dias = ? WHERE id = ? AND consultorio_id = ?')
                ->execute([mb_substr($nom, 0, 120), $esp, $ref, $id, $tid]);
            flash(t('Vacuna actualizada.'));
        } else {
            $pdo->prepare('INSERT INTO vacunas_catalogo (consultorio_id, nombre, especie, refuerzo_dias) VALUES (?,?,?,?)')
                ->execute([$tid, mb_substr($nom, 0, 120), $esp, $ref]);
            flash(t('Vacuna agregada al catálogo.'));
        }
    } elseif ($acc === 'cat_toggle') {
        $pdo->prepare('UPDATE vacunas_catalogo SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')->execute([(int) $_POST['id'], $tid]);
    }
    redirect('/vacunacion/index?tab=catalogo');
}

/* ── Pendientes: la última aplicación de cada vacuna por paciente ────── */
$dias = in_array((int) ($_GET['dias'] ?? 30), [7, 30, 90], true) ? (int) $_GET['dias'] : 30;
$pend = [];
if ($tab === 'pendientes') {
    $q = $pdo->prepare(
        "SELECT a.id, a.nombre vacuna, a.fecha, a.proxima, p.*
         FROM vacunas_aplicadas a JOIN pacientes p ON p.id = a.paciente_id
         WHERE a.consultorio_id = ? AND a.proxima IS NOT NULL AND a.proxima <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
           AND NOT EXISTS (SELECT 1 FROM vacunas_aplicadas b WHERE b.consultorio_id = a.consultorio_id AND b.paciente_id = a.paciente_id
                           AND b.nombre = a.nombre AND (b.fecha > a.fecha OR (b.fecha = a.fecha AND b.id > a.id)))
         ORDER BY a.proxima LIMIT 300"
    );
    $q->execute([$tid, $dias]);
    $pend = $q->fetchAll();
}
$cat = $tab === 'catalogo' ? vacunas_catalogo(false) : [];
$hoy = date('Y-m-d');

$titulo = t('Vacunación');
$activo = 'vacunacion';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-shield-plus"></i> <?= et('Vacunación') ?></h1>
    <a href="<?= BASE_URL ?>/pacientes/index" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Registrar vacuna') ?></a>
</div>

<?php
$vencidas = ui_cuenta("SELECT COUNT(*) FROM vacunas_aplicadas a WHERE a.consultorio_id = ? AND a.proxima < CURDATE()
    AND NOT EXISTS (SELECT 1 FROM vacunas_aplicadas b WHERE b.consultorio_id = a.consultorio_id AND b.paciente_id = a.paciente_id AND b.nombre = a.nombre AND (b.fecha > a.fecha OR (b.fecha = a.fecha AND b.id > a.id)))", [$tid]);
echo ui_stats([
    [t('Vencidas'), $vencidas, 'bi-exclamation-circle', '#ef4444', BASE_URL . '/vacunacion/index?dias=7', $vencidas > 0],
    [t('Tocan en 7 días'), ui_cuenta("SELECT COUNT(*) FROM vacunas_aplicadas a WHERE a.consultorio_id = ? AND a.proxima BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        AND NOT EXISTS (SELECT 1 FROM vacunas_aplicadas b WHERE b.consultorio_id = a.consultorio_id AND b.paciente_id = a.paciente_id AND b.nombre = a.nombre AND (b.fecha > a.fecha OR (b.fecha = a.fecha AND b.id > a.id)))", [$tid]), 'bi-calendar-event', '#f59e0b'],
    [t('Aplicadas este mes'), ui_cuenta("SELECT COUNT(*) FROM vacunas_aplicadas WHERE consultorio_id = ? AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", [$tid]), 'bi-check2-circle', '#16a34a'],
    [t('En el catálogo'), ui_cuenta("SELECT COUNT(*) FROM vacunas_catalogo WHERE consultorio_id = ? AND activo = 1", [$tid]), 'bi-collection', 'var(--brand)', $esAdmin ? BASE_URL . '/vacunacion/index?tab=catalogo' : null],
]); ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link<?= $tab === 'pendientes' ? ' active' : '' ?>" href="<?= BASE_URL ?>/vacunacion/index"><i class="bi bi-bell"></i> <?= et('Por aplicar') ?></a></li>
    <?php if ($esAdmin): ?><li class="nav-item"><a class="nav-link<?= $tab === 'catalogo' ? ' active' : '' ?>" href="<?= BASE_URL ?>/vacunacion/index?tab=catalogo"><i class="bi bi-collection"></i> <?= et('Catálogo') ?></a></li><?php endif; ?>
</ul>

<?php if ($tab === 'pendientes'): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ([7 => 'Próximos 7 días', 30 => 'Próximos 30 días', 90 => 'Próximos 90 días'] as $d => $lbl): ?>
        <a href="<?= BASE_URL ?>/vacunacion/index?dias=<?= $d ?>" class="btn btn-sm <?= $dias === $d ? 'btn-primary' : 'btn-light' ?>"><?= et($lbl) ?></a>
    <?php endforeach; ?>
    <span class="small text-muted align-self-center"><?= et('Incluye las vencidas.') ?></span>
</div>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th><?= et('Paciente') ?></th><th><?= et('Vacuna') ?></th><th><?= et('Le toca') ?></th><th><?= et('Última') ?></th><th class="text-end"><?= et('Acciones') ?></th></tr></thead>
            <tbody>
            <?php if (!$pend): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Nadie tiene vacunas pendientes en este periodo.') ?></td></tr>
            <?php else: foreach ($pend as $r):
                $venc = $r['proxima'] < $hoy;
                $wa = wa_link($r['telefono'] ?? '', vacuna_wa_mensaje($r, $r['vacuna'], $r['proxima'])); ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/vacunacion/paciente?paciente_id=<?= (int) $r['id'] ?>" class="fw-semibold"><?= e($vet ? $r['nombre'] : $r['nombre'] . ' ' . $r['apellidos']) ?></a>
                        <?php if ($vet && mascota_propietario($r)): ?><div class="small text-muted"><?= e(mascota_propietario($r)) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($r['vacuna']) ?></td>
                    <td><span class="badge rounded-pill <?= $venc ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $venc ? et('Vencida') . ' · ' : '' ?><?= e(fmt_fecha($r['proxima'])) ?></span></td>
                    <td class="small text-muted"><?= e(fmt_fecha($r['fecha'])) ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="<?= e(t('Recordar por WhatsApp')) ?>"><i class="bi bi-whatsapp"></i></a><?php endif; ?>
                        <a href="<?= BASE_URL ?>/vacunacion/paciente?paciente_id=<?= (int) $r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-shield-plus"></i> <?= et('Aplicar') ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: /* Catálogo */ ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th><?= et('Vacuna') ?></th><?php if ($vet): ?><th><?= et('Especie') ?></th><?php endif; ?><th><?= et('Refuerzo') ?></th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($cat as $v): ?>
                        <tr class="<?= $v['activo'] ? '' : 'opacity-50' ?>">
                            <td class="fw-semibold"><?= e($v['nombre']) ?></td>
                            <?php if ($vet): ?><td><?= e($v['especie'] ?: t('Todas')) ?></td><?php endif; ?>
                            <td class="small"><?= e(vacuna_intervalo($v['refuerzo_dias'] !== null ? (int) $v['refuerzo_dias'] : null)) ?></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-edit='<?= e(json_encode(['id' => (int) $v['id'], 'nombre' => $v['nombre'], 'especie' => $v['especie'], 'ref' => (int) $v['refuerzo_dias']], JSON_UNESCAPED_UNICODE)) ?>' aria-label="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></button>
                                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="accion" value="cat_toggle"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="<?= $v['activo'] ? e(t('Desactivar')) : e(t('Activar')) ?>"><i class="bi bi-<?= $v['activo'] ? 'eye-slash' : 'eye' ?>"></i></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <form method="post" class="card" id="catForm">
            <div class="card-header fw-semibold" id="catTit"><i class="bi bi-plus-circle text-brand"></i> <?= et('Agregar vacuna') ?></div>
            <div class="card-body">
                <?= csrf_field() ?><input type="hidden" name="accion" value="cat_guardar"><input type="hidden" name="id" id="catId" value="">
                <label class="form-label"><?= et('Nombre') ?> *</label>
                <input type="text" name="nombre" id="catNom" class="form-control mb-2" required maxlength="120">
                <?php if ($vet): ?>
                <label class="form-label"><?= et('Especie') ?></label>
                <select name="especie" id="catEsp" class="form-select mb-2">
                    <option value="Todas"><?= et('Todas') ?></option>
                    <?php foreach (especies_vet() as $es): ?><option value="<?= e($es) ?>"><?= et($es) ?></option><?php endforeach; ?>
                </select>
                <?php else: ?><input type="hidden" name="especie" id="catEsp" value=""><?php endif; ?>
                <label class="form-label"><?= et('Refuerzo') ?></label>
                <select name="refuerzo_dias" id="catRef" class="form-select">
                    <?php foreach ([0 => 'Sin refuerzo', 21 => 'Cada 21 días', 30 => 'Cada mes', 90 => 'Cada 3 meses', 180 => 'Cada 6 meses', 365 => 'Cada año', 1095 => 'Cada 3 años', 3650 => 'Cada 10 años'] as $d => $lbl): ?>
                        <option value="<?= $d ?>"><?= et($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?= et('Con el refuerzo, la próxima dosis se calcula sola al registrar la vacuna.') ?></div>
                <button class="btn btn-primary w-100 mt-3"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
                <button type="button" class="btn btn-link w-100 d-none" id="catNuevo"><?= et('Cancelar edición') ?></button>
            </div>
        </form>
        <p class="small text-muted mt-2"><?= et('El catálogo inicial es un punto de partida: ajusta nombres e intervalos a tu práctica.') ?></p>
    </div>
</div>
<script>
document.querySelectorAll('[data-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
        var v = JSON.parse(b.dataset.edit), ref = document.getElementById('catRef');
        document.getElementById('catId').value = v.id; document.getElementById('catNom').value = v.nombre;
        document.getElementById('catEsp').value = v.especie || 'Todas';
        if (![].some.call(ref.options, function (o) { return +o.value === v.ref; })) { var o = new Option(v.ref + ' días', v.ref); ref.add(o); }
        ref.value = v.ref || 0;
        document.getElementById('catTit').innerHTML = '<i class="bi bi-pencil text-brand"></i> <?= e(t('Editar vacuna')) ?>';
        document.getElementById('catNuevo').classList.remove('d-none');
        document.getElementById('catNom').focus();
    });
});
document.getElementById('catNuevo').addEventListener('click', function () { location.href = '<?= BASE_URL ?>/vacunacion/index?tab=catalogo'; });
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
