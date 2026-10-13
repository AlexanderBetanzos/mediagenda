<?php
/**
 * Aviso de privacidad de la plataforma MediOS (LFPDPPP).
 *
 * Cubre a quien usa la plataforma (consultorios y su personal) y a quien
 * visita el sitio. Los datos de PACIENTES los trata MediOS por cuenta de cada
 * consultorio (encargado), así que aquí solo se explica ese papel: el aviso
 * frente al paciente lo da su consultorio.
 *
 * Si se agrega un proveedor que reciba datos (otra API, otro hosting, otra
 * herramienta de anuncios), hay que listarlo en la sección 6 y mover la fecha.
 */
require_once __DIR__ . '/includes/functions.php';

$marca       = 'MediOS';
$actualizado = '13 de octubre de 2026';
$correo      = SOPORTE_EMAIL;
$wa          = SOPORTE_WHATSAPP;
$waTexto     = preg_replace('/^52/', '', $wa);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aviso de privacidad · <?= e($marca) ?></title>
    <meta name="description" content="Aviso de privacidad de <?= e($marca) ?>: qué datos tratamos, para qué, con quién los compartimos y cómo ejercer tus derechos ARCO.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= asset('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        .legal { max-width: 820px; }
        .legal h2 { font-size: 1.15rem; font-weight: 700; margin-top: 2rem; }
        .legal li { margin-bottom: .35rem; }
        .legal p, .legal li { line-height: 1.6; }
    </style>
