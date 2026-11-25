<?php
/**
 * ¿Qué es este código escaneado? (JSON para assets/js/dispositivos.js)
 *   GET  ?c=<código>             → producto (por SKU / código de barras) o paciente (por CURP)
 *   POST accion=vincular         → guarda el código como SKU de un producto (desde el POS)
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$tid = (int) tenant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cod = mb_substr(trim((string) ($_POST['codigo'] ?? '')), 0, 60);
    $pid = (int) ($_POST['producto_id'] ?? 0);
    if (($_POST['accion'] ?? '') !== 'vincular' || $cod === '' || !$pid || !pertenece_al_tenant('productos', $pid)) {
        echo json_encode(['ok' => false, 'error' => t('Datos incompletos.')]);
        exit;
    }
    $dup = db()->prepare('SELECT nombre FROM productos WHERE consultorio_id = ? AND sku = ? AND id <> ?');
    $dup->execute([$tid, $cod, $pid]);
    if ($otro = $dup->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => t('Ese código ya es de') . ' ' . $otro . '.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    db()->prepare('UPDATE productos SET sku = ? WHERE id = ? AND consultorio_id = ?')->execute([$cod, $pid, $tid]);
    auditar('editar', 'producto', $pid, 'Código de barras ' . $cod);
    echo json_encode(['ok' => true]);
    exit;
}

$c = trim((string) ($_GET['c'] ?? ''));
if ($c === '') { echo json_encode(['tipo' => null]); exit; }

if (modulo_activo('farmacia')) {
    $st = db()->prepare(
        "SELECT p.id, p.nombre, p.precio,
                COALESCE((SELECT SUM(l.cantidad) FROM inventario_lotes l
                          WHERE l.producto_id = p.id AND l.consultorio_id = p.consultorio_id), 0) AS stock
         FROM productos p WHERE p.consultorio_id = ? AND p.sku = ? LIMIT 1"
    );
    $st->execute([$tid, $c]);
    if ($p = $st->fetch()) {
        echo json_encode([
            'tipo' => 'producto', 'id' => (int) $p['id'], 'nombre' => $p['nombre'],
            'precio' => fmt_money($p['precio']), 'stock' => (int) $p['stock'],
            'url' => BASE_URL . '/inventario/index?q=' . rawurlencode($c),
            'pos' => BASE_URL . '/pos/index?codigo=' . rawurlencode($c),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// CURP (18 caracteres): credencial o INE con código del paciente.
if (preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i', $c)) {
    $st = db()->prepare('SELECT id FROM pacientes WHERE consultorio_id = ? AND curp = ? LIMIT 1');
    try {
        $st->execute([$tid, strtoupper($c)]);
        if ($id = $st->fetchColumn()) {
            echo json_encode(['tipo' => 'paciente', 'url' => BASE_URL . '/pacientes/ver?id=' . (int) $id]);
            exit;
        }
    } catch (Throwable $e) {}
}

echo json_encode(['tipo' => null]);
