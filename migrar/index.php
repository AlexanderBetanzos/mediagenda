<?php
/**
 * Migrar desde otro sistema (Nimbo, Doctoralia, Excel…): los cuatro pasos en
 * orden y el texto para pedirle la información al proveedor anterior.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_role('admin');

$tid = (int) tenant_id();
$cuenta = function (string $sql) use ($tid) { $s = db()->prepare($sql); $s->execute([$tid]); return (int) $s->fetchColumn(); };
$nPac = $cuenta('SELECT COUNT(*) FROM pacientes WHERE consultorio_id = ?');
$nMed = $cuenta("SELECT COUNT(*) FROM usuarios WHERE consultorio_id = ? AND activo = 1 AND rol IN ('medico','admin')");
$marca = marca_nombre();
$carta = "Por medio del presente solicito la exportación completa de la información de mi consultorio registrada en su sistema, "
    . "en formatos abiertos, para continuar la atención de mis pacientes en otro sistema:\n\n"
    . "1. Pacientes: nombre, apellidos, fecha de nacimiento, sexo, CURP, teléfono, correo, dirección, alergias y antecedentes (Excel o CSV).\n"
    . "2. Citas futuras: paciente, fecha, hora, médico y motivo (Excel o CSV).\n"
    . "3. Historial clínico: consultas y notas con su fecha, médico, motivo, diagnóstico y tratamiento (Excel o CSV).\n"
    . "4. Expedientes y documentos adjuntos (estudios, imágenes, recetas) en PDF, organizados por paciente.\n\n"
    . "Como responsable del expediente clínico (NOM-004-SSA3-2012) y de los datos personales de mis pacientes "
    . "(Ley Federal de Protección de Datos Personales en Posesión de los Particulares), les pido la entrega en un plazo razonable.\n\n"
    . "Atentamente,\n" . ($marca);

$titulo = t('Migrar desde otro sistema');
$activo = 'migrar';
include __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-1"><i class="bi bi-box-arrow-in-down text-brand"></i> <?= et('Migrar desde otro sistema') ?></h1>
<p class="text-muted mb-4"><?= et('Trae tus pacientes, tu agenda, tu historial y tus documentos desde Nimbo, Doctoralia, Excel u otro sistema. Sigue los pasos en orden.') ?></p>

<div class="row g-3 mb-4">
    <?php foreach ([
        [1, 'bi-people', t('Pacientes'), t('Primero, para que todo lo demás se ligue al expediente correcto.'), '/pacientes/importar', sprintf(t('%s pacientes hoy'), number_format($nPac))],
        [2, 'bi-calendar-plus', t('Citas'), t('Tu agenda futura (y las pasadas, si quieres el historial de asistencias).'), '/migrar/csv?tipo=citas', sprintf(t('%d médicos dados de alta'), $nMed)],
        [3, 'bi-journal-medical', t('Historial clínico'), t('Consultas y notas pasadas, con su fecha original, en el expediente de cada paciente.'), '/migrar/csv?tipo=historial', ''],
        [4, 'bi-file-earmark-zip', t('Documentos'), t('Un ZIP con PDFs, estudios e imágenes; cada archivo va a su paciente.'), '/migrar/archivos', ''],
    ] as [$n, $ic, $tit, $txt, $url, $extra]): ?>
    <div class="col-md-6 col-xl-3"><a href="<?= BASE_URL . $url ?>" class="card h-100 text-decoration-none text-reset"><div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge rounded-pill text-bg-primary"><?= $n ?></span><i class="bi <?= $ic ?> fs-5 text-brand"></i>
            <span class="fw-semibold"><?= e($tit) ?></span>
        </div>
        <div class="small text-muted"><?= e($txt) ?></div>
        <?php if ($extra): ?><div class="small mt-2"><?= e($extra) ?></div><?php endif; ?>
    </div></a></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7"><div class="card h-100">
        <div class="card-header"><i class="bi bi-envelope-paper"></i> <?= et('Pídele tu información a tu sistema anterior') ?></div>
        <div class="card-body">
            <p class="small text-muted"><?= et('Tus expedientes son tuyos y de tus pacientes: tu proveedor anterior debe entregártelos. Copia este texto y mándalo a su soporte.') ?></p>
            <textarea class="form-control small" rows="12" readonly id="carta"><?= e($carta) ?></textarea>
            <button class="btn btn-sm btn-outline-primary mt-2" type="button" onclick="navigator.clipboard.writeText(document.getElementById('carta').value);this.textContent='<?= e(t('¡Copiado!')) ?>'"><i class="bi bi-clipboard"></i> <?= et('Copiar texto') ?></button>
        </div>
    </div></div>
    <div class="col-lg-5"><div class="card h-100">
        <div class="card-header"><i class="bi bi-lightbulb"></i> <?= et('Consejos') ?></div>
        <div class="card-body small">
            <ul class="ps-3 mb-3">
                <li class="mb-1"><?= et('Si te entregan Excel, ábrelo y guárdalo como CSV UTF-8.') ?></li>
                <li class="mb-1"><?= et('Da de alta a tus médicos en Personal con el mismo nombre que usaban antes: así las citas y consultas se asignan solas.') ?></li>
                <li class="mb-1"><?= et('Puedes importar el mismo archivo dos veces sin miedo: lo que ya existe se salta.') ?></li>
                <li class="mb-1"><?= et('Cada importación queda registrada en la Bitácora.') ?></li>
            </ul>
            <div class="alert alert-light border mb-0"><i class="bi bi-headset"></i> <?= et('¿Prefieres que lo hagamos nosotros? Mándanos los archivos y te dejamos todo listo.') ?>
                <a href="<?= e(wa_link(SOPORTE_WHATSAPP, t('Hola, quiero migrar mi información desde otro sistema a') . ' ' . APP_NAME . '.')) ?>" target="_blank" rel="noopener"><?= et('Escríbenos por WhatsApp') ?></a></div>
        </div>
    </div></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
