<?php
/**
 * Destacados de la landing: lo nuevo y lo que más vende, cada uno con una
 * ilustración propia (maqueta HTML/CSS/SVG con datos de ejemplo).
 * Todo lo que se anuncia existe en el producto.
 */
$__barI = [55, 72, 64, 88, 79, 96];      // alturas de ejemplo (%), no cifras reales
$__barE = [30, 34, 28, 38, 31, 36];
?>
<!-- Destacado: vacunación -->
<section class="lx-dest">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-6" data-reveal="left">
                <span class="lp-eyebrow">Vacunación <span class="lx-nuevo">Nuevo</span></span>
                <h2 class="mb-3">Que ninguna vacuna se quede pendiente</h2>
                <p class="lead mb-4">La cartilla de cada paciente, con la próxima dosis calculada sola y el recordatorio listo para enviarse.</p>
                <ul>
                    <li><i class="bi bi-check2-circle"></i> Catálogo de vacunas con su refuerzo; en veterinaria, por especie</li>
                    <li><i class="bi bi-check2-circle"></i> Lista de vencidas y próximas con WhatsApp en un clic</li>
                    <li><i class="bi bi-check2-circle"></i> Recordatorio automático por correo antes de la dosis</li>
                    <li><i class="bi bi-check2-circle"></i> El paciente ve su cartilla en su portal y la puede imprimir</li>
                </ul>
            </div>
            <div class="col-lg-6" data-reveal="right">
                <div class="position-relative" style="max-width:470px;margin:0 auto">
                    <div class="lx-win"><div class="lx-win-bar"><i></i><i></i><i></i><span>DEMO</span></div>
                        <div class="lx-win-body" style="min-height:0">
                            <div class="d-flex justify-content-between align-items-center mb-2"><b>Cartilla de vacunación</b><span class="small text-muted">Sofía R. · 4 años</span></div>
                            <div class="mk-row" style="--c:#16a34a"><b>03/25</b> Hexavalente · 4a dosis<span class="st">Aplicada</span></div>
                            <div class="mk-row" style="--c:#16a34a"><b>10/25</b> Influenza<span class="st">Aplicada</span></div>
                            <div class="mk-row" style="--c:#f59e0b"><b>10/26</b> Influenza · refuerzo anual<span class="st">En 3 días</span></div>
                            <div class="mk-row" style="--c:#ef4444"><b>09/26</b> SRP · 2a dosis<span class="st">Vencida</span></div>
                        </div></div>
                    <div class="lx-float" style="right:-14px;bottom:-22px"><i class="bi bi-whatsapp" style="color:#25d366"></i><div><b>Recordatorio enviado</b><div class="text-muted small">Influenza · refuerzo</div></div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Destacado: sala de espera y pantalla de turnos -->
<section class="lx-dest">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center flex-lg-row-reverse">
            <div class="col-lg-6" data-reveal="right">
                <span class="lp-eyebrow">Sala de espera <span class="lx-nuevo">Nuevo</span></span>
                <h2 class="mb-3">Tu recepción, como la de un hospital</h2>
                <p class="lead mb-4">Llegada, espera y paso a consulta en un tablero, y una pantalla de turnos para la TV de tu sala.</p>
                <ul>
                    <li><i class="bi bi-check2-circle"></i> Check-in, pasar a consulta y finalizar con un clic</li>
                    <li><i class="bi bi-check2-circle"></i> El nombre aparece en grande con un timbre cuando le toca</li>
                    <li><i class="bi bi-check2-circle"></i> Privacidad: solo nombre e inicial del apellido</li>
                    <li><i class="bi bi-check2-circle"></i> Tiempo de espera promedio del día</li>
                </ul>
            </div>
            <div class="col-lg-6" data-reveal="left">
                <div style="max-width:500px;margin:0 auto">
                    <div class="mk-tv"><div class="mk-tv-in">
                        <div class="mk-call"><small>Pasa a consulta</small><b>Jorge L.</b><span>con Dr. Carlos</span></div>
                        <div class="mk-wait"><small>En espera</small><div>Ana R.<span>4 min</span></div><div>Luis M.<span>9 min</span></div><div>Carmen T.<span>15 min</span></div><div>Pedro S.<span>21 min</span></div></div>
                    </div></div><div class="mk-tv-pie"></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Destacado: dinero bajo control (gráfica) -->
