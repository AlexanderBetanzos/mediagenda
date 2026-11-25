<?php
/**
 * Regreso desde Mercado Pago tras comprar timbres. El webhook es quien
 * acredita, pero aquí se intenta confirmar de inmediato con el id que trae la
 * URL para que el consultorio vea su saldo nuevo sin esperar.
 */
require_once __DIR__ . '/../includes/creditos.php';
require_login();
asegurar_creditos();

$st = db()->prepare('SELECT * FROM timbres_compras WHERE token = ? AND consultorio_id = ?');
$st->execute([(string) ($_GET['t'] ?? ''), (int) tenant_id()]);
$c = $st->fetch();
if (!$c) redirect('/timbres/index');
$d = credito_def($c['recurso'] ?: 'timbre');

$pagoId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
if ($c['estado'] !== 'pagado' && preg_match('/^\d+$/', $pagoId)) {
    try { credito_confirmar_pago($pagoId); } catch (Throwable $e) { /* lo resolverá el webhook */ }
    $st->execute([(string) $_GET['t'], (int) tenant_id()]);
    $c = $st->fetch() ?: $c;
}

$mp = (string) ($_GET['status'] ?? $_GET['collection_status'] ?? '');
if ($c['estado'] === 'pagado') {
    flash(sprintf('¡Listo! Se sumaron %s %s a tu saldo.', number_format($c['timbres']), $d['nombre']));
} elseif ($c['estado'] === 'rechazado' || $mp === 'rejected' || $mp === 'failure') {
    flash('El pago no se completó. No se hizo ningún cargo; puedes intentarlo de nuevo.', 'danger');
} elseif ($mp === 'null' || $mp === '') {
    flash('Cancelaste el pago. No se hizo ningún cargo.', 'warning');
} else {
    flash('Tu pago está en proceso. En cuanto Mercado Pago lo acredite (OXXO o transferencia pueden tardar), se suman solos a tu saldo.', 'info');
}
redirect($d['pagina']);
