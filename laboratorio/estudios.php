<?php
/**
 * Catálogo de estudios de laboratorio del consultorio (alta, edición, baja
 * lógica). La unidad y el valor de referencia se guardan aquí para pre-llenar
 * el resultado de cada orden y no teclearlos cada vez.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('laboratorio');

$editar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? 'guardar';

    /* Carga inicial: estudios comunes para que el catálogo no nazca vacío. */
    if ($accion === 'sembrar') {
        $ya = db()->prepare('SELECT COUNT(*) FROM lab_estudios WHERE consultorio_id = ?');
        $ya->execute([tenant_id()]);
        if ((int) $ya->fetchColumn() > 0) {
            flash('El catálogo ya tiene estudios; la carga inicial solo aplica cuando está vacío.', 'warning');
            redirect('/laboratorio/estudios');
        }
        $ins = db()->prepare(
            'INSERT INTO lab_estudios (consultorio_id, nombre, categoria, muestra, preparacion, unidad, referencia, precio)
             VALUES (?,?,?,?,?,?,?,0)'
        );
        foreach (lab_estudios_comunes() as [$nombre, $cat, $muestra, $prep, $unidad, $ref]) {
            $ins->execute([tenant_id(), $nombre, $cat, $muestra, $prep ?: null, $unidad ?: null, $ref ?: null]);
        }
        auditar('lab_estudios_carga_inicial', 'lab_estudio');
        flash('Estudios comunes cargados. Ajusta los precios a los de tu laboratorio.');
        redirect('/laboratorio/estudios');
    }

    if ($accion === 'toggle') {
        db()->prepare('UPDATE lab_estudios SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')
            ->execute([(int) $_POST['id'], tenant_id()]);
        redirect('/laboratorio/estudios');
    }

    /* Alta / edición. */
    $id     = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $campos = [
        trim((string) ($_POST['codigo'] ?? ''))      ?: null,
        trim((string) ($_POST['categoria'] ?? ''))   ?: null,
        trim((string) ($_POST['muestra'] ?? ''))     ?: null,
        trim((string) ($_POST['preparacion'] ?? '')) ?: null,
        trim((string) ($_POST['unidad'] ?? ''))      ?: null,
        trim((string) ($_POST['referencia'] ?? ''))  ?: null,
        (float) ($_POST['precio'] ?? 0),
    ];

    if ($nombre === '') {
        flash('El estudio necesita un nombre.', 'warning');
    } elseif ($id) {
        db()->prepare(
            'UPDATE lab_estudios SET nombre = ?, codigo = ?, categoria = ?, muestra = ?,
                    preparacion = ?, unidad = ?, referencia = ?, precio = ?
             WHERE id = ? AND consultorio_id = ?'
        )->execute(array_merge([$nombre], $campos, [$id, tenant_id()]));
        auditar('lab_estudio_editar', 'lab_estudio', $id, $nombre);
        flash('Estudio actualizado.');
    } else {
        db()->prepare(
            'INSERT INTO lab_estudios (consultorio_id, nombre, codigo, categoria, muestra,
                                       preparacion, unidad, referencia, precio)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute(array_merge([tenant_id(), $nombre], $campos));
        auditar('lab_estudio_crear', 'lab_estudio', (int) db()->lastInsertId(), $nombre);
        flash('Estudio agregado al catálogo.');
    }
    redirect('/laboratorio/estudios');
}

