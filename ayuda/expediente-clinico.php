<?php
/**
 * Artículo público de ayuda: "Expediente clínico electrónico".
 * Explica, paso a paso, cómo se crea y se usa el expediente en MediAgenda.
 * Solo describe funciones que existen; si una cambia, se actualiza aquí.
 */
require_once __DIR__ . '/../includes/functions.php';
track_pageview('publico');
$marca = 'MediAgenda';

$indice = [
    'perfil'     => 'Crear el perfil del paciente',
    'contenido'  => 'Qué contiene el expediente',
    'consulta'   => 'Registrar una consulta (nota médica)',
    'corregir'   => 'Corregir una nota: notas aclaratorias',
    'firma'      => 'Firmar notas con tu e.firma',
    'imprimir'   => 'Imprimir el expediente',
    'qr'         => 'Códigos QR en las recetas',
    'historial'  => 'Quién vio o cambió qué',
    'normas'     => 'NOM-004 y NOM-024',
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expediente clínico electrónico · Ayuda de <?= e($marca) ?></title>
    <meta name="description" content="Cómo crear y usar el expediente clínico electrónico en <?= e($marca) ?>: perfil del paciente, notas médicas, notas aclaratorias, firma con e.firma, impresión y recetas con QR.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --brand:#0f8dbf; --brand-dark:#0b6e96; --ink:#0f1d2b; --mut:#5b6b7c; }
        body { font-family: Inter, system-ui, sans-serif; color: var(--ink); }
        .ay-top { background: linear-gradient(#f5fafd, #fff); border-bottom: 1px solid #edf1f5; padding: 2.5rem 0 2rem; }
        .ay-top h1 { font-weight: 800; letter-spacing: -.03em; }
        .ay-wrap { max-width: 1100px; }
        .ay-toc { position: sticky; top: 1.5rem; font-size: .9rem; }
        .ay-toc a { display: block; color: var(--mut); text-decoration: none; padding: .3rem 0 .3rem .8rem; border-left: 2px solid #e6ecf2; }
        .ay-toc a:hover { color: var(--brand); border-left-color: var(--brand); }
        article h2 { font-weight: 750; font-size: 1.45rem; letter-spacing: -.02em; margin-top: 2.6rem; scroll-margin-top: 1rem; }
        article h3 { font-weight: 650; font-size: 1.05rem; margin-top: 1.4rem; }
        article p, article li { line-height: 1.65; }
        .paso { display: flex; gap: .8rem; margin: .6rem 0; }
        .paso .n { flex: none; width: 28px; height: 28px; border-radius: 50%; background: #e6f4fa; color: var(--brand-dark); font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: .85rem; }
        .nota { background: #f5fafd; border-left: 3px solid var(--brand); border-radius: 8px; padding: .8rem 1rem; font-size: .93rem; }
        .aviso { background: #fffbeb; border-left: 3px solid #d97706; border-radius: 8px; padding: .8rem 1rem; font-size: .93rem; }
        .ui { font-weight: 600; background: #eef2f6; border-radius: 6px; padding: .05rem .4rem; font-size: .9em; white-space: nowrap; }
        .btn-primary { background: var(--brand); border-color: var(--brand); }
    </style>
</head>
<body>
<header class="ay-top">
    <div class="container ay-wrap">
        <nav class="small mb-3"><a href="<?= BASE_URL ?>/" class="text-decoration-none"><?= e($marca) ?></a> <span class="text-muted">/ Ayuda / Expedientes clínicos</span></nav>
        <h1>Expediente clínico electrónico</h1>
        <p class="lead text-secondary mb-0" style="max-width:760px">Cómo crear el perfil de tus pacientes, registrar consultas, corregirlas sin borrar nada, firmarlas con tu e.firma e imprimir el expediente.</p>
    </div>
</header>

<div class="container ay-wrap py-4">
<div class="row g-5">
    <aside class="col-lg-3 d-none d-lg-block"><nav class="ay-toc">
        <div class="fw-semibold mb-2">En este artículo</div>
        <?php foreach ($indice as $k => $t): ?><a href="#<?= $k ?>"><?= e($t) ?></a><?php endforeach; ?>
    </nav></aside>

    <article class="col-lg-9">

    <h2 id="perfil" style="margin-top:0">1. Crear el perfil del paciente</h2>
    <p>Hay dos formas de dar de alta pacientes:</p>
    <h3>Uno por uno</h3>
    <div class="paso"><span class="n">1</span><div>Entra a <span class="ui">Pacientes</span> y da clic en <span class="ui">Nuevo paciente</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Escribe su <b>CURP</b>: el sistema revisa que sea válida, llena solos la fecha de nacimiento y el sexo, y te avisa si ya existe otro paciente con esa CURP.</div></div>
    <div class="paso"><span class="n">3</span><div>Completa sus datos de contacto, <b>alergias</b>, tipo de sangre y antecedentes. Si quieres, tómale una foto con la cámara.</div></div>
    <div class="paso"><span class="n">4</span><div>Da clic en <span class="ui">Guardar</span>.</div></div>
    <h3>Muchos a la vez (importar)</h3>
    <p>Si vienes de otro sistema o de Excel, en <span class="ui">Pacientes → Importar</span> subes un archivo CSV. El sistema reconoce las columnas por su nombre (nombre, apellidos, celular, CURP, alergias…), te deja revisarlas y <b>no duplica</b> pacientes que ya existen. También puedes traer tus <b>citas</b>, tu <b>historial de consultas</b> y tus <b>documentos en PDF</b> desde <span class="ui">Migrar desde otro sistema</span>.</p>

    <h2 id="contenido">2. Qué contiene el expediente</h2>
    <p>Al abrir a un paciente ves todo en una sola pantalla:</p>
    <ul>
        <li><b>Encabezado</b> con edad, tipo de sangre y sus <b>alergias en rojo</b>, para verlas antes de atender.</li>
        <li><b>Resumen</b>: antecedentes, diagnósticos recientes, últimos signos vitales con IMC, número de consultas y su próxima cita.</li>
        <li>Pestaña <span class="ui">Expediente</span>: todas sus consultas, de la más reciente a la más antigua, con buscador ("¿cuándo le di amoxicilina?") y gráficas de la evolución de sus signos vitales.</li>
        <li><span class="ui">Medicamentos</span> actuales, <span class="ui">Archivos</span> (estudios, imágenes, PDFs), <span class="ui">Citas</span> y <span class="ui">Documentos</span> (consentimientos firmados).</li>
        <li>Las herramientas de tu especialidad: odontograma, valoración de fisioterapia, nutrición, pediatría y otras.</li>
    </ul>
    <p>Para cambiar sus datos generales usa <span class="ui">Editar</span> en el encabezado del paciente.</p>

    <h2 id="consulta">3. Registrar una consulta (nota médica)</h2>
    <div class="paso"><span class="n">1</span><div>En el expediente da clic en <span class="ui">Nueva consulta</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Captura el <b>motivo</b>, los <b>signos vitales</b> (peso, talla, TA, temperatura, glucosa, FC, FR y SpO₂), la <b>exploración</b>, el <b>diagnóstico</b>, el <b>pronóstico</b>, el <b>tratamiento</b> y notas. Solo el motivo es obligatorio.</div></div>
    <div class="paso"><span class="n">3</span><div>En el diagnóstico, escribe el padecimiento o su clave y elige del <b>catálogo CIE-10</b> oficial: se guarda el código junto con el texto.</div></div>
    <div class="paso"><span class="n">4</span><div>Si quieres, adjunta un archivo (un estudio o una foto) que quedará ligado a esa consulta, y da clic en <span class="ui">Guardar</span>.</div></div>
    <div class="nota"><i class="bi bi-mic"></i> En Chrome y Edge puedes <b>dictar</b> la consulta y, si tu plan incluye la IA clínica, ella acomoda lo que dictaste en cada campo. Tú revisas antes de guardar.</div>
    <p class="mt-3">Al guardarse, la nota queda con la <b>fecha, la hora y el nombre</b> de quien la escribió, y recibe un <b>sello de integridad</b> (aparece como <span class="ui"><i class="bi bi-shield-check text-success"></i> Íntegra</span>). Si alguien la modificara por fuera del sistema, aparecería en rojo como <span class="ui">Alterada</span>.</p>

    <h2 id="corregir">4. Corregir una nota: notas aclaratorias</h2>
    <p>Las notas médicas <b>no se editan ni se borran</b>: así lo pide la NOM-004 y es lo que le da valor legal al expediente. Si te equivocaste o necesitas agregar algo:</p>
    <div class="paso"><span class="n">1</span><div>En la nota, da clic en <span class="ui">Nota aclaratoria</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Escribe la corrección o el dato adicional y da clic en <span class="ui">Agregar aclaración</span>.</div></div>
    <p>La aclaración aparece debajo de la nota original, con su propia fecha, hora y autor. La nota original se conserva tal cual.</p>

    <h2 id="firma">5. Firmar notas con tu e.firma</h2>
    <p>Puedes firmar tus notas con tu <b>e.firma del SAT</b> (antes FIEL), que es firma electrónica avanzada.</p>
    <h3>Configurarla (una sola vez)</h3>
    <div class="paso"><span class="n">1</span><div>Abre tu menú (arriba a la derecha) → <span class="ui">Mi firma electrónica</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Sube tu archivo <b>.cer</b> y tu <b>.key</b> y escribe tu contraseña para comprobar que son tuyos. La .key se guarda cifrada; <b>la contraseña no se guarda</b>.</div></div>
    <h3>Firmar una nota</h3>
    <div class="paso"><span class="n">1</span><div>En una nota que tú escribiste, da clic en <span class="ui">Firmar con e.firma</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Escribe la contraseña de tu e.firma. La nota queda marcada como <span class="ui"><i class="bi bi-pen"></i> Firmada</span>, con el número de tu certificado y la fecha.</div></div>
    <div class="aviso"><i class="bi bi-info-circle"></i> Solo quien escribió la nota puede firmarla. Se usa la e.firma, no el sello digital (CSD) que sirve para facturar.</div>

    <h2 id="imprimir">6. Imprimir el expediente</h2>
    <div class="paso"><span class="n">1</span><div>En el expediente da clic en el botón <span class="ui"><i class="bi bi-printer"></i></span> junto a <span class="ui">Nueva consulta</span>, o en <span class="ui">Imprimir nota</span> dentro de una consulta.</div></div>
    <div class="paso"><span class="n">2</span><div>Elige <b>Expediente completo</b> (todas las notas, de la más antigua a la más reciente) o <b>Resumen clínico</b> (datos, antecedentes, medicamentos, diagnósticos y últimas notas).</div></div>
    <div class="paso"><span class="n">3</span><div>Da clic en <span class="ui">Imprimir / PDF</span> y elige tu impresora o "Guardar como PDF".</div></div>
    <p>El documento lleva el membrete de tu consultorio (con tu CLUES si la capturaste en Configuración), la ficha de identificación del paciente, cada nota con fecha, hora, autor y cédula, las notas aclaratorias, el sello de integridad, la firma electrónica cuando existe y la línea de firma autógrafa.</p>

    <h2 id="qr">7. Códigos QR en las recetas</h2>
    <p>Cada receta impresa o enviada por WhatsApp lleva un <b>código QR</b>. Al escanearlo:</p>
    <ul>
        <li>Cualquiera puede confirmar que la receta <b>existe en el sistema del consultorio</b>: folio, fecha, médico y cédula profesional (con enlace para consultarla en la SEP).</li>
        <li>Para ver los <b>medicamentos</b>, se pide la <b>fecha de nacimiento del paciente</b>. Así la información de salud no queda expuesta si alguien encuentra la receta.</li>
    </ul>

    <h2 id="historial">8. Quién vio o cambió qué</h2>
    <p>En <span class="ui">Administración → Bitácora</span> el administrador ve quién entró al sistema, quién <b>abrió cada expediente</b>, qué se creó, cambió, firmó, imprimió o borró, con fecha, hora e IP. Puedes filtrar por persona, por tipo y por fechas. La bitácora no se puede editar ni borrar.</p>
    <div class="nota"><i class="bi bi-shield-lock"></i> Para proteger aún más los expedientes, activa la <b>verificación en dos pasos</b> en tu menú → <span class="ui">Seguridad de mi cuenta</span>. El administrador puede exigirla a todo el personal.</div>

    <h2 id="normas">9. NOM-004 y NOM-024</h2>
    <p>El expediente de <?= e($marca) ?> se construyó tomando como guía la <b>NOM-004-SSA3-2012</b> (del expediente clínico) y la <b>NOM-024-SSA3-2012</b> (sistemas de registro electrónico para la salud): identificación con CURP, catálogo CIE-10, notas con fecha, hora y autor que no se alteran, notas aclaratorias, control de acceso por rol, bitácora, sello de integridad y firma electrónica.</p>
    <p><?= e($marca) ?> todavía no cuenta con la certificación de la DGIS. El contenido de cada nota (lo que se escribe) sigue siendo responsabilidad del médico, como en el papel. Más detalle en <a href="<?= BASE_URL ?>/seguridad">Seguridad</a>.</p>

    <div class="border rounded-4 p-4 mt-5 text-center">
        <div class="fw-semibold mb-1">¿Te quedó alguna duda?</div>
        <p class="text-secondary small">Escríbenos y te ayudamos en español.</p>
        <a href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-whatsapp"></i> WhatsApp</a>
        <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-primary btn-sm"><i class="bi bi-rocket-takeoff"></i> Pruébalo 15 días gratis</a>
    </div>
    </article>
</div>
</div>
</body>
</html>
