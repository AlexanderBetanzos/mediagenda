<?php
/**
 * Ilustraciones animadas (propias, HTML/SVG) del hero de cada página de
 * especialidad. Usan el color de la página (--c) y datos de ejemplo (DEMO).
 */
function ilustracion_especialidad(string $slug): string
{
    ob_start();
    $demo = '<span class="il-demo">DEMO</span>';
    switch ($slug) {
    case 'dentistas':
        // Odontograma FDI: 16 piezas arriba y 16 abajo, algunas con hallazgos.
        $marca = [16 => 'car', 14 => 'trat', 11 => 'ok', 26 => 'car', 36 => 'trat', 46 => 'ok', 47 => 'car', 35 => 'ok'];
        $fila = fn ($ns) => implode('', array_map(fn ($n) => '<span class="il-diente ' . ($marca[$n] ?? '') . '" title="' . $n . '"><small>' . $n . '</small></span>', $ns));
        ?>
        <div class="il-card"><div class="il-hd"><b>Odontograma</b><span>Juan P. · 34 años</span><?= $demo ?></div>
            <div class="il-odo"><?= $fila([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28]) ?></div>
            <div class="il-odo"><?= $fila([48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38]) ?></div>
            <div class="il-ley"><span class="car">Caries</span><span class="trat">Por tratar</span><span class="ok">Realizado</span></div>
        </div>
        <div class="il-float" style="right:-14px;bottom:-22px"><i class="bi bi-clipboard2-check"></i><div><b>Plan aceptado</b><span>Saldo: $3,200</span></div></div>
        <?php break;
    case 'psicologos': ?>
        <div class="il-card"><div class="il-hd"><b>PHQ-9 · evolución</b><span>Paciente · 6 sesiones</span><?= $demo ?></div>
            <svg class="il-svg" viewBox="0 0 300 140" preserveAspectRatio="none" aria-hidden="true">
                <line x1="0" y1="35" x2="300" y2="35" class="il-guia"/><line x1="0" y1="80" x2="300" y2="80" class="il-guia"/>
                <path class="il-linea" d="M10,22 C55,30 70,48 110,58 C150,68 170,84 205,96 C240,106 260,112 290,118"/>
                <?php foreach ([[10,22],[66,44],[110,58],[160,76],[205,96],[290,118]] as $i => [$x, $y]): ?><circle class="il-pt" style="--i:<?= $i ?>" cx="<?= $x ?>" cy="<?= $y ?>" r="5"/><?php endforeach; ?>
            </svg>
            <div class="d-flex justify-content-between small text-muted"><span>Sesión 1 · Moderada-grave</span><span class="il-chip">Sesión 6 · Leve</span></div>
        </div>
        <div class="il-float" style="right:-14px;bottom:-22px"><i class="bi bi-camera-video"></i><div><b>Sesión en línea</b><span>Jueves 18:00</span></div></div>
        <?php break;
    case 'nutriologos': ?>
        <div class="il-card"><div class="il-hd"><b>Peso · contra la meta</b><span>Valoraciones</span><?= $demo ?></div>
            <svg class="il-svg" viewBox="0 0 300 140" preserveAspectRatio="none" aria-hidden="true">
                <line x1="0" y1="112" x2="300" y2="112" class="il-meta"/><text x="12" y="106" class="il-txt">Meta 72 kg</text>
                <path class="il-linea" d="M10,24 C50,34 80,46 120,60 C160,74 200,84 240,94 C260,98 280,100 290,102"/>
                <?php foreach ([[10,24],[70,42],[120,60],[180,79],[240,94],[290,102]] as $i => [$x, $y]): ?><circle class="il-pt" style="--i:<?= $i ?>" cx="<?= $x ?>" cy="<?= $y ?>" r="5"/><?php endforeach; ?>
            </svg>
            <div class="il-mini"><span><small>Peso</small><b>76.4 kg</b></span><span><small>% grasa</small><b>27%</b></span><span><small>Cintura</small><b>84 cm</b></span></div>
        </div>
        <div class="il-float" style="right:-14px;bottom:-22px"><i class="bi bi-graph-down-arrow"></i><div><b>−4.2 kg</b><span>desde la 1a valoración</span></div></div>
        <?php break;
    case 'pediatras': ?>
        <div class="il-card"><div class="il-hd"><b>Curva de crecimiento · talla</b><span>Sofía · 0–4 años</span><?= $demo ?></div>
            <svg class="il-svg" viewBox="0 0 300 140" preserveAspectRatio="none" aria-hidden="true">
                <path class="il-banda" d="M0,120 C80,80 160,52 300,26 L300,58 C160,82 80,108 0,138 Z"/>
                <path class="il-linea" d="M10,128 C60,108 100,90 150,72 C200,58 250,48 290,40"/>
                <?php foreach ([[10,128],[70,103],[150,72],[220,54],[290,40]] as $i => [$x, $y]): ?><circle class="il-pt" style="--i:<?= $i ?>" cx="<?= $x ?>" cy="<?= $y ?>" r="5"/><?php endforeach; ?>
            </svg>
            <div class="d-flex flex-wrap gap-2 mt-1"><span class="il-tag ok"><i class="bi bi-check-circle-fill"></i> Hexavalente</span><span class="il-tag pend"><i class="bi bi-calendar-event"></i> Influenza en 3 días</span></div>
        </div>
        <div class="il-float" style="right:-14px;bottom:-22px"><i class="bi bi-whatsapp"></i><div><b>Aviso a mamá</b><span>Vacuna de influenza</span></div></div>
        <?php break;
    case 'oftalmologos': ?>
        <div class="il-card"><div class="il-hd"><b>Graduación</b><span>Laura M. · 12/10</span><?= $demo ?></div>
            <table class="il-tab"><thead><tr><th></th><th>Esfera</th><th>Cilindro</th><th>Eje</th><th>AV</th></tr></thead>
                <tbody><tr><td>OD</td><td>−1.75</td><td>−0.50</td><td>180°</td><td>20/20</td></tr><tr><td>OI</td><td>−2.00</td><td>−0.75</td><td>175°</td><td>20/25</td></tr></tbody></table>
            <div class="il-hd mt-3 mb-1"><b>Presión intraocular</b><span>mmHg</span></div>
            <svg class="il-svg" style="height:70px" viewBox="0 0 300 70" preserveAspectRatio="none" aria-hidden="true">
                <path class="il-linea" d="M10,40 C60,36 100,30 150,34 C200,38 240,30 290,28"/>
            </svg>
        </div>
        <div class="il-float" style="left:-16px;bottom:-22px"><i class="bi bi-kanban"></i><div><b>Trabajo en proceso</b><span>Micas antirreflejo</span></div></div>
        <?php break;
    case 'dermatologos': ?>
        <div class="il-card"><div class="il-hd"><b>Mapa corporal</b><span>3 hallazgos</span><?= $demo ?></div>
            <div class="il-cuerpo">
                <svg viewBox="0 0 120 230" aria-hidden="true">
                    <circle cx="60" cy="24" r="18" class="il-piel"/>
                    <rect x="34" y="46" width="52" height="82" rx="20" class="il-piel"/>
                    <rect x="14" y="52" width="16" height="76" rx="8" class="il-piel"/><rect x="90" y="52" width="16" height="76" rx="8" class="il-piel"/>
                    <rect x="38" y="126" width="18" height="96" rx="9" class="il-piel"/><rect x="64" y="126" width="18" height="96" rx="9" class="il-piel"/>
                    <?php foreach ([[52, 22], [72, 78], [24, 104]] as $i => [$x, $y]): ?><circle class="il-mark" style="--i:<?= $i ?>" cx="<?= $x ?>" cy="<?= $y ?>" r="5"/><?php endforeach; ?>
                </svg>
                <div class="il-lista">
                    <div><i style="--k:#ef4444"></i>Mejilla · lesión pigmentada</div>
                    <div><i style="--k:#f59e0b"></i>Abdomen · seguimiento</div>
                    <div><i style="--k:#16a34a"></i>Brazo · tratada</div>
                </div>
            </div>
        </div>
        <div class="il-float" style="right:-14px;bottom:-22px"><i class="bi bi-images"></i><div><b>Antes / después</b><span>4 fotos comparables</span></div></div>
        <?php break;
    case 'clinicas': ?>
        <div class="il-card"><div class="il-hd"><b>Agenda de hoy</b><span>3 médicos</span><?= $demo ?></div>
            <div class="il-cols">
                <?php foreach ([['Dra. Laura', [[0, 2, 'Confirmada'], [3, 1, ''], [5, 2, '']]], ['Dr. Carlos', [[1, 2, ''], [4, 2, 'Confirmada']]], ['Dra. Ana', [[0, 1, ''], [2, 2, ''], [5, 1, '']]]] as $ci => [$med, $bloques]): ?>
                <div class="il-col"><small><?= $med ?></small>
                    <?php foreach ($bloques as $bi => [$ini, $dur, $e]): ?><span class="il-blk" style="top:<?= 22 + $ini * 26 ?>px;height:<?= $dur * 26 - 4 ?>px;--i:<?= $ci * 3 + $bi ?>"></span><?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="il-float" style="left:-16px;bottom:-22px"><i class="bi bi-tv"></i><div><b>Pasa a consulta</b><span>María G. · Dra. Laura</span></div></div>
        <?php break;
    default: /* medicos */ ?>
        <div class="il-card"><div class="il-hd"><b>María García</b><span>36 años · Femenino</span><?= $demo ?></div>
            <span class="il-tag alg"><i class="bi bi-exclamation-triangle-fill"></i> Alergia: Penicilina</span>
            <div class="il-vit">
                <?php foreach ([['Peso', '68.5', 'kg', 'M0,12 C30,16 60,22 90,20 C110,19 120,24 130,26'], ['Presión', '120/80', '', 'M0,18 C30,16 60,20 90,17 C110,15 120,18 130,16'], ['IMC', '25.2', '', 'M0,10 C30,14 60,18 90,20 C110,22 120,24 130,25']] as $i => [$t, $v, $u, $p]): ?>
                <div><small><?= $t ?></small><b><?= $v ?><em><?= $u ?></em></b><svg viewBox="0 0 130 34" preserveAspectRatio="none"><path class="il-linea" style="stroke-width:2.5" d="<?= $p ?>"/></svg></div>
                <?php endforeach; ?>
            </div>
            <div class="il-row"><i class="bi bi-capsule"></i> Receta · Paracetamol 500 mg<span>Lista</span></div>
        </div>
        <div class="il-float" style="right:-14px;bottom:-22px"><i class="bi bi-whatsapp"></i><div><b>Cita confirmada</b><span>Mañana 10:30</span></div></div>
        <?php
    }
    return (string) ob_get_clean();
}
