<?php
/**
 * Sala de espera / flujo del día: check-in, pasar a consulta y finalizar,
 * con tiempos de espera. Muestra las citas de HOY.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');

$tid = tenant_id();
$hoy = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $cid    = (int) ($_POST['cita_id'] ?? 0);
    $accion = $_POST['accion'] ?? '';
    if ($cid && pertenece_al_tenant('citas', $cid)) {
        $map = [
            'checkin'   => ['esperando',   'checkin_en = NOW()'],
            'consulta'  => ['en_consulta', 'atencion_en = NOW()'],
            'finalizar' => ['atendida',    null],
            'no_asistio'=> ['no_asistio',  null],
        ];
        if (isset($map[$accion])) {
            [$estado, $extra] = $map[$accion];
            $sql = 'UPDATE citas SET estado = ?' . ($extra ? ", $extra" : '') . ' WHERE id = ? AND consultorio_id = ?';
            db()->prepare($sql)->execute([$estado, $cid, $tid]);
            auditar('sala_' . $accion, 'cita', $cid);
        }
    }
    redirect('/citas/sala');
}

$st = db()->prepare(
    "SELECT c.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.foto AS pac_foto, u.nombre AS med_nombre
     FROM citas c
     JOIN pacientes p ON p.id = c.paciente_id
     JOIN usuarios  u ON u.id = c.medico_id
     WHERE c.consultorio_id = ? AND c.fecha = ?
     ORDER BY c.hora"
);
$st->execute([$tid, $hoy]);
$citas = $st->fetchAll();

// Agrupa por etapa del flujo.
$porLlegar = $espera = $enConsulta = $terminadas = [];
$sumaEspera = $nEspera = 0;
foreach ($citas as $c) {
    switch ($c['estado']) {
        case 'programada': case 'confirmada': $porLlegar[] = $c; break;
        case 'esperando':   $espera[] = $c; break;
        case 'en_consulta': $enConsulta[] = $c; break;
        case 'atendida':    $terminadas[] = $c;
            if ($c['checkin_en'] && $c['atencion_en']) {
                $sumaEspera += (strtotime($c['atencion_en']) - strtotime($c['checkin_en'])); $nEspera++;
            }
            break;
    }
}
$promEspera = $nEspera ? round($sumaEspera / $nEspera / 60) : null;

/** Minutos transcurridos desde un timestamp. */
function mins_desde(?string $ts): int { return $ts ? max(0, (int) round((time() - strtotime($ts)) / 60)) : 0; }

function btn(string $accion, int $cid, string $clase, string $icono, string $txt): string
{
    return '<form method="post" class="d-inline">' . csrf_field()
        . '<input type="hidden" name="cita_id" value="' . $cid . '">'
        . '<input type="hidden" name="accion" value="' . $accion . '">'
        . '<button class="btn btn-sm ' . $clase . '"><i class="bi ' . $icono . '"></i> ' . $txt . '</button></form>';
}

$titulo = t('Sala de espera');
$activo = 'citas';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-hourglass-split text-brand"></i> <?= et('Sala de espera') ?> <span class="text-muted fs-6">· <?= fmt_fecha($hoy) ?></span></h1>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($promEspera !== null): ?><span class="badge bg-light text-dark border"><?= et('Espera promedio hoy:') ?> <strong><?= $promEspera ?> min</strong></span><?php endif; ?>
        <a href="<?= BASE_URL ?>/citas/calendario" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calendar3"></i> <?= et('Calendario') ?></a>
    </div>
</div>

<div class="row g-3">
    <!-- Por llegar -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock text-secondary"></i> <?= et('Por llegar') ?> (<?= count($porLlegar) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$porLlegar): ?><li class="list-group-item text-muted text-center py-3">—</li>
                <?php else: foreach ($porLlegar as $c): ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between"><span class="fw-semibold"><?= fmt_hora($c['hora']) ?></span><span class="badge bg-<?= estado_badge($c['estado']) ?>"><?= estado_label($c['estado']) ?></span></div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <?= avatar_paciente((int) $c['paciente_id'], $c['pac_nombre'], $c['pac_ape'], $c['pac_foto'] ?? null, 34) ?>
                        <div class="min-w-0">
                            <div class="small text-truncate"><?= e($c['pac_nombre'].' '.$c['pac_ape']) ?></div>
                            <div class="small text-muted text-truncate"><?= e($c['med_nombre']) ?></div>
                        </div>
                    </div>
                    <?= btn('checkin', $c['id'], 'btn-warning', 'bi-box-arrow-in-right', t('Check-in')) ?>
                    <?= btn('no_asistio', $c['id'], 'btn-outline-danger', 'bi-x', t('No asistió')) ?>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
    <!-- En espera -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-people text-warning"></i> <?= et('En espera') ?> (<?= count($espera) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$espera): ?><li class="list-group-item text-muted text-center py-3">—</li>
                <?php else: foreach ($espera as $c): $m = mins_desde($c['checkin_en']); ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between">
                        <span class="fw-semibold"><?= fmt_hora($c['hora']) ?></span>
                        <span class="badge bg-<?= $m >= 30 ? 'danger' : 'warning' ?> text-dark"><?= et('esperando') ?> <?= $m ?> min</span>
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <?= avatar_paciente((int) $c['paciente_id'], $c['pac_nombre'], $c['pac_ape'], $c['pac_foto'] ?? null, 34) ?>
                        <div class="min-w-0">
                            <div class="small text-truncate"><?= e($c['pac_nombre'].' '.$c['pac_ape']) ?></div>
                            <div class="small text-muted text-truncate"><?= e($c['med_nombre']) ?></div>
                        </div>
                    </div>
                    <?= btn('consulta', $c['id'], 'btn-primary', 'bi-door-open', t('Pasar a consulta')) ?>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
    <!-- En consulta -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-pulse text-primary"></i> <?= et('En consulta') ?> (<?= count($enConsulta) ?>)</div>
            <ul class="list-group list-group-flush">
                <?php if (!$enConsulta): ?><li class="list-group-item text-muted text-center py-3">—</li>
                <?php else: foreach ($enConsulta as $c): $m = mins_desde($c['atencion_en']); ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between"><span class="fw-semibold"><?= fmt_hora($c['hora']) ?></span><span class="badge bg-primary"><?= et('en consulta') ?> <?= $m ?> min</span></div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <?= avatar_paciente((int) $c['paciente_id'], $c['pac_nombre'], $c['pac_ape'], $c['pac_foto'] ?? null, 34) ?>
                        <div class="min-w-0">
                            <div class="small text-truncate"><?= e($c['pac_nombre'].' '.$c['pac_ape']) ?></div>
                            <div class="small text-muted text-truncate"><?= e($c['med_nombre']) ?></div>
                        </div>
                    </div>
                    <?= btn('finalizar', $c['id'], 'btn-success', 'bi-check2-circle', t('Finalizar')) ?>
                    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $c['paciente_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-folder2-open"></i> <?= et('Expediente') ?></a>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div>
    </div>
</div>

<?php if ($terminadas): ?>
<div class="card mt-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-check2-all text-success"></i> <?= et('Atendidas hoy') ?> (<?= count($terminadas) ?>)</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <?php foreach ($terminadas as $c): ?>
            <span class="badge bg-light text-dark border"><?= fmt_hora($c['hora']) ?> · <?= e($c['pac_nombre'].' '.$c['pac_ape']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
