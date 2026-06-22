<?php
/**
 * Odontograma interactivo (odontología). Pinta el estado de cada diente
 * (notación FDI) y lo guarda como JSON por paciente.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
if (!has_role('medico', 'admin')) { http_response_code(403); die('Solo médico o admin.'); }

$pid = (int) ($_GET['paciente_id'] ?? $_POST['paciente_id'] ?? 0);
$pac = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$pac->execute([$pid, tenant_id()]);
$pac = $pac->fetch();
if (!$pac) { http_response_code(404); die('Paciente no encontrado.'); }

// Estado => [etiqueta, color de fondo, color de texto]
$estados = [
    'sano'       => ['Sano',        '#ffffff', '#1f2d3d'],
    'caries'     => ['Caries',      '#dc3545', '#ffffff'],
    'obturado'   => ['Obturado',    '#0d6efd', '#ffffff'],
    'corona'     => ['Corona',      '#ffc107', '#1f2d3d'],
    'endodoncia' => ['Endodoncia',  '#6f42c1', '#ffffff'],
    'ausente'    => ['Ausente',     '#adb5bd', '#ffffff'],
    'extraccion' => ['Extracción',  '#212529', '#ffffff'],
];
$arribaDer = [18,17,16,15,14,13,12,11]; $arribaIzq = [21,22,23,24,25,26,27,28];
$abajoDer  = [48,47,46,45,44,43,42,41]; $abajoIzq  = [38,37,36,35,34,33,32,31];
$dientesValidos = array_merge($arribaDer, $arribaIzq, $abajoDer, $abajoIzq);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $datos = json_decode($_POST['datos'] ?? '[]', true);
    $limpio = [];
    if (is_array($datos)) {
        foreach ($datos as $diente => $estado) {
            if (in_array((int) $diente, $dientesValidos, true) && isset($estados[$estado]) && $estado !== 'sano') {
                $limpio[(int) $diente] = $estado;
            }
        }
    }
    db()->prepare(
        'INSERT INTO odontogramas (consultorio_id, paciente_id, datos, actualizado_por) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE datos = VALUES(datos), actualizado_por = VALUES(actualizado_por)'
    )->execute([tenant_id(), $pid, json_encode($limpio, JSON_UNESCAPED_UNICODE), current_user()['id']]);
    auditar('odontograma', 'paciente', $pid);
    flash(t('Odontograma guardado.'));
    redirect('/odontograma/index?paciente_id=' . $pid);
}

$row = db()->prepare('SELECT datos FROM odontogramas WHERE paciente_id = ? AND consultorio_id = ?');
$row->execute([$pid, tenant_id()]);
$actual = ($r = $row->fetchColumn()) ? (json_decode($r, true) ?: []) : [];

/** Pinta una fila de dientes. */
function fila_dientes(array $dientes, array $actual, array $estados): string
{
    $h = '<div class="d-flex justify-content-center flex-wrap gap-1 mb-1">';
    foreach ($dientes as $d) {
        $est = $actual[$d] ?? 'sano';
        [$lbl, $bg, $fg] = $estados[$est];
        $h .= '<button type="button" class="diente btn btn-sm border" data-diente="' . $d . '" data-estado="' . e($est) . '"'
            . ' style="width:40px;background:' . $bg . ';color:' . $fg . '" title="' . $d . ' · ' . e($lbl) . '">' . $d . '</button>';
    }
    return $h . '</div>';
}

$titulo = t('Odontograma') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>"><?= e($pac['nombre'].' '.$pac['apellidos']) ?></a></li>
    <li class="breadcrumb-item active"><?= et('Odontograma') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-emoji-smile text-brand"></i> <?= et('Odontograma') ?></h1>

<form method="post" id="odoForm">
    <?= csrf_field() ?>
    <input type="hidden" name="paciente_id" value="<?= $pid ?>">
    <input type="hidden" name="datos" id="datosInput">

    <!-- Paleta -->
    <div class="card mb-3"><div class="card-body">
        <div class="small text-muted mb-2"><?= et('Elige un estado y haz clic en los dientes para marcarlos.') ?></div>
        <div class="d-flex flex-wrap gap-2" id="paleta">
            <?php foreach ($estados as $k => [$lbl, $bg, $fg]): ?>
            <button type="button" class="btn btn-sm border paleta-btn <?= $k === 'caries' ? 'active' : '' ?>" data-estado="<?= e($k) ?>"
                    style="background:<?= $bg ?>;color:<?= $fg ?>"><?= et($lbl) ?></button>
            <?php endforeach; ?>
        </div>
    </div></div>

    <!-- Arcadas -->
    <div class="card mb-3"><div class="card-body">
        <?= fila_dientes($arribaDer, $actual, $estados) . fila_dientes($arribaIzq, $actual, $estados) ?>
        <hr class="my-2">
        <?= fila_dientes($abajoDer, $actual, $estados) . fila_dientes($abajoIzq, $actual, $estados) ?>
        <div class="text-end mt-3">
            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $pid ?>" class="btn btn-light"><?= et('Cancelar') ?></a>
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
        </div>
    </div></div>
</form>

<script>
(function () {
    var estados = <?= json_encode(array_map(fn($v) => ['bg'=>$v[1],'fg'=>$v[2]], $estados), JSON_UNESCAPED_UNICODE) ?>;
    var activo = 'caries';
    var datos = <?= json_encode((object) $actual, JSON_UNESCAPED_UNICODE) ?>;

    document.querySelectorAll('.paleta-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            activo = b.dataset.estado;
            document.querySelectorAll('.paleta-btn').forEach(x => x.classList.remove('active'));
            b.classList.add('active');
        });
    });

    document.querySelectorAll('.diente').forEach(function (t) {
        t.addEventListener('click', function () {
            var d = t.dataset.diente;
            if (activo === 'sano') { delete datos[d]; } else { datos[d] = activo; }
            t.style.background = estados[activo].bg;
            t.style.color = estados[activo].fg;
        });
    });

    document.getElementById('odoForm').addEventListener('submit', function () {
        document.getElementById('datosInput').value = JSON.stringify(datos);
    });
})();
</script>
<style>.paleta-btn.active{outline:3px solid var(--brand,#0b6fb8);outline-offset:1px}.diente{font-weight:600}</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
