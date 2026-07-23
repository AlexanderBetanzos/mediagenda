<?php
/**
 * Plataforma — SOCIOS. Solo el dueño (súper) entra aquí. Aprueba/desactiva a
 * los socios que se registraron y decide qué consultorios (clientes) puede ver
 * cada uno. El súper ve y gestiona todo; el socio solo lo asignado.
 */
require_once __DIR__ . '/../includes/functions.php';
require_platform_super();
ensure_plataforma_admins_table();

$pdo   = db();
$yoId  = (int) (platform_admin()['id'] ?? 0);
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Dueño') . ' (plataforma)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';
    $aid    = (int) ($_POST['admin_id'] ?? 0);

    // Nadie puede tocarse a sí mismo ni tocar a otro súper por accidente.
    $obj = $pdo->prepare("SELECT * FROM plataforma_admins WHERE id = ?");
    $obj->execute([$aid]);
    $obj = $obj->fetch();

    if (!$obj) {
        flash('Cuenta no encontrada.', 'warning');
    } elseif ($aid === $yoId) {
        flash('No puedes modificar tu propia cuenta desde aquí.', 'warning');
    } elseif (($obj['rol'] ?? 'super') === 'super') {
        flash('No puedes modificar a otro dueño del sistema.', 'warning');
    } else {
        switch ($accion) {
            case 'aprobar':
                $pdo->prepare("UPDATE plataforma_admins SET activo = 1 WHERE id = ?")->execute([$aid]);
                auditar('socio_aprobar', 'plataforma_admin', $aid, $obj['email'], null, $actor);
                flash('Socio «' . $obj['nombre'] . '» aprobado. Ahora asígnale sus clientes.');
                break;
            case 'desactivar':
                $pdo->prepare("UPDATE plataforma_admins SET activo = 0 WHERE id = ?")->execute([$aid]);
                auditar('socio_desactivar', 'plataforma_admin', $aid, $obj['email'], null, $actor);
                flash('Socio «' . $obj['nombre'] . '» desactivado.', 'warning');
                break;
            case 'eliminar':
                $pdo->prepare("DELETE FROM plataforma_admin_consultorios WHERE admin_id = ?")->execute([$aid]);
                $pdo->prepare("DELETE FROM plataforma_admins WHERE id = ?")->execute([$aid]);
                auditar('socio_eliminar', 'plataforma_admin', $aid, $obj['email'], null, $actor);
                flash('Socio «' . $obj['nombre'] . '» eliminado.');
                break;
            case 'asignar':
                $ids = array_map('intval', (array) ($_POST['consultorios'] ?? []));
                $pdo->prepare("DELETE FROM plataforma_admin_consultorios WHERE admin_id = ?")->execute([$aid]);
                if ($ids) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO plataforma_admin_consultorios (admin_id, consultorio_id) VALUES (?,?)");
                    foreach ($ids as $cid) { if ($cid > 0) $ins->execute([$aid, $cid]); }
                }
                auditar('socio_asignar', 'plataforma_admin', $aid, count($ids) . ' consultorios', null, $actor);
                flash('Clientes de «' . $obj['nombre'] . '» actualizados: ' . count($ids) . '.');
                break;
        }
    }
    redirect('/platform/socios' . (($accion === 'asignar' || isset($_POST['seguir'])) ? '?socio=' . $aid : ''));
}

/* Lista de socios (los súper no se listan como gestionables). */
$socios = $pdo->query(
    "SELECT a.*,
            (SELECT COUNT(*) FROM plataforma_admin_consultorios ac WHERE ac.admin_id = a.id) n_clientes
     FROM plataforma_admins a
     WHERE a.rol = 'socio'
     ORDER BY a.activo ASC, a.creado_en DESC"
)->fetchAll();

/* ¿Estamos gestionando los clientes de un socio en particular? */
$socioSel = null;
$asignados = [];
$consultorios = [];
if (($sid = (int) ($_GET['socio'] ?? 0)) > 0) {
    $s = $pdo->prepare("SELECT * FROM plataforma_admins WHERE id = ? AND rol = 'socio'");
    $s->execute([$sid]);
    $socioSel = $s->fetch() ?: null;
    if ($socioSel) {
        $a = $pdo->prepare("SELECT consultorio_id FROM plataforma_admin_consultorios WHERE admin_id = ?");
        $a->execute([$sid]);
        $asignados = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN));
        $consultorios = $pdo->query(
            "SELECT id, nombre, slug, estado, plan FROM consultorios ORDER BY nombre"
        )->fetchAll();
    }
}

