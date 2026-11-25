<?php /* Artículo largo: expediente clínico electrónico (se incluye desde includes/centro_ayuda.php). */ ?>
<h2 id="perfil">Crear el perfil del paciente</h2>
    <p>Hay dos formas de dar de alta pacientes:</p>
    <h3>Uno por uno</h3>
    <div class="paso"><span class="n">1</span><div>Entra a <span class="ui">Pacientes</span> y da clic en <span class="ui">Nuevo paciente</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Escribe su <b>CURP</b>: el sistema revisa que sea válida, llena solos la fecha de nacimiento y el sexo, y te avisa si ya existe otro paciente con esa CURP.</div></div>
    <div class="paso"><span class="n">3</span><div>Completa sus datos de contacto, <b>alergias</b>, tipo de sangre y antecedentes. Si quieres, tómale una foto con la cámara.</div></div>
    <div class="paso"><span class="n">4</span><div>Da clic en <span class="ui">Guardar</span>.</div></div>
    <h3>Muchos a la vez (importar)</h3>
    <p>Si vienes de otro sistema o de Excel, en <span class="ui">Pacientes → Importar</span> subes un archivo CSV. El sistema reconoce las columnas por su nombre (nombre, apellidos, celular, CURP, alergias…), te deja revisarlas y <b>no duplica</b> pacientes que ya existen. También puedes traer tus <b>citas</b>, tu <b>historial de consultas</b> y tus <b>documentos en PDF</b> desde <span class="ui">Migrar desde otro sistema</span>.</p>

    <h2 id="contenido">Qué contiene el expediente</h2>
    <p>Al abrir a un paciente ves todo en una sola pantalla:</p>
    <ul>
        <li><b>Encabezado</b> con edad, tipo de sangre y sus <b>alergias en rojo</b>, para verlas antes de atender.</li>
        <li><b>Resumen</b>: antecedentes, diagnósticos recientes, últimos signos vitales con IMC, número de consultas y su próxima cita.</li>
        <li>Pestaña <span class="ui">Expediente</span>: todas sus consultas, de la más reciente a la más antigua, con buscador ("¿cuándo le di amoxicilina?") y gráficas de la evolución de sus signos vitales.</li>
        <li><span class="ui">Medicamentos</span> actuales, <span class="ui">Archivos</span> (estudios, imágenes, PDFs), <span class="ui">Citas</span> y <span class="ui">Documentos</span> (consentimientos firmados).</li>
        <li>Las herramientas de tu especialidad: odontograma, valoración de fisioterapia, nutrición, pediatría y otras.</li>
    </ul>
    <p>Para cambiar sus datos generales usa <span class="ui">Editar</span> en el encabezado del paciente.</p>

    <h2 id="consulta">Registrar una consulta (nota médica)</h2>
    <div class="paso"><span class="n">1</span><div>En el expediente da clic en <span class="ui">Nueva consulta</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Captura el <b>motivo</b>, los <b>signos vitales</b> (peso, talla, TA, temperatura, glucosa, FC, FR y SpO₂), la <b>exploración</b>, el <b>diagnóstico</b>, el <b>pronóstico</b>, el <b>tratamiento</b> y notas. Solo el motivo es obligatorio.</div></div>
    <div class="paso"><span class="n">3</span><div>En el diagnóstico, escribe el padecimiento o su clave y elige del <b>catálogo CIE-10</b> oficial: se guarda el código junto con el texto.</div></div>
    <div class="paso"><span class="n">4</span><div>Si quieres, adjunta un archivo (un estudio o una foto) que quedará ligado a esa consulta, y da clic en <span class="ui">Guardar</span>.</div></div>
    <div class="nota"><i class="bi bi-mic"></i> En Chrome y Edge puedes <b>dictar</b> la consulta y, si tu plan incluye la IA clínica, ella acomoda lo que dictaste en cada campo. Tú revisas antes de guardar.</div>
    <p class="mt-3">Al guardarse, la nota queda con la <b>fecha, la hora y el nombre</b> de quien la escribió, y recibe un <b>sello de integridad</b> (aparece como <span class="ui"><i class="bi bi-shield-check text-success"></i> Íntegra</span>). Si alguien la modificara por fuera del sistema, aparecería en rojo como <span class="ui">Alterada</span>.</p>

    <h2 id="corregir">Corregir una nota: notas aclaratorias</h2>
    <p>Las notas médicas <b>no se editan ni se borran</b>: así lo pide la NOM-004 y es lo que le da valor legal al expediente. Si te equivocaste o necesitas agregar algo:</p>
    <div class="paso"><span class="n">1</span><div>En la nota, da clic en <span class="ui">Nota aclaratoria</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Escribe la corrección o el dato adicional y da clic en <span class="ui">Agregar aclaración</span>.</div></div>
    <p>La aclaración aparece debajo de la nota original, con su propia fecha, hora y autor. La nota original se conserva tal cual.</p>

    <h2 id="firma">Firmar notas con tu e.firma</h2>
    <p>Puedes firmar tus notas con tu <b>e.firma del SAT</b> (antes FIEL), que es firma electrónica avanzada.</p>
    <h3>Configurarla (una sola vez)</h3>
    <div class="paso"><span class="n">1</span><div>Abre tu menú (arriba a la derecha) → <span class="ui">Mi firma electrónica</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Sube tu archivo <b>.cer</b> y tu <b>.key</b> y escribe tu contraseña para comprobar que son tuyos. La .key se guarda cifrada; <b>la contraseña no se guarda</b>.</div></div>
    <h3>Firmar una nota</h3>
    <div class="paso"><span class="n">1</span><div>En una nota que tú escribiste, da clic en <span class="ui">Firmar con e.firma</span>.</div></div>
    <div class="paso"><span class="n">2</span><div>Escribe la contraseña de tu e.firma. La nota queda marcada como <span class="ui"><i class="bi bi-pen"></i> Firmada</span>, con el número de tu certificado y la fecha.</div></div>
    <div class="aviso"><i class="bi bi-info-circle"></i> Solo quien escribió la nota puede firmarla. Se usa la e.firma, no el sello digital (CSD) que sirve para facturar.</div>

    <h2 id="imprimir">Imprimir el expediente</h2>
    <div class="paso"><span class="n">1</span><div>En el expediente da clic en el botón <span class="ui"><i class="bi bi-printer"></i></span> junto a <span class="ui">Nueva consulta</span>, o en <span class="ui">Imprimir nota</span> dentro de una consulta.</div></div>
    <div class="paso"><span class="n">2</span><div>Elige <b>Expediente completo</b> (todas las notas, de la más antigua a la más reciente) o <b>Resumen clínico</b> (datos, antecedentes, medicamentos, diagnósticos y últimas notas).</div></div>
    <div class="paso"><span class="n">3</span><div>Da clic en <span class="ui">Imprimir / PDF</span> y elige tu impresora o "Guardar como PDF".</div></div>
    <p>El documento lleva el membrete de tu consultorio (con tu CLUES si la capturaste en Configuración), la ficha de identificación del paciente, cada nota con fecha, hora, autor y cédula, las notas aclaratorias, el sello de integridad, la firma electrónica cuando existe y la línea de firma autógrafa.</p>

    <h2 id="qr">Códigos QR en las recetas</h2>
    <p>Cada receta impresa o enviada por WhatsApp lleva un <b>código QR</b>. Al escanearlo:</p>
    <ul>
        <li>Cualquiera puede confirmar que la receta <b>existe en el sistema del consultorio</b>: folio, fecha, médico y cédula profesional (con enlace para consultarla en la SEP).</li>
        <li>Para ver los <b>medicamentos</b>, se pide la <b>fecha de nacimiento del paciente</b>. Así la información de salud no queda expuesta si alguien encuentra la receta.</li>
    </ul>

    <h2 id="historial">Quién vio o cambió qué</h2>
    <p>En <span class="ui">Administración → Bitácora</span> el administrador ve quién entró al sistema, quién <b>abrió cada expediente</b>, qué se creó, cambió, firmó, imprimió o borró, con fecha, hora e IP. Puedes filtrar por persona, por tipo y por fechas. La bitácora no se puede editar ni borrar.</p>
    <div class="nota"><i class="bi bi-shield-lock"></i> Para proteger aún más los expedientes, activa la <b>verificación en dos pasos</b> en tu menú → <span class="ui">Seguridad de mi cuenta</span>. El administrador puede exigirla a todo el personal.</div>

    <h2 id="normas">NOM-004 y NOM-024</h2>
    <p>El expediente de MediAgenda se construyó tomando como guía la <b>NOM-004-SSA3-2012</b> (del expediente clínico) y la <b>NOM-024-SSA3-2012</b> (sistemas de registro electrónico para la salud): identificación con CURP, catálogo CIE-10, notas con fecha, hora y autor que no se alteran, notas aclaratorias, control de acceso por rol, bitácora, sello de integridad y firma electrónica.</p>
    <p>MediAgenda todavía no cuenta con la certificación de la DGIS. El contenido de cada nota (lo que se escribe) sigue siendo responsabilidad del médico, como en el papel. Más detalle en <a href="<?= BASE_URL ?>/seguridad">Seguridad</a>.</p>
