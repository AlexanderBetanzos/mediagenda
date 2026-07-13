<?php
/** Documentos clínicos emitidos: listado, búsqueda y acceso rápido a reimprimir. */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('documentos');

$pacFil = (int) ($_GET['paciente_id'] ?? 0);
$q      = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT d.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape,
               COALESCE(p.foto_mime, p.foto) AS pac_foto,
               m.nombre AS medico_nombre
        FROM documentos d
        JOIN pacientes p ON p.id = d.paciente_id
        LEFT JOIN usuarios m ON m.id = d.medico_id
        WHERE d.consultorio_id = ?";
$params = [tenant_id()];
if ($pacFil) { $sql .= ' AND d.paciente_id = ?'; $params[] = $pacFil; }
if ($q !== '') {
    $sql .= ' AND (d.titulo LIKE ? OR d.folio LIKE ? OR p.nombre LIKE ? OR p.apellidos LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY d.fecha DESC, d.id DESC';

$st = db()->prepare($sql);
$st->execute($params);
$lista = $st->fetchAll();

$titulo = t('Documentos');
$activo = 'documentos';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-file-earmark-medical text-brand"></i> <?= et('Documentos clínicos') ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <?php if (has_role('admin')): ?>
        <a href="<?= BASE_URL ?>/documentos/plantillas" class="btn btn-outline-secondary">
            <i class="bi bi-file-text"></i> <?= et('Plantillas') ?>
        </a>
        <?php endif; ?>
        <?php if (has_role('medico', 'admin')): ?>
        <a href="<?= BASE_URL ?>/documentos/nuevo<?= $pacFil ? '?paciente_id=' . $pacFil : '' ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= et('Nuevo documento') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-md-5">
        <input type="search" name="q" class="form-control"
               placeholder="<?= e(t('Buscar por folio, título o paciente…')) ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> <?= et('Buscar') ?></button>
        <?php if ($q || $pacFil): ?>
            <a href="<?= BASE_URL ?>/documentos/index" class="btn btn-link"><?= et('Limpiar') ?></a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th><?= et('Folio') ?></th>
                <th><?= et('Fecha') ?></th>
                <th><?= et('Paciente') ?></th>
                <th><?= et('Documento') ?></th>
                <th><?= et('Firma') ?></th>
                <th class="text-end"><?= et('Acciones') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lista as $d): ?>
                <tr>
                    <td class="fw-semibold"><?= e($d['folio']) ?></td>
                    <td class="text-muted small"><?= fmt_fecha($d['fecha']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?= avatar_paciente((int) $d['paciente_id'], $d['pac_nombre'], $d['pac_ape'], $d['pac_foto'] ?? null, 32) ?>
                            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $d['paciente_id'] ?>" class="text-decoration-none">
                                <?= e($d['pac_ape'] . ', ' . $d['pac_nombre']) ?>
                            </a>
                        </div>
                    </td>
                    <td><?= e($d['titulo']) ?></td>
                    <td class="small text-muted"><?= e($d['medico_nombre'] ?: '—') ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="<?= BASE_URL ?>/documentos/imprimir?id=<?= (int) $d['id'] ?>" target="_blank"
                               class="btn btn-outline-secondary py-0" title="<?= e(t('Imprimir')) ?>">
                                <i class="bi bi-printer"></i>
                            </a>
                            <?php if (has_role('admin')): ?>
                            <form method="post" action="<?= BASE_URL ?>/documentos/delete" class="d-inline"
                                  onsubmit="return confirm('<?= e(t('¿Eliminar este documento?')) ?>')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-outline-danger py-0" title="<?= e(t('Eliminar')) ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">
                    <i class="bi bi-file-earmark-medical d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    <?= et('Todavía no se ha emitido ningún documento.') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
