<?php
/**
 * "Recorre MediOS": pestañas que cambian solas entre cinco pantallas del
 * producto. Las pantallas son maquetas propias (HTML/CSS/SVG) con datos de
 * ejemplo —los mismos de la landing— y van marcadas DEMO.
 */
$__curva = 'M0,92 C30,88 45,70 75,72 C105,74 120,52 150,50 C180,48 195,60 225,44 C255,28 270,34 300,20';
$__tabs = [
    ['dash', 'bi-grid-1x2', 'Dashboard', 'Tu consultorio de un vistazo', ['Citas, pacientes e ingresos con su tendencia de la semana', 'Calendario del mes y agenda de hoy', 'Lo pendiente: por confirmar, por cobrar y tu corte de caja']],
    ['agenda', 'bi-calendar-week', 'Agenda', 'La semana completa, sin huecos', ['Cada médico con su agenda y sus horarios', 'Recordatorio por WhatsApp con enlace para confirmar', 'Agenda en línea: tus pacientes reservan solos']],
    ['exp', 'bi-clipboard2-pulse', 'Expediente', 'Todo el historial, en dos clics', ['Signos vitales con su evolución y su estado', 'Alergias en rojo antes de recetar', 'Recetas, estudios, documentos y vacunas en la misma ficha']],
    ['cobros', 'bi-receipt', 'Cobros', 'Cobra rápido y cuadra tu caja', ['Servicios del catálogo con un clic', 'Efectivo, tarjeta o transferencia, sin errores', 'Cierre de caja por persona: lo cobrado contra lo contado']],
    ['turnos', 'bi-tv', 'Sala de espera', 'Una sala de espera moderna', ['Check-in y paso a consulta desde recepción', 'Pantalla de turnos para la TV con timbre', 'Tiempos de espera del día']],
];
?>
<section id="recorrido" class="py-6">
    <div class="container">
        <div class="text-center mb-4" data-reveal>
            <span class="lp-eyebrow">Recorre <?= e($marca) ?></span>
            <h2 class="section-title">Así se ve por dentro</h2>
            <p class="text-muted">Así se ven las pantallas, con datos de ejemplo.</p>
            <div class="lx-tabs mt-2" role="tablist">
                <?php foreach ($__tabs as $__i => [$__k, $__ic, $__n]): ?>
                    <button type="button" class="lx-tab<?= $__i ? '' : ' on' ?>" data-tab="<?= $__k ?>" role="tab"><i class="bi <?= $__ic ?>"></i><?= e($__n) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="lx-prog" id="lxProg"><span></span></div>
        </div>

        <?php foreach ($__tabs as $__i => [$__k, $__ic, $__n, $__tt, $__bul]): ?>
        <div class="lx-pane<?= $__i ? '' : ' on' ?>" data-pane="<?= $__k ?>">
            <div class="row g-5 align-items-center">
                <div class="col-lg-4 lx-tour-txt">
                    <h3><?= e($__tt) ?></h3>
                    <ul><?php foreach ($__bul as $__b): ?><li><i class="bi bi-check2-circle"></i> <?= e($__b) ?></li><?php endforeach; ?></ul>
                    <a href="<?= BASE_URL ?>/auth/registro" class="btn lp-btn lp-btn-fill mt-2">Pruébalo gratis <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="col-lg-8">
                    <div class="lx-win">
                        <div class="lx-win-bar"><i></i><i></i><i></i><span>DEMO</span></div>
                        <div class="lx-win-body">
                        <?php if ($__k === 'dash'): ?>
                            <div class="mk-kpis mb-3">
                                <div class="mk-kpi"><small>Citas hoy</small><b>14</b> <em>▲ 12%</em></div>
                                <div class="mk-kpi"><small>Pacientes</small><b>231</b> <em>▲ 8</em></div>
                                <div class="mk-kpi"><small>Ingresos del mes</small><b>$48k</b> <em>▲ 9%</em></div>
                            </div>
                            <div class="row g-3">
                                <div class="col-7"><div class="mk-card"><div class="mk-lbl">Ingresos del mes</div>
                                    <svg class="mk-chart" viewBox="0 0 300 110" preserveAspectRatio="none" aria-hidden="true">
                                        <defs><linearGradient id="mkGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--brand);stop-opacity:.3"/><stop offset="1" style="stop-color:var(--brand);stop-opacity:0"/></linearGradient></defs>
                                        <path class="mk-area" d="<?= $__curva ?> L300,110 L0,110 Z"/><path class="mk-line" d="<?= $__curva ?>"/></svg></div></div>
                                <div class="col-5"><div class="mk-card"><div class="mk-lbl">Agenda de hoy</div>
                                    <div class="mk-row" style="--c:#16a34a"><b>09:00</b> Martínez G.<span class="st">Confirmada</span></div>
                                    <div class="mk-row" style="--c:#3b82f6"><b>10:30</b> López P.<span class="st">Programada</span></div>
                                    <div class="mk-row" style="--c:#8b5cf6"><b>11:15</b> Rodríguez C.<span class="st">Atendida</span></div></div></div>
                            </div>
                        <?php elseif ($__k === 'agenda'): ?>
                            <div class="mk-days"><?php foreach (['Lun' => 13, 'Mar' => 14, 'Mié' => 15, 'Jue' => 16, 'Vie' => 17, 'Sáb' => 18] as $__d => $__n2): ?><span class="<?= $__n2 === 14 ? 'on' : '' ?>"><b><?= $__n2 ?></b><?= $__d ?></span><?php endforeach; ?></div>
                            <div class="mk-row" style="--c:#16a34a"><b>09:00</b> Martínez García · Consulta general · Dra. Laura<span class="st">Confirmada</span></div>
                            <div class="mk-row" style="--c:#3b82f6"><b>10:30</b> López Pérez · Seguimiento · Dr. Carlos<span class="st">Programada</span></div>
                            <div class="mk-row" style="--c:#16a34a"><b>11:15</b> Rodríguez Cruz · Primera vez · Dra. Laura<span class="st">Confirmada</span></div>
                            <div class="mk-row" style="--c:#0ea5e9"><b>12:00</b> García Ruiz · Videoconsulta · Dr. Carlos<span class="st"><i class="bi bi-camera-video"></i> En línea</span></div>
                            <div class="mk-row" style="--c:#3b82f6"><b>13:30</b> Hernández Luna · Control · Dra. Laura<span class="st">Programada</span></div>
                        <?php elseif ($__k === 'exp'): ?>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-3"><b class="fs-5">María García</b><span class="text-muted small">36 años · Femenino</span><span class="mk-alg ms-auto"><i class="bi bi-exclamation-triangle-fill"></i> Alergia: Penicilina</span></div>
                            <div class="mk-vit mb-3">
                                <?php foreach ([['Peso', '68.5 kg', '#2563eb', 'M0,10 C20,14 40,22 60,20 C80,18 100,26 120,28'], ['Presión', '120/80', '#ef4444', 'M0,18 C20,16 40,20 60,17 C80,15 100,19 120,16'], ['IMC', '25.2', '#7c3aed', 'M0,8 C20,12 40,16 60,18 C80,20 100,22 120,24']] as [$__t, $__v, $__c, $__p]): ?>
                                <div class="mk-vt" style="--c:<?= $__c ?>"><small><?= $__t ?></small><b><?= $__v ?></b><span class="ch">Normal</span>
                                    <svg viewBox="0 0 120 34" preserveAspectRatio="none"><path d="<?= $__p ?>" fill="none" stroke="<?= $__c ?>" stroke-width="2.5" stroke-linecap="round"/></svg></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mk-card"><div class="mk-lbl">Última consulta · 12/06 · Dra. Laura</div>
                                <div class="small"><b>Motivo:</b> Cefalea tensional · <b>Diagnóstico:</b> Cefalea tensional · <b>Receta:</b> 1 medicamento</div></div>
                        <?php elseif ($__k === 'cobros'): ?>
                            <div class="row g-3">
                                <div class="col-7"><div class="mk-card">
                                    <div class="mk-lbl">Conceptos</div>
                                    <div class="mk-row"><b>1×</b> Consulta general<span class="ms-auto fw-bold">$600</span></div>
                                    <div class="mk-row"><b>1×</b> Certificado médico<span class="ms-auto fw-bold">$350</span></div>
                                    <div class="mk-lbl mt-3">Método de pago</div>
                                    <div class="mk-met"><span class="on">Efectivo</span><span>Tarjeta</span><span>Transfer.</span><span>Otro</span></div>
                                </div></div>
                                <div class="col-5"><div class="mk-card text-center">
                                    <div class="mk-lbl">Total</div><div class="mk-total">$950</div>
                                    <div class="mk-lbl mt-3">Corte de caja</div><div class="mk-ok"><i class="bi bi-check-circle-fill"></i> Cuadra exacto</div>
                                </div></div>
                            </div>
                        <?php else: ?>
                            <div class="mk-tv"><div class="mk-tv-in">
                                <div class="mk-call"><small>Pasa a consulta</small><b>María G.</b><span>con Dra. Laura</span></div>
                                <div class="mk-wait"><small>En espera</small><div>Jorge L.<span>5 min</span></div><div>Ana R.<span>12 min</span></div><div>Luis M.<span>18 min</span></div></div>
                            </div></div><div class="mk-tv-pie"></div>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<script>
/* Pestañas del recorrido: cambian solas cada 7 s hasta que el visitante toca una. */
(function () {
    var tabs = document.querySelectorAll('.lx-tab'), panes = document.querySelectorAll('.lx-pane'), prog = document.getElementById('lxProg');
    if (!tabs.length) return;
    var i = 0, timer = null, manual = false;
    function ir(n) {
        i = (n + tabs.length) % tabs.length;
        tabs.forEach(function (t, k) { t.classList.toggle('on', k === i); });
        panes.forEach(function (p, k) { p.classList.toggle('on', k === i); });
        if (prog && !manual) { prog.classList.remove('run'); void prog.offsetWidth; prog.classList.add('run'); }
    }
    function auto() { if (!manual) timer = setInterval(function () { ir(i + 1); }, 7000); }
    tabs.forEach(function (t, k) { t.addEventListener('click', function () { manual = true; clearInterval(timer); if (prog) prog.classList.remove('run'); ir(k); }); });
    var sec = document.getElementById('recorrido');
    if ('IntersectionObserver' in window && sec) {
        var io = new IntersectionObserver(function (en) { if (en[0].isIntersecting) { ir(0); auto(); io.disconnect(); } }, { threshold: .3 });
        io.observe(sec);
    }
})();
</script>