<section class="lx-dest">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center">
            <div class="col-lg-6" data-reveal="left">
                <span class="lp-eyebrow">Tu dinero</span>
                <h2 class="mb-3">Sabes cuánto entra, cuánto sale y cuánto te queda</h2>
                <p class="lead mb-4">Cobros, egresos y utilidad del mes, con el cierre de caja de cada persona al terminar su turno.</p>
                <ul>
                    <li><i class="bi bi-check2-circle"></i> Cobrado − egresos = balance, en tu dashboard</li>
                    <li><i class="bi bi-check2-circle"></i> Corte de caja: lo cobrado contra lo contado, con la diferencia</li>
                    <li><i class="bi bi-check2-circle"></i> Punto de venta e inventario con alertas de stock</li>
                    <li><i class="bi bi-check2-circle"></i> Descarga todos tus datos en Excel cuando quieras</li>
                </ul>
            </div>
            <div class="col-lg-6" data-reveal="right">
                <div class="lx-win" style="max-width:500px;margin:0 auto"><div class="lx-win-bar"><i></i><i></i><i></i><span>DEMO</span></div>
                    <div class="lx-win-body" style="min-height:0">
                        <div class="mk-kpis mb-3">
                            <div class="mk-kpi"><small>Cobrado</small><b style="color:#16a34a">$48,200</b></div>
                            <div class="mk-kpi"><small>Egresos</small><b style="color:#ef4444">$17,350</b></div>
                            <div class="mk-kpi"><small>Balance</small><b>$30,850</b></div>
                        </div>
                        <div class="mk-card"><div class="mk-lbl">Ingresos y egresos · 6 meses</div>
                            <div class="mk-bars">
                                <?php foreach ($__barI as $__k => $__h): ?><span style="height:<?= $__h ?>%"></span><span class="eg" style="height:<?= $__barE[$__k] ?>%"></span><?php endforeach; ?>
                            </div>
                        </div>
                    </div></div>
            </div>
        </div>
    </div>
</section>

<!-- Destacado: WhatsApp al paciente -->
<section class="lx-dest">
    <div class="container">
        <div class="row g-4 g-lg-5 align-items-center flex-lg-row-reverse">
            <div class="col-lg-6" data-reveal="right">
                <span class="lp-eyebrow">WhatsApp</span>
                <h2 class="mb-3">Un mensaje y tu paciente tiene todo</h2>
                <p class="lead mb-4">Desde su ficha le mandas su próxima cita con el enlace para confirmar, el acceso a su portal y tu página para agendar.</p>
                <ul>
                    <li><i class="bi bi-check2-circle"></i> Recordatorio de cita con confirmación en un clic</li>
                    <li><i class="bi bi-check2-circle"></i> Portal del paciente: citas, recetas, vacunas y estudios</li>
                    <li><i class="bi bi-check2-circle"></i> Vista previa del mensaje antes de enviarlo</li>
                </ul>
            </div>
            <div class="col-lg-6" data-reveal="left">
                <div class="mk-phone"><div class="mk-phone-in">
                    <div class="mk-phone-hd"><i class="bi bi-whatsapp"></i> María García</div>
                    <div class="mk-phone-bd">
                        <div class="mk-bub">¡Hola María! Te saludamos de <b>Clínica Demo</b>.<br><br><b>Tu próxima cita</b><br>15/10/2026 a las 10:30 con Dra. Laura<br>Confirma o cancela aquí: <a>mediagenda.com.mx/agenda/…</a><br><br><b>Tu portal de paciente</b><br>Consulta tus citas, recetas y vacunas: <a>mediagenda.com.mx/portal/…</a><br><br>¡Te esperamos!<small>10:24 ✓✓</small></div>
                    </div>
                </div></div>
            </div>
        </div>
    </div>
</section>
