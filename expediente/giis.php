<?php
/**
 * Reporte de Consulta Externa para la DGIS (GIIS-B015) de este consultorio:
 * vista previa con validaciones y descarga del archivo.
 */
require_once __DIR__ . '/../includes/giis.php';
require_role('admin');
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01', strtotime('first day of last month'));
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-t', strtotime('last day of last month'));
$sep = ($_GET['sep'] ?? '|') === ',' ? ',' : '|';
$rep = null; $err = '';
try { $rep = giis_reporte((int) tenant_id(), $desde, $hasta); } catch (Throwable $e) { $err = $e->getMessage(); }

if ($rep && isset($_GET['descargar'])) {
    auditar('giis_exportar', 'giis', null, "$desde a $hasta · {$rep['total']} consultas");
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="consulta_externa_' . preg_replace('/\W/', '', (string) cfg('clues')) . '_' . str_replace('-', '', $desde) . '_' . str_replace('-', '', $hasta) . '.txt"');
    $out = fopen('php://output', 'w');
    fwrite($out, implode($sep, $rep['enc']) . "\r\n");
    foreach ($rep['filas'] as $f) fwrite($out, implode($sep, $f) . "\r\n");
    exit;
}
$titulo = t('Reporte DGIS de consulta externa');
$activo = 'expediente';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-diagram-3 text-brand"></i> <?= et('Reporte de consulta externa (DGIS)') ?></h1>
<p class="text-muted"><?= et('Genera el archivo de intercambio de la GIIS-B015 con las consultas del periodo, revisando antes los datos obligatorios.') ?></p>
<form class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small"><?= et('Desde') ?></label><input type="date" name="desde" class="form-control" value="<?= e($desde) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small"><?= et('Hasta') ?></label><input type="date" name="hasta" class="form-control" value="<?= e($hasta) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small"><?= et('Separador') ?></label><select name="sep" class="form-select"><option value="|">|</option><option value="," <?= $sep === ',' ? 'selected' : '' ?>>,</option></select></div>
    <div class="col-6 col-md-2"><button class="btn btn-outline-primary w-100"><?= et('Revisar') ?></button></div>
    <?php if ($rep && $rep['total']): ?><div class="col-md-2"><a class="btn btn-primary w-100" href="?desde=<?= e($desde) ?>&hasta=<?= e($hasta) ?>&sep=<?= rawurlencode($sep) ?>&descargar=1"><i class="bi bi-download"></i> <?= et('Descargar') ?></a></div><?php endif; ?>
</div></form>
<?php if ($err): ?><div class="alert alert-info"><?= e($err) ?></div>
<?php elseif ($rep): ?>
    <?php if (!cfg('clues')): ?><div class="alert alert-warning small"><?= et('Tu consultorio no tiene CLUES capturada (Configuración).') ?></div><?php endif; ?>
    <div class="alert alert-<?= $rep['errores'] ? 'warning' : 'success' ?>"><?= number_format($rep['total']) ?> <?= et('consultas en el periodo') ?> ·
        <?= $rep['errores'] ? count($rep['errores']) . ' ' . et('observaciones por corregir (se muestran abajo)') : et('sin observaciones') ?></div>
    <?php if ($rep['errores']): ?>
    <div class="card"><div class="table-responsive" style="max-height:420px"><table class="table table-sm mb-0">
        <thead><tr><th><?= et('Consulta') ?></th><th><?= et('Variable') ?></th><th><?= et('Observación') ?></th></tr></thead>
        <tbody><?php foreach ($rep['errores'] as $x): ?><tr><td>#<?= (int) $x['consulta'] ?></td><td><?= e($x['var']) ?></td><td><?= e($x['msg']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <p class="small text-muted mt-2"><?= et('La mayoría se corrige completando los datos del paciente (primer apellido, entidad de nacimiento, CURP o motivo, domicilio) o el diagnóstico CIE-10 en la consulta.') ?></p>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
