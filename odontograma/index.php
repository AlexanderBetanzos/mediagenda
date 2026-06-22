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

/** Grupo de dientes (un cuadrante). $arco = 'sup' | 'inf' para la forma. */
function cuadrante(array $dientes, array $actual, array $estados, string $arco): string
{
    $h = '<div class="cuadrante">';
    foreach ($dientes as $d) {
        $est = $actual[$d] ?? 'sano';
        [$lbl, $bg, $fg] = $estados[$est];
        $h .= '<button type="button" class="diente diente-' . $arco . ' border" data-diente="' . $d . '" data-estado="' . e($est) . '"'
            . ' style="background:' . $bg . ';color:' . $fg . '" title="' . $d . ' · ' . e($lbl) . '">'
            . '<span class="num">' . $d . '</span></button>';
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
        <div class="odo-wrap mx-auto">
            <div class="d-flex justify-content-between text-muted small px-1 mb-1">
                <span><i class="bi bi-arrow-left"></i> <?= et('Derecha') ?></span>
                <span class="fw-semibold"><?= et('Superior') ?></span>
                <span><?= et('Izquierda') ?> <i class="bi bi-arrow-right"></i></span>
            </div>
            <div class="arcada">
                <?= cuadrante($arribaDer, $actual, $estados, 'sup') ?>
                <div class="midline"></div>
                <?= cuadrante($arribaIzq, $actual, $estados, 'sup') ?>
            </div>
            <div class="arcada mt-2">
                <?= cuadrante($abajoDer, $actual, $estados, 'inf') ?>
                <div class="midline"></div>
                <?= cuadrante($abajoIzq, $actual, $estados, 'inf') ?>
            </div>
            <div class="text-center text-muted small fw-semibold mt-1"><?= et('Inferior') ?></div>
        </div>
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
<style>
.odo-wrap{max-width:640px}
.arcada{display:flex;justify-content:center;align-items:center}
.cuadrante{display:flex;gap:5px}
.midline{width:2px;align-self:stretch;background:#cbd5e1;margin:0 12px;border-radius:2px}
.diente{width:38px;height:46px;display:flex;align-items:center;justify-content:center;
        font-weight:700;font-size:.8rem;padding:0;cursor:pointer;background:#fff;
        box-shadow:0 1px 2px rgba(15,39,71,.08);transition:transform .06s,box-shadow .06s}
.diente:hover{transform:translateY(-2px);box-shadow:0 4px 10px rgba(15,39,71,.18)}
.diente-sup{border-radius:9px 9px 15px 15px}
.diente-inf{border-radius:15px 15px 9px 9px}
.paleta-btn{font-weight:600;border-radius:8px}
.paleta-btn.active{outline:3px solid var(--brand,#0b6fb8);outline-offset:2px;box-shadow:0 2px 8px rgba(0,0,0,.18)}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
