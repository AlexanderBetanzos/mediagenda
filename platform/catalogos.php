<?php
/**
 * Catálogos oficiales (consola): versión cargada de cada uno y carga de la
 * nueva versión desde el archivo oficial. Proceso: docs/sgsi/12-actualizacion-catalogos.md
 */
require_once __DIR__ . '/../includes/catalogos.php';
require_once __DIR__ . '/../includes/cie10.php';
require_platform_super();
asegurar_catalogos();
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    @set_time_limit(600);
    $cat = (string) ($_POST['catalogo'] ?? '');
    $f = $_FILES['archivo'] ?? null;
    try {
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se recibió el archivo (si es muy grande, súbelo comprimido por partes o pide ampliar el límite).');
        $ver = trim((string) ($_POST['version'] ?? '')) ?: date('Y-m-d');
        $n = catalogo_importar($cat, $f['tmp_name'], $ver, $actor['nombre']);
        auditar('catalogo_actualizado', 'catalogo', null, "$cat · $ver · $n filas", null, $actor);
        flash("Catálogo actualizado: $n registros (versión $ver).");
    } catch (Throwable $e) { flash($e->getMessage(), 'danger'); }
    redirect('/platform/catalogos');
}

try { asegurar_cie10(); $nCie = (int) db()->query('SELECT COUNT(*) FROM cie10')->fetchColumn(); } catch (Throwable $e) { $nCie = 0; }
$titulo = 'Catálogos';
$platNav = 'catalogos';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-journal-bookmark"></i> Catálogos oficiales</h1>
<p class="text-muted">La NOM-024 pide usar los catálogos fundamentales en su versión vigente. Cuando la autoridad publica una versión nueva, descarga el archivo oficial y súbelo aquí (CSV).</p>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<div class="card mb-4"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Catálogo</th><th class="text-end">Registros</th><th>Versión</th><th>Actualizado</th><th>Fuente oficial</th></tr></thead>
    <tbody>
        <tr><td>CIE-10 (diagnósticos, SSA)</td><td class="text-end"><?= number_format($nCie) ?></td><td><?= e(defined('CIE10_CREDITO') ? 'Ver crédito' : '—') ?></td><td>—</td><td class="small">Módulo propio (sql/data/cie10_mx.csv)</td></tr>
        <tr><td>Entidades federativas (INEGI)</td><td class="text-end"><?= count(entidades_inegi()) - 1 ?></td><td>Integrado</td><td>—</td><td class="small">Claves INEGI 01–32</td></tr>
        <?php foreach (catalogos_def() as $k => [$nom, $url]): $v = catalogo_version($k); ?>
        <tr><td><?= e($nom) ?></td><td class="text-end"><?= number_format($v['filas'] ?? 0) ?></td><td><?= e($v['version'] ?? 'Sin cargar') ?></td>
            <td class="small"><?= $v ? e(fmt_fecha($v['actualizado_en'])) . ' · ' . e($v['actualizado_por']) : '—' ?></td>
            <td class="small"><a href="<?= e($url) ?>" target="_blank" rel="noopener">Descargar <i class="bi bi-box-arrow-up-right"></i></a></td></tr>
        <?php endforeach; ?>
    </tbody>
</table></div></div>
<form method="post" enctype="multipart/form-data" class="card"><div class="card-body row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-4"><label class="form-label small">Catálogo</label><select name="catalogo" class="form-select"><?php foreach (catalogos_def() as $k => [$nom]): ?><option value="<?= $k ?>"><?= e($nom) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small">Versión (como la publica la fuente)</label><input name="version" class="form-control" placeholder="p. ej. octubre 2026"></div>
    <div class="col-md-3"><label class="form-label small">Archivo CSV oficial</label><input type="file" name="archivo" accept=".csv,.txt" class="form-control" required></div>
    <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-upload"></i> Cargar</button></div>
    <div class="col-12 small text-muted">Reemplaza el catálogo completo. Las columnas se reconocen por su encabezado (p. ej. CVE_ENT, CVE_MUN, NOM_MUN en el de municipios).</div>
</div></form>
<?php include __DIR__ . '/_foot.php'; ?>
