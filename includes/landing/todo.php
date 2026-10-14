<?php
/** "Todo incluido": mosaico con los módulos que existen (según el plan). */
$__todo = [
    ['bi-calendar-check', 'Agenda y horarios', '#2563eb'], ['bi-globe2', 'Agenda en línea', '#0ea5e9'], ['bi-whatsapp', 'Recordatorios por WhatsApp', '#16a34a'],
    ['bi-folder2-open', 'Expediente clínico', '#6366f1'], ['bi-activity', 'Signos vitales con gráficas', '#ef4444'], ['bi-capsule', 'Recetas con tu marca', '#8b5cf6'],
    ['bi-shield-plus', 'Vacunación', '#10b981'], ['bi-file-earmark-medical', 'Documentos y consentimientos', '#64748b'], ['bi-file-earmark-text', 'Plantillas de consulta', '#6366f1'],
    ['bi-eyedropper', 'Laboratorio', '#0d9488'], ['bi-soundwave', 'Ultrasonidos', '#0ea5e9'], ['bi-emoji-smile', 'Odontograma', '#f59e0b'],
    ['bi-eyeglasses', 'Óptica', '#2563eb'], ['bi-camera-video', 'Videoconsulta', '#0ea5e9'], ['bi-person-heart', 'Portal del paciente', '#ec4899'],
    ['bi-receipt', 'Cobros y comprobantes', '#16a34a'], ['bi-clipboard2-check', 'Presupuestos con abonos', '#f59e0b'], ['bi-shop', 'Punto de venta', '#0d9488'],
    ['bi-box-seam', 'Inventario con alertas', '#64748b'], ['bi-safe2', 'Corte de caja', '#16a34a'], ['bi-arrow-left-right', 'Egresos y utilidad', '#ef4444'],
    ['bi-tv', 'Pantalla de turnos', '#2563eb'], ['bi-people-fill', 'CRM y campañas', '#8b5cf6'], ['bi-star', 'Reseñas de pacientes', '#f59e0b'],
    ['bi-graph-up-arrow', 'Reportes y gráficas', '#6366f1'], ['bi-person-badge', 'Equipo con permisos por rol', '#64748b'], ['bi-file-earmark-zip', 'Descarga de tus datos', '#0d9488'],
    ['bi-question-circle', 'Guías de ayuda integradas', '#2563eb'],
];
?>
<section id="todo" class="py-6 bg-white">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="lp-eyebrow">Todo incluido</span>
            <h2 class="section-title">Un solo sistema para todo tu consultorio</h2>
            <p class="text-muted">Lo que necesitas hoy y lo que vas a necesitar al crecer. Cada plan incluye más módulos.</p>
        </div>
        <div class="lx-todo" data-stagger>
            <?php foreach ($__todo as [$__i, $__t, $__c]): ?>
                <div class="lx-todo-it"><i class="bi <?= $__i ?>" style="--c:<?= $__c ?>"></i> <?= e($__t) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-4"><a href="#planes" class="btn lp-btn lp-btn-ghost">Ver qué incluye cada plan</a></div>
    </div>
</section>
