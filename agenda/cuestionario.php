<?php
/**
 * Cuestionario previo a la consulta (includes/cuestionarios.php).
 *
 * PÁGINA PÚBLICA: sin sesión. El token de la cita es la credencial, igual que
 * en /agenda/confirmar. El paciente puede corregir sus respuestas hasta la
 * hora de su cita; después queda como lo dejó.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cuestionarios.php';

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? $_POST['t'] ?? ''));
if (strlen($token) !== 32) { http_response_code(404); die('Enlace no válido.'); }

$st = db()->prepare('SELECT c.*, p.nombre AS pac_nombre, u.nombre AS med_nombre
                     FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id
                     WHERE c.token = ?');
$st->execute([$token]);
$c = $st->fetch();
if (!$c) { http_response_code(404); die('Esta cita ya no existe.'); }
tenant_forzar((int) $c['consultorio_id']);
asegurar_cuestionarios();

$resp    = cuest_respuesta_de_cita((int) $c['id']);
$abierta = in_array($c['estado'], ['programada', 'confirmada'], true) && strtotime($c['fecha'] . ' ' . $c['hora']) > time();

// Si ya respondió, se muestra SU cuestionario (aunque el consultorio lo haya cambiado después).
if ($resp) {
    $q = ['nombre' => $resp['nombre'], 'descripcion' => '', 'id' => (int) $resp['cuestionario_id'], 'preguntas' => $resp['respuestas']];
    $prev = [];
    foreach ($resp['respuestas'] as $a) $prev[$a['id']] = $a['valor'] ?? '';
} else {
    $q = cuest_para_cita($c);
    $prev = [];
}
if (!$q) { http_response_code(404); die('Esta cita no tiene cuestionario pendiente.'); }

$errores = []; $guardado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta) {
    $in = (array) ($_POST['r'] ?? []);
    $snap = [];
    foreach ($q['preguntas'] as $p) {
        $v = $in[$p['id']] ?? '';
        if ($p['tipo'] === 'multiple') {
            $v = array_values(array_filter(array_map(fn ($x) => trim(mb_substr((string) $x, 0, 120)), (array) $v), fn ($x) => in_array($x, $p['opciones'] ?? [], true)));
        } else {
            $v = trim(mb_substr(is_array($v) ? '' : (string) $v, 0, 3000));
            if ($p['tipo'] === 'opcion' && $v !== '' && !in_array($v, $p['opciones'] ?? [], true)) $v = '';
            if ($p['tipo'] === 'sino' && !in_array($v, ['Sí', 'No'], true)) $v = '';
            if ($p['tipo'] === 'escala' && $v !== '') $v = (string) max(0, min(10, (int) $v));
            if ($p['tipo'] === 'numero' && $v !== '' && !is_numeric($v)) $v = '';
            if ($p['tipo'] === 'fecha' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $v = '';
        }
        if (!empty($p['requerida']) && ($v === '' || $v === [])) $errores[] = $p['texto'];
        $prev[$p['id']] = $v;
        $snap[] = ['id' => $p['id'], 'texto' => $p['texto'], 'tipo' => $p['tipo'], 'opciones' => $p['opciones'] ?? [],
                   'ayuda' => $p['ayuda'] ?? '', 'requerida' => !empty($p['requerida']), 'campo' => $p['campo'] ?? '', 'valor' => $v];
    }
    if (!$errores) {
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
        if ($resp) {
            db()->prepare('UPDATE cuestionario_respuestas SET respuestas = ?, respondido_en = NOW(), revisado_en = NULL, revisado_por = NULL WHERE id = ? AND consultorio_id = ?')
                ->execute([$json, (int) $resp['id'], (int) $c['consultorio_id']]);
        } else {
            db()->prepare('INSERT INTO cuestionario_respuestas (consultorio_id, cuestionario_id, cita_id, paciente_id, nombre, respuestas, respondido_en)
                           VALUES (?,?,?,?,?,?,NOW())')
                ->execute([(int) $c['consultorio_id'], (int) $q['id'], (int) $c['id'], (int) $c['paciente_id'], $q['nombre'], $json]);
        }
        auditar('cuestionario_respondido', 'cita', (int) $c['id'], $q['nombre'], (int) $c['consultorio_id']);
        $guardado = true;
        $resp = cuest_respuesta_de_cita((int) $c['id']);
    }
}

$titulo = t('Cuestionario');
include __DIR__ . '/../includes/publico_header.php';
$val = fn ($id) => $prev[$id] ?? '';
?>
<div class="card pub-card">
    <div class="card-body p-4 p-sm-5">
        <div style="max-width:560px;margin:0 auto">
        <?php if ($guardado): ?>
            <div class="text-center">
                <span class="d-inline-flex align-items-center justify-content-center" style="width:64px;height:64px;border-radius:50%;background:#e7f7ee">
                    <i class="bi bi-check-lg text-success" style="font-size:2rem"></i></span>
                <h1 class="h4 fw-bold mt-3 mb-1"><?= et('¡Gracias! Recibimos tus respuestas') ?></h1>
                <p class="text-muted small mb-4"><?= et('Tu médico las revisará antes de tu consulta. Puedes corregirlas hasta la hora de tu cita.') ?></p>
                <a href="<?= BASE_URL ?>/agenda/confirmar?t=<?= e($token) ?>" class="btn btn-primary"><i class="bi bi-calendar-check"></i> <?= et('Ver mi cita') ?></a>
                <div><a href="?t=<?= e($token) ?>" class="d-inline-block mt-3 small"><?= et('Revisar mis respuestas') ?></a></div>
            </div>
        <?php else: ?>
            <div class="text-center mb-4">
                <i class="bi bi-clipboard2-pulse text-brand" style="font-size:2.2rem"></i>
                <h1 class="h4 fw-bold mt-2 mb-1"><?= e($q['nombre']) ?></h1>
                <p class="text-muted small mb-0">
                    <?= et('Hola') ?>, <?= e($c['pac_nombre']) ?>. <?= et('Tu cita') ?>: <b class="text-capitalize"><?= e(fmt_fecha($c['fecha'])) ?></b> <?= et('a las') ?> <b><?= fmt_hora($c['hora']) ?></b><?= $c['med_nombre'] ? ' · ' . e($c['med_nombre']) : '' ?>.
                </p>
                <?php if (!empty($q['descripcion'])): ?><p class="small mt-2 mb-0"><?= e($q['descripcion']) ?></p><?php endif; ?>
            </div>

            <?php if (!$abierta): ?>
                <div class="alert alert-light border small text-center"><?= $resp ? et('Estas son las respuestas que enviaste. Tu cita ya pasó o se canceló, así que ya no se pueden cambiar.') : et('Tu cita ya pasó o se canceló.') ?></div>
            <?php elseif ($resp): ?>
                <div class="alert alert-success small text-center py-2"><i class="bi bi-check-circle"></i> <?= et('Ya respondiste. Si quieres, corrige y vuelve a enviar.') ?></div>
            <?php endif; ?>
            <?php if ($errores): ?>
                <div class="alert alert-danger small"><?= et('Falta responder') ?>: <?= e(implode(' · ', $errores)) ?></div>
            <?php endif; ?>

            <form method="post" class="cuest-form">
                <input type="hidden" name="t" value="<?= e($token) ?>">
                <fieldset <?= $abierta ? '' : 'disabled' ?>>
                <?php foreach ($q['preguntas'] as $i => $p): $nm = 'r[' . e($p['id']) . ']'; $v = $val($p['id']); $req = !empty($p['requerida']); ?>
                <div class="mb-4">
                    <label class="form-label fw-semibold"><?= ($i + 1) ?>. <?= e($p['texto']) ?><?= $req ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <?php if (!empty($p['ayuda'])): ?><div class="form-text mt-0 mb-2"><?= e($p['ayuda']) ?></div><?php endif; ?>
                    <?php switch ($p['tipo']):
                        case 'parrafo': ?>
                        <textarea name="<?= $nm ?>" class="form-control" rows="3" <?= $req ? 'required' : '' ?>><?= e((string) $v) ?></textarea>
                        <?php break; case 'sino': ?>
                        <div class="d-flex gap-2">
                            <?php foreach (['Sí', 'No'] as $o): $oid = 'q' . e($p['id']) . md5($o); ?>
                            <input type="radio" class="btn-check" name="<?= $nm ?>" id="<?= $oid ?>" value="<?= e($o) ?>" <?= $v === $o ? 'checked' : '' ?> <?= $req ? 'required' : '' ?>>
                            <label class="btn btn-outline-primary flex-fill" for="<?= $oid ?>"><?= e(t($o)) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?php break; case 'opcion': ?>
                        <?php foreach ($p['opciones'] as $o): $oid = 'q' . e($p['id']) . md5($o); ?>
                        <div class="form-check"><input class="form-check-input" type="radio" name="<?= $nm ?>" id="<?= $oid ?>" value="<?= e($o) ?>" <?= $v === $o ? 'checked' : '' ?> <?= $req ? 'required' : '' ?>>
                            <label class="form-check-label" for="<?= $oid ?>"><?= e($o) ?></label></div>
                        <?php endforeach; ?>
                        <?php break; case 'multiple': $vs = (array) $v; ?>
                        <?php foreach ($p['opciones'] as $o): $oid = 'q' . e($p['id']) . md5($o); ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="<?= $nm ?>[]" id="<?= $oid ?>" value="<?= e($o) ?>" <?= in_array($o, $vs, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="<?= $oid ?>"><?= e($o) ?></label></div>
                        <?php endforeach; ?>
                        <?php break; case 'escala': ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php for ($n = 0; $n <= 10; $n++): $oid = 'q' . e($p['id']) . 'n' . $n; ?>
                            <input type="radio" class="btn-check" name="<?= $nm ?>" id="<?= $oid ?>" value="<?= $n ?>" <?= (string) $v === (string) $n ? 'checked' : '' ?> <?= $req ? 'required' : '' ?>>
                            <label class="btn btn-sm btn-outline-primary" style="min-width:2.4rem" for="<?= $oid ?>"><?= $n ?></label>
                            <?php endfor; ?>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mt-1"><span><?= et('Nada') ?></span><span><?= et('Muchísimo') ?></span></div>
                        <?php break; case 'numero': ?>
                        <input type="number" step="any" name="<?= $nm ?>" class="form-control" style="max-width:12rem" value="<?= e((string) $v) ?>" <?= $req ? 'required' : '' ?>>
                        <?php break; case 'fecha': ?>
                        <input type="date" name="<?= $nm ?>" class="form-control" style="max-width:14rem" value="<?= e((string) $v) ?>" <?= $req ? 'required' : '' ?>>
                        <?php break; default: ?>
                        <input type="text" name="<?= $nm ?>" class="form-control" maxlength="300" value="<?= e((string) $v) ?>" <?= $req ? 'required' : '' ?>>
                    <?php endswitch; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($abierta): ?>
                <button class="btn btn-primary btn-lg w-100 py-3 fw-semibold"><i class="bi bi-send"></i> <?= $resp ? et('Guardar cambios') : et('Enviar mis respuestas') ?></button>
                <p class="small text-muted text-center mt-3 mb-0"><i class="bi bi-shield-lock"></i> <?= et('Tus respuestas solo las ve el personal de tu consultorio y forman parte de tu expediente clínico.') ?></p>
                <?php endif; ?>
                </fieldset>
            </form>
        <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
