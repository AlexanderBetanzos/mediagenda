<?php
/**
 * Captura de la graduación (receta oftálmica) de un paciente.
 *
 * La rejilla imita el papel que ya usa el optometrista: una fila por ojo (OD
 * arriba, OI abajo) y una columna por dato (esfera, cilindro, eje, adición…).
 * Si el formulario no se parece a lo que tiene en la mano, no lo va a usar.
 *
 * La graduación NO se edita: cada revisión es una nueva. El historial es el
 * valor clínico —así se ve cómo avanza la miopía de un paciente año con año—
 * y sobrescribir borraría esa historia.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('optica');

$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$u   = current_user();

$pac = null;
if ($pid) {
    $st = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
    $st->execute([$pid, tenant_id()]);
    $pac = $st->fetch() ?: null;
}
if (!$pac) { flash('Selecciona un paciente para capturar su graduación.', 'warning'); redirect('/optica/index'); }

/* Dioptría: acepta "-1.25", "+1.25" o "1.25" y la guarda como número.
   El "+" se escribe en la receta pero no es parte del número. */
$dioptria = function ($v) {
    $v = str_replace([' ', '+'], '', trim((string) $v));
    if ($v === '' || !is_numeric($v)) return null;
    return round((float) $v, 2);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $campos = [
        'consultorio_id'  => tenant_id(),
        'paciente_id'     => $pid,
        'optometrista_id' => ((int) ($_POST['optometrista_id'] ?? 0)) ?: null,
        'fecha'           => ($_POST['fecha'] ?? '') ?: date('Y-m-d'),
        'vigencia'        => ($_POST['vigencia'] ?? '') ?: date('Y-m-d', strtotime('+1 year')),
    ];

    foreach (['od', 'oi'] as $ojo) {
        $campos[$ojo . '_esfera']   = $dioptria($_POST[$ojo . '_esfera']   ?? '');
        $campos[$ojo . '_cilindro'] = $dioptria($_POST[$ojo . '_cilindro'] ?? '');
        $eje = trim((string) ($_POST[$ojo . '_eje'] ?? ''));
        $campos[$ojo . '_eje']      = $eje === '' ? null : max(0, min(180, (int) $eje));
        $campos[$ojo . '_adicion']  = $dioptria($_POST[$ojo . '_adicion'] ?? '');
        $campos[$ojo . '_prisma']   = trim((string) ($_POST[$ojo . '_prisma'] ?? '')) ?: null;
        $campos[$ojo . '_av']       = trim((string) ($_POST[$ojo . '_av'] ?? '')) ?: null;
        $campos[$ojo . '_dip']      = ($v = trim((string) ($_POST[$ojo . '_dip'] ?? ''))) !== '' ? (float) $v : null;
        $campos[$ojo . '_altura']   = ($v = trim((string) ($_POST[$ojo . '_altura'] ?? ''))) !== '' ? (float) $v : null;
    }

    $campos['dip']         = ($v = trim((string) ($_POST['dip'] ?? ''))) !== '' ? (float) $v : null;
    $campos['tipo_lente']  = isset(optica_tipos_lente()[$_POST['tipo_lente'] ?? '']) ? $_POST['tipo_lente'] : null;
    $campos['diagnostico'] = trim((string) ($_POST['diagnostico'] ?? '')) ?: null;
    $campos['notas']       = trim((string) ($_POST['notas'] ?? '')) ?: null;

    // Una graduación sin un solo dato en ninguno de los dos ojos no sirve de nada.
    if ($campos['od_esfera'] === null && $campos['oi_esfera'] === null) {
        flash('Captura al menos la esfera de un ojo.', 'warning');
        redirect('/optica/graduacion?paciente_id=' . $pid);
    }

    $cols = array_keys($campos);
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    db()->prepare('INSERT INTO optica_graduaciones (' . implode(', ', $cols) . ") VALUES ($ph)")
        ->execute(array_values($campos));
    $gid = (int) db()->lastInsertId();

    // Si el paciente aún no estaba marcado como de óptica, ya lo está.
    if ($pac['tipo'] !== 'optica') {
        db()->prepare("UPDATE pacientes SET tipo = 'optica' WHERE id = ? AND consultorio_id = ?")
            ->execute([$pid, tenant_id()]);
    }

    auditar('optica_graduacion', 'paciente', $pid, 'Graduación #' . $gid);
    flash('Graduación registrada.');
    redirect(($_POST['ir_a_trabajo'] ?? '') === '1'
        ? '/optica/trabajo?paciente_id=' . $pid . '&graduacion_id=' . $gid
        : '/pacientes/ver?id=' . $pid);
}

/* Última graduación: se ofrece para copiarla y solo ajustar lo que cambió, que
   es como trabaja de verdad una óptica en una revisión anual. */
$ant = db()->prepare(
    'SELECT * FROM optica_graduaciones WHERE paciente_id = ? AND consultorio_id = ?
     ORDER BY fecha DESC, id DESC LIMIT 1'
);
$ant->execute([$pid, tenant_id()]);
$previa = $ant->fetch() ?: null;

$medicos = db()->prepare(
    "SELECT id, nombre FROM usuarios WHERE consultorio_id = ? AND rol IN ('medico','admin') AND activo = 1 ORDER BY nombre"
);
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();

$titulo = t('Nueva graduación');
$activo = 'optica';
include __DIR__ . '/../includes/header.php';

