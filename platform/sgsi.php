<?php
/**
 * SGSI en Salud (NOM-024): registro de evidencias con fecha.
 * La DGIS pide al menos 6 meses de operación del SGSI; lo que lo demuestra
 * son las minutas mensuales, los incidentes registrados (aunque no haya),
 * las revisiones de accesos y de proveedores y las pruebas de respaldo.
 * Los documentos base viven en docs/sgsi/ y se leen desde aquí.
 */
require_once __DIR__ . '/../includes/respaldos.php';
require_platform_super();
asegurar_respaldos();
$actor = ['nombre' => (platform_admin()['nombre'] ?? 'Plataforma') . ' (plataforma)'];

db()->exec("CREATE TABLE IF NOT EXISTS sgsi_registros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(30) NOT NULL,
    fecha DATE NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    descripcion TEXT NULL,
    acuerdos TEXT NULL,
    participantes VARCHAR(300) NULL,
    severidad VARCHAR(10) NULL,
    estado VARCHAR(15) NOT NULL DEFAULT 'cerrado',
    registrado_por VARCHAR(120) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tipo (tipo, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$TIPOS = [
    'minuta'      => ['Minuta mensual del SGSI', 'bi-journal-text'],
    'incidente'   => ['Incidente de seguridad', 'bi-exclamation-octagon'],
    'accesos'     => ['Revisión de accesos', 'bi-people'],
    'proveedor'   => ['Revisión de proveedor', 'bi-building'],
    'riesgos'     => ['Revisión de riesgos', 'bi-clipboard-data'],
    'capacitacion'=> ['Capacitación / concientización', 'bi-mortarboard'],
    'cambio'      => ['Cambio relevante al sistema', 'bi-git'],
];
$DOCS = __DIR__ . '/../docs/sgsi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tipo = (string) ($_POST['tipo'] ?? '');
    if (isset($TIPOS[$tipo])) {
        $desc = trim((string) ($_POST['descripcion'] ?? ''));
        if ($tipo === 'accesos' && !empty($_POST['foto_accesos'])) {
            // Foto de quién tiene acceso hoy a la consola de plataforma.
            $adm = db()->query('SELECT nombre, email, rol, activo FROM plataforma_admins ORDER BY nombre')->fetchAll();
            $desc .= "\n\nAccesos a la consola de plataforma al " . date('d/m/Y') . ":\n" . implode("\n", array_map(fn ($a) => '- ' . $a['nombre'] . ' <' . $a['email'] . '> · ' . $a['rol'] . ($a['activo'] ? '' : ' (inactivo)'), $adm));
        }
        db()->prepare('INSERT INTO sgsi_registros (tipo, fecha, titulo, descripcion, acuerdos, participantes, severidad, estado, registrado_por) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$tipo, preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d'),
                mb_substr(trim((string) ($_POST['titulo'] ?? '')) ?: $TIPOS[$tipo][0], 0, 200), $desc ?: null,
                trim((string) ($_POST['acuerdos'] ?? '')) ?: null, mb_substr(trim((string) ($_POST['participantes'] ?? '')), 0, 300) ?: null,
                in_array($_POST['severidad'] ?? '', ['baja', 'media', 'alta'], true) ? $_POST['severidad'] : null,
                ($_POST['estado'] ?? '') === 'abierto' ? 'abierto' : 'cerrado', $actor['nombre']]);
        auditar('sgsi_registro', 'sgsi', (int) db()->lastInsertId(), $tipo, null, $actor);
        flash('Registro guardado.');
    }
    redirect('/platform/sgsi');
}

$verDoc = basename((string) ($_GET['doc'] ?? ''));
$docs = is_dir($DOCS) ? array_values(array_filter(scandir($DOCS), fn ($f) => str_ends_with($f, '.md'))) : [];

$regs = db()->query('SELECT * FROM sgsi_registros ORDER BY fecha DESC, id DESC LIMIT 200')->fetchAll();
$inicio = db()->query("SELECT MIN(fecha) FROM sgsi_registros WHERE tipo = 'minuta'")->fetchColumn();
$meses = $inicio ? (int) floor((time() - strtotime($inicio)) / (30.44 * 86400)) : 0;
$minutas = db()->query("SELECT DATE_FORMAT(fecha,'%Y-%m') m FROM sgsi_registros WHERE tipo='minuta' GROUP BY m")->fetchAll(PDO::FETCH_COLUMN);
$ultAcc = db()->query("SELECT MAX(fecha) FROM sgsi_registros WHERE tipo='accesos'")->fetchColumn();
$abiertos = (int) db()->query("SELECT COUNT(*) FROM sgsi_registros WHERE tipo='incidente' AND estado='abierto'")->fetchColumn();
$pruebas90 = (int) db()->query("SELECT COUNT(*) FROM respaldos WHERE prueba_ok = 1 AND prueba_en > NOW() - INTERVAL 90 DAY")->fetchColumn();

$titulo = 'SGSI';
$platNav = 'sgsi';
include __DIR__ . '/_head.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-shield-lock"></i> SGSI en Salud</h1>
<p class="text-muted">Evidencias con fecha del sistema de gestión de seguridad de la información. La DGIS pide <b>mínimo 6 meses</b> operando antes de la verificación.</p>
<?php foreach (get_flash() as $f): ?><div class="alert alert-<?= e($f['tipo']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>

