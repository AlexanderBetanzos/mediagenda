<?php
/**
 * Crea un presupuesto en borrador con los tratamientos marcados como
 * "requeridos" en el odontograma del paciente. Si el catálogo tiene un servicio
 * con el mismo nombre que el tratamiento, se copia su precio; si no, el
 * concepto queda en cero para que el dentista lo cotice.
 */
require_once __DIR__ . '/../includes/odontograma.php';
require_login();
require_modulo('especialidades');
require_modulo('presupuestos');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }
verify_csrf();

$pid = (int) ($_POST['paciente_id'] ?? 0);
if (!$pid || !pertenece_al_tenant('pacientes', $pid)) { http_response_code(404); die('Paciente no encontrado.'); }

$requeridos = odo_requeridos($pid);
if (!$requeridos) {
    flash(t('No hay tratamientos marcados como requeridos en el odontograma.'), 'warning');
    redirect('/odontograma/index?paciente_id=' . $pid);
}

// Catálogo indexado por nombre en minúsculas, para casar "Obturación" => servicio.
$cat = db()->prepare('SELECT id, nombre, precio FROM servicios WHERE consultorio_id = ? AND activo = 1');
$cat->execute([tenant_id()]);
$porNombre = [];
foreach ($cat as $s) { $porNombre[mb_strtolower($s['nombre'])] = $s; }

$tratamientos = odo_tratamientos();
$u   = current_user();
$pdo = db();

$pdo->beginTransaction();
try {
    $folio = presupuesto_siguiente_folio();
    $pdo->prepare('INSERT INTO presupuestos
                   (consultorio_id, folio, paciente_id, medico_id, fecha, estado, subtotal, descuento, total, notas, creado_por)
                   VALUES (?,?,?,?,?,\'borrador\',0,0,0,?,?)')
        ->execute([tenant_id(), $folio, $pid, $u['rol'] === 'medico' ? $u['id'] : null, date('Y-m-d'),
                   t('Generado desde el odontograma.'), $u['id']]);
    $preId = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO presupuesto_items
                          (presupuesto_id, servicio_id, descripcion, diente, caras, cantidad, precio, importe, tratamiento, orden)
                          VALUES (?,?,?,?,?,1,?,?,?,?)');
    $subtotal = 0.0;
    foreach ($requeridos as $i => $r) {
        $def = $tratamientos[$r['estado']] ?? null;
        if (!$def) continue;

        // 'C' es el diente completo: no es una cara que valga la pena listar.
        $caras = implode(',', array_filter(explode(',', (string) $r['caras']), fn($c) => $c !== 'C')) ?: null;
        $etiqueta = t($def['label']) . ' · ' . t('diente') . ' ' . $r['diente'] . ($caras ? " ($caras)" : '');

        $servicio = $porNombre[mb_strtolower(t($def['label']))] ?? null;
        $precio   = $servicio ? (float) $servicio['precio'] : 0.0;
        $subtotal += $precio;

        $ins->execute([$preId, $servicio['id'] ?? null, $etiqueta, $r['diente'], $caras,
                       $precio, $precio, $r['estado'], $i]);
    }

    $pdo->prepare('UPDATE presupuestos SET subtotal = ?, total = ? WHERE id = ? AND consultorio_id = ?')
        ->execute([$subtotal, $subtotal, $preId, tenant_id()]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

auditar('crear', 'presupuesto', $preId, $folio . ' (odontograma)');
flash(t('Presupuesto creado desde el odontograma. Revisa precios y conceptos antes de proponerlo.'));
redirect('/presupuestos/edit?id=' . $preId);
