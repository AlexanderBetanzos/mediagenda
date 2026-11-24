<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recetas.php';
require_role('medico', 'admin');
require_modulo('recetas');
asegurar_recetas();

$u = current_user();
$errores = [];
$presel = (int) ($_GET['paciente_id'] ?? 0);

/* Punto de partida del formulario: vacío, o copia de otra receta ("Repetir"). */
$base = ['diagnostico' => '', 'indicaciones' => '', 'items' => []];
$desde = (int) ($_GET['desde'] ?? 0);
if ($desde) {
    $st = db()->prepare('SELECT * FROM recetas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$desde, tenant_id()]);
    if ($o = $st->fetch()) {
        $presel = $presel ?: (int) $o['paciente_id'];
        $base['diagnostico']  = (string) $o['diagnostico'];
        $base['indicaciones'] = (string) $o['indicaciones'];
        $it = db()->prepare('SELECT medicamento, dosis, via, frecuencia, duracion FROM receta_items WHERE receta_id = ? ORDER BY id');
        $it->execute([$desde]);
        $base['items'] = $it->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $paciente_id = (int) ($_POST['paciente_id'] ?? 0);
    $medico_id   = (int) ($_POST['medico_id'] ?? $u['id']);
    $meds        = $_POST['med'] ?? [];

    if (!$paciente_id || !pertenece_al_tenant('pacientes', $paciente_id)) $errores[] = t('Selecciona un paciente.');
    if (!pertenece_al_tenant('usuarios', $medico_id)) $medico_id = (int) $u['id'];
    // Al menos un medicamento con nombre
    $items = [];
    foreach ($meds as $m) {
        if (trim($m['medicamento'] ?? '') !== '') {
            $items[] = $m;
        }
    }
    if (!$items) $errores[] = t('Agrega al menos un medicamento.');
    $presel = $paciente_id;
    $base = ['diagnostico' => trim($_POST['diagnostico'] ?? ''), 'indicaciones' => trim($_POST['indicaciones'] ?? ''), 'items' => array_values($items)];

    if (!$errores) {
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO recetas (consultorio_id, paciente_id, medico_id, diagnostico, indicaciones, notas) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            tenant_id(), $paciente_id, $medico_id,
            trim($_POST['diagnostico'] ?? '') ?: null,
            trim($_POST['indicaciones'] ?? '') ?: null,
            trim($_POST['notas'] ?? '') ?: null,
        ]);
        $rid = $pdo->lastInsertId();
        $it = $pdo->prepare('INSERT INTO receta_items (receta_id, medicamento, dosis, via, frecuencia, duracion) VALUES (?,?,?,?,?,?)');
        foreach ($items as $m) {
            $it->execute([
                $rid, trim($m['medicamento']),
                trim($m['dosis'] ?? '') ?: null,
                mb_substr(trim($m['via'] ?? ''), 0, 60) ?: null,
                trim($m['frecuencia'] ?? '') ?: null,
                trim($m['duracion'] ?? '') ?: null,
            ]);
        }
        $pdo->prepare('UPDATE recetas SET token = ? WHERE id = ?')->execute([bin2hex(random_bytes(16)), $rid]);
        med_aprender($items);

        // ¿Guardarla como favorita para reutilizarla?
        $favNom = trim($_POST['fav_nombre'] ?? '');
        if ($favNom === '') $favNom = trim($_POST['diagnostico'] ?? '') ?: trim((string) (reset($items)['medicamento'] ?? ''));
        if (!empty($_POST['fav']) && $favNom !== '') {
            $soloYo = !empty($_POST['fav_mia']);
            $limpios = array_map(fn($m) => [
                'medicamento' => trim($m['medicamento']), 'dosis' => trim($m['dosis'] ?? ''), 'via' => trim($m['via'] ?? ''),
                'frecuencia' => trim($m['frecuencia'] ?? ''), 'duracion' => trim($m['duracion'] ?? ''),
            ], array_values($items));
            $pdo->prepare('INSERT INTO receta_favoritas (consultorio_id, medico_id, nombre, diagnostico, indicaciones, items) VALUES (?,?,?,?,?,?)')
                ->execute([tenant_id(), $soloYo ? (int) $u['id'] : null, mb_substr($favNom, 0, 120),
                           trim($_POST['diagnostico'] ?? '') ?: null, trim($_POST['indicaciones'] ?? '') ?: null,
                           json_encode($limpios, JSON_UNESCAPED_UNICODE)]);
        }
        $favUsada = (int) ($_POST['fav_usada'] ?? 0);
        if ($favUsada) $pdo->prepare('UPDATE receta_favoritas SET usos = usos + 1 WHERE id = ? AND consultorio_id = ?')->execute([$favUsada, tenant_id()]);
        $pdo->commit();
        auditar('crear', 'receta', (int) $rid, 'Paciente #' . $paciente_id);
        flash('Receta creada correctamente.');
        redirect('/recetas/ver?id=' . $rid);
    }
}

