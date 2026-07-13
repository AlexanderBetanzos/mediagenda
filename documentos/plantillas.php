<?php
/**
 * Plantillas de documento del consultorio.
 *
 * El texto lleva marcadores ({paciente}, {edad}, {diagnostico}…) que se
 * resuelven al emitir. Editar una plantilla NO altera los documentos ya
 * emitidos: esos guardaron su texto resuelto, como debe ser con un papel firmado.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
require_modulo('documentos');

$editar = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? 'guardar';

    if ($accion === 'sembrar') {
        $ya = db()->prepare('SELECT COUNT(*) FROM documento_plantillas WHERE consultorio_id = ?');
        $ya->execute([tenant_id()]);
        if ((int) $ya->fetchColumn() > 0) {
            flash('Ya tienes plantillas; la carga inicial solo aplica cuando no hay ninguna.', 'warning');
            redirect('/documentos/plantillas');
        }
        $ins = db()->prepare('INSERT INTO documento_plantillas (consultorio_id, nombre, cuerpo, orden) VALUES (?,?,?,?)');
        foreach (documento_plantillas_comunes() as $i => [$nombre, $cuerpo]) {
            $ins->execute([tenant_id(), $nombre, $cuerpo, $i]);
        }
        auditar('documento_plantillas_carga_inicial', 'documento_plantilla');
        flash('Plantillas cargadas. Edítalas con las palabras de tu consultorio.');
        redirect('/documentos/plantillas');
    }

    if ($accion === 'toggle') {
        db()->prepare('UPDATE documento_plantillas SET activo = 1 - activo WHERE id = ? AND consultorio_id = ?')
            ->execute([(int) $_POST['id'], tenant_id()]);
        redirect('/documentos/plantillas');
    }

    $id     = (int) ($_POST['id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $cuerpo = trim((string) ($_POST['cuerpo'] ?? ''));

    if ($nombre === '' || $cuerpo === '') {
        flash('La plantilla necesita nombre y texto.', 'warning');
    } elseif ($id) {
        db()->prepare('UPDATE documento_plantillas SET nombre = ?, cuerpo = ? WHERE id = ? AND consultorio_id = ?')
            ->execute([mb_substr($nombre, 0, 120), $cuerpo, $id, tenant_id()]);
        auditar('documento_plantilla_editar', 'documento_plantilla', $id, $nombre);
        flash('Plantilla actualizada.');
    } else {
        db()->prepare('INSERT INTO documento_plantillas (consultorio_id, nombre, cuerpo) VALUES (?,?,?)')
            ->execute([tenant_id(), mb_substr($nombre, 0, 120), $cuerpo]);
        auditar('documento_plantilla_crear', 'documento_plantilla', (int) db()->lastInsertId(), $nombre);
        flash('Plantilla creada.');
    }
    redirect('/documentos/plantillas');
}

if ($eid = (int) ($_GET['editar'] ?? 0)) {
    $st = db()->prepare('SELECT * FROM documento_plantillas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$eid, tenant_id()]);
    $editar = $st->fetch() ?: null;
}

$st = db()->prepare('SELECT * FROM documento_plantillas WHERE consultorio_id = ? ORDER BY activo DESC, orden, nombre');
$st->execute([tenant_id()]);
$plantillas = $st->fetchAll();

$titulo = t('Plantillas de documento');
$activo = 'documentos';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/documentos/index"><?= et('Documentos') ?></a></li>
    <li class="breadcrumb-item active"><?= et('Plantillas') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-file-text text-brand"></i> <?= et('Plantillas de documento') ?></h1>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= $editar ? et('Editar plantilla') : et('Nueva plantilla') ?></div>
            <form method="post" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="guardar">
                <input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">

                <div class="mb-2">
                    <label class="form-label"><?= et('Nombre') ?> *</label>
                    <input name="nombre" class="form-control" required maxlength="120"
                           placeholder="<?= e(t('Constancia de buena salud')) ?>"
                           value="<?= e($editar['nombre'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= et('Texto') ?> *</label>
                    <textarea name="cuerpo" class="form-control font-monospace" rows="14" required><?= e($editar['cuerpo'] ?? '') ?></textarea>
                </div>

                <button class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> <?= $editar ? et('Guardar cambios') : et('Crear plantilla') ?>
                </button>
                <?php if ($editar): ?>
                    <a href="<?= BASE_URL ?>/documentos/plantillas" class="btn btn-link"><?= et('Cancelar') ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-braces"></i> <?= et('Marcadores') ?></div>
            <div class="card-body py-2">
                <p class="small text-muted">
                    <?= et('Escríbelos en el texto y se sustituyen solos al emitir el documento.') ?>
                </p>
                <ul class="list-unstyled small mb-0">
                    <?php foreach (documento_marcadores() as $clave => $para): ?>
                    <li class="d-flex justify-content-between border-bottom border-opacity-10 py-1">
                        <code><?= e($clave) ?></code>
                        <span class="text-muted text-end ms-2"><?= et($para) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold"><?= et('Tus plantillas') ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($plantillas as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center <?= $p['activo'] ? '' : 'opacity-50' ?>">
                    <span>
                        <?= e($p['nombre']) ?>
                        <?php if (!$p['activo']): ?><span class="badge bg-secondary ms-1"><?= et('Inactiva') ?></span><?php endif; ?>
                    </span>
                    <span class="btn-group btn-group-sm">
                        <a href="?editar=<?= (int) $p['id'] ?>" class="btn btn-outline-secondary py-0"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-outline-secondary py-0">
                                <i class="bi bi-<?= $p['activo'] ? 'eye-slash' : 'eye' ?>"></i>
                            </button>
                        </form>
                    </span>
                </li>
                <?php endforeach; ?>
                <?php if (!$plantillas): ?>
                <li class="list-group-item text-center text-muted py-4">
                    <p class="mb-2"><?= et('Sin plantillas todavía.') ?></p>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="sembrar">
                        <button class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-download"></i> <?= et('Cargar las cuatro más usadas') ?>
                        </button>
                    </form>
                    <div class="form-text mt-2">
                        <?= et('Constancia de buena salud, justificante, referencia y resumen clínico.') ?>
                    </div>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
