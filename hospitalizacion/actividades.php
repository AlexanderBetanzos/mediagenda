<?php
/**
 * Programa del día del internamiento: actividades grupales (terapia grupal,
 * psicoeducación, danzaterapia, yoga, nutrición…) con pase de lista de los
 * pacientes internados. Lo registrado aparece en cada estancia.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/hospitalizacion.php';
require_login();
require_modulo('expediente');
asegurar_hosp();

$pdo = db();
$tid = (int) tenant_id();
$u = current_user();
$dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['dia'] ?? $_POST['dia'] ?? '')) ? ($_GET['dia'] ?? $_POST['dia']) : date('Y-m-d');
$TIPOS = ['Terapia grupal', 'Psicoeducación', 'Terapia ocupacional', 'Danzaterapia', 'Yoga', 'Activación física', 'Nutrición', 'Visita familiar', 'Recreativa', 'Otra'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    $txt = fn ($k, $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
    if ($acc === 'crear') {
        $nombre = $txt('nombre', 120);
        if ($nombre === '') { flash(t('Escribe la actividad.'), 'warning'); redirect('/hospitalizacion/actividades?dia=' . $dia); }
        $hora = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['hora'] ?? '')) ? $_POST['hora'] . ':00' : null;
        // Repetir: solo ese día, de lunes a viernes o diario, por N semanas.
        $rep = $_POST['repetir'] ?? 'no';
        $sem = max(1, min(12, (int) ($_POST['semanas'] ?? 1)));
        $fechas = [$dia];
        if ($rep !== 'no') {
            $fechas = [];
            for ($i = 0; $i < $sem * 7; $i++) {
                $f = date('Y-m-d', strtotime($dia . " +$i day"));
                if ($rep === 'lv' && (int) date('N', strtotime($f)) > 5) continue;
                $fechas[] = $f;
            }
        }
        $ins = $pdo->prepare('INSERT INTO hosp_actividades (consultorio_id, fecha, hora, nombre, tipo, responsable, notas, creado_por) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($fechas as $f) $ins->execute([$tid, $f, $hora, $nombre, in_array($_POST['tipo'] ?? '', $TIPOS, true) ? $_POST['tipo'] : null,
                                               $txt('responsable', 120) ?: null, $txt('notas', 255) ?: null, (int) $u['id']]);
        flash(count($fechas) > 1 ? sprintf(t('Actividad programada en %d días.'), count($fechas)) : t('Actividad programada.'));
    } elseif ($acc === 'asistencia') {
        $aid = (int) ($_POST['actividad'] ?? 0);
        $ok = $pdo->prepare('SELECT id FROM hosp_actividades WHERE id = ? AND consultorio_id = ?');
        $ok->execute([$aid, $tid]);
        if ($ok->fetchColumn()) {
            $ins = $pdo->prepare('INSERT INTO hosp_asistencias (consultorio_id, actividad_id, estancia_id, asistio, registrado_por) VALUES (?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE asistio = VALUES(asistio), registrado_por = VALUES(registrado_por)');
            foreach ((array) ($_POST['est'] ?? []) as $eid => $v) {
                if (!pertenece_al_tenant('hosp_estancias', (int) $eid)) continue;
                if ($v === '') { $pdo->prepare('DELETE FROM hosp_asistencias WHERE actividad_id = ? AND estancia_id = ? AND consultorio_id = ?')->execute([$aid, (int) $eid, $tid]); continue; }
                $ins->execute([$tid, $aid, (int) $eid, $v === '1' ? 1 : 0, (int) $u['id']]);
            }
            flash(t('Asistencia guardada.'));
        }
    } elseif ($acc === 'borrar') {
        $aid = (int) ($_POST['actividad'] ?? 0);
        $pdo->prepare('DELETE FROM hosp_asistencias WHERE actividad_id = ? AND consultorio_id = ?')->execute([$aid, $tid]);
        $pdo->prepare('DELETE FROM hosp_actividades WHERE id = ? AND consultorio_id = ?')->execute([$aid, $tid]);
        flash(t('Actividad eliminada.'));
    }
    redirect('/hospitalizacion/actividades?dia=' . $dia);
}

$st = $pdo->prepare('SELECT * FROM hosp_actividades WHERE consultorio_id = ? AND fecha = ? ORDER BY hora IS NULL, hora, id');
$st->execute([$tid, $dia]);
$acts = $st->fetchAll();
// Internados ese día (ingresaron antes de que acabara y no tenían alta antes de que empezara).
$st = $pdo->prepare("SELECT e.id, e.cuarto_id, e.cama, p.nombre, p.apellidos FROM hosp_estancias e JOIN pacientes p ON p.id = e.paciente_id
                     WHERE e.consultorio_id = ? AND e.ingreso <= ? AND (e.alta_en IS NULL OR e.alta_en >= ?) ORDER BY p.apellidos, p.nombre");
$st->execute([$tid, $dia . ' 23:59:59', $dia . ' 00:00:00']);
$internos = $st->fetchAll();
$marcas = [];
if ($acts) {
    $ids = implode(',', array_map(fn ($a) => (int) $a['id'], $acts));
    foreach ($pdo->query("SELECT actividad_id, estancia_id, asistio FROM hosp_asistencias WHERE actividad_id IN ($ids)") as $r) $marcas[$r['actividad_id']][$r['estancia_id']] = (int) $r['asistio'];
}

$titulo = t('Programa del día');
$activo = 'hospitalizacion';
include __DIR__ . '/../includes/header.php';
$prev = date('Y-m-d', strtotime($dia . ' -1 day')); $next = date('Y-m-d', strtotime($dia . ' +1 day'));
?>
<nav aria-label="breadcrumb" class="no-print"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/hospitalizacion/index"><?= et('Hospitalización') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Programa del día') ?></li>
</ol></nav>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-calendar2-week"></i> <?= et('Programa del día') ?> <span class="text-muted fs-5 text-capitalize">· <?= e(fmt_fecha($dia)) ?></span></h1>
    <div class="d-flex gap-2 no-print">
        <div class="btn-group">
            <a href="?dia=<?= $prev ?>" class="btn btn-light"><i class="bi bi-chevron-left"></i></a>
            <a href="?" class="btn btn-light"><?= et('Hoy') ?></a>
            <a href="?dia=<?= $next ?>" class="btn btn-light"><i class="bi bi-chevron-right"></i></a>
        </div>
        <button onclick="window.print()" class="btn btn-light"><i class="bi bi-printer"></i></button>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <?php if (!$acts): ?>
        <div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-calendar2-week fs-1 d-block mb-2 opacity-50"></i><?= et('No hay actividades este día. Prográmalas a la derecha (puedes repetirlas toda la semana).') ?></div></div>
        <?php endif; ?>
        <?php foreach ($acts as $a): $m = $marcas[$a['id']] ?? []; $si = count(array_filter($m)); ?>
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-bold"><?= $a['hora'] ? '<span class="text-brand">' . substr($a['hora'], 0, 5) . '</span> · ' : '' ?><?= e($a['nombre']) ?></div>
                    <div class="small text-muted"><?= e(implode(' · ', array_filter([$a['tipo'] ? t($a['tipo']) : '', $a['responsable'], $a['notas']]))) ?></div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-light border"><?= $si ?> / <?= count($internos) ?> <?= et('asistieron') ?></span>
                    <form method="post" class="no-print" onsubmit="return confirm(<?= e(json_encode(t('¿Eliminar esta actividad?'))) ?>)"><?= csrf_field() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="dia" value="<?= e($dia) ?>"><input type="hidden" name="actividad" value="<?= (int) $a['id'] ?>">
                        <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button></form>
                </div>
            </div>
            <?php if ($internos): ?>
            <form method="post" class="mt-3">
                <?= csrf_field() ?><input type="hidden" name="accion" value="asistencia"><input type="hidden" name="dia" value="<?= e($dia) ?>"><input type="hidden" name="actividad" value="<?= (int) $a['id'] ?>">
                <div class="pa-lista">
                    <?php foreach ($internos as $p): $v = $m[$p['id']] ?? null; ?>
                    <div class="pa">
                        <span class="text-truncate"><?= e($p['nombre'] . ' ' . $p['apellidos']) ?><?php if ($p['cama']): ?> <small class="text-muted">· <?= e($p['cama']) ?></small><?php endif; ?></span>
                        <span class="btn-group btn-group-sm">
                            <input type="radio" class="btn-check" name="est[<?= (int) $p['id'] ?>]" id="a<?= $a['id'] ?>_<?= $p['id'] ?>_1" value="1" <?= $v === 1 ? 'checked' : '' ?>><label class="btn btn-outline-success" for="a<?= $a['id'] ?>_<?= $p['id'] ?>_1"><i class="bi bi-check-lg"></i></label>
                            <input type="radio" class="btn-check" name="est[<?= (int) $p['id'] ?>]" id="a<?= $a['id'] ?>_<?= $p['id'] ?>_0" value="0" <?= $v === 0 ? 'checked' : '' ?>><label class="btn btn-outline-danger" for="a<?= $a['id'] ?>_<?= $p['id'] ?>_0"><i class="bi bi-x-lg"></i></label>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2 no-print">
                    <button type="button" class="btn btn-sm btn-link px-0 pa-todos"><?= et('Marcar a todos como presentes') ?></button>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-check2"></i> <?= et('Guardar asistencia') ?></button>
                </div>
            </form>
            <?php else: ?><p class="small text-muted mt-2 mb-0"><?= et('No hay pacientes internados este día.') ?></p><?php endif; ?>
        </div></div>
        <?php endforeach; ?>
    </div>
    <div class="col-lg-4 no-print">
        <div class="card"><div class="card-body">
            <h2 class="h6 mb-3"><i class="bi bi-plus-circle"></i> <?= et('Programar actividad') ?></h2>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="dia" value="<?= e($dia) ?>">
                <div class="col-4"><label class="form-label"><?= et('Hora') ?></label><input type="time" name="hora" class="form-control"></div>
                <div class="col-8"><label class="form-label"><?= et('Tipo') ?></label><select name="tipo" class="form-select" id="selTipoAct"><?php foreach ($TIPOS as $tp): ?><option><?= e(t($tp)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label"><?= et('Actividad') ?> *</label><input name="nombre" id="inNomAct" class="form-control" maxlength="120" required placeholder="<?= e(t('Ej. Grupo de manejo de emociones')) ?>"></div>
                <div class="col-12"><label class="form-label"><?= et('Responsable') ?></label><input name="responsable" class="form-control" maxlength="120"></div>
                <div class="col-7"><label class="form-label"><?= et('Repetir') ?></label><select name="repetir" class="form-select"><option value="no"><?= et('Solo este día') ?></option><option value="lv"><?= et('Lunes a viernes') ?></option><option value="diario"><?= et('Todos los días') ?></option></select></div>
                <div class="col-5"><label class="form-label"><?= et('Semanas') ?></label><input type="number" name="semanas" min="1" max="12" value="4" class="form-control"></div>
                <div class="col-12"><input name="notas" class="form-control" maxlength="255" placeholder="<?= e(t('Lugar o notas (opcional)')) ?>"></div>
                <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Programar') ?></button></div>
            </form>
        </div></div>
    </div>
</div>
<style>
.pa-lista{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:.4rem .9rem}
.pa{display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.3rem 0;border-bottom:1px solid var(--bs-border-color)}
@media print{.app-navbar,.sidebar,.no-print,footer,.ayuda-fab{display:none!important}main{width:100%!important;max-width:100%!important;flex:0 0 100%!important;margin:0!important}}
</style>
<script>
document.querySelectorAll('.pa-todos').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('form').querySelectorAll('input[value="1"]').forEach(function (r) { r.checked = true; }); });
});
(function () {   // el tipo sugiere el nombre si aún está vacío
    var s = document.getElementById('selTipoAct'), n = document.getElementById('inNomAct');
    if (s && n) s.addEventListener('change', function () { if (!n.value.trim()) n.value = s.value; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
