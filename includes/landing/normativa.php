<?php
/**
 * Landing: marco normativo de la receta médica en México.
 * Regla (docs/REVISION-NOM-004-NOM-024.md): aquí NO se dice que MediOS
 * "cumple" o "está certificado" en NOM-004/NOM-024. Solo se cita qué normas
 * aplican y qué datos imprime hoy la receta (includes/comprobante.php,
 * receta_documento). Si la receta cambia, actualizar la lista de la derecha.
 */
?>
<style>
    .lp-norma { background: #fff; }
    .lp-norma-card { height: 100%; border: 1px solid color-mix(in srgb, var(--ink, #0f172a) 10%, transparent);
                     border-radius: 16px; padding: 1.6rem 1.6rem 1.3rem; background: color-mix(in srgb, var(--brand) 3%, #fff); }
    .lp-norma-card h3 { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: .55rem; margin-bottom: 1rem; }
    .lp-norma-card h3 .bi { width: 2.1rem; height: 2.1rem; border-radius: 50%; display: inline-flex; align-items: center;
                            justify-content: center; font-size: 1rem; color: var(--brand);
                            background: color-mix(in srgb, var(--brand) 11%, #fff); flex: none; }
    .lp-norma-card ul { list-style: none; padding: 0; margin: 0; }
    .lp-norma-card li { display: flex; gap: .55rem; padding: .45rem 0; font-size: .93rem; line-height: 1.45; color: #475569; }
    .lp-norma-card li + li { border-top: 1px dashed color-mix(in srgb, var(--ink, #0f172a) 9%, transparent); }
    .lp-norma-card li .bi { color: var(--brand); margin-top: .15rem; flex: none; }
    .lp-norma-card li b { color: var(--ink, #0f172a); }
    .lp-norma-card .opt { font-size: .78rem; color: #94a3b8; white-space: nowrap; }
    .lp-norma-nota { border-radius: 14px; padding: 1.1rem 1.3rem; font-size: .9rem; color: #475569; line-height: 1.5;
                     background: color-mix(in srgb, var(--brand) 7%, #fff);
                     border: 1px solid color-mix(in srgb, var(--brand) 22%, transparent); }
    .lp-norma-nota b { color: var(--ink, #0f172a); }
</style>
<section id="normativa" class="lp-norma py-6">
    <div class="container">
        <div class="text-center mb-5" data-reveal>
            <span class="lp-eyebrow">Receta médica</span>
            <h2 class="section-title">El marco normativo detrás de cada receta</h2>
            <p class="text-muted mx-auto" style="max-width:640px">Las normas que rigen la receta en México y los datos que MediOS pone en cada una para que la emitas completa.</p>
        </div>
        <div class="row g-4">
            <div class="col-lg-6" data-reveal="left">
                <div class="lp-norma-card">
                    <h3><i class="bi bi-bank"></i> Marco normativo aplicable</h3>
                    <ul>
                        <li><i class="bi bi-dot"></i><span><b>Ley General de Salud</b>, art. 28 Bis (quién puede recetar) y art. 226 (clasifica los medicamentos en seis grupos para su venta y suministro).</span></li>
                        <li><i class="bi bi-dot"></i><span><b>Reglamento de Insumos para la Salud</b>, arts. 29 y 30: datos del médico, fecha, firma, y medicamento con dosis, frecuencia y duración.</span></li>
                        <li><i class="bi bi-dot"></i><span><b>NOM-004-SSA3-2012</b>, del expediente clínico, donde queda integrada cada receta.</span></li>
                        <li><i class="bi bi-dot"></i><span><b>NOM-024-SSA3-2012</b>, sistemas de información de registro electrónico para la salud.</span></li>
                        <li><i class="bi bi-dot"></i><span><b>Medicamentos controlados (COFEPRIS)</b>: los del grupo I se recetan solo en el recetario especial con código de barras; las recetas de los grupos II y III las retiene la farmacia.</span></li>
                    </ul>
                </div>
            </div>
            <div class="col-lg-6" data-reveal="right">
                <div class="lp-norma-card">
                    <h3><i class="bi bi-clipboard2-check"></i> Lo que lleva cada receta de MediOS</h3>
                    <ul>
                        <li><i class="bi bi-check2-circle"></i><span>Nombre, especialidad y cédula profesional del médico <span class="opt">(desde su perfil)</span></span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Membrete con nombre y domicilio del consultorio <span class="opt">(desde Configuración)</span></span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Nombre y edad del paciente, con sus <b>alergias</b> resaltadas</span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Medicamento, dosis, vía de administración, frecuencia y duración</span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Folio y fecha de emisión</span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Espacio para tu firma autógrafa, con nombre y cédula</span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Código <b>QR</b> para que la farmacia compruebe que la receta existe en tu sistema</span></li>
                        <li><i class="bi bi-check2-circle"></i><span>Copia en el expediente, en el portal del paciente y lista para mandar por WhatsApp</span></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="lp-norma-nota mt-4" data-reveal>
            <b>Bueno saberlo:</b> la receta de MediOS se imprime para firmarla a mano; todavía no tiene firma electrónica.
            Esta sección es informativa y no sustituye asesoría legal. Las normas pueden cambiar, así que confirma la versión vigente en el
            <a href="https://www.dof.gob.mx/" target="_blank" rel="noopener">Diario Oficial de la Federación</a>.
        </div>
    </div>
</section>