<?php if ($verDoc !== '' && in_array($verDoc, $docs, true)): ?>
<div class="card mb-4"><div class="card-header d-flex justify-content-between"><span><i class="bi bi-file-earmark-text"></i> <?= e($verDoc) ?></span><a href="?" class="small">Cerrar</a></div>
    <div class="card-body"><pre style="white-space:pre-wrap;font-family:inherit;font-size:.9rem;margin:0"><?= e((string) file_get_contents($DOCS . '/' . $verDoc)) ?></pre></div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Madurez', $inicio ? $meses . ' meses' : 'Sin iniciar', $meses >= 6 ? 'success' : 'warning', $inicio ? 'desde la 1.ª minuta (' . fmt_fecha($inicio) . ')' : 'registra la primera minuta'],
        ['Minutas mensuales', count($minutas), count($minutas) >= max(1, $meses) ? 'success' : 'warning', 'una por mes, sin huecos'],
        ['Última revisión de accesos', $ultAcc ? fmt_fecha($ultAcc) : 'Nunca', $ultAcc && strtotime($ultAcc) > strtotime('-95 days') ? 'success' : 'danger', 'cada 3 meses'],
        ['Incidentes abiertos', $abiertos, $abiertos ? 'danger' : 'success', 'registra aunque no haya'],
        ['Pruebas de respaldo (90 días)', $pruebas90, $pruebas90 >= 4 ? 'success' : 'warning', 'automáticas, una diaria'],
    ] as [$l, $v, $c, $s]): ?>
    <div class="col-6 col-lg"><div class="card h-100 border-<?= $c ?>"><div class="card-body">
        <div class="small text-muted"><?= e($l) ?></div><div class="fs-4 fw-bold text-<?= $c ?>"><?= e((string) $v) ?></div><div class="small text-muted"><?= e($s) ?></div>
    </div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7"><form method="post" class="card h-100">
        <?= csrf_field() ?>
        <div class="card-header"><i class="bi bi-plus-circle"></i> Nuevo registro</div>
        <div class="card-body row g-2">
            <div class="col-md-6"><label class="form-label small">Tipo</label>
                <select name="tipo" class="form-select" id="sgTipo"><?php foreach ($TIPOS as $k => [$l]): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label small">Fecha</label><input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><label class="form-label small">Estado</label><select name="estado" class="form-select"><option value="cerrado">Cerrado</option><option value="abierto">Abierto</option></select></div>
            <div class="col-md-8"><label class="form-label small">Título</label><input name="titulo" class="form-control" maxlength="200" placeholder="Minuta de noviembre 2026"></div>
            <div class="col-md-4"><label class="form-label small">Severidad (incidentes)</label><select name="severidad" class="form-select"><option value="">—</option><option>baja</option><option>media</option><option>alta</option></select></div>
            <div class="col-12"><label class="form-label small">Participantes</label><input name="participantes" class="form-control" placeholder="Alexander Betanzos (responsable de seguridad), …"></div>
            <div class="col-12"><label class="form-label small">Qué se revisó / qué pasó</label><textarea name="descripcion" class="form-control" rows="4" placeholder="Bitácora íntegra, respaldos verificados, accesos sin cambios, incidentes: ninguno…"></textarea></div>
            <div class="col-12"><label class="form-label small">Acuerdos y acciones (responsable y fecha)</label><textarea name="acuerdos" class="form-control" rows="3"></textarea></div>
            <div class="col-12 form-check ms-1"><input class="form-check-input" type="checkbox" name="foto_accesos" value="1" id="fa"><label class="form-check-label small" for="fa">Adjuntar la lista actual de accesos a la consola (para revisiones de accesos)</label></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-save"></i> Guardar registro</button></div>
        </div>
    </form></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header"><i class="bi bi-folder2"></i> Documentos del SGSI</div>
        <div class="list-group list-group-flush small">
            <?php if (!$docs): ?><div class="list-group-item text-muted">No se encontró docs/sgsi/.</div><?php endif; ?>
            <?php foreach ($docs as $d): ?><a class="list-group-item list-group-item-action" href="?doc=<?= e(rawurlencode($d)) ?>"><i class="bi bi-file-earmark-text"></i> <?= e($d) ?></a><?php endforeach; ?>
        </div>
        <div class="card-body small text-muted">Imprímelos, fírmalos y fecha cada versión: la firma de la política por BETASYD es parte de la evidencia.</div>
    </div></div>
</div>

<div class="card"><div class="card-header"><i class="bi bi-clock-history"></i> Registros</div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Título</th><th>Estado</th><th>Registró</th></tr></thead>
        <tbody>
        <?php if (!$regs): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin registros. Empieza con la minuta de este mes.</td></tr><?php endif; ?>
        <?php foreach ($regs as $r): [$tl, $ti] = $TIPOS[$r['tipo']] ?? [$r['tipo'], 'bi-dot']; ?>
            <tr>
                <td class="small text-nowrap"><?= e(fmt_fecha($r['fecha'])) ?></td>
                <td class="small"><i class="bi <?= $ti ?>"></i> <?= e($tl) ?><?= $r['severidad'] ? ' · ' . e($r['severidad']) : '' ?></td>
                <td><details><summary><?= e($r['titulo']) ?></summary>
                    <div class="small mt-1" style="white-space:pre-line"><?= e($r['descripcion'] ?? '') ?></div>
                    <?php if ($r['acuerdos']): ?><div class="small mt-1"><b>Acuerdos:</b> <span style="white-space:pre-line"><?= e($r['acuerdos']) ?></span></div><?php endif; ?>
                    <?php if ($r['participantes']): ?><div class="small text-muted mt-1">Participantes: <?= e($r['participantes']) ?></div><?php endif; ?>
                </details></td>
                <td><span class="badge text-bg-<?= $r['estado'] === 'abierto' ? 'warning' : 'secondary' ?>"><?= e($r['estado']) ?></span></td>
                <td class="small text-muted"><?= e($r['registrado_por'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
