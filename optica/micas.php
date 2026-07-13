<?php
/**
 * Catálogo de micas (lentes).
 *
 * Lo que distingue este catálogo de uno de productos normales: el precio va
 * ligado a un RANGO DE GRADUACIÓN. Tallar una esfera de -8.00 no cuesta lo mismo
 * que una de -1.00, y hay micas que directamente no se pueden fabricar arriba de
 * cierto cilindro. Esos rangos son los que hacen que al armar un trabajo solo se
 * ofrezcan las micas que el laboratorio sí puede entregar.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('optica');

$editar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'sembrar') {
        $ya = db()->prepare('SELECT COUNT(*) FROM optica_micas WHERE consultorio_id = ?');
        $ya->execute([tenant_id()]);
        if ((int) $ya->fetchColumn() > 0) {
            flash('El catálogo ya tiene micas; la carga inicial solo aplica cuando está vacío.', 'warning');
            redirect('/optica/micas');
        }
        $ins = db()->prepare(
            'INSERT INTO optica_micas
             (consultorio_id, nombre, tipo_lente, material, tratamientos, esfera_min, esfera_max, cilindro_max, dias_entrega, precio)
             VALUES (?,?,?,?,?,?,?,?,?,0)'
        );
        foreach (optica_micas_comunes() as [$nom, $tipo, $mat, $trat, $emin, $emax, $cmax, $dias]) {
            $ins->execute([tenant_id(), $nom, $tipo, $mat, $trat, $emin, $emax, $cmax, $dias]);
        }
        auditar('optica_micas_carga_inicial', 'optica_mica');
        flash('Micas comunes cargadas. Pon los precios que te cobra tu laboratorio.');
        redirect('/optica/micas');
    }

    if ($accion === 'toggle') {
        db()->prepare('UPDATE optica_micas SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')
            ->execute([(int) $_POST['id'], tenant_id()]);
        redirect('/optica/micas');
    }

    $id     = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $num    = fn(string $k) => (($v = trim((string) ($_POST[$k] ?? ''))) !== '' ? (float) $v : null);

    $datos = [
        'nombre'       => mb_substr($nombre, 0, 160),
        'tipo_lente'   => isset(optica_tipos_lente()[$_POST['tipo_lente'] ?? '']) ? $_POST['tipo_lente'] : 'monofocal',
        'material'     => trim((string) ($_POST['material'] ?? '')) ?: null,
        'tratamientos' => trim((string) ($_POST['tratamientos'] ?? '')) ?: null,
        'esfera_min'   => $num('esfera_min'),
        'esfera_max'   => $num('esfera_max'),
        'cilindro_max' => $num('cilindro_max'),
        'precio'       => (float) ($_POST['precio'] ?? 0),
        'dias_entrega' => max(0, (int) ($_POST['dias_entrega'] ?? 3)),
    ];

    if ($nombre === '') {
        flash('La mica necesita un nombre.', 'warning');
    } elseif ($id) {
        $set = implode(' = ?, ', array_keys($datos)) . ' = ?';
        db()->prepare("UPDATE optica_micas SET $set WHERE id = ? AND consultorio_id = ?")
            ->execute(array_merge(array_values($datos), [$id, tenant_id()]));
        auditar('optica_mica_editar', 'optica_mica', $id, $nombre);
        flash('Mica actualizada.');
    } else {
        $cols = array_merge(['consultorio_id'], array_keys($datos));
        $ph   = implode(',', array_fill(0, count($cols), '?'));
        db()->prepare('INSERT INTO optica_micas (' . implode(', ', $cols) . ") VALUES ($ph)")
            ->execute(array_merge([tenant_id()], array_values($datos)));
        auditar('optica_mica_crear', 'optica_mica', (int) db()->lastInsertId(), $nombre);
        flash('Mica agregada al catálogo.');
    }
    redirect('/optica/micas');
}

if ($eid = (int) ($_GET['editar'] ?? 0)) {
    $st = db()->prepare('SELECT * FROM optica_micas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$eid, tenant_id()]);
    $editar = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT * FROM optica_micas WHERE consultorio_id = ? ORDER BY activo DESC, tipo_lente, precio');
$st->execute([tenant_id()]);
$micas = $st->fetchAll();

$titulo = t('Catálogo de micas');
$activo = 'optica';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/optica/index"><?= et('Óptica') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Catálogo de micas') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-circle-half text-brand"></i> <?= et('Catálogo de micas') ?></h1>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><?= $editar ? et('Editar mica') : et('Nueva mica') ?></div>
            <form method="post" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">

                <div class="mb-2">
                    <label class="form-label"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" required maxlength="160"
                           placeholder="<?= e(t('Progresivo policarbonato AR')) ?>"
                           value="<?= e($editar['nombre'] ?? '') ?>">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label"><?= et('Tipo') ?></label>
                        <select name="tipo_lente" class="form-select">
                            <?php foreach (optica_tipos_lente() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($editar['tipo_lente'] ?? '') === $k ? 'selected' : '' ?>><?= et($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label"><?= et('Material') ?></label>
                        <input name="material" class="form-control" maxlength="60" list="materiales"
                               value="<?= e($editar['material'] ?? '') ?>">
                        <datalist id="materiales">
                            <option value="CR-39"><option value="Policarbonato">
                            <option value="Alto índice 1.67"><option value="Alto índice 1.74"><option value="Trivex">
                        </datalist>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label"><?= et('Tratamientos') ?></label>
                    <input name="tratamientos" class="form-control" maxlength="255"
                           placeholder="<?= e(t('Antirreflejante, fotocromático, filtro azul')) ?>"
                           value="<?= e($editar['tratamientos'] ?? '') ?>">
                </div>

                <div class="border rounded p-2 mb-2 bg-body-tertiary">
                    <div class="small fw-semibold mb-2">
                        <i class="bi bi-rulers"></i> <?= et('Rango que cubre') ?>
                    </div>
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label small"><?= et('Esfera mín.') ?></label>
                            <input type="number" step="0.25" name="esfera_min" class="form-control form-control-sm"
                                   placeholder="-6.00" value="<?= e((string) ($editar['esfera_min'] ?? '')) ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label small"><?= et('Esfera máx.') ?></label>
                            <input type="number" step="0.25" name="esfera_max" class="form-control form-control-sm"
                                   placeholder="+6.00" value="<?= e((string) ($editar['esfera_max'] ?? '')) ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label small"><?= et('Cilindro máx.') ?></label>
                            <input type="number" step="0.25" min="0" name="cilindro_max" class="form-control form-control-sm"
                                   placeholder="4.00" value="<?= e((string) ($editar['cilindro_max'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="form-text">
                        <?= et('Vacío = sin límite. Al armar un trabajo solo se ofrecen las micas cuyo rango cubre la graduación del paciente.') ?>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label"><?= et('Precio del par') ?></label>
                        <input type="number" step="0.01" min="0" name="precio" class="form-control"
                               value="<?= e((string) ($editar['precio'] ?? '0')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label"><?= et('Días de entrega') ?></label>
                        <input type="number" min="0" name="dias_entrega" class="form-control"
                               value="<?= e((string) ($editar['dias_entrega'] ?? '3')) ?>">
                    </div>
                </div>

                <button class="btn btn-primary w-100">
                    <i class="bi bi-check-lg"></i> <?= $editar ? et('Guardar cambios') : et('Agregar mica') ?>
                </button>
                <?php if ($editar): ?>
                    <a href="<?= BASE_URL ?>/optica/micas" class="btn btn-link w-100 mt-1"><?= et('Cancelar') ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th><?= et('Mica') ?></th>
                        <th><?= et('Tipo') ?></th>
                        <th><?= et('Rango') ?></th>
                        <th class="text-center"><?= et('Días') ?></th>
                        <th class="text-end"><?= et('Precio') ?></th>
                        <th class="text-end"><?= et('Acciones') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($micas as $m): ?>
                        <tr class="<?= $m['activo'] ? '' : 'opacity-50' ?>">
                            <td>
                                <span class="fw-semibold"><?= e($m['nombre']) ?></span>
                                <?php if (!$m['activo']): ?><span class="badge bg-secondary ms-1"><?= et('Inactiva') ?></span><?php endif; ?>
                                <?php if ($m['tratamientos']): ?>
                                    <br><small class="text-muted"><?= e($m['tratamientos']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= et(optica_tipos_lente()[$m['tipo_lente']] ?? $m['tipo_lente']) ?>
                                <?php if ($m['material']): ?><br><?= e($m['material']) ?><?php endif; ?>
                            </td>
                            <td class="small font-monospace text-muted">
                                <?= $m['esfera_min'] !== null ? fmt_dioptria($m['esfera_min']) : '−∞' ?>
                                …
                                <?= $m['esfera_max'] !== null ? fmt_dioptria($m['esfera_max']) : '+∞' ?>
                                <?php if ($m['cilindro_max'] !== null): ?>
                                    <br><?= et('cil. máx.') ?> <?= fmt_dioptria($m['cilindro_max']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= (int) $m['dias_entrega'] ?></td>
                            <td class="text-end fw-semibold"><?= fmt_money($m['precio']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="?editar=<?= (int) $m['id'] ?>" class="btn btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                        <button class="btn btn-outline-secondary py-0"
                                                title="<?= e($m['activo'] ? t('Desactivar') : t('Activar')) ?>">
                                            <i class="bi bi-<?= $m['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$micas): ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-circle-half d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                            <p><?= et('Tu catálogo de micas está vacío.') ?></p>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="sembrar">
                                <button class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-download"></i> <?= et('Cargar micas comunes') ?>
                                </button>
                            </form>
                            <div class="form-text mt-2">
                                <?= et('Once micas típicas con sus rangos, en precio 0 para que pongas el tuyo.') ?>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
