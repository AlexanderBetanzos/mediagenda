<?php
/**
 * El paciente deja su reseña.
 *
 * PÁGINA PÚBLICA: sin sesión. El token de la reseña es la credencial, igual
 * que en agenda/confirmar.php y en la videoconsulta. Pedirle una cuenta a
 * alguien para que califique es la forma más segura de no recibir reseñas.
 *
 * El token identifica la reseña Y el consultorio: el tenant sale de ahí.
 */
require_once __DIR__ . '/../includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? $_POST['t'] ?? ''));
if (strlen($token) !== 32) { http_response_code(404); die('Enlace no válido.'); }

$st = db()->prepare(
    'SELECT r.*, p.nombre AS pac_nombre, u.nombre AS med_nombre, c.fecha AS cita_fecha
     FROM resenas r
     JOIN pacientes p ON p.id = r.paciente_id
     LEFT JOIN usuarios u ON u.id = r.medico_id
     LEFT JOIN citas c    ON c.id = r.cita_id
     WHERE r.token = ?'
);
$st->execute([$token]);
$r = $st->fetch();
if (!$r) { http_response_code(404); die('Esta reseña ya no existe.'); }

tenant_forzar((int) $r['consultorio_id']);

$yaRespondio = $r['estrellas'] !== null;
$gracias     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$yaRespondio) {
    $estrellas = (int) ($_POST['estrellas'] ?? 0);
    $comentario = trim((string) ($_POST['comentario'] ?? ''));

    if ($estrellas >= 1 && $estrellas <= 5) {
        // Se publica al responder. Ocultar es para abuso, no para tapar lo
        // malo: un promedio filtrado no le sirve de nada a nadie.
        db()->prepare(
            "UPDATE resenas
                SET estrellas = ?, comentario = ?, estado = 'publicada', respondida_en = NOW()
              WHERE token = ? AND estrellas IS NULL"
        )->execute([$estrellas, mb_substr($comentario, 0, 1000) ?: null, $token]);
        auditar('resena_recibida', 'resena', (int) $r['id'], $estrellas . ' estrellas',
                (int) $r['consultorio_id']);
        $gracias = true;
        $yaRespondio = true;
    }
}

$marca  = marca_nombre();
$titulo = t('Tu opinión');
include __DIR__ . '/../includes/publico_header.php';
?>
<style>
    /* Estrellas grandes: se califica con el pulgar en un celular, no con el
       ratón. El input real es un radio oculto; la etiqueta es la estrella. */
    .rs-estrellas { display: flex; flex-direction: row-reverse; justify-content: center; gap: .35rem; }
    .rs-estrellas input { position: absolute; opacity: 0; width: 0; height: 0; }
    .rs-estrellas label { font-size: 2.6rem; line-height: 1; color: #d2d2d7; cursor: pointer;
                          transition: color .15s ease, transform .12s ease; }
    /* row-reverse + ~ : al pasar por una estrella se encienden ella y todas
       las anteriores, que es como se espera que funcione. */
    .rs-estrellas label:hover, .rs-estrellas label:hover ~ label,
    .rs-estrellas input:checked ~ label { color: #f5a524; }
    .rs-estrellas label:active { transform: scale(.9); }
    .rs-fija { color: #f5a524; font-size: 1.6rem; letter-spacing: .1rem; }
</style>

<div class="card pub-card">
    <div class="card-body p-4 p-sm-5">
        <div style="max-width:460px;margin:0 auto">

        <?php if ($gracias): ?>
            <div class="text-center">
                <span class="d-inline-flex align-items-center justify-content-center mb-3"
                      style="width:64px;height:64px;border-radius:50%;background:#e7f7ee">
                    <i class="bi bi-heart-fill text-success" style="font-size:2rem"></i>
                </span>
                <h1 class="h4"><?= et('¡Gracias por tu opinión!') ?></h1>
                <p class="text-muted"><?= et('La acabamos de publicar en nuestra página. Nos ayuda a mejorar y a que otros pacientes nos conozcan.') ?></p>
                <?php if ($slug = (tenant()['slug'] ?? '')): ?>
                    <a href="<?= e(micrositio_url($slug)) ?>" class="btn btn-primary mt-2">
                        <?= et('Ver la página de') ?> <?= e($marca) ?>
                    </a>
                <?php endif; ?>
            </div>

        <?php elseif ($yaRespondio): ?>
            <div class="text-center">
                <span class="d-inline-flex align-items-center justify-content-center mb-3"
                      style="width:64px;height:64px;border-radius:50%;background:rgba(127,127,127,.14)">
                    <i class="bi bi-check-lg text-secondary" style="font-size:2rem"></i>
                </span>
                <h1 class="h4"><?= et('Ya nos diste tu opinión') ?></h1>
                <p class="text-muted mb-3"><?= et('Gracias. Solo se puede calificar una vez por consulta.') ?></p>
                <div class="rs-fija"><?= resena_estrellas((float) $r['estrellas']) ?></div>
                <?php if ($r['comentario']): ?>
                    <p class="text-muted small mt-3 fst-italic">“<?= e($r['comentario']) ?>”</p>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="text-center mb-4">
                <h1 class="h4 mb-1"><?= et('Hola') ?>, <?= e($r['pac_nombre']) ?></h1>
                <p class="text-muted small mb-0">
                    <?= et('¿Cómo te fue en tu consulta') ?>
                    <?php if ($r['med_nombre']): ?><?= et('con') ?> <?= e($r['med_nombre']) ?><?php endif; ?>
                    <?php if ($r['cita_fecha']): ?> <?= et('del') ?> <?= fmt_fecha($r['cita_fecha']) ?><?php endif; ?>?
                </p>
            </div>

            <form method="post">
                <input type="hidden" name="t" value="<?= e($token) ?>">

                <?php /* Invertido a propósito: row-reverse necesita el 5 primero. */ ?>
                <div class="rs-estrellas mb-2">
                    <?php foreach ([5, 4, 3, 2, 1] as $n): ?>
                        <input type="radio" name="estrellas" id="e<?= $n ?>" value="<?= $n ?>" required>
                        <label for="e<?= $n ?>" title="<?= $n ?>"><i class="bi bi-star-fill"></i></label>
                    <?php endforeach; ?>
                </div>
                <p class="text-center text-muted small mb-4"><?= et('Toca las estrellas para calificar') ?></p>

                <label class="form-label"><?= et('Cuéntanos (opcional)') ?></label>
                <textarea name="comentario" class="form-control mb-2" rows="4" maxlength="1000"
                          placeholder="<?= e(t('¿Qué fue lo que más te gustó? ¿Qué podríamos mejorar?')) ?>"></textarea>
                <div class="form-text mb-4">
                    <i class="bi bi-info-circle"></i>
                    <?= et('Tu opinión se publica con tu nombre y la inicial de tu apellido. Nunca se muestra tu expediente ni tus datos de contacto.') ?>
                </div>

                <button class="btn btn-primary btn-lg w-100 py-3 fw-semibold">
                    <i class="bi bi-send"></i> <?= et('Enviar mi opinión') ?>
                </button>
            </form>
        <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/publico_footer.php'; ?>