$pacientes = db()->prepare('SELECT id, nombre, apellidos, alergias, enf_cronicas, fecha_nacimiento FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();
$medicos   = db()->prepare("SELECT id, nombre FROM usuarios WHERE rol='medico' AND activo=1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$catalogo  = med_autocompletar();
$favoritas = receta_favoritas();

$titulo = t('Nueva receta');
$activo = 'recetas';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/recetas/index"><?= et('Recetas') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Nueva') ?></li>
</ol></nav>
<h1 class="h3 mb-3"><i class="bi bi-capsule"></i> <?= et('Nueva receta') ?></h1>

<?php if ($errores): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<?php /* Datos clínicos de cada paciente para la tarjeta lateral (alergias primero). */
$clin = [];
foreach ($pacientes as $p) $clin[(int) $p['id']] = ['n' => $p['nombre'] . ' ' . $p['apellidos'], 'a' => (string) ($p['alergias'] ?? ''),
                                                      'c' => (string) ($p['enf_cronicas'] ?? ''), 'e' => edad($p['fecha_nacimiento'] ?? null)]; ?>
<form method="post" class="rx-form">
    <?= csrf_field() ?>
    <div class="cobro-grid">
        <div class="cobro-main">
            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-person"></i> <?= et('Paciente y médico') ?></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?= et('Paciente') ?> *</label>
                        <select name="paciente_id" id="rxPac" class="form-select" required>
                            <option value=""><?= et('— Selecciona —') ?></option>
                            <?php foreach ($pacientes as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $presel===(int) $p['id']?'selected':'' ?>><?= e($p['apellidos'].', '.$p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?= et('Médico') ?> *</label>
                        <select name="medico_id" class="form-select" required>
                            <?php foreach ($medicos as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= (int)$u['id']===$m['id']?'selected':'' ?>><?= e($m['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?= et('Diagnóstico') ?></label>
                        <input type="text" name="diagnostico" id="rxDx" class="form-control" value="<?= e($base['diagnostico']) ?>" placeholder="<?= e(t('Ej. Faringitis aguda')) ?>">
                    </div>
                </div>
            </section>

            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-capsule"></i> <?= et('Medicamentos') ?> *
                    <a href="<?= BASE_URL ?>/recetas/medicamentos" class="ms-auto small fw-normal text-decoration-none"><i class="bi bi-journal-medical"></i> <?= et('Catálogo') ?></a></div>
                <?php if ($favoritas): ?>
                <div class="rx-favs mb-3">
                    <span class="small text-muted me-1"><i class="bi bi-star-fill text-warning"></i> <?= et('Favoritas') ?>:</span>
                    <?php foreach ($favoritas as $f): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary rx-fav" data-fav="<?= (int) $f['id'] ?>" title="<?= e(implode(', ', array_column($f['items'], 'medicamento'))) ?>"><?= e($f['nombre']) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2" id="tablaMeds">
                        <thead><tr><th style="width:32%"><?= et('Medicamento') ?></th><th><?= et('Dosis') ?></th><th style="width:15%"><?= et('Vía') ?></th><th><?= et('Frecuencia') ?></th><th><?= et('Duración') ?></th><th></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <datalist id="rxCat"><?php foreach ($catalogo as $m): ?><option value="<?= e($m[0]) ?>"></option><?php endforeach; ?></datalist>
                <datalist id="rxVias"><?php foreach (receta_vias() as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
                <button type="button" class="btn btn-sm btn-outline-primary" id="agregarMed"><i class="bi bi-plus"></i> <?= et('Agregar medicamento') ?></button>
                <div class="form-text"><?= et('Escribe el medicamento y elígelo de la lista: la dosis, vía, frecuencia y duración que ya usaste se llenan solas.') ?></div>
            </section>

            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-card-text"></i> <?= et('Indicaciones') ?> <span class="pf-opc"><?= et('Opcional') ?></span></div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label"><?= et('Indicaciones generales') ?></label><textarea name="indicaciones" id="rxInd" class="form-control" rows="3" placeholder="<?= e(t('Reposo, hidratación, dieta…')) ?>"><?= e($base['indicaciones']) ?></textarea></div>
                    <div class="col-md-6"><label class="form-label"><?= et('Notas') ?></label><textarea name="notas" class="form-control" rows="3"></textarea></div>
                </div>
            </section>
        </div>

        <aside class="cobro-side">
            <div class="pf-card" id="rxPanel">
                <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-person-badge text-brand fs-5"></i>
                    <div class="fw-semibold" id="rxNom"><?= et('Elige un paciente') ?></div></div>
                <div class="small text-muted mb-2" id="rxEdad"></div>
                <div class="rx-alerg" id="rxAlerg" hidden><i class="bi bi-exclamation-triangle-fill"></i> <span><b><?= et('Alergias') ?>:</b> <span id="rxAlergTxt"></span></span></div>
                <div class="rx-ok small" id="rxSinAlerg" hidden><i class="bi bi-shield-check"></i> <?= et('Sin alergias registradas') ?></div>
                <div class="small mt-2" id="rxCron" hidden><b><?= et('Enfermedades crónicas') ?>:</b> <span id="rxCronTxt"></span></div>
                <div class="rx-cruce small mt-2" id="rxCruce" hidden><i class="bi bi-exclamation-octagon-fill"></i> <?= et('Un medicamento coincide con una alergia registrada. Revísalo antes de guardar.') ?></div>
                <hr>
                <input type="hidden" name="fav_usada" id="rxFavUsada" value="0">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="fav" value="1" id="rxFav">
                    <label class="form-check-label small" for="rxFav"><?= et('Guardar también como receta favorita') ?></label>
                </div>
                <div id="rxFavBox" class="mb-3" hidden>
                    <input type="text" name="fav_nombre" class="form-control form-control-sm mb-1" maxlength="120" placeholder="<?= e(t('Nombre, ej. Faringitis adulto')) ?>">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="fav_mia" value="1" id="rxFavMia">
                        <label class="form-check-label small text-muted" for="rxFavMia"><?= et('Solo para mí') ?></label></div>
                </div>
                <button class="btn btn-primary btn-lg w-100"><i class="bi bi-check-lg"></i> <?= et('Guardar receta') ?></button>
                <a href="<?= BASE_URL ?>/recetas/index" class="btn btn-link w-100 mt-1 text-muted"><?= et('Cancelar') ?></a>
            </div>
        </aside>
    </div>
</form>

<style>
.cobro-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1.25rem; align-items: start; }
@media (max-width: 991.98px) { .cobro-grid { grid-template-columns: 1fr; } }
.cobro-side { position: sticky; top: 90px; }
.pf-card, .pf-sec { background: var(--bs-body-bg); border-radius: 18px; padding: 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 10px 28px rgba(15,30,60,.06); }
body.ui-glass .pf-card, body.ui-glass .pf-sec { background: var(--g-glass-2, #fff); box-shadow: var(--g-shadow-sm); }
.pf-sec + .pf-sec { margin-top: 1rem; }
.pf-sec-h { display: flex; align-items: center; gap: .55rem; font-weight: 650; margin-bottom: 1rem; }
.pf-sec-h .bi { width: 34px; height: 34px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center; color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, transparent); }
.pf-opc { margin-left: auto; font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; opacity: .55; border: 1px solid rgba(127,127,127,.3); border-radius: 999px; padding: .15rem .55rem; }
.rx-alerg { display: flex; gap: .5rem; padding: .7rem .8rem; border-radius: 12px; background: rgba(239,68,68,.12); color: #b91c1c; font-size: .9rem; }
.rx-ok { color: #15803d; }
.rx-favs { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.rx-cruce { padding: .6rem .7rem; border-radius: 12px; background: #b91c1c; color: #fff; }
#tablaMeds tr.choca input.med { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.18); }
</style>

<script>
/* Renglones de medicamentos. Al elegir uno del catálogo se llenan sus
   valores guardados, sin pisar lo que el médico ya escribió. */
const CAT = {};
<?= json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>.forEach(m => { CAT[m[0].toLowerCase()] = m; });
const FAVS = {};
<?= json_encode(array_map(fn($f) => ['id' => (int) $f['id'], 'dx' => (string) $f['diagnostico'], 'ind' => (string) $f['indicaciones'], 'items' => $f['items']], $favoritas), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>.forEach(f => { FAVS[f.id] = f; });
const TXT_QUITAR = <?= json_encode(t('Quitar'), JSON_UNESCAPED_UNICODE) ?>;
const tbody = document.querySelector('#tablaMeds tbody');
let idx = 0;
function esc(v) { return String(v ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function addRow(d = {}) {
    const i = idx++, tr = document.createElement('tr');
    const ph = i === 0 && !d.medicamento;
    tr.innerHTML = `
        <td><input type="text" name="med[${i}][medicamento]" class="form-control med" list="rxCat" autocomplete="off" value="${esc(d.medicamento)}"${ph ? ' placeholder="Paracetamol 500 mg tabletas"' : ''}></td>
        <td><input type="text" name="med[${i}][dosis]" class="form-control f-dosis" value="${esc(d.dosis)}"${ph ? ' placeholder="1 tableta"' : ''}></td>
        <td><input type="text" name="med[${i}][via]" class="form-control f-via" list="rxVias" value="${esc(d.via)}"${ph ? ' placeholder="Oral"' : ''}></td>
        <td><input type="text" name="med[${i}][frecuencia]" class="form-control f-frecuencia" value="${esc(d.frecuencia)}"${ph ? ' placeholder="Cada 8 h"' : ''}></td>
        <td><input type="text" name="med[${i}][duracion]" class="form-control f-duracion" value="${esc(d.duracion)}"${ph ? ' placeholder="5 días"' : ''}></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar" aria-label="${esc(TXT_QUITAR)}"><i class="bi bi-x"></i></button></td>`;
    tbody.appendChild(tr);
    return tr;
}
function filasVacias() { return [...tbody.querySelectorAll('tr')].filter(tr => !tr.querySelector('input.med').value.trim()); }
document.getElementById('agregarMed').addEventListener('click', () => addRow().querySelector('input.med').focus());
tbody.addEventListener('change', e => {
    if (!e.target.matches('input.med')) return;
    const m = CAT[e.target.value.trim().toLowerCase()];
    if (!m) return;
    const tr = e.target.closest('tr');
    [['dosis', 1], ['via', 2], ['frecuencia', 3], ['duracion', 4]].forEach(([k, n]) => {
        const inp = tr.querySelector('.f-' + k);
        if (!inp.value.trim() && m[n]) inp.value = m[n];
    });
});
document.querySelectorAll('.rx-fav').forEach(b => b.addEventListener('click', () => {
    const f = FAVS[b.dataset.fav];
    if (!f) return;
    filasVacias().forEach(tr => { if (tbody.children.length > 1) tr.remove(); });
    const vacia = filasVacias()[0];
    f.items.forEach((it, n) => {
        if (n === 0 && vacia) { vacia.remove(); }
        addRow(it);
    });
    const dx = document.getElementById('rxDx'), ind = document.getElementById('rxInd');
    if (!dx.value.trim() && f.dx) dx.value = f.dx;
    if (!ind.value.trim() && f.ind) ind.value = f.ind;
    document.getElementById('rxFavUsada').value = f.id;
    b.classList.replace('btn-outline-secondary', 'btn-secondary');
    cruce();
}));
document.getElementById('rxFav').addEventListener('change', e => {
    const box = document.getElementById('rxFavBox');
    box.hidden = !e.target.checked;
    if (e.target.checked) box.querySelector('input').focus();
});
<?php $ini = $base['items'] ?: [[]]; ?>
<?= json_encode(array_values($ini), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>.forEach(d => addRow(d));
document.addEventListener('click', e => {
    if (e.target.closest('.quitar')) {
        const rows = document.querySelectorAll('#tablaMeds tbody tr');
        if (rows.length > 1) e.target.closest('tr').remove();
        cruce();
    }
});

/* Tarjeta lateral: alergias del paciente y aviso si un medicamento coincide
   con una alergia registrada (por palabra, sin acentos). Solo avisa. */
const CLIN = <?= json_encode($clin, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const norm = t => (t || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
let alergias = [];
function panel() {
    const c = CLIN[document.getElementById('rxPac').value];
    document.getElementById('rxNom').textContent = c ? c.n : <?= json_encode(t('Elige un paciente'), JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('rxEdad').textContent = c && c.e ? c.e : '';
    const a = c ? c.a.trim() : '';
    document.getElementById('rxAlerg').hidden = !a;
    document.getElementById('rxSinAlerg').hidden = !c || !!a;
    document.getElementById('rxAlergTxt').textContent = a;
    document.getElementById('rxCron').hidden = !(c && c.c.trim());
    document.getElementById('rxCronTxt').textContent = c ? c.c : '';
    alergias = norm(a).split(/[^a-z0-9]+/).filter(w => w.length >= 4 && !['alergia', 'alergico', 'alergica', 'ninguna', 'ninguno', 'niega'].includes(w));
    cruce();
}
function cruce() {
    let hay = false;
    document.querySelectorAll('#tablaMeds tbody tr').forEach(tr => {
        const v = norm(tr.querySelector('input.med')?.value);
        const ch = !!v && alergias.some(w => v.includes(w));
        tr.classList.toggle('choca', ch);
        hay = hay || ch;
    });
    document.getElementById('rxCruce').hidden = !hay;
}
document.getElementById('rxPac').addEventListener('change', panel);
document.addEventListener('input', e => { if (e.target.matches('input.med')) cruce(); });
panel();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
