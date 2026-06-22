<?php
/**
 * Bitácora de auditoría. El admin ve los eventos de su consultorio;
 * el súper-admin ve los de todos.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
if (!has_role('admin') && !es_superadmin()) {
    http_response_code(403);
    die('<h3 style="font-family:sans-serif;padding:2rem">403 — Solo administradores.</h3>');
}

$verTodos = es_superadmin();

// Filtros
$accion = trim($_GET['accion'] ?? '');
$q      = trim($_GET['q'] ?? '');
$pag    = max(1, (int) ($_GET['p'] ?? 1));
$porPag = 50;

$where  = [];
$params = [];
if (!$verTodos) { $where[] = 'a.consultorio_id = ?'; $params[] = tenant_id(); }
if ($accion !== '') { $where[] = 'a.accion = ?'; $params[] = $accion; }
if ($q !== '') {
    $where[] = '(a.usuario_nombre LIKE ? OR a.detalle LIKE ? OR a.ip LIKE ?)';
    $like = "%$q%"; array_push($params, $like, $like, $like);
}
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total para paginación
$cnt = db()->prepare("SELECT COUNT(*) FROM auditoria a $sqlWhere");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$paginas = max(1, (int) ceil($total / $porPag));
$pag = min($pag, $paginas);
$offset = ($pag - 1) * $porPag;

// Listado (consultorio para superadmin)
$sql = "SELECT a.*" . ($verTodos ? ', c.nombre AS consultorio' : '') . "
        FROM auditoria a" . ($verTodos ? ' LEFT JOIN consultorios c ON c.id = a.consultorio_id' : '') . "
        $sqlWhere
        ORDER BY a.id DESC
        LIMIT $porPag OFFSET $offset";
$st = db()->prepare($sql);
$st->execute($params);
$filas = $st->fetchAll();

// Acciones distintas para el filtro
$acciones = db()->query('SELECT DISTINCT accion FROM auditoria ORDER BY accion')->fetchAll(PDO::FETCH_COLUMN);

/** Color de badge por acción. */
function aud_badge(string $a): string
{
    if (str_contains($a, 'fallido') || $a === '2fa_desactivar') return 'danger';
    if (str_starts_with($a, 'borrar')) return 'warning';
    if ($a === 'login' || str_contains($a, 'activar') || str_starts_with($a, 'crear')) return 'success';
    if (str_starts_with($a, 'editar')) return 'info';
    return 'secondary';
}

$titulo = t('Auditoría');
$activo = '';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-clipboard-data text-brand"></i> <?= et('Auditoría') ?></h1>
    <span class="text-muted small"><?= number_format($total) ?> <?= et('eventos') ?><?= $verTodos ? ' · ' . et('todos los consultorios') : '' ?></span>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-sm-5 col-md-4">
        <input type="search" name="q" class="form-control" placeholder="<?= et('Buscar por usuario, detalle o IP…') ?>" value="<?= e($q) ?>">
    </div>
    <div class="col-sm-4 col-md-3">
        <select name="accion" class="form-select">
            <option value=""><?= et('Todas las acciones') ?></option>
            <?php foreach ($acciones as $ac): ?>
                <option value="<?= e($ac) ?>" <?= $accion === $ac ? 'selected' : '' ?>><?= e($ac) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> <?= et('Filtrar') ?></button>
        <a href="<?= BASE_URL ?>/admin/auditoria" class="btn btn-link"><?= et('Limpiar') ?></a>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= et('Fecha') ?></th>
                    <?php if ($verTodos): ?><th><?= et('Consultorio') ?></th><?php endif; ?>
                    <th><?= et('Usuario') ?></th><th><?= et('Acción') ?></th><th><?= et('Entidad') ?></th><th><?= et('Detalle') ?></th><th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$filas): ?>
                <tr><td colspan="<?= $verTodos ? 7 : 6 ?>" class="text-center text-muted py-4"><?= et('Sin eventos.') ?></td></tr>
            <?php else: foreach ($filas as $f): ?>
                <tr>
                    <td class="text-nowrap small"><?= fmt_fecha($f['creado_en']) ?> <?= date('H:i', strtotime($f['creado_en'])) ?></td>
                    <?php if ($verTodos): ?><td class="small"><?= e($f['consultorio'] ?? ('#' . $f['consultorio_id'])) ?></td><?php endif; ?>
                    <td class="small"><?= e($f['usuario_nombre'] ?: '—') ?></td>
                    <td><span class="badge bg-<?= aud_badge($f['accion']) ?>"><?= e($f['accion']) ?></span></td>
                    <td class="small"><?= e($f['entidad'] ?: '') ?><?= $f['entidad_id'] ? ' #' . (int) $f['entidad_id'] : '' ?></td>
                    <td class="small text-muted"><?= e($f['detalle'] ?: '') ?></td>
                    <td class="small text-muted"><?= e($f['ip'] ?: '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($paginas > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qs = function ($p) use ($accion, $q) {
            return BASE_URL . '/admin/auditoria?' . http_build_query(array_filter(['accion' => $accion, 'q' => $q, 'p' => $p]));
        };
        for ($i = max(1, $pag - 3); $i <= min($paginas, $pag + 3); $i++): ?>
            <li class="page-item <?= $i === $pag ? 'active' : '' ?>"><a class="page-link" href="<?= e($qs($i)) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
