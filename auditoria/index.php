<?php
/**
 * Bitácora del consultorio: quién entró, quién abrió qué expediente y qué se
 * creó, cambió o borró (NOM-024: trazabilidad de accesos). Solo administradores.
 * Los registros los escribe auditar() en todo el sistema; aquí solo se leen.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$tid = (int) tenant_id();
$f = [
    'usuario' => (int) ($_GET['usuario'] ?? 0),
    'tipo'    => (string) ($_GET['tipo'] ?? ''),
    'desde'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-d', strtotime('-30 days')),
    'hasta'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d'),
    'q'       => trim((string) ($_GET['q'] ?? '')),
];
$TIPOS = [
    ''          => 'Todo',
    'acceso'    => 'Entradas y salidas',
    'ver'       => 'Expedientes abiertos',
    'crear'     => 'Altas',
    'editar'    => 'Cambios',
    'borrar'    => 'Borrados',
];
$where = ['a.consultorio_id = ?', 'a.creado_en >= ?', 'a.creado_en < ?'];
$params = [$tid, $f['desde'] . ' 00:00:00', date('Y-m-d', strtotime($f['hasta'] . ' +1 day')) . ' 00:00:00'];
if ($f['usuario']) { $where[] = 'a.usuario_id = ?'; $params[] = $f['usuario']; }
switch ($f['tipo']) {
    case 'acceso': $where[] = "a.accion IN ('login','logout','login_fallido','sesion_expirada','2fa_ok','2fa_fallido','reset_solicitado','reset_hecho')"; break;
    case 'ver':    $where[] = "a.accion = 'ver'"; break;
    case 'crear':  $where[] = "(a.accion = 'crear' OR a.accion LIKE '%alta%' OR a.accion LIKE 'subir%')"; break;
    case 'editar': $where[] = "(a.accion = 'editar' OR a.accion LIKE '%_estado' OR a.accion LIKE '%editar%')"; break;
    case 'borrar': $where[] = "(a.accion IN ('borrar','eliminar','borrar_bloqueado') OR a.accion LIKE '%borr%' OR a.accion LIKE '%elimin%')"; break;
}
if ($f['q'] !== '') {
    $where[] = '(a.detalle LIKE ? OR a.entidad LIKE ? OR a.accion LIKE ? OR a.usuario_nombre LIKE ?)';
    $like = '%' . $f['q'] . '%';
    array_push($params, $like, $like, $like, $like);
}
$sqlW = implode(' AND ', $where);
$total = db()->prepare("SELECT COUNT(*) FROM auditoria a WHERE $sqlW");
$total->execute($params);
$total = (int) $total->fetchColumn();
$porPag = 50;
$pag = max(1, (int) ($_GET['p'] ?? 1));
$pags = max(1, (int) ceil($total / $porPag));
$pag = min($pag, $pags);
$st = db()->prepare("SELECT a.* FROM auditoria a WHERE $sqlW ORDER BY a.id DESC LIMIT $porPag OFFSET " . (($pag - 1) * $porPag));
$st->execute($params);
$filas = $st->fetchAll();

// Nombres de pacientes para "abrió el expediente de…"
$pacIds = array_unique(array_map(fn($r) => (int) $r['entidad_id'], array_filter($filas, fn($r) => $r['entidad'] === 'paciente' && $r['entidad_id'])));
$pacs = [];
if ($pacIds) {
    $in = implode(',', array_fill(0, count($pacIds), '?'));
    $q = db()->prepare("SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? AND id IN ($in)");
    $q->execute(array_merge([$tid], array_values($pacIds)));
    foreach ($q->fetchAll() as $p) $pacs[(int) $p['id']] = trim($p['nombre'] . ' ' . $p['apellidos']);
}
$usuarios = db()->prepare('SELECT id, nombre FROM usuarios WHERE consultorio_id = ? ORDER BY nombre');
$usuarios->execute([$tid]);
$usuarios = $usuarios->fetchAll();

/** Frase legible para cada registro. */
function aud_frase(array $r, array $pacs): array
{
    $ac = (string) $r['accion'];
    $ent = (string) $r['entidad'];
    $quien = $ent === 'paciente' && $r['entidad_id'] ? ($pacs[(int) $r['entidad_id']] ?? ('paciente #' . $r['entidad_id'])) : '';
    $obj = $ent !== '' ? $ent . ($r['entidad_id'] ? ' #' . $r['entidad_id'] : '') : '';
    $map = [
        'login'            => ['bi-box-arrow-in-right', '#16a34a', 'Entró al sistema'],
        'logout'           => ['bi-box-arrow-right', '#64748b', 'Cerró sesión'],
        'login_fallido'    => ['bi-shield-exclamation', '#dc2626', 'Intento de entrada fallido'],
        'sesion_expirada'  => ['bi-hourglass-bottom', '#64748b', 'Sesión cerrada por inactividad'],
        'ver'              => ['bi-eye', '#0891b2', 'Abrió el expediente' . ($quien ? ' de ' . $quien : '')],
        'crear'            => ['bi-plus-circle', '#16a34a', 'Creó ' . ($quien ?: $obj)],
        'editar'           => ['bi-pencil', '#d97706', 'Modificó ' . ($quien ?: $obj)],
        'borrar'           => ['bi-trash', '#dc2626', 'Borró ' . ($quien ?: $obj)],
        'eliminar'         => ['bi-trash', '#dc2626', 'Eliminó ' . ($quien ?: $obj)],
        'borrar_bloqueado' => ['bi-shield-lock', '#d97706', 'Intentó borrar ' . ($quien ?: $obj) . ' (bloqueado: tiene historial)'],
    ];
    if (isset($map[$ac])) return $map[$ac];
    $txt = ucfirst(str_replace('_', ' ', $ac)) . ($obj ? ' · ' . $obj : '');
    $col = preg_match('/borr|elimin|cancel/', $ac) ? '#dc2626' : (preg_match('/login|portal/', $ac) ? '#0891b2' : '#64748b');
    return ['bi-dot', $col, $txt];
}