$estBadge = ['trial' => 'info', 'activa' => 'success', 'suspendida' => 'danger', 'expirada' => 'secondary'];

$titulo  = 'Socios';
$platNav = 'socios';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-people"></i> Socios</h1>
<p class="text-muted">Aprueba a tus socios y decide qué consultorios puede ver cada uno.</p>

<?php foreach (get_flash() as $f): ?>
<div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>

<div class="row g-3">
    <!-- Lista de socios -->
    <div class="col-lg-<?= $socioSel ? '6' : '12' ?>">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-badge"></i> Socios registrados</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Socio</th><th>Estado</th><th class="text-center">Clientes</th><th class="text-end">Acciones</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$socios): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Aún no hay socios registrados. Comparte el enlace <code><?= e(BASE_URL) ?>/platform/registro</code>.</td></tr>
                    <?php else: foreach ($socios as $s): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($s['nombre']) ?></div>
                                <div class="small text-muted"><?= e($s['email']) ?></div>
                                <?php if (!empty($s['telefono'])): ?><div class="small text-muted"><i class="bi bi-telephone"></i> <?= e($s['telefono']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($s['activo']): ?>
                                    <span class="badge bg-success">Activo</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="<?= BASE_URL ?>/platform/socios?socio=<?= (int) $s['id'] ?>" class="text-decoration-none">
                                    <span class="badge bg-secondary"><?= (int) $s['n_clientes'] ?></span>
                                </a>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="<?= BASE_URL ?>/platform/socios?socio=<?= (int) $s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-buildings"></i> Clientes</a>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <?php if (!$s['activo']): ?>
                                            <li><form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="admin_id" value="<?= (int) $s['id'] ?>"><button name="accion" value="aprobar" class="dropdown-item text-success"><i class="bi bi-check-lg me-2"></i>Aprobar</button></form></li>
                                        <?php else: ?>
                                            <li><form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="admin_id" value="<?= (int) $s['id'] ?>"><button name="accion" value="desactivar" class="dropdown-item text-warning"><i class="bi bi-pause-circle me-2"></i>Desactivar</button></form></li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><form method="post" class="m-0" onsubmit="return confirm('¿Eliminar a este socio y todos sus accesos?')"><?= csrf_field() ?><input type="hidden" name="admin_id" value="<?= (int) $s['id'] ?>"><button name="accion" value="eliminar" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Eliminar</button></form></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Asignar clientes al socio seleccionado -->
    <?php if ($socioSel): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-buildings"></i> Clientes de <strong><?= e($socioSel['nombre']) ?></strong></span>
                <a href="<?= BASE_URL ?>/platform/socios" class="btn-close" aria-label="Cerrar"></a>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="asignar">
                <input type="hidden" name="admin_id" value="<?= (int) $socioSel['id'] ?>">
                <div class="card-body">
                    <?php if (!$socioSel['activo']): ?>
                        <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle"></i> Este socio está <strong>pendiente</strong>. Apruébalo para que pueda entrar (puedes asignarle clientes desde ya).</div>
                    <?php endif; ?>
                    <p class="text-muted small">Marca los consultorios que este socio podrá ver y gestionar.</p>

                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.cli-chk').forEach(c=>c.checked=true)">Todos</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.cli-chk').forEach(c=>c.checked=false)">Ninguno</button>
                    </div>

                    <div style="max-height:360px;overflow:auto" class="border rounded p-2">
                        <?php if (!$consultorios): ?>
                            <div class="text-muted small p-2">No hay consultorios todavía.</div>
                        <?php else: foreach ($consultorios as $c): ?>
                            <label class="d-flex align-items-center gap-2 py-1 px-1">
                                <input class="form-check-input cli-chk m-0" type="checkbox" name="consultorios[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $asignados, true) ? 'checked' : '' ?>>
                                <span class="flex-grow-1">
                                    <span class="fw-semibold"><?= e($c['nombre']) ?></span>
                                    <span class="text-muted small">· <?= e($c['slug']) ?></span>
                                </span>
                                <span class="badge bg-<?= $estBadge[$c['estado']] ?? 'secondary' ?>"><?= e($c['estado']) ?></span>
                            </label>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar clientes</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/_foot.php'; ?>
