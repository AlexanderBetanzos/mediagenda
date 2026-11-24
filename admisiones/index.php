<?php
/**
 * Admisiones: el embudo de la Jefatura de Admisión. Cada solicitud (de la
 * página pública o capturada a mano) se contacta por WhatsApp, se convierte
 * en paciente, se le agenda valoración o se interna.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admisiones.php';
require_once __DIR__ . '/../includes/correo.php';
require_login();
require_modulo('crm');
asegurar_admisiones();

$pdo = db();
$tid = (int) tenant_id();
$u = current_user();
$EST = admision_estados();
$SERV = admision_servicios();
$URG = admision_urgencias();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $acc = $_POST['accion'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $txt = fn ($k, $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
    $a = null;
    if ($id) { $q = $pdo->prepare('SELECT * FROM admisiones WHERE id = ? AND consultorio_id = ?'); $q->execute([$id, $tid]); $a = $q->fetch() ?: null; }

    if ($acc === 'nueva') {
        if ($txt('paciente_nombre', 160) === '') { flash(t('Escribe para quién es la atención.'), 'warning'); redirect('/admisiones/index'); }
        $pdo->prepare("INSERT INTO admisiones (consultorio_id, paciente_nombre, paciente_edad, solicitante, parentesco, telefono, email, situacion, servicio, urgencia, origen, asignado_a, creado_en)
                       VALUES (?,?,?,?,?,?,?,?,?,?,'manual',?,NOW())")
            ->execute([$tid, $txt('paciente_nombre', 160), $txt('paciente_edad', 20) ?: null, $txt('solicitante', 160) ?: null, $txt('parentesco', 60) ?: null,
                       preg_replace('/[^\d+ ]/', '', $txt('telefono', 40)) ?: null, filter_var($txt('email', 150), FILTER_VALIDATE_EMAIL) ?: null,
                       $txt('situacion', 3000) ?: null, array_key_exists($_POST['servicio'] ?? '', $SERV) ? $_POST['servicio'] : null,
                       array_key_exists($_POST['urgencia'] ?? '', $URG) ? $_POST['urgencia'] : null, (int) $u['id']]);
        flash(t('Solicitud registrada.'));
    } elseif ($a && $acc === 'estado') {
        admision_marcar($id, (string) ($_POST['estado'] ?? ''));
        flash(t('Solicitud actualizada.'));
    } elseif ($a && $acc === 'nota') {
        if ($txt('nota', 1000) !== '') {
            $linea = '[' . date('d/m H:i') . ' · ' . $u['nombre'] . '] ' . $txt('nota', 1000);
            $pdo->prepare('UPDATE admisiones SET notas = CONCAT_WS(CHAR(10), ?, notas), actualizado_en = NOW(),
                           estado = IF(estado = \'nuevo\', \'contactado\', estado) WHERE id = ? AND consultorio_id = ?')
                ->execute([$linea, $id, $tid]);
            flash(t('Nota agregada.'));
        }
    } elseif ($a && $acc === 'paciente') {
        // Crea el paciente con lo que sabemos; el familiar queda como contacto de emergencia.
        if (!$a['paciente_id']) {
            $pz = preg_split('/\s+/', trim($a['paciente_nombre']));
            $k = count($pz) >= 3 ? count($pz) - 2 : 1;
            $nombre = implode(' ', array_slice($pz, 0, $k)) ?: $a['paciente_nombre'];
            $apellidos = implode(' ', array_slice($pz, $k)) ?: '—';
            $esElMismo = !$a['solicitante'] || mb_strtolower(trim($a['solicitante'])) === mb_strtolower(trim($a['paciente_nombre'])) || in_array(mb_strtolower((string) $a['parentesco']), ['yo', 'yo mismo', 'yo misma', 'paciente'], true);
            $campos = paciente_post_campos([
                'nombre' => $nombre, 'apellidos' => $apellidos,
                'telefono' => $esElMismo ? $a['telefono'] : '', 'email' => $esElMismo ? $a['email'] : '',
                'contacto_nombre' => $esElMismo ? '' : $a['solicitante'], 'contacto_telefono' => $esElMismo ? '' : $a['telefono'],
                'contacto_parentesco' => $esElMismo ? '' : $a['parentesco'],
                'notas' => trim(t('Solicitud de admisión') . ' #' . $a['id'] . ($a['situacion'] ? ":\n" . $a['situacion'] : '')),
            ]);
            $cols = array_keys($campos);
            $pdo->prepare('INSERT INTO pacientes (consultorio_id, ' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')
                ->execute(array_merge([$tid], array_values($campos)));
            $pid = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE admisiones SET paciente_id = ?, actualizado_en = NOW() WHERE id = ? AND consultorio_id = ?')->execute([$pid, $id, $tid]);
            auditar('crear', 'paciente', $pid, 'Desde admisión #' . $id);
            flash(t('Paciente creado. Revisa y completa su ficha.'));
            $a['paciente_id'] = $pid;
        }
        $ir = $_POST['ir'] ?? '';
        if ($ir === 'cita') { admision_marcar($id, 'valoracion'); redirect('/citas/create?paciente_id=' . (int) $a['paciente_id']); }
        if ($ir === 'internar') {
            redirect('/hospitalizacion/index?paciente_id=' . (int) $a['paciente_id'] . '&admision=' . $id
                     . '&responsable=' . rawurlencode((string) $a['solicitante']) . '&parentesco=' . rawurlencode((string) $a['parentesco'])
                     . '&tel=' . rawurlencode((string) $a['telefono']));
        }
        redirect('/pacientes/ver?id=' . (int) $a['paciente_id']);
    } elseif ($acc === 'web' && has_role('admin')) {
        guardar_cfg(['admision_web' => !empty($_POST['admision_web']) ? '1' : '0']);
        flash(!empty($_POST['admision_web']) ? t('Tu página ya muestra "¿Tienes un ser querido que necesita ayuda?".') : t('Se quitó el formulario de tu página.'));
    }
    redirect('/admisiones/index' . (isset($_GET['ver']) ? '?ver=' . (int) $_GET['ver'] : ''));
}

$filtro = array_key_exists($_GET['estado'] ?? '', $EST) ? $_GET['estado'] : '';
$st = $pdo->prepare('SELECT a.*, p.nombre pnom, p.apellidos pape FROM admisiones a LEFT JOIN pacientes p ON p.id = a.paciente_id
                     WHERE a.consultorio_id = ? ORDER BY FIELD(a.estado, \'nuevo\', \'contactado\', \'valoracion\', \'ingreso\', \'no_procede\'),
                     FIELD(a.urgencia, \'hoy\', \'semana\', \'info\'), a.creado_en DESC LIMIT 300');
$st->execute([$tid]);
$todas = $st->fetchAll();
$cuenta = array_fill_keys(array_keys($EST), 0);
foreach ($todas as $r) $cuenta[$r['estado']] = ($cuenta[$r['estado']] ?? 0) + 1;
$lista = $filtro ? array_values(array_filter($todas, fn ($r) => $r['estado'] === $filtro))
                 : array_values(array_filter($todas, fn ($r) => !in_array($r['estado'], ['ingreso', 'no_procede'], true)));
$mes = 0; $ing = 0;
foreach ($todas as $r) if ($r['creado_en'] >= date('Y-m-01')) { $mes++; if ($r['estado'] === 'ingreso') $ing++; }
$slug = '';
try { $q = $pdo->prepare('SELECT slug FROM consultorios WHERE id = ?'); $q->execute([$tid]); $slug = (string) $q->fetchColumn(); } catch (Throwable $e) {}
$urlPublica = $slug ? correo_url('/admision/solicitar?c=' . rawurlencode($slug)) : '';

$titulo = t('Admisiones');
$activo = 'admisiones';
include __DIR__ . '/../includes/header.php';
$hace = function (string $f): string {
    $s = time() - strtotime($f);
    if ($s < 3600) return sprintf(t('hace %d min'), max(1, (int) floor($s / 60)));
    if ($s < 86400) return sprintf(t('hace %d h'), (int) floor($s / 3600));
    return sprintf(t('hace %d días'), (int) floor($s / 86400));
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-person-plus"></i> <?= et('Admisiones') ?></h1>
    <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#nueva"><i class="bi bi-telephone-plus"></i> <?= et('Registrar solicitud') ?></button>
</div>

<?= ui_stats([
    [t('Por contactar'), number_format($cuenta['nuevo']), 'bi-inbox', '#2563eb', null, $cuenta['nuevo'] > 0],
    [t('En seguimiento'), number_format($cuenta['contactado'] + $cuenta['valoracion']), 'bi-arrow-repeat', '#f59e0b'],
    [t('Solicitudes este mes'), number_format($mes), 'bi-calendar3', '#7c3aed'],
    [t('Ingresaron este mes'), number_format($ing) . ($mes ? ' · ' . round($ing * 100 / $mes) . '%' : ''), 'bi-check-circle', '#16a34a'],
]) ?>

<div class="collapse mb-3" id="nueva"><div class="card"><div class="card-body">
    <h2 class="h6 mb-3"><?= et('Solicitud recibida por teléfono o en persona') ?></h2>
    <form method="post" class="row g-2">
        <?= csrf_field() ?><input type="hidden" name="accion" value="nueva">
        <div class="col-md-5"><label class="form-label"><?= et('¿Para quién es la atención?') ?> *</label><input name="paciente_nombre" class="form-control" required maxlength="160"></div>
        <div class="col-md-2"><label class="form-label"><?= et('Edad') ?></label><input name="paciente_edad" class="form-control" maxlength="20"></div>
        <div class="col-md-5"><label class="form-label"><?= et('Servicio') ?></label><select name="servicio" class="form-select"><?php foreach ($SERV as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label"><?= et('Quién llama') ?></label><input name="solicitante" class="form-control" maxlength="160"></div>
        <div class="col-md-2"><label class="form-label"><?= et('Parentesco') ?></label><input name="parentesco" class="form-control" maxlength="60" list="dlParen"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Teléfono') ?></label><input name="telefono" class="form-control" maxlength="40"></div>
        <div class="col-md-3"><label class="form-label"><?= et('Urgencia') ?></label><select name="urgencia" class="form-select"><?php foreach ($URG as $k => $l): ?><option value="<?= $k ?>"><?= et($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label"><?= et('¿Qué está pasando?') ?></label><textarea name="situacion" class="form-control" rows="2"></textarea></div>
        <input type="hidden" name="email" value="">
        <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button></div>
    </form>
</div></div></div>
<datalist id="dlParen"><?php foreach (['Yo mismo(a)', 'Madre', 'Padre', 'Hijo(a)', 'Pareja', 'Hermano(a)', 'Amigo(a)', 'Otro familiar'] as $o): ?><option value="<?= e(t($o)) ?>"><?php endforeach; ?></datalist>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="?" class="btn btn-sm <?= $filtro === '' ? 'btn-primary' : 'btn-light' ?>"><?= et('Abiertas') ?> <span class="badge text-bg-light"><?= $cuenta['nuevo'] + $cuenta['contactado'] + $cuenta['valoracion'] ?></span></a>
    <?php foreach ($EST as $k => [$l, $ic]): ?><a href="?estado=<?= $k ?>" class="btn btn-sm <?= $filtro === $k ? 'btn-primary' : 'btn-light' ?>"><i class="bi <?= $ic ?>"></i> <?= et($l) ?> <span class="badge text-bg-light"><?= $cuenta[$k] ?></span></a><?php endforeach; ?>
</div>

<?php if (!$lista): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
    <?= $filtro ? et('No hay solicitudes en esta etapa.') : et('Sin solicitudes abiertas. Las que lleguen por tu página aparecerán aquí al momento.') ?>
</div></div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($lista as $a): [$elbl, $eic, $ecol] = $EST[$a['estado']] ?? ['—', 'bi-dot', '#64748b']; $wa = admision_wa($a); ?>
    <div class="col-lg-6">
        <div class="card h-100" style="border-left:4px solid <?= $ecol ?> !important"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="min-w-0">
                    <div class="fw-bold text-truncate"><?= e($a['paciente_nombre']) ?><?= $a['paciente_edad'] ? ' <span class="text-muted fw-normal">· ' . e($a['paciente_edad']) . '</span>' : '' ?></div>
                    <div class="small text-muted"><?= $a['solicitante'] && $a['solicitante'] !== $a['paciente_nombre'] ? et('Solicita') . ': ' . e($a['solicitante']) . ($a['parentesco'] ? ' (' . e($a['parentesco']) . ')' : '') . ' · ' : '' ?><?= e($hace($a['creado_en'])) ?> · <?= $a['origen'] === 'web' ? et('desde tu página') : et('captura manual') ?></div>
                </div>
                <div class="text-end flex-shrink-0">
                    <span class="badge" style="background:<?= $ecol ?>"><?= et($elbl) ?></span>
                    <?php if ($a['urgencia'] === 'hoy'): ?><div><span class="badge text-bg-danger mt-1"><i class="bi bi-lightning-fill"></i> <?= et('Urgente') ?></span></div><?php endif; ?>
                </div>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-1">
                <?php if ($a['servicio']): ?><span class="badge text-bg-light border"><?= et($SERV[$a['servicio']] ?? $a['servicio']) ?></span><?php endif; ?>
                <?php if ($a['preferencia']): ?><span class="badge text-bg-light border"><?= et('Prefiere') ?> <?= e(t($a['preferencia'])) ?></span><?php endif; ?>
                <?php if ($a['paciente_id']): ?><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $a['paciente_id'] ?>" class="badge text-bg-success text-decoration-none"><i class="bi bi-person-check"></i> <?= et('Ya es paciente') ?></a><?php endif; ?>
            </div>
            <?php if ($a['situacion']): ?><div class="small mt-2" style="white-space:pre-line"><?= e(mb_strimwidth($a['situacion'], 0, 400, '…')) ?></div><?php endif; ?>
            <?php if ($a['notas']): ?><div class="small text-muted mt-2 border-top pt-2" style="white-space:pre-line;max-height:110px;overflow:auto"><?= e($a['notas']) ?></div><?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp"></i> <?= et('Escribir') ?></a><?php endif; ?>
                <?php if ($a['telefono']): ?><a href="tel:<?= e(preg_replace('/\s+/', '', $a['telefono'])) ?>" class="btn btn-sm btn-light"><i class="bi bi-telephone"></i> <?= e($a['telefono']) ?></a><?php endif; ?>
                <?php if ($a['email']): ?><a href="mailto:<?= e($a['email']) ?>" class="btn btn-sm btn-light"><i class="bi bi-envelope"></i></a><?php endif; ?>
                <div class="dropdown ms-auto">
                    <button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown"><?= et('Siguiente paso') ?></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ([['cita', 'bi-calendar-plus', 'Agendar valoración'], ['internar', 'bi-hospital', 'Ingresar a internamiento'], ['', 'bi-person-plus', $a['paciente_id'] ? 'Abrir su ficha' : 'Crear paciente']] as [$ir, $ic, $lb]):
                            if ($ir === 'internar' && !giro_aplica('hospitalizacion')) continue; ?>
                        <li><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="paciente"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="ir" value="<?= $ir ?>">
                            <button class="dropdown-item"><i class="bi <?= $ic ?> me-2"></i><?= et($lb) ?></button></form></li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ($EST as $k => [$l, $ic]): if ($k === $a['estado']) continue; ?>
                        <li><form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="estado" value="<?= $k ?>">
                            <button class="dropdown-item"><i class="bi <?= $ic ?> me-2"></i><?= et('Mover a') ?> <?= mb_strtolower(t($l)) ?></button></form></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <form method="post" class="d-flex gap-2 mt-2">
                <?= csrf_field() ?><input type="hidden" name="accion" value="nota"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input name="nota" class="form-control form-control-sm" maxlength="1000" placeholder="<?= e(t('Nota del contacto: qué se habló, siguiente paso…')) ?>">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i></button>
            </form>
        </div></div>
    </div>
<?php endforeach; ?>
</div>

<?php if (has_role('admin')): ?>
<div class="card mt-4"><div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
        <div class="fw-semibold"><i class="bi bi-globe2"></i> <?= et('Formulario en tu página: "¿Tienes un ser querido que necesita ayuda?"') ?></div>
        <div class="small text-muted"><?= et('Las solicitudes llegan aquí y te avisamos por correo.') ?><?php if ($urlPublica): ?> <a href="<?= e($urlPublica) ?>" target="_blank" rel="noopener"><?= et('Ver formulario') ?></a> · <button type="button" class="btn btn-link btn-sm p-0 align-baseline" onclick="navigator.clipboard.writeText(<?= e(json_encode($urlPublica)) ?>).then(()=>this.textContent=<?= e(json_encode(t('¡Copiado!'))) ?>)"><?= et('Copiar enlace') ?></button><?php endif; ?></div>
    </div>
    <form method="post" class="form-check form-switch m-0">
        <?= csrf_field() ?><input type="hidden" name="accion" value="web">
        <input class="form-check-input" type="checkbox" role="switch" id="admWeb" name="admision_web" value="1" <?= admision_web_activa() ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label" for="admWeb"><?= et('Mostrar en mi página') ?></label>
    </form>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