</head>
<body class="bg-white">
<main class="container legal py-5">
    <a href="<?= BASE_URL ?>/" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> Volver a <?= e($marca) ?></a>

    <h1 class="h2 mt-3 mb-1">Aviso de privacidad</h1>
    <p class="text-muted small">Última actualización: <?= e($actualizado) ?></p>

    <p>Este aviso explica qué datos personales trata <strong><?= e($marca) ?></strong> (el sistema para consultorios
    disponible en mediagenda.com.mx), para qué los usa, con quién los comparte y cómo puedes ejercer tus derechos,
    conforme a la Ley Federal de Protección de Datos Personales en Posesión de los Particulares y su normatividad.</p>

    <h2>1. Responsable</h2>
    <p><strong>BETASYD Consultoría</strong>, con presencia en Ciudad de México y Oaxaca, México, opera <?= e($marca) ?> y es
    responsable de los datos de las personas que usan la plataforma y de quienes visitan este sitio.
    Contacto: <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a> ·
    WhatsApp <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><?= e($waTexto) ?></a>.</p>

    <h2>2. Datos de pacientes: los trata su consultorio</h2>
    <p>Los datos de los pacientes (identificación, contacto, expediente clínico, diagnósticos, recetas, estudios,
    imágenes y pagos) los captura y administra <strong>cada consultorio</strong>, que es el responsable de ellos y
    quien debe darle su propio aviso de privacidad al paciente. <?= e($marca) ?> solo los almacena y procesa
    <strong>por cuenta y bajo las instrucciones del consultorio</strong>, para prestarle el servicio; no los usa para
    fines propios, no los vende y no los usa para publicidad.</p>
    <p>Si eres paciente y quieres consultar, corregir o eliminar tu información, dirígete a tu consultorio. Si nos
    escribes a nosotros, le haremos llegar tu solicitud.</p>

    <h2>3. Datos que tratamos como responsables</h2>
    <ul>
        <li><strong>Cuenta del consultorio y su personal:</strong> nombre, nombre del consultorio, correo electrónico,
            teléfono, rol en el sistema y contraseña (se guarda cifrada; nadie puede leerla).</li>
        <li><strong>Suscripción:</strong> plan contratado, estado de la membresía y datos del pago. Los datos de la
            tarjeta los recibe y procesa directamente Mercado Pago; <?= e($marca) ?> nunca los ve ni los guarda.</li>
        <li><strong>Uso y seguridad:</strong> dirección IP, tipo de navegador, páginas visitadas, la página de la que
            vienes y la bitácora de acciones dentro del sistema (quién hizo qué y cuándo).</li>
        <li><strong>Soporte:</strong> lo que nos escribas por correo, WhatsApp o el buzón de comentarios.</li>
    </ul>

    <h2>4. Para qué los usamos</h2>
    <p><strong>Finalidades necesarias</strong> para darte el servicio:</p>
    <ul>
        <li>Crear y administrar tu cuenta y darte acceso según tu rol.</li>
        <li>Cobrar la suscripción y avisarte de vencimientos o cambios de plan.</li>
        <li>Enviarte correos del servicio (bienvenida, avisos de tu cuenta y avisos técnicos).</li>
        <li>Darte soporte técnico.</li>
        <li>Proteger la plataforma: detectar accesos indebidos, auditar cambios y resolver fallas.</li>
    </ul>
    <p><strong>Finalidades adicionales</strong> (no son necesarias para el servicio):</p>
    <ul>
        <li>Medir el resultado de nuestros anuncios en Facebook e Instagram (ver sección 5).</li>
        <li>Enviarte novedades, encuestas o promociones de <?= e($marca) ?>.</li>
    </ul>
    <p>Si no quieres que usemos tus datos para estas finalidades adicionales, escríbenos a
    <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a>. Negarte no afecta tu servicio.</p>

    <h2>5. Cookies y medición de anuncios (Meta Pixel)</h2>
    <ul>
        <li><strong>Cookies propias:</strong> una cookie de sesión para mantenerte dentro del sistema y una cookie
            (<code>viz</code>) que distingue visitas únicas para nuestras estadísticas; dura un año.</li>
        <li><strong>Meta Pixel:</strong> usamos la herramienta de Meta Platforms (Facebook/Instagram) para saber si
            alguien que vio nuestros anuncios visitó el sitio o creó una cuenta. Meta puede colocar sus propias cookies
            y asociar esa actividad a tu perfil conforme a su
            <a href="https://www.facebook.com/privacy/policy/" target="_blank" rel="noopener">política de privacidad</a>.</li>
        <li>El Pixel <strong>solo</strong> se carga en la página principal y en el registro. <strong>Nunca</strong> se carga
            en el panel clínico, el expediente, el portal del paciente ni las páginas de los consultorios, y no se le
            envía ningún dato de pacientes.</li>
        <li>Puedes bloquear o borrar cookies desde tu navegador, y ajustar los anuncios que ves en tu
            <a href="https://www.facebook.com/adpreferences/" target="_blank" rel="noopener">configuración de anuncios de Meta</a>.</li>
    </ul>

    <h2>6. Con quién se comparten los datos</h2>
    <p>No vendemos datos personales. Solo los compartimos con proveedores que necesitamos para operar, y solo cuando
    se usa la función correspondiente:</p>
    <ul>
        <li><strong>Hostinger</strong>: hospedaje del sistema y de la base de datos, y envío de correos. Sus servidores
            pueden estar fuera de México.</li>
        <li><strong>Mercado Pago</strong>: cobro de la suscripción. Si el consultorio conecta su propia cuenta de
            Mercado Pago para cobrar a sus pacientes, el pago va directo a esa cuenta.</li>
        <li><strong>Anthropic</strong> (IA clínica): solo cuando el médico usa la función de estructurar la consulta
            con IA, se envía el texto que dictó o pegó para devolverlo ordenado en los campos de la consulta.</li>
        <li><strong>Jitsi</strong> (videoconsulta): la llamada se realiza en el servidor de videollamadas que tenga
            configurado el consultorio (por omisión, meet.jit.si).</li>
        <li><strong>Meta</strong>: medición de anuncios (sección 5) y, si el consultorio conecta su propia cuenta de
            WhatsApp Business, el envío de avisos a sus pacientes desde el número del consultorio.</li>
        <li><strong>Autoridades</strong>, cuando una ley o una orden judicial lo exija.</li>
    </ul>
    <p>Las transferencias a estos proveedores son necesarias para prestar el servicio que contrataste, por lo que
    no requieren tu consentimiento conforme a la ley.</p>

    <h2>7. Cómo protegemos la información</h2>
    <p>La información viaja cifrada (HTTPS), las contraseñas se guardan cifradas, cada consultorio está aislado de los
    demás, el acceso se controla por roles y los cambios importantes quedan registrados en una bitácora.</p>

    <h2>8. Conservación y cancelación</h2>
    <p>Conservamos los datos mientras la cuenta esté activa. Si cancelas, conservas el acceso hasta el final del
    periodo pagado y te ayudamos a llevarte tu información. Después puedes pedirnos la eliminación definitiva.</p>
    <p>Ten en cuenta que la NOM-004-SSA3-2012 obliga al consultorio a conservar el expediente clínico por al menos
    cinco años: antes de pedir la eliminación, descarga lo que debas conservar.</p>

    <h2>9. Tus derechos (ARCO) y revocación del consentimiento</h2>
    <p>Puedes pedir el <strong>acceso</strong> a tus datos, su <strong>rectificación</strong>, su
    <strong>cancelación</strong> u <strong>oponerte</strong> a su uso, así como revocar tu consentimiento o limitar
    su uso, escribiendo a <a href="mailto:<?= e($correo) ?>"><?= e($correo) ?></a> con:</p>
    <ul>
        <li>Tu nombre y un medio para responderte.</li>
        <li>Un documento que acredite tu identidad (o la de tu representante).</li>
        <li>Qué datos y qué derecho quieres ejercer.</li>
    </ul>
    <p>Te responderemos en un máximo de 20 días hábiles y, si procede, lo haremos efectivo dentro de los 15 días
    hábiles siguientes. Si consideras que tu derecho no fue atendido, puedes acudir a la autoridad competente en
    materia de protección de datos personales.</p>

    <h2>10. Cambios a este aviso</h2>
    <p>Cualquier cambio se publicará en esta misma página con su fecha de actualización. Si el cambio es importante,
    también lo avisaremos por correo a las cuentas activas.</p>

    <hr class="my-5">
    <p class="small text-muted mb-0">&copy; <?= date('Y') ?> BETASYD Consultoría · <?= e($marca) ?></p>
</main>
</body>
</html>
