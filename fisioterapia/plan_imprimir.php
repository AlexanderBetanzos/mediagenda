<?php
/**
 * Hoja del plan de ejercicios para el paciente: cada ejercicio con su imagen
 * (o miniatura del video), instrucciones y dosificación, y una cuadrícula
 * semanal para que el paciente palomee los días que lo hizo.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('especialidades');
ensure_fisio_tables();

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT pl.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento, u.nombre AS fisio_nombre, u.cedula
                     FROM fisio_planes pl JOIN pacientes p ON p.id = pl.paciente_id LEFT JOIN usuarios u ON u.id = pl.creado_por
                     WHERE pl.id = ? AND pl.consultorio_id = ?');
$st->execute([$id, tenant_id()]);
$pl = $st->fetch();
if (!$pl) { http_response_code(404); die('Plan no encontrado.'); }
$items = fisio_plan_items($id);
$semanas = max(1, min(8, (int) ($pl['semanas'] ?: 2)));
$dias = [t('L'), t('M'), t('Mi'), t('J'), t('V'), t('S'), t('D')];

$hojaTitulo = $pl['nombre'];
$hojaDoc    = t('Plan de ejercicios en casa');
$hojaFecha  = $pl['fecha'];
$hojaVolver = BASE_URL . '/fisioterapia/index?paciente_id=' . (int) $pl['paciente_id'] . '&tab=planes';
include __DIR__ . '/_hoja.php';
?>
<style>
.ej{display:flex;gap:1rem;border:1px solid #e2e8f0;border-radius:.6rem;padding:.75rem;margin-bottom:.6rem}
.ej .n{width:1.8rem;height:1.8rem;border-radius:50%;background:<?= $acento ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none}
.ej img{width:150px;height:110px;object-fit:cover;border-radius:.4rem;flex:none}
.dosis{display:inline-block;background:#f1f5f9;border-radius:99px;padding:.1rem .6rem;font-weight:600;font-size:.82rem;margin:.15rem .25rem .15rem 0}
.chk td,.chk th{text-align:center;padding:.25rem;font-size:.75rem;border:1px solid #cbd5e1}
.chk td{height:1.4rem}
</style>
<div class="row mb-2 kv">
    <div class="col-7"><b><?= et('Paciente') ?>:</b> <?= e(trim($pl['pac_nombre'] . ' ' . $pl['pac_ape'])) ?></div>
    <div class="col-5 text-end"><?php if ($pl['frecuencia']): ?><b><?= et('Frecuencia') ?>:</b> <?= e($pl['frecuencia']) ?><?php endif; ?><?php if ($pl['semanas']): ?> · <?= (int) $pl['semanas'] ?> <?= et('semanas') ?><?php endif; ?></div>
</div>
<h2 class="h5 mb-1"><?= e($pl['nombre']) ?></h2>
<?php if ($pl['objetivo']): ?><div class="text-muted mb-2"><?= et('Objetivo') ?>: <?= e($pl['objetivo']) ?></div><?php endif; ?>
<?php if ($pl['indicaciones']): ?><div class="border rounded p-2 small mb-3 narrativa" style="background:#f8fafc"><i class="bi bi-info-circle acento"></i> <?= e($pl['indicaciones']) ?></div><?php endif; ?>

<?php foreach ($items as $i => $it): $yt = fisio_youtube_id($it['video_url']); $img = $it['imagen_url'] ?: ($yt ? 'https://img.youtube.com/vi/' . $yt . '/hqdefault.jpg' : ''); ?>
<div class="ej avoid">
    <div class="n"><?= $i + 1 ?></div>
    <div class="flex-grow-1">
        <div class="fw-bold"><?= e($it['nombre']) ?></div>
        <div>
            <?php if ($it['series'] && $it['reps']): ?><span class="dosis"><?= (int) $it['series'] ?> <?= et('series') ?> × <?= e($it['reps']) ?></span>
            <?php elseif ($it['series']): ?><span class="dosis"><?= (int) $it['series'] ?> <?= et('series') ?></span>
            <?php elseif ($it['reps']): ?><span class="dosis"><?= e($it['reps']) ?> <?= et('repeticiones') ?></span><?php endif; ?>
            <?php if ($it['segundos']): $s = (int) $it['segundos']; ?><span class="dosis"><?= $s >= 120 ? round($s / 60) . ' min' : $s . ' ' . et('seg') ?></span><?php endif; ?>
            <?php if ($it['carga']): ?><span class="dosis"><?= e($it['carga']) ?></span><?php endif; ?>
            <?php if ($it['descanso']): ?><span class="dosis"><?= et('Descanso') ?> <?= e($it['descanso']) ?></span><?php endif; ?>
            <?php if ($it['lado']): ?><span class="dosis"><?= e($it['lado']) ?></span><?php endif; ?>
        </div>
        <?php if ($it['instrucciones']): ?><div class="small mt-1 narrativa"><?= e($it['instrucciones']) ?></div><?php endif; ?>
        <?php if ($it['notas']): ?><div class="small mt-1 text-muted"><i class="bi bi-arrow-up-right"></i> <?= e($it['notas']) ?></div><?php endif; ?>
        <?php if ($it['video_url']): ?><div class="small mt-1"><i class="bi bi-play-btn acento"></i> <?= et('Video:') ?> <a href="<?= e($it['video_url']) ?>"><?= e($it['video_url']) ?></a></div><?php endif; ?>
    </div>
    <?php if ($img): ?><img src="<?= e($img) ?>" alt=""><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="avoid">
<div class="seccion"><?= et('Registro: marca cada día que hiciste tu rutina') ?></div>
<table class="chk w-100"><thead><tr><th style="width:18%"><?= et('Semana') ?></th><?php foreach ($dias as $d): ?><th><?= e($d) ?></th><?php endforeach; ?><th style="width:24%"><?= et('Dolor / comentarios') ?></th></tr></thead>
<tbody><?php for ($w = 1; $w <= $semanas; $w++): ?><tr><th><?= $w ?></th><?php foreach ($dias as $d): ?><td></td><?php endforeach; ?><td></td></tr><?php endfor; ?></tbody></table>
<?php if ($pl['portal'] && modulo_activo('portal')): ?>
<div class="small text-muted mt-2"><i class="bi bi-phone"></i> <?= et('También puedes ver los videos y marcar tus días en tu portal:') ?> <b><?= e(url_absoluta('/portal/ejercicios')) ?></b></div>
<?php endif; ?>
<div class="small mt-2"><i class="bi bi-exclamation-triangle text-warning"></i> <?= et('Si un ejercicio te provoca dolor fuerte, mareo o adormecimiento, suspéndelo y avísanos.') ?></div>
<div class="row mt-2"><div class="col-6 offset-6 text-center"><div class="firma"><?= e($pl['fisio_nombre'] ?? '') ?><?php if (!empty($pl['cedula'])): ?><br><?= et('Céd. Prof.') ?> <?= e($pl['cedula']) ?><?php endif; ?></div></div></div>
</div>
</div>
</body>
</html>
