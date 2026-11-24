<?php
/**
 * Verificación pública de una receta: es la página que abre el QR impreso y
 * el enlace que se manda por WhatsApp.
 *
 * PÁGINA PÚBLICA: sin sesión. El token (128 bits, aleatorio, en la propia
 * receta) es la credencial, igual que en agenda/confirmar. Quien la abre ya
 * tiene la receta en la mano o en su chat, así que se muestra completa; lo que
 * agrega es la confirmación de que el consultorio la emitió y sigue vigente
 * en su sistema (si el consultorio la elimina, el enlace deja de validarla).
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/comprobante.php';
asegurar_recetas();
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
$r = null;
if (strlen($token) === 32) {
    $st = db()->prepare(
        'SELECT r.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.fecha_nacimiento,
                u.nombre AS med_nombre, u.especialidad
         FROM recetas r
         JOIN pacientes p ON p.id = r.paciente_id
         JOIN usuarios  u ON u.id = r.medico_id
         WHERE r.token = ?'
    );
    $st->execute([$token]);
    $r = $st->fetch() ?: null;
}

if (!$r) {
    http_response_code(404);
    $titulo = t('Receta no encontrada');
    include __DIR__ . '/includes/publico_header.php'; ?>
<div class="card pub-card"><div class="card-body p-4 p-sm-5 text-center" style="max-width:460px;margin:0 auto">
    <span class="d-inline-flex align-items-center justify-content-center" style="width:64px;height:64px;border-radius:50%;background:rgba(220,38,38,.12)">
        <i class="bi bi-x-lg text-danger" style="font-size:2rem"></i></span>
    <h1 class="h4 fw-bold mt-3 mb-2"><?= et('No pudimos validar esta receta') ?></h1>
    <p class="text-muted mb-0"><?= et('El enlace no es válido o el consultorio ya no tiene esta receta en su sistema. Si la estás surtiendo, confírmala directamente con el médico que la firmó.') ?></p>
</div></div>
<?php include __DIR__ . '/includes/publico_footer.php';
    exit;
}

// El tenant sale de la receta: membrete, logo y color del consultorio correcto.
tenant_forzar((int) $r['consultorio_id']);

$items = db()->prepare('SELECT * FROM receta_items WHERE receta_id = ? ORDER BY id');
$items->execute([(int) $r['id']]);
$items = $items->fetchAll();

$cedula = '';
try {
    $q = db()->prepare('SELECT cedula FROM usuarios WHERE id = ?');
    $q->execute([(int) $r['medico_id']]);
    $cedula = (string) $q->fetchColumn();
} catch (Throwable $e) {}

$dias = max(0, (int) floor((time() - strtotime((string) $r['fecha'])) / 86400));

$titulo = t('Receta') . ' ' . receta_folio((int) $r['id']);
include __DIR__ . '/includes/publico_header.php';
?>
<?= comprobante_estilos() ?>
<style>
.rx-ok-card { max-width: 820px; margin: 0 auto 1.25rem; border-radius: 18px; padding: 1.1rem 1.25rem; background: #ecfdf3;
              border: 1px solid #a7f3d0; display: flex; gap: .9rem; align-items: flex-start; }
.rx-ok-card .ic { width: 44px; height: 44px; border-radius: 50%; background: #16a34a; color: #fff; display: inline-flex;
                  align-items: center; justify-content: center; font-size: 1.35rem; flex: none; }
.rx-ok-card dl { display: grid; grid-template-columns: auto 1fr; gap: .15rem .8rem; margin: .5rem 0 0; font-size: .9rem; }
.rx-ok-card dt { color: #64748b; font-weight: 500; }
.rx-ok-card dd { margin: 0; color: #0f172a; }
@media print { .rx-ok-card, .no-print { display: none !important; } }
</style>
<div class="rx-ok-card">
    <span class="ic"><i class="bi bi-patch-check-fill"></i></span>
    <div class="flex-grow-1">
        <div class="fw-bold" style="color:#166534"><?= et('Receta registrada en el sistema del consultorio') ?></div>
        <div class="small text-muted"><?= e(marca_nombre()) ?> <?= et('la emitió y la conserva en su expediente.') ?></div>
        <dl>
            <dt><?= et('Folio') ?></dt><dd><?= e(receta_folio((int) $r['id'])) ?></dd>
            <dt><?= et('Emitida') ?></dt><dd><?= e(fmt_fecha($r['fecha'])) ?> · <?= $dias === 0 ? et('hoy') : e(sprintf(t('hace %d días'), $dias)) ?></dd>
            <dt><?= et('Médico') ?></dt><dd><?= e($r['med_nombre']) ?><?= $r['especialidad'] ? ' · ' . e($r['especialidad']) : '' ?></dd>
            <?php if ($cedula !== ''): ?>
            <dt><?= et('Cédula') ?></dt><dd><?= e($cedula) ?> · <a href="https://cedulaprofesional.sep.gob.mx/" target="_blank" rel="noopener"><?= et('consultar en la SEP') ?></a></dd>
            <?php endif; ?>
        </dl>
    </div>
</div>
<div class="d-flex justify-content-end mb-3 no-print" style="max-width:820px;margin:0 auto">
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> <?= et('Imprimir / Guardar PDF') ?></button>
</div>
<?= receta_documento($r, $items, false) ?>
<p class="text-center small text-muted mt-3 no-print" style="max-width:820px;margin:0 auto">
    <?= et('Esta verificación confirma que la receta existe en el sistema del consultorio. La validez para surtirla la determina la farmacia conforme a la normativa aplicable.') ?>
</p>
<?php include __DIR__ . '/includes/publico_footer.php'; ?>
