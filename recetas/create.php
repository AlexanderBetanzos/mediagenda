<?php
require_once __DIR__ . '/../includes/functions.php';
require_role('medico', 'admin');
require_modulo('recetas');

$u = current_user();
$errores = [];
$presel = (int) ($_GET['paciente_id'] ?? 0);

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
        $it = $pdo->prepare('INSERT INTO receta_items (receta_id, medicamento, dosis, frecuencia, duracion) VALUES (?,?,?,?,?)');
        foreach ($items as $m) {
            $it->execute([
                $rid, trim($m['medicamento']),
                trim($m['dosis'] ?? '') ?: null,
                trim($m['frecuencia'] ?? '') ?: null,
                trim($m['duracion'] ?? '') ?: null,
            ]);
        }
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
                                <option value="<?= $p['id'] ?>" <?= $presel===$p['id']?'selected':'' ?>><?= e($p['apellidos'].', '.$p['nombre']) ?></option>
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
                        <input type="text" name="diagnostico" class="form-control" placeholder="<?= e(t('Ej. Faringitis aguda')) ?>">
                    </div>
                </div>
            </section>

            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-capsule"></i> <?= et('Medicamentos') ?> *</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-2" id="tablaMeds">
                        <thead><tr><th style="width:34%"><?= et('Medicamento') ?></th><th><?= et('Dosis') ?></th><th><?= et('Frecuencia') ?></th><th><?= et('Duración') ?></th><th></th></tr></thead>
                        <tbody>
                            <tr>
                                <td><input type="text" name="med[0][medicamento]" class="form-control med" placeholder="Paracetamol 500mg"></td>
                                <td><input type="text" name="med[0][dosis]" class="form-control" placeholder="1 tableta"></td>
                                <td><input type="text" name="med[0][frecuencia]" class="form-control" placeholder="Cada 8 h"></td>
                                <td><input type="text" name="med[0][duracion]" class="form-control" placeholder="5 días"></td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar" aria-label="<?= e(t('Quitar')) ?>"><i class="bi bi-x"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="agregarMed"><i class="bi bi-plus"></i> <?= et('Agregar medicamento') ?></button>
            </section>

            <section class="pf-sec">
                <div class="pf-sec-h"><i class="bi bi-card-text"></i> <?= et('Indicaciones') ?> <span class="pf-opc"><?= et('Opcional') ?></span></div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label"><?= et('Indicaciones generales') ?></label><textarea name="indicaciones" class="form-control" rows="3" placeholder="<?= e(t('Reposo, hidratación, dieta…')) ?>"></textarea></div>
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
.rx-cruce { padding: .6rem .7rem; border-radius: 12px; background: #b91c1c; color: #fff; }
#tablaMeds tr.choca input.med { border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.18); }
</style>

<script>
let idx = 1;
document.getElementById('agregarMed').addEventListener('click', () => {
    const tb = document.querySelector('#tablaMeds tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="med[${idx}][medicamento]" class="form-control med"></td>
        <td><input type="text" name="med[${idx}][dosis]" class="form-control"></td>
        <td><input type="text" name="med[${idx}][frecuencia]" class="form-control"></td>
        <td><input type="text" name="med[${idx}][duracion]" class="form-control"></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x"></i></button></td>`;
    tb.appendChild(tr);
    idx++;
});
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