if ($id = (int) ($_GET['editar'] ?? 0)) {
    $st = db()->prepare('SELECT * FROM lab_estudios WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, tenant_id()]);
    $editar = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT * FROM lab_estudios WHERE consultorio_id = ? ORDER BY activo DESC, categoria, nombre');
$st->execute([tenant_id()]);
$estudios = $st->fetchAll();

$titulo = t('Catálogo de estudios');
$activo = 'laboratorio';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/laboratorio/index"><?= et('Laboratorio') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Catálogo de estudios') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-list-check text-brand"></i> <?= et('Catálogo de estudios') ?></h1>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold">
                <?= $editar ? et('Editar estudio') : et('Nuevo estudio') ?>
            </div>
            <form method="post" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">

                <div class="mb-2">
                    <label class="form-label"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" required maxlength="160"
                           value="<?= e($editar['nombre'] ?? '') ?>">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label"><?= et('Categoría') ?></label>
                        <input name="categoria" class="form-control" maxlength="60" list="lab-categorias"
                               value="<?= e($editar['categoria'] ?? '') ?>">
                        <datalist id="lab-categorias">
                            <option value="Sangre"><option value="Orina"><option value="Heces">
                            <option value="Imagen"><option value="Patología">
                        </datalist>
                    </div>
                    <div class="col-6">
                        <label class="form-label"><?= et('Clave') ?></label>
                        <input name="codigo" class="form-control" maxlength="40"
                               value="<?= e($editar['codigo'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label"><?= et('Muestra') ?></label>
                    <input name="muestra" class="form-control" maxlength="60"
                           placeholder="<?= e(t('Sangre venosa, orina…')) ?>"
                           value="<?= e($editar['muestra'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label"><?= et('Preparación del paciente') ?></label>
                    <input name="preparacion" class="form-control" maxlength="255"
                           placeholder="<?= e(t('Ayuno de 8 h')) ?>"
                           value="<?= e($editar['preparacion'] ?? '') ?>">
                    <div class="form-text"><?= et('Se imprime en la orden que se lleva el paciente.') ?></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label"><?= et('Unidad') ?></label>
                        <input name="unidad" class="form-control" maxlength="30" placeholder="mg/dL"
                               value="<?= e($editar['unidad'] ?? '') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label"><?= et('Referencia') ?></label>
                        <input name="referencia" class="form-control" maxlength="60" placeholder="70 - 100"
                               value="<?= e($editar['referencia'] ?? '') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label"><?= et('Precio') ?></label>
                        <input name="precio" type="number" step="0.01" min="0" class="form-control"
                               value="<?= e((string) ($editar['precio'] ?? '0')) ?>">
                    </div>
                </div>

                <button class="btn btn-primary w-100">
                    <i class="bi bi-check-lg"></i> <?= $editar ? et('Guardar cambios') : et('Agregar estudio') ?>
                </button>
                <?php if ($editar): ?>
                    <a href="<?= BASE_URL ?>/laboratorio/estudios" class="btn btn-link w-100 mt-1"><?= et('Cancelar') ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr>
                        <th><?= et('Estudio') ?></th>
                        <th><?= et('Categoría') ?></th>
                        <th><?= et('Muestra') ?></th>
                        <th class="text-end"><?= et('Precio') ?></th>
                        <th class="text-end"><?= et('Acciones') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($estudios as $es): ?>
                        <tr class="<?= $es['activo'] ? '' : 'opacity-50' ?>">
                            <td>
                                <span class="fw-semibold"><?= e($es['nombre']) ?></span>
                                <?php if (!$es['activo']): ?><span class="badge bg-secondary ms-1"><?= et('Inactivo') ?></span><?php endif; ?>
                                <?php if ($es['preparacion']): ?>
                                    <br><small class="text-muted"><i class="bi bi-info-circle"></i> <?= e($es['preparacion']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($es['categoria'] ?: '—') ?></td>
                            <td class="small text-muted"><?= e($es['muestra'] ?: '—') ?></td>
                            <td class="text-end fw-semibold"><?= fmt_money($es['precio']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="?editar=<?= (int) $es['id'] ?>" class="btn btn-outline-secondary py-0" title="<?= e(t('Editar')) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="accion" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int) $es['id'] ?>">
                                        <button class="btn btn-outline-secondary py-0"
                                                title="<?= e($es['activo'] ? t('Desactivar') : t('Activar')) ?>">
                                            <i class="bi bi-<?= $es['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$estudios): ?>
                        <tr><td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-list-check d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                            <p><?= et('Tu catálogo está vacío.') ?></p>
                            <form method="post" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="accion" value="sembrar">
                                <button class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-download"></i> <?= et('Cargar estudios comunes') ?>
                                </button>
                            </form>
                            <div class="form-text mt-2"><?= et('Se agregan con precio 0 para que pongas el tuyo.') ?></div>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
