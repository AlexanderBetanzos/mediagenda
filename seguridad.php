<?php
/**
 * Página pública "Seguridad": cómo protegemos el expediente y los datos.
 *
 * REGLA: aquí solo va lo que el sistema HACE hoy (cada punto apunta a código
 * real). Nada de certificaciones, porcentajes de disponibilidad ni políticas
 * de respaldo que no estén confirmadas por escrito con el proveedor de
 * hosting. Si algo cambia (proveedor, respaldos, certificación), se actualiza
 * aquí y en privacidad.php.
 */
require_once __DIR__ . '/includes/functions.php';
track_pageview('publico');
$marca = 'MediAgenda';
$actualizado = '24 de noviembre de 2026';
$wa = SOPORTE_WHATSAPP;

$secciones = [
    ['bi-person-lock', 'Acceso a tu cuenta', [
        ['Verificación en dos pasos', 'Además de la contraseña, un código de 6 dígitos de una app autenticadora (Google Authenticator, Microsoft Authenticator, Authy). Con códigos de respaldo por si pierdes el celular. El administrador puede exigirla a todo el personal.'],
        ['Contraseñas que no se pueden leer', 'Se guardan con hash bcrypt: ni nosotros podemos ver tu contraseña. Recuperarla es con un enlace de un solo uso que vence en una hora.'],
        ['Freno a intentos repetidos', 'Después de varios intentos fallidos desde la misma conexión, el inicio de sesión se bloquea temporalmente.'],
        ['Cierre por inactividad', 'Si la computadora se queda abierta, la sesión se cierra sola después del tiempo que elijas (15 minutos a 4 horas), con aviso antes.'],
        ['Tus últimos accesos', 'Cada persona ve desde dónde y cuándo entró a su cuenta, incluidos los intentos fallidos.'],
    ]],
    ['bi-people', 'Quién ve qué', [
        ['Cada consultorio, aislado', 'La información de tu consultorio solo la ve tu equipo. Ningún otro consultorio del sistema puede consultarla.'],
        ['Permisos por rol', 'Administrador, médico y recepción tienen accesos distintos. Solo el personal médico escribe, firma e imprime notas clínicas.'],
        ['Bitácora de todo', 'Queda registrado quién entró, quién abrió cada expediente, qué se creó, cambió, imprimió o borró, con fecha, hora e IP. La bitácora no se puede editar ni borrar desde el sistema.'],
    ]],
    ['bi-file-earmark-medical', 'El expediente clínico', [
        ['Las notas no se editan ni se borran', 'Una corrección se hace con una nota aclaratoria nueva, con su fecha y autor; la nota original se conserva tal cual, como pide la NOM-004.'],
        ['Sello de integridad', 'Cada nota recibe al guardarse un sello SHA-256 encadenado con la nota anterior. Si alguien la modificara por fuera del sistema, el expediente la marca como alterada.'],
        ['Firma electrónica con tu e.firma', 'El médico firma sus notas con su e.firma del SAT. Su archivo .key se guarda cifrado y su contraseña nunca se guarda: nadie puede firmar en su nombre.'],
        ['Conservación', 'No se puede eliminar a un paciente que ya tiene expediente clínico, recetas u hospitalizaciones.'],
        ['Archivos protegidos', 'Estudios, imágenes y PDFs del expediente no tienen una dirección pública: solo se abren desde el sistema, con sesión y permiso.'],
        ['Catálogos oficiales', 'Diagnósticos con el catálogo CIE-10 de la Secretaría de Salud y validación del dígito verificador de la CURP.'],
    ]],
    ['bi-shield-lock', 'Cifrado', [
        ['En tránsito', 'Toda la comunicación entre tu navegador y el sistema viaja cifrada por HTTPS (TLS).'],
        ['Llaves y certificados', 'Los certificados del SAT (sello digital para facturar y e.firma) y los secretos de la verificación en dos pasos se guardan cifrados con AES-256-GCM.'],
        ['Sesión segura', 'La cookie de sesión es HttpOnly, SameSite y Secure, y se renueva al entrar para evitar robos de sesión.'],
    ]],
    ['bi-qr-code', 'Documentos que salen del consultorio', [
        ['Recetas verificables', 'El QR de cada receta confirma que el consultorio la emitió. Para ver los medicamentos se pide la fecha de nacimiento del paciente.'],
        ['Enlaces privados', 'Los enlaces que reciben los pacientes (confirmar cita, recetas, portal) usan claves aleatorias de 128 bits, imposibles de adivinar.'],
        ['Videoconsulta', 'Funciona con WebRTC, que cifra el audio y el video en tránsito (DTLS-SRTP). El sistema no graba las videoconsultas.'],
        ['Pagos', 'Los pagos con tarjeta los procesa Mercado Pago. Los datos de la tarjeta nunca pasan por nuestro sistema ni se guardan en él.'],
    ]],
    ['bi-box-arrow-up', 'Tus datos son tuyos', [
        ['Descárgalos cuando quieras', 'El administrador descarga toda la información de su consultorio en un archivo ZIP desde Configuración, sin pedirnos permiso.'],
        ['Sin venta de datos', 'No vendemos datos personales. Solo los compartimos con los proveedores necesarios para operar, que se listan en el aviso de privacidad.'],
        ['Migración en ambos sentidos', 'Te ayudamos a traer tu información de otro sistema y, si algún día te vas, te llevas tu información.'],
    ]],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seguridad · <?= e($marca) ?></title>
    <meta name="description" content="Cómo protege <?= e($marca) ?> el expediente clínico y los datos de tus pacientes: verificación en dos pasos, bitácora, notas selladas y firmadas con e.firma, cifrado y control de acceso.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --brand:#0f8dbf; --brand-dark:#0b6e96; --ink:#0f1d2b; --mut:#5b6b7c; }
        body { font-family: Inter, system-ui, sans-serif; color: var(--ink); background: #fff; }
        .sg-hero { background: radial-gradient(900px 400px at 85% 0%, rgba(15,141,191,.14), transparent 70%), linear-gradient(#f5fafd, #fff); padding: 4.5rem 0 3rem; }
        .sg-hero h1 { font-weight: 800; letter-spacing: -.03em; font-size: clamp(2rem, 4vw, 3rem); }
        .sg-hero h1 span { color: var(--brand); }
        .sg-pill { display: inline-flex; gap: .4rem; align-items: center; font-weight: 600; font-size: .85rem; color: var(--brand-dark); background: #e6f4fa; padding: .35rem .8rem; border-radius: 99px; }
        .sg-sec { padding: 2.2rem 0; border-top: 1px solid #edf1f5; }
        .sg-sec h2 { font-weight: 750; font-size: 1.4rem; letter-spacing: -.02em; display: flex; gap: .6rem; align-items: center; }
        .sg-sec h2 .ic { width: 40px; height: 40px; border-radius: 12px; background: #e6f4fa; color: var(--brand); display: inline-flex; align-items: center; justify-content: center; }
        .sg-item { border: 1px solid #e6ecf2; border-radius: 14px; padding: 1rem 1.1rem; height: 100%; }
        .sg-item h3 { font-size: 1rem; font-weight: 650; margin-bottom: .3rem; }
        .sg-item p { color: var(--mut); font-size: .92rem; margin: 0; line-height: 1.55; }
        .sg-nom { background: #0f1d2b; color: #e6eef5; border-radius: 20px; padding: 2rem; }
        .sg-nom h2 { color: #fff; font-weight: 750; }
        .sg-nom a { color: #7fd0ee; }
        .btn-primary { background: var(--brand); border-color: var(--brand); }
        .btn-primary:hover { background: var(--brand-dark); border-color: var(--brand-dark); }
    </style>
</head>
<body>
<header class="sg-hero">
    <div class="container" style="max-width:1040px">
        <a href="<?= BASE_URL ?>/" class="text-decoration-none small"><i class="bi bi-arrow-left"></i> <?= e($marca) ?></a>
        <div class="mt-4"><span class="sg-pill"><i class="bi bi-shield-check"></i> Seguridad y privacidad</span></div>
        <h1 class="mt-3">El expediente de tus pacientes, <span>protegido en cada paso</span></h1>
        <p class="lead text-secondary mt-3" style="max-width:720px">Esto es exactamente lo que hace <?= e($marca) ?> para cuidar la información de tu consultorio: quién entra, quién ve qué, cómo se protege cada nota y qué pasa con tus datos.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
            <a href="<?= BASE_URL ?>/auth/registro" class="btn btn-primary btn-lg"><i class="bi bi-rocket-takeoff"></i> Pruébalo 15 días gratis</a>
            <a href="<?= BASE_URL ?>/privacidad" class="btn btn-outline-secondary btn-lg">Aviso de privacidad</a>
        </div>
    </div>
</header>

<main class="container" style="max-width:1040px">
    <?php foreach ($secciones as [$ic, $tit, $items]): ?>
    <section class="sg-sec">
        <h2 class="mb-3"><span class="ic"><i class="bi <?= $ic ?>"></i></span> <?= e($tit) ?></h2>
        <div class="row g-3">
            <?php foreach ($items as [$h, $p]): ?>
            <div class="col-md-6 col-lg-4"><div class="sg-item"><h3><?= e($h) ?></h3><p><?= e($p) ?></p></div></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <section class="sg-sec">
        <div class="sg-nom">
            <h2 class="h4 mb-3"><i class="bi bi-journal-check"></i> NOM-004 y NOM-024</h2>
            <p class="mb-2">Las funciones del expediente se construyeron tomando como guía la <strong>NOM-004-SSA3-2012</strong> (expediente clínico) y la <strong>NOM-024-SSA3-2012</strong> (sistemas de registro electrónico para la salud): identificación con CURP, catálogo CIE-10, control de acceso por rol, bitácora, notas que no se alteran, sello de integridad y firma electrónica.</p>
            <p class="mb-2">Para ser transparentes: <strong><?= e($marca) ?> todavía no cuenta con la certificación de la Dirección General de Información en Salud (DGIS)</strong>, que es la única que puede declarar que un sistema cumple la NOM-024. Si un proveedor te dice que está certificado, puedes confirmarlo en el listado oficial de la DGIS.</p>
            <p class="mb-0 small" style="opacity:.8">La responsabilidad sobre el expediente es compartida: nosotros damos las herramientas y tu consultorio decide quién tiene acceso, mantiene sus contraseñas seguras y recaba el consentimiento de sus pacientes.</p>
        </div>
    </section>

    <section class="sg-sec text-center pb-5">
        <h2 class="justify-content-center mb-2">¿Tienes una pregunta de seguridad?</h2>
        <p class="text-secondary">Escríbenos y te respondemos con detalle técnico si lo necesitas. Si encuentras una vulnerabilidad, avísanos por el mismo medio.</p>
        <a href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hola, tengo una pregunta sobre la seguridad de MediAgenda.') ?>" target="_blank" rel="noopener" class="btn btn-outline-primary"><i class="bi bi-whatsapp"></i> WhatsApp</a>
        <a href="mailto:<?= e(SOPORTE_EMAIL) ?>" class="btn btn-outline-secondary"><i class="bi bi-envelope"></i> <?= e(SOPORTE_EMAIL) ?></a>
        <p class="small text-muted mt-4 mb-0">Última actualización: <?= e($actualizado) ?></p>
    </section>
</main>
</body>
</html>