$titulo = t('Bitácora');
$activo = 'auditoria';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-journal-text text-brand"></i> <?= et('Bitácora') ?></h1>
    <span class="small text-muted"><?= et('Quién entró, qué expedientes abrió y qué cambió. Los registros no se pueden editar ni borrar.') ?></span>
</div>

<form method="get" class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Desde') ?></label><input type="date" name="desde" class="form-control form-control-sm" value="<?= e($f['desde']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Hasta') ?></label><input type="date" name="hasta" class="form-control form-control-sm" value="<?= e($f['hasta']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Persona') ?></label>
        <select name="usuario" class="form-select form-select-sm"><option value="0"><?= et('Todas') ?></option>
            <?php foreach ($usuarios as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['usuario'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Tipo') ?></label>
        <select name="tipo" class="form-select form-select-sm"><?php foreach ($TIPOS as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['tipo'] === $k ? 'selected' : '' ?>><?= et($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-8 col-md-3"><label class="form-label small"><?= et('Buscar') ?></label><input type="search" name="q" class="form-control form-control-sm" value="<?= e($f['q']) ?>" placeholder="<?= e(t('Detalle, acción…')) ?>"></div>
    <div class="col-4 col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i></button></div>
</div></form>

<div class="card">
    <div class="card-header small text-muted"><?= number_format($total) ?> <?= et('registros') ?></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th style="width:150px"><?= et('Fecha y hora') ?></th><th><?= et('Persona') ?></th><th><?= et('Qué hizo') ?></th><th class="d-none d-lg-table-cell"><?= et('Detalle') ?></th><th class="d-none d-md-table-cell"><?= et('IP') ?></th></tr></thead>
            <tbody>
            <?php if (!$filas): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= et('Sin registros con estos filtros.') ?></td></tr>
            <?php else: foreach ($filas as $r): [$ic, $col, $txt] = aud_frase($r, $pacs); ?>
                <tr>
                    <td class="small text-nowrap"><?= e(fmt_fecha($r['creado_en'])) ?> <span class="text-muted"><?= e(date('H:i', strtotime($r['creado_en']))) ?></span></td>
                    <td class="small"><?= e($r['usuario_nombre'] ?: '—') ?></td>
                    <td><i class="bi <?= $ic ?>" style="color:<?= $col ?>"></i> <?= $r['entidad'] === 'paciente' && $r['entidad_id'] && isset($pacs[(int) $r['entidad_id']])
                        ? str_replace(e($pacs[(int) $r['entidad_id']]), '<a href="' . BASE_URL . '/pacientes/ver?id=' . (int) $r['entidad_id'] . '">' . e($pacs[(int) $r['entidad_id']]) . '</a>', e($txt))
                        : e($txt) ?></td>
                    <td class="small text-muted d-none d-lg-table-cell"><?= e($r['detalle'] ?? '') ?></td>
                    <td class="small text-muted d-none d-md-table-cell" title="<?= e($r['user_agent'] ?? '') ?>"><?= e($r['ip'] ?? '') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pags > 1): $qs = $_GET; ?>
    <div class="card-footer d-flex justify-content-between align-items-center small">
        <span><?= et('Página') ?> <?= $pag ?> / <?= $pags ?></span>
        <span class="d-flex gap-2">
            <?php if ($pag > 1): $qs['p'] = $pag - 1; ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query($qs)) ?>">← <?= et('Anterior') ?></a><?php endif; ?>
            <?php if ($pag < $pags): $qs['p'] = $pag + 1; ?><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query($qs)) ?>"><?= et('Siguiente') ?> →</a><?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
