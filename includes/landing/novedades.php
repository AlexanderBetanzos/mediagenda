<?php
/** Cinta de novedades bajo el hero de la landing (lo último que se agregó). */
$__nov = [
    ['bi-shield-plus', 'Vacunación con cartilla y recordatorios'],
    ['bi-tv', 'Pantalla de turnos para tu sala de espera'],
    ['bi-safe2', 'Corte de caja por persona'],
    ['bi-grid-1x2', 'Interfaz para tu especialidad (incluye veterinaria)'],
    ['bi-whatsapp', 'WhatsApp al paciente con su portal'],
    ['bi-graph-up-arrow', 'Dashboard con gráficas y tendencias'],
    ['bi-question-circle', 'Guías paso a paso en cada pantalla'],
];
?>
<div class="lx-nov" aria-label="Novedades">
    <div class="lx-nov-track">
        <?php foreach ([false, true] as $__c): foreach ($__nov as [$__i, $__t]): ?>
            <span class="lx-nov-item"<?= $__c ? ' aria-hidden="true"' : '' ?>><b>Nuevo</b><i class="bi <?= $__i ?>"></i> <?= e($__t) ?></span>
        <?php endforeach; endforeach; ?>
    </div>
</div>
