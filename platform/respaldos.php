<?php
/**
 * Respaldos de la base de datos (consola del dueño): lista, crear ahora,
 * prueba de restauración y descarga. Cada acción queda en la bitácora; las
 * pruebas con su fecha son la evidencia que pide el SGSI.
 */
require_once __DIR__ . '/../includes/respaldos.php';
require_platform_super();
asegurar_respaldos();
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear') {
            $r = respaldo_crear('manual');
            $v = respaldo_verificar((int) $r['id']);
            auditar('respaldo_crear', 'respaldo', (int) $r['id'], $r['archivo'], null, $actor);
            flash('Respaldo creado (' . number_format($r['filas']) . ' filas). ' . $v['detalle'], $v['ok'] ? 'success' : 'danger');
        } elseif ($accion === 'verificar') {
            $id = (int) ($_POST['id'] ?? 0);
            $v = respaldo_verificar($id);
            auditar('respaldo_verificar', 'respaldo', $id, $v['ok'] ? 'ok' : 'falla', null, $actor);
            flash($v['detalle'], $v['ok'] ? 'success' : 'danger');
        } elseif ($accion === 'retencion') {
            guardar_plataforma_cfg(['respaldo_retencion' => (string) max(3, min(90, (int) ($_POST['retencion'] ?? 14)))]);
            flash('Retención guardada.');
        } elseif ($accion === 'descargar') {
            $r = respaldo_fila((int) ($_POST['id'] ?? 0));
            if (!$r || $r['borrado_en']) throw new RuntimeException('Ese respaldo ya no existe.');
            $sql = respaldo_leer_sql($r);
            auditar('respaldo_descargar', 'respaldo', (int) $r['id'], $r['archivo'], null, $actor);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . str_replace('.enc', '', basename($r['archivo'])) . '"');
            echo gzencode($sql, 6);
            exit;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'danger');
    }
    redirect('/platform/respaldos');
}

$lista = db()->query('SELECT * FROM respaldos ORDER BY id DESC LIMIT 60')->fetchAll();
$ult = $lista[0] ?? null;
$ultPrueba = db()->query('SELECT * FROM respaldos WHERE prueba_en IS NOT NULL ORDER BY prueba_en DESC LIMIT 1')->fetch();
try { $dir = respaldo_dir(); } catch (Throwable $e) { $dir = '—'; }

$titulo = 'Respaldos';
$platNav = 'respaldos';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-database-check"></i> Respaldos</h1>
<p class="text-muted">Copia completa de la base de datos, comprimida y cifrada (AES-256-GCM). El cron horario hace una al día y la verifica.</p>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Último respaldo</div>
        <div class="fs-5 fw-bold"><?= $ult ? e(fmt_fecha($ult['creado_en'])) . ' ' . e(date('H:i', strtotime($ult['creado_en']))) : 'Ninguno' ?></div>
        <?php if ($ult && strtotime($ult['creado_en']) < time() - 36 * 3600): ?><div class="small text-danger">Tiene más de 36 horas: revisa el cron.</div><?php endif; ?>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body">
        <div class="small text-muted">Última prueba de restauración</div>
        <div class="fs-5 fw-bold"><?= $ultPrueba ? e(fmt_fecha($ultPrueba['prueba_en'])) : 'Ninguna' ?>
            <?php if ($ultPrueba): ?><span class="badge text-bg-<?= $ultPrueba['prueba_ok'] ? 'success' : 'danger' ?>"><?= $ultPrueba['prueba_ok'] ? 'correcta' : 'falló' ?></span><?php endif; ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card h-100"><div class="card-body d-flex flex-column gap-2">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
            <button class="btn btn-primary w-100" onclick="this.disabled=true;this.form.submit()"><i class="bi bi-plus-circle"></i> Respaldar ahora</button></form>
        <form method="post" class="input-group input-group-sm"><?= csrf_field() ?><input type="hidden" name="accion" value="retencion">
            <span class="input-group-text">Conservar los últimos</span><input type="number" name="retencion" min="3" max="90" class="form-control" value="<?= (int) (plataforma_cfg('respaldo_retencion') ?: 14) ?>"><button class="btn btn-outline-secondary">OK</button></form>
    </div></div></div>
</div>

<div class="alert alert-light border small"><i class="bi bi-info-circle"></i>
    Carpeta: <code><?= e($dir) ?></code>. Los archivos solo se pueden abrir con la <b>APP_KEY</b>; guárdala también fuera del servidor.
    Recomendado: descarga un respaldo cada semana y guárdalo en otro lugar (copia fuera del hosting).</div>

<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Fecha</th><th>Origen</th><th class="text-end">Tablas</th><th class="text-end">Filas</th><th class="text-end">Tamaño</th><th>Prueba de restauración</th><th></th></tr></thead>
    <tbody>
    <?php if (!$lista): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no hay respaldos. Crea el primero.</td></tr><?php endif; ?>
    <?php foreach ($lista as $r): ?>
        <tr class="<?= $r['borrado_en'] ? 'text-muted' : '' ?>">
            <td class="small text-nowrap"><?= e(fmt_fecha($r['creado_en'])) ?> <?= e(date('H:i', strtotime($r['creado_en']))) ?></td>
            <td class="small"><?= e($r['origen']) ?><?= $r['borrado_en'] ? ' · <span class="badge text-bg-secondary">eliminado por retención</span>' : '' ?></td>
            <td class="text-end"><?= (int) $r['tablas'] ?></td>
            <td class="text-end"><?= number_format($r['filas']) ?></td>
            <td class="text-end small"><?= e(fmt_bytes((int) $r['tamano'])) ?></td>
            <td class="small"><?php if ($r['prueba_en']): ?><span class="badge text-bg-<?= $r['prueba_ok'] ? 'success' : 'danger' ?>"><?= $r['prueba_ok'] ? 'OK' : 'Falló' ?></span> <?= e(fmt_fecha($r['prueba_en'])) ?>
                <div class="text-muted"><?= e($r['prueba_detalle']) ?></div><?php else: ?><span class="text-muted">Sin probar</span><?php endif; ?></td>
            <td class="text-end text-nowrap"><?php if (!$r['borrado_en']): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button name="accion" value="verificar" class="btn btn-sm btn-outline-success" title="Prueba de restauración"><i class="bi bi-check2-square"></i></button>
                    <button name="accion" value="descargar" class="btn btn-sm btn-outline-secondary" title="Descargar .sql.gz"><i class="bi bi-download"></i></button>
                </form><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php include __DIR__ . '/_foot.php'; ?>
