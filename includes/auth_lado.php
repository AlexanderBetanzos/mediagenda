<?php
/**
 * Panel de marca de las pantallas de acceso (login, registro, recuperar).
 * Solo funciones que existen en el producto; nada de cifras ni testimonios.
 * $authModo: 'login' | 'registro' | 'recuperar'.
 */
$authModo = $authModo ?? 'login';
$puntos = $authModo === 'portal'
    ? [['bi-calendar-check', t('Tus citas'), t('Consulta, reagenda o cancela sin llamar.')],
       ['bi-capsule', t('Tus recetas'), t('Imprímelas o guárdalas en PDF cuando las necesites.')],
       ['bi-paperclip', t('Estudios y vacunas'), t('Tus archivos y tu cartilla en un solo lugar.')]]
    : ($authModo === 'registro'
    ? [['bi-calendar2-check', t('Agenda con recordatorios'), t('Tus pacientes confirman por WhatsApp o correo.')],
       ['bi-journal-medical', t('Expediente y recetas'), t('Notas, signos, alergias y recetas impresas con tu marca.')],
       ['bi-cash-coin', t('Cobros y cierre de caja'), t('Abonos, métodos de pago y corte por persona.')],
       ['bi-globe2', t('Tu página para agendar'), t('Los pacientes reservan en línea, sin llamadas.')]]
    : [['bi-calendar2-check', t('Tu agenda de hoy'), t('Quién viene, quién confirmó y quién está en sala.')],
       ['bi-journal-medical', t('Expedientes al día'), t('Todo el historial del paciente en una ficha.')],
       ['bi-shield-lock', t('Acceso por persona'), t('Cada quien entra con su cuenta y su rol.')]]);
?>
<aside class="auth-lado">
    <div class="auth-lado-in">
        <a href="<?= BASE_URL ?>/index" class="auth-marca">
            <?php if (cfg('marca_logo')): ?><img src="<?= e(cfg('marca_logo')) ?>" alt=""><?php else: ?><i class="bi bi-heart-pulse-fill"></i><?php endif; ?>
            <span><?= e(marca_nombre()) ?></span>
        </a>
        <h2 class="auth-titular"><?= $authModo === 'portal' ? et('Tu salud, a la mano.') : ($authModo === 'registro' ? et('Tu consultorio, en orden desde el primer día.') : ($authModo === 'recuperar' ? et('Recupera tu acceso en un minuto.') : et('Qué bueno verte de nuevo.'))) ?></h2>
        <ul class="auth-puntos">
            <?php foreach ($puntos as [$ic, $t, $d]): ?>
            <li><i class="bi <?= $ic ?>"></i><div><b><?= e($t) ?></b><span><?= e($d) ?></span></div></li>
            <?php endforeach; ?>
        </ul>
        <div class="auth-pie"><i class="bi bi-lock-fill"></i> <?= et('La conexión viaja cifrada (HTTPS).') ?></div>
    </div>
</aside>