/** Celda de la rejilla: un input por dato del ojo. */
$celda = function (string $ojo, string $campo, string $ph = '', string $paso = '0.25') use ($previa) {
    $val = $previa[$ojo . '_' . $campo] ?? '';
    // Las dioptrías se muestran con signo para que se lean como en la receta.
    if (in_array($campo, ['esfera', 'cilindro', 'adicion'], true) && $val !== '' && $val !== null) {
        $val = sprintf('%+.2f', (float) $val);
    }
    $tipo = in_array($campo, ['prisma', 'av'], true) ? 'text' : 'number';
    echo '<td><input type="' . $tipo . '" name="' . $ojo . '_' . $campo . '"'
       . ($tipo === 'number' ? ' step="' . $paso . '"' : '')
       . ' class="form-control form-control-sm text-center" placeholder="' . e($ph) . '"'
       . ' value="' . e((string) $val) . '"></td>';
};
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/optica/index"><?= et('Óptica') ?></a></li>
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($pac['nombre'] . ' ' . $pac['apellidos']) ?></a></li>
    <li class="breadcrumb-item active"><?= et('Nueva graduación') ?></li>
</ol></nav>

<div class="d-flex align-items-center gap-3 mb-3">
    <?= avatar_paciente((int) $pac['id'], $pac['nombre'], $pac['apellidos'], ($pac['foto_mime'] ?? null) ?: ($pac['foto'] ?? null), 56) ?>
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-eyeglasses text-brand"></i> <?= et('Nueva graduación') ?></h1>
        <div class="text-muted small"><?= e($pac['nombre'] . ' ' . $pac['apellidos']) ?> · <?= e(edad($pac['fecha_nacimiento'])) ?></div>
    </div>
</div>

<?php if ($previa): ?>
<div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
    <span>
        <i class="bi bi-clock-history"></i>
        <?= et('Se precargó su graduación del') ?> <strong><?= fmt_fecha($previa['fecha']) ?></strong>:
        <code><?= e(optica_graduacion_resumen($previa)) ?></code>
        <?= et('Ajusta solo lo que cambió.') ?>
    </span>
</div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">

    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-eye text-brand"></i> <?= et('Graduación') ?></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 text-center" data-no-sort>
                <thead><tr>
                    <th style="width:70px"></th>
                    <th><?= et('Esfera') ?></th>
                    <th><?= et('Cilindro') ?></th>
                    <th><?= et('Eje') ?></th>
                    <th><?= et('Adición') ?></th>
                    <th><?= et('Prisma') ?></th>
                    <th><?= et('Agudeza') ?></th>
                    <th><?= et('DIP') ?></th>
                    <th><?= et('Altura') ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <th class="text-start align-middle">OD<br><small class="text-muted fw-normal"><?= et('derecho') ?></small></th>
                        <?php $celda('od', 'esfera', '-1.25'); $celda('od', 'cilindro', '-0.50');
                              $celda('od', 'eje', '90', '1'); $celda('od', 'adicion', '+2.00');
                              $celda('od', 'prisma'); $celda('od', 'av', '20/20');
                              $celda('od', 'dip', '31', '0.5'); $celda('od', 'altura', '18', '0.5'); ?>
                    </tr>
                    <tr>
                        <th class="text-start align-middle">OI<br><small class="text-muted fw-normal"><?= et('izquierdo') ?></small></th>
                        <?php $celda('oi', 'esfera', '-1.00'); $celda('oi', 'cilindro', '-0.75');
                              $celda('oi', 'eje', '85', '1'); $celda('oi', 'adicion', '+2.00');
                              $celda('oi', 'prisma'); $celda('oi', 'av', '20/20');
                              $celda('oi', 'dip', '31', '0.5'); $celda('oi', 'altura', '18', '0.5'); ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card-body border-top row g-3">
            <div class="col-md-2">
                <label class="form-label"><?= et('DIP total') ?></label>
                <input type="number" step="0.5" name="dip" class="form-control" placeholder="62"
                       value="<?= e((string) ($previa['dip'] ?? '')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Tipo de lente') ?></label>
                <select name="tipo_lente" class="form-select">
                    <option value=""><?= et('Sin definir') ?></option>
                    <?php foreach (optica_tipos_lente() as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= ($previa['tipo_lente'] ?? '') === $k ? 'selected' : '' ?>><?= et($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?= et('Diagnóstico') ?></label>
                <input name="diagnostico" class="form-control" maxlength="255"
                       placeholder="<?= e(t('Miopía, astigmatismo, presbicia…')) ?>"
                       value="<?= e($previa['diagnostico'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Optometrista') ?></label>
                <select name="optometrista_id" class="form-select">
                    <option value=""><?= et('Sin especificar') ?></option>
                    <?php $sel = $u['rol'] === 'medico' ? (int) $u['id'] : 0; ?>
                    <?php foreach ($medicos as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= $sel === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label"><?= et('Fecha') ?></label>
                <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?= et('Vigencia') ?></label>
                <input type="date" name="vigencia" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                <div class="form-text"><?= et('Por omisión, un año.') ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?= et('Notas') ?></label>
                <input name="notas" class="form-control" maxlength="500">
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between gap-2">
        <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-light"><?= et('Cancelar') ?></a>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" name="ir_a_trabajo" value="1">
                <i class="bi bi-bag-plus"></i> <?= et('Guardar y armar el trabajo') ?>
            </button>
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar graduación') ?></button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
