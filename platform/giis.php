<?php
/**
 * GIIS-B015 (Consulta Externa): carga del diccionario de datos vigente y
 * mapeo de cada variable a un campo de MediAgenda.
 */
require_once __DIR__ . '/../includes/giis.php';
require_platform_super();
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (($_POST['accion'] ?? '') === 'diccionario') {
            $f = $_FILES['archivo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('No se recibió el archivo.');
            $vars = giis_leer_diccionario($f['tmp_name']);
            guardar_plataforma_cfg(['giis_b015_dic' => json_encode($vars, JSON_UNESCAPED_UNICODE),
                                    'giis_b015_version' => mb_substr(trim((string) ($_POST['version'] ?? '')), 0, 60) ?: date('Y-m-d')]);
            auditar('giis_diccionario', 'giis', null, count($vars) . ' variables', null, $actor);
            flash(count($vars) . ' variables cargadas. Ahora asigna cada una.');
        } else {
            $map = [];
            foreach ((array) ($_POST['m'] ?? []) as $var => $m) {
                $map[$var] = ['fuente' => array_key_exists($m['fuente'] ?? '', giis_fuentes()) ? $m['fuente'] : '',
                    'valor' => mb_substr((string) ($m['valor'] ?? ''), 0, 120), 'fmt' => in_array($m['fmt'] ?? '', ['Y-m-d', 'd/m/Y', 'Ymd', 'dmY'], true) ? $m['fmt'] : '',
                    'mapa' => mb_substr((string) ($m['mapa'] ?? ''), 0, 300), 'mayus' => !empty($m['mayus'])];
            }
            guardar_plataforma_cfg(['giis_b015_map' => json_encode($map, JSON_UNESCAPED_UNICODE)]);
            auditar('giis_mapeo', 'giis', null, count(array_filter($map, fn ($x) => $x['fuente'])) . ' asignadas', null, $actor);
            flash('Mapeo guardado.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'danger'); }
    redirect('/platform/giis');
}
$dic = giis_diccionario(); $map = giis_mapeo(); $fuentes = giis_fuentes();
$asig = count(array_filter($dic, fn ($d) => !empty($map[$d['var']]['fuente'])));
$oblSin = array_filter($dic, fn ($d) => $d['obl'] && empty($map[$d['var']]['fuente']));
$titulo = 'GIIS-B015';
$platNav = 'giis';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-diagram-3"></i> Reporte de Consulta Externa (GIIS-B015)</h1>
<p class="text-muted">Sube el diccionario de datos de la guía vigente (DGIS) y asigna a cada variable el campo de MediAgenda que le corresponde. Con eso cada consultorio genera su reporte.</p>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="card mb-3"><div class="card-body row g-2 align-items-end">
    <?= csrf_field() ?><input type="hidden" name="accion" value="diccionario">
    <div class="col-md-5"><label class="form-label small">Diccionario de datos (CSV exportado del Excel de la guía)</label><input type="file" name="archivo" accept=".csv,.txt" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label small">Versión de la guía</label><input name="version" class="form-control" value="<?= e(plataforma_cfg('giis_b015_version')) ?>" placeholder="GIIS-B015 versión …"></div>
    <div class="col-md-3"><button class="btn btn-outline-primary w-100"><i class="bi bi-upload"></i> Cargar diccionario</button></div>
    <div class="col-12 small text-muted">Columnas que se reconocen: variable/campo, descripción, tipo, longitud, obligatoriedad y catálogo.</div>
</div></form>
<?php if ($dic): ?>
<div class="alert alert-<?= $oblSin ? 'warning' : 'success' ?> small"><?= $asig ?> de <?= count($dic) ?> variables asignadas.
    <?= $oblSin ? count($oblSin) . ' obligatorias sin asignar: ' . e(implode(', ', array_slice(array_column($oblSin, 'var'), 0, 12))) : 'Todas las obligatorias están asignadas.' ?></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="accion" value="mapeo">
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Variable</th><th>Descripción</th><th>Tipo / long.</th><th style="min-width:230px">Campo de MediAgenda</th><th>Valor fijo</th><th>Fecha</th><th>Equivalencias</th><th>MAY</th></tr></thead>
    <tbody>
    <?php foreach ($dic as $d): $m = $map[$d['var']] ?? []; $n = 'm[' . e($d['var']) . ']'; ?>
    <tr class="<?= $d['obl'] && empty($m['fuente']) ? 'table-warning' : '' ?>">
        <td class="fw-semibold small"><?= e($d['var']) ?><?= $d['obl'] ? ' <span class="text-danger">*</span>' : '' ?></td>
        <td class="small text-muted" style="max-width:260px"><?= e($d['desc']) ?><?= $d['cat'] ? '<br><i>Cat: ' . e($d['cat']) . '</i>' : '' ?></td>
        <td class="small"><?= e($d['tipo']) ?> <?= $d['lon'] ?: '' ?></td>
        <td><select name="<?= $n ?>[fuente]" class="form-select form-select-sm"><?php foreach ($fuentes as $k => $l): ?><option value="<?= e($k) ?>" <?= ($m['fuente'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
        <td><input name="<?= $n ?>[valor]" class="form-control form-control-sm" value="<?= e($m['valor'] ?? '') ?>" style="width:90px"></td>
        <td><select name="<?= $n ?>[fmt]" class="form-select form-select-sm" style="width:115px"><?php foreach (['' => '—', 'Y-m-d' => 'aaaa-mm-dd', 'd/m/Y' => 'dd/mm/aaaa', 'Ymd' => 'aaaammdd', 'dmY' => 'ddmmaaaa'] as $k => $l): ?><option value="<?= $k ?>" <?= ($m['fmt'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
        <td><input name="<?= $n ?>[mapa]" class="form-control form-control-sm" value="<?= e($m['mapa'] ?? '') ?>" placeholder="M=H, F=M" style="width:120px"></td>
        <td><input type="checkbox" name="<?= $n ?>[mayus]" value="1" <?= !empty($m['mayus']) ? 'checked' : '' ?>></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="card-footer"><button class="btn btn-primary"><i class="bi bi-save"></i> Guardar mapeo</button></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
