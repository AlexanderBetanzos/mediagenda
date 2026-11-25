<?php
/**
 * Guías de ayuda por pantalla (clave 'ruta:<carpeta>/<archivo>') y por
 * acción (clave 'modulo.accion'), complemento de includes/ayuda.php.
 *
 * - El "?" del título y el botón flotante eligen la guía por la RUTA de la
 *   página (ayuda_clave_pagina()), así cada pantalla tiene la suya aunque
 *   comparta menú con otras (todas las especialidades viven en "Pacientes").
 * - Las guías de acción se colocan con ayuda_btn('clave') junto al botón o
 *   sección que explican.
 *
 * Mismo formato: titulo, intro, pasos[], tips[]. Pasan por t() (vocabulario
 * del giro). Solo describen funciones que existen.
 */
function ayuda_textos_rutas(): array
{
    $T = [];
    $g = fn (string $titulo, string $intro, array $pasos, array $tips = []) => compact('titulo', 'intro', 'pasos', 'tips');

    /* ───────────── Pacientes y expediente ───────────── */
    $T['ruta:pacientes/ver'] = $g('Expediente del paciente',
        'Todo lo del paciente en una pantalla: datos, alertas, consultas, recetas, archivos, citas y las herramientas de tu especialidad.', [
        'Arriba ves sus <b>alergias</b> (en rojo), edad y tipo de sangre. Revísalas antes de atender.',
        'Los botones del encabezado son lo de todos los días: <b>WhatsApp</b>, <b>Cita</b>, <b>Receta</b> y la herramienta principal de tu especialidad.',
        '<b>Más acciones</b> agrupa cobros, estudios (laboratorio, ultrasonido, vacunas) y las herramientas de especialidad.',
        'En <b>Nueva consulta</b> registras la visita: motivo, signos vitales, exploración, diagnóstico y tratamiento.',
        'Abajo están sus consultas anteriores, medicamentos actuales, archivos, citas y documentos firmados.',
    ], ['Los resúmenes de especialidad (dental, fisioterapia, nutrición…) aparecen solos en la ficha cuando el paciente tiene registros.']);
    $T['paciente.consulta'] = $g('Cómo registrar una consulta',
        'La nota de la visita. Solo el motivo es obligatorio; lo demás llénalo según el caso.', [
        'Si tienes plantillas, elige una en <b>Plantilla</b> y se rellenan los campos; después ajústalos.',
        'Captura los <b>signos vitales</b>: el IMC se calcula solo con peso y estatura, y se grafican en "Signos vitales · evolución".',
        'Escribe exploración, <b>diagnóstico</b> y tratamiento. Lo que pongas en <b>Receta</b> sale en la receta impresa.',
        'Si quieres, adjunta un archivo (estudio, foto, PDF) a esta misma consulta.',
        'Da clic en <b>Guardar consulta</b>.',
    ], ['Crea tus plantillas en <b>Plantillas de consulta</b> para no escribir lo mismo cada vez.']);
    $T['paciente.ia'] = $g('Dictar la consulta con IA',
        'Dicta o pega tus notas como las dirías en voz alta, y la IA las acomoda en motivo, exploración, diagnóstico y tratamiento.', [
        'Da clic en <b>Dictar</b> y habla, o pega el texto de tus notas.',
        'Da clic en <b>Acomodar en los campos</b>.',
        'Revisa cada campo y corrige lo necesario antes de guardar.',
    ], ['La consulta la firmas tú: revisa siempre lo que rellene la IA.', 'El contador indica cuántos usos te quedan este mes según tu plan.']);
    $T['paciente.medicamentos'] = $g('Medicamentos actuales',
        'La lista de lo que el paciente toma hoy, para revisar interacciones y no repetir tratamientos.', [
        'Agrega el medicamento con dosis, frecuencia, vía y desde cuándo lo toma.',
        'Cuando lo deje, márcalo como <b>Suspendido</b> en lugar de borrarlo: así queda el historial.',
    ]);
    $T['paciente.archivos'] = $g('Archivos del expediente',
        'Estudios, radiografías, PDFs y fotos guardados de forma privada en el expediente.', [
        'Elige el archivo y escribe una descripción (por ejemplo "Radiografía de tórax").',
        'Da clic en <b>Subir</b>. Desde la lista puedes verlo, descargarlo o eliminarlo.',
    ], ['Solo el personal del consultorio puede verlos; el paciente los ve en su portal si tiene acceso.']);
    $T['paciente.portal'] = $g('Portal del paciente',
        'Un acceso para que el paciente vea sus citas, recetas, estudios y, si aplica, su rutina de ejercicios.', [
        'El paciente necesita un <b>correo</b> en su ficha.',
        'Activa el portal y asígnale una contraseña (mínimo 6 caracteres).',
        'Mándale el acceso por WhatsApp desde el botón del encabezado.',
    ]);
    $T['ruta:pacientes/edit'] = $g('Editar paciente', 'Corrige o completa los datos del paciente.', [
        'Cambia lo que necesites: contacto, datos clínicos, contacto de emergencia.',
        'Da clic en <b>Guardar</b>; los cambios se ven de inmediato en su expediente.',
    ]);
    $T['ruta:expediente/index'] = $g('Expedientes', 'Busca a un paciente y entra a su historial clínico.', [
        'Escribe el nombre en el buscador.', 'Filtra para ver solo a quienes ya tienen consultas.', 'Da clic en el paciente para abrir su expediente.',
    ]);

    /* ───────────── Agenda ───────────── */
    $T['ruta:citas/calendario'] = $g('Calendario', 'Tus citas por día, semana o mes.', [
        'Cambia entre día, semana y mes con los botones de arriba.',
        '<b>Arrastra</b> una cita para cambiarla de día u hora; se guarda sola.',
        'Da clic en una cita para ver su detalle o editarla.',
    ]);
    $T['ruta:citas/sala'] = $g('Sala de espera', 'El flujo de hoy: quién llegó, quién está en consulta y cuánto ha esperado cada paciente.', [
        'Cuando el paciente llegue, da clic en <b>Check-in</b>: empieza a contar su espera.',
        'Al pasarlo, da clic en <b>Pasar a consulta</b>.',
        'Al terminar, <b>Finalizar</b>: la cita queda como atendida.',
    ], ['Úsala en recepción en una pantalla aparte para que todos vean el estado del día.']);
    $T['ruta:citas/edit'] = $g('Editar cita', 'Cambia fecha, hora, médico, motivo o estado de la cita.', [
        'Ajusta lo necesario y guarda.', 'Si cambiaste la fecha, manda de nuevo el recordatorio por WhatsApp desde la agenda.',
    ]);
    $T['ruta:citas/create'] = 'citas.nueva';
    $T['ruta:pacientes/create'] = 'pacientes.nuevo';
    $T['ruta:citas/horarios'] = 'page:horarios';
    $T['ruta:telemedicina/sala'] = $g('Videoconsulta', 'La sala del médico para atender en línea, sin que el paciente instale nada.', [
        'Entra a la sala unos minutos antes: el paciente ve en su pantalla que ya estás conectado.',
        'Permite el acceso a cámara y micrófono cuando el navegador lo pida.',
        'Al terminar, registra la consulta en el expediente como siempre.',
    ], ['El paciente entra con el enlace de su cita (le llega en el recordatorio).']);

    /* ───────────── Recetas, cobros, caja ───────────── */
    $T['ruta:recetas/create'] = $g('Cómo hacer una receta', 'Receta con tu marca, lista para imprimir o mandar.', [
        'Elige al paciente: verás sus <b>alergias</b> y enfermedades crónicas a la derecha.',
        '¿Recetas lo mismo seguido? Toca una <b>favorita</b> arriba de los medicamentos y se llena sola.',
        'Escribe el medicamento y elígelo de la lista: la dosis, vía, frecuencia y duración que ya usaste se llenan solas.',
        'Si un medicamento coincide con una alergia registrada, aparece un aviso: revísalo antes de guardar.',
        'Escribe las indicaciones generales y da clic en <b>Guardar receta</b>.',
    ], ['Desde la receta guardada la imprimes o la compartes; el paciente también la ve en su portal.']);
    $T['ruta:recetas/ver'] = $g('Receta', 'La receta lista para imprimir o compartir.', [
        'Usa <b>Imprimir</b> para sacarla en papel o guardarla como PDF.',
        '<b>Enviar por WhatsApp</b> le manda al paciente el enlace de su receta.',
        'El <b>código QR</b> impreso abre una página donde la farmacia comprueba que la receta existe en tu sistema.',
        '<b>Repetir</b> crea una receta nueva con los mismos medicamentos.',
    ]);
    $T['ruta:auditoria/index'] = $g('Bitácora', 'Quién entró, qué expedientes abrió y qué cambió.', [
        'Filtra por fechas, por persona o por tipo (entradas, expedientes abiertos, altas, cambios, borrados).',
        'Da clic en el nombre de un paciente para abrir su expediente.',
        'Todo lo que alguien guarda en el sistema queda registrado, con la hora y desde qué IP.',
        'Los registros no se pueden editar ni borrar: así sirven como evidencia.',
    ], ['El cierre de sesión por inactividad se ajusta en Configuración → Seguridad de la sesión.']);
    $T['ruta:timbres/index'] = $g('Facturación electrónica', 'Tus timbres para facturar ante el SAT y tus datos fiscales.', [
        'Arriba ves cuántas facturas puedes timbrar hoy: las incluidas en tu plan este mes y las de tus paquetes.',
        'Elige un <b>paquete</b> y paga con Mercado Pago; los timbres se suman solos cuando se confirma el pago.',
        'En <b>Tus datos fiscales</b> captura RFC, razón social, régimen y código postal como en tu Constancia de Situación Fiscal.',
        'Sube tu <b>CSD</b> (.cer, .key y contraseña). No es la e.firma: se tramita gratis en el portal del SAT.',
    ], ['Cada factura timbrada usa 1 timbre. Si el SAT la rechaza, el timbre regresa a tu saldo.']);
    $T['ruta:mensajes/index'] = $g('Mensajes de WhatsApp', 'Recordatorios automáticos por WhatsApp sin trámites con Meta.', [
        'Arriba ves cuántos mensajes te quedan: los incluidos en tu plan este mes y los de tus paquetes.',
        'Con el interruptor prendido, los recordatorios de cita salen solos con el nombre de tu consultorio y un enlace para confirmar.',
        'Si se te acaban, compra un paquete; mientras tanto los recordatorios siguen saliendo por correo.',
    ], ['Para mandarlos tú desde tu propio celular (gratis), usa Agenda → Recordar por WhatsApp.']);
    $T['ruta:citas/recordar'] = $g('Recordar por WhatsApp', 'Manda los recordatorios desde tu propio WhatsApp, uno por uno.', [
        'Elige el día (por omisión, mañana).',
        'Da clic en <b>Enviar la siguiente</b>: se abre WhatsApp con el mensaje listo y solo le das enviar.',
        'Cada cita queda marcada como enviada para que sepas por dónde vas.',
    ], ['Usa el WhatsApp abierto en esa computadora o celular. No gasta saldo.']);
    $T['ruta:migrar/index'] = $g('Migrar desde otro sistema', 'Trae todo desde Nimbo, Doctoralia o Excel en cuatro pasos.', [
        'Pide a tu sistema anterior la exportación (hay un texto listo para copiar).',
        'Importa en orden: <b>pacientes</b>, <b>citas</b>, <b>historial</b> y <b>documentos</b>.',
        'En cada paso revisas a qué campo va cada columna antes de importar; nada se duplica.',
    ], ['Da de alta a tus médicos con el mismo nombre que usaban antes para que se asignen solos.']);
    $T['ruta:usuarios/efirma'] = $g('Mi firma electrónica', 'Firma tus notas clínicas con tu e.firma del SAT.', [
        'Sube tu archivo <b>.cer</b> y tu <b>.key</b> y escribe tu contraseña para comprobar que son tuyos.',
        'En el expediente, cada nota que escribiste tiene el botón <b>Firmar con e.firma</b>.',
        'Tu contraseña nunca se guarda: se pide cada vez que firmas.',
    ], ['Es la e.firma (antes FIEL), no el sello digital (CSD) de facturación.']);
    $T['ruta:usuarios/seguridad'] = $g('Seguridad de mi cuenta', 'Verificación en dos pasos, contraseña y tus accesos.', [
        'Activa la <b>verificación en dos pasos</b>: escaneas un QR con Google Authenticator o Authy y al entrar te pedimos el código.',
        'Guarda tus <b>códigos de respaldo</b>: sirven si pierdes el celular.',
        'Revisa <b>tus últimos accesos</b>; si ves uno que no reconoces, cambia tu contraseña.',
    ], ['El administrador puede exigir la verificación en dos pasos a todo el personal.']);
    $T['ruta:expediente/imprimir'] = $g('Imprimir expediente', 'Expediente completo, resumen clínico o una nota.', [
        'Elige <b>Expediente completo</b> o <b>Resumen clínico</b> arriba.',
        'Da clic en <b>Imprimir / PDF</b>; en el diálogo puedes guardarlo como PDF.',
        'Cada nota muestra su sello de integridad y, si se firmó, los datos de la e.firma.',
    ], ['Cada impresión queda registrada en la Bitácora.']);
    $T['ruta:dispositivos/index'] = $g('Impresoras y escáneres', 'Conecta la impresora de tickets y el lector de esta computadora.', [
        '<b>Agregar impresora</b>: elige USB, Bluetooth o puerto serie y el navegador te muestra las que están conectadas.',
        'Si tu impresora ya está instalada en Windows o Mac, agrégala como <b>Impresora instalada en la PC</b>.',
        'Imprime un <b>ticket de prueba</b> para revisar los acentos y el QR; si salen raros, cambia la opción de Acentos.',
        'Los lectores USB o Bluetooth tipo teclado no se agregan: conéctalos y escanea en cualquier pantalla.',
    ], ['Se configura en cada computadora y navegador. Funciona en Google Chrome y Microsoft Edge.']);
    $T['ruta:recetas/medicamentos'] = $g('Medicamentos y favoritas', 'Tu catálogo para recetar más rápido.', [
        'Cada medicamento que recetas se guarda aquí solo, con la dosis que usaste.',
        'Marca con la <b>estrella</b> los que más usas: salen primero al recetar.',
        'En <b>Recetas favoritas</b> ves las recetas completas que guardaste para reutilizar.',
    ]);
    $T['ruta:facturacion/create'] = $g('Cómo registrar un cobro', 'Lo que el paciente pagó (o debe), con sus conceptos.', [
        'Elige al paciente y la fecha.',
        'Agrega conceptos: <b>Agregar del catálogo</b> trae el precio de tus servicios; también puedes escribir uno libre.',
        'Aplica descuento si hace falta; el total se calcula solo.',
        'Marca <b>Pagada</b> (y el método de pago) o <b>Pendiente</b> si aún no paga.',
        'Da clic en <b>Guardar factura</b>.',
    ], ['Lo pagado entra al corte de caja de quien lo registró.', 'Lo pendiente aparece en el dashboard en "Por cobrar".']);
    $T['ruta:facturacion/ver'] = $g('Cobro', 'El detalle del cobro y su comprobante.', ['Imprime el comprobante o márcalo como pagado cuando liquide.']);
    $T['ruta:corte/cerrar'] = $g('Cerrar el corte de caja', 'Cuadra lo que cobraste contra lo que tienes en caja.', [
        'Revisa lo cobrado desde tu último cierre, por método de pago.',
        'Captura el <b>fondo inicial</b>, los <b>retiros</b> y el <b>efectivo contado</b>.',
        'El sistema calcula si cuadra, falta o sobra.',
        'Indica cuánto dejas en caja para el siguiente turno y confirma el cierre.',
    ], ['Un cierre ya no se modifica: revisa bien antes de confirmar.', 'Los egresos en efectivo se restan solos.']);
    $T['ruta:corte/cierres'] = $g('Historial de cierres', 'Quién cerró caja, cuándo y con qué diferencia.', ['Da clic en un cierre para ver su detalle.']);
    $T['ruta:corte/cierre'] = $T['ruta:corte/cierres'];
    $T['ruta:presupuestos/edit'] = $g('Cómo armar un presupuesto', 'El plan de tratamiento cotizado para el paciente.', [
        'Elige al paciente y agrega los conceptos (del catálogo o libres).',
        'Mientras esté en <b>borrador</b> o <b>propuesto</b> puedes cambiar los conceptos.',
        'Cuando el paciente acepta, márcalo <b>aceptado</b>: los conceptos quedan fijos y registras abonos.',
    ], ['En odontología, el presupuesto se genera solo desde el plan de la carta dental.']);
    $T['ruta:presupuestos/ver'] = $g('Presupuesto', 'El plan cotizado, sus abonos y su saldo.', [
        'Registra cada abono conforme paga el paciente.', 'Marca los conceptos realizados.', 'Imprímelo para entregarlo.',
    ]);

    /* ───────────── Documentos y consentimientos ───────────── */
    $T['ruta:consentimientos/nuevo'] = $g('Consentimiento informado', 'Se firma en pantalla y queda guardado en el expediente.', [
        'Elige la plantilla de consentimiento (tu especialidad ya trae las suyas).',
        'Revisa el texto: el nombre del paciente y del consultorio se llenan solos.',
        'El paciente (o su tutor) firma con el dedo o el mouse; después firmas tú.',
        'Guarda. Desde el expediente lo puedes imprimir cuando quieras.',
    ]);
    $T['ruta:documentos/nuevo'] = $g('Emitir un documento', 'Constancias, justificantes y otros documentos con tu marca.', [
        'Elige la plantilla.',
        'El sistema llena los datos que ya tiene (nombre, edad, último diagnóstico).',
        'Lee y corrige el texto: queda editable hasta que guardas.',
        'Guarda e imprime.',
    ]);
    $T['ruta:documentos/plantillas'] = $g('Plantillas de documentos', 'Tus formatos de documentos, con marcadores que se llenan solos.', [
        'Crea la plantilla con su título y texto.', 'Usa los marcadores que se muestran para el nombre, edad y diagnóstico.',
    ]);

    /* ───────────── Especialidades ───────────── */
    $T['ruta:cardiologia/index'] = $g('Cardiología', 'Valoraciones cardiovasculares con su evolución.', [
        'Registra TA, FC, perfil de lípidos y glucosa.', 'Marca factores de riesgo, clase funcional (NYHA) y hallazgos de ECG.',
        'Las gráficas muestran TA y lípidos en el tiempo.',
    ]);
    $T['ruta:cirugia/index'] = $g('Cirugía', 'Bitácora quirúrgica: cada procedimiento con su estado.', [
        'Programa la cirugía: procedimiento, carácter (programada o urgencia), abordaje y riesgo ASA.',
        'Al realizarla, registra hallazgos, tiempo, sangrado y complicaciones.',
        'Lleva el diagnóstico pre y postoperatorio.',
    ]);
    $T['ruta:crecimiento/index'] = $g('Curvas de crecimiento', 'Peso, talla e IMC contra la edad, con la mediana de referencia.', [
        'Las mediciones salen de las consultas: captura peso y estatura en cada visita.',
        'La gráfica compara al paciente contra la referencia por sexo.',
    ]);
    $T['ruta:dermatologia/index'] = $g('Dermatología', 'Lesiones con seguimiento fotográfico.', [
        'Registra la lesión con su ubicación y descripción.',
        'Súbele fotos en cada revisión: se acomodan por fecha para comparar cómo evoluciona.',
        'Cuando se resuelva, márcala como <b>resuelta</b>.',
    ]);
    $T['ruta:endocrinologia/index'] = $g('Endocrinología', 'Control metabólico y tiroideo.', [
        'Registra peso, cintura, glucosa, HbA1c e insulina (HOMA-IR se calcula solo).', 'Captura el perfil tiroideo y el tamizaje de complicaciones del diabético.',
        'La gráfica de HbA1c y glucosa dice si el paciente está controlado.',
    ]);
    $T['ruta:estetica/index'] = $g('Medicina estética', 'Procedimientos por sesión con trazabilidad del producto.', [
        'Registra zona, producto, cantidad, <b>lote</b> y caducidad de lo aplicado.', 'Lleva la cuenta de sesiones de cada paquete.',
    ], ['El lote es lo que responde si hay una reacción meses después: captúralo siempre.']);
    $T['ruta:gastroenterologia/index'] = $g('Gastroenterología', 'Consultas y estudios endoscópicos.', [
        'Registra indicación y calidad de preparación (Boston en colonoscopia).', 'Captura hallazgos por segmento, Los Ángeles, Helicobacter, pólipos y biopsias.',
        'Anota la fecha del próximo control.',
    ]);
    $T['ruta:mapacorporal/index'] = $g('Mapa corporal 3D', 'Ubica hallazgos exactamente donde están, sobre un cuerpo en 3D que puedes girar.', [
        'Arrastra para girar el cuerpo, usa la rueda (o pellizca) para acercar. Los botones <b>Frente</b>, <b>Espalda</b> y ⇄ lo orientan.',
        'Al pasar el cursor se ilumina la zona; al tocar, el punto queda fijado y la <b>zona</b> se elige sola (corazón, pulmones, hígado, riñones…).',
        'Escribe el hallazgo, la severidad y una nota, y da clic en <b>Agregar</b>.',
        'Cada hallazgo aparece como etiqueta sobre el cuerpo; tócala para ubicarlo en la lista.',
    ], ['Cambia la apariencia entre <b>Piel</b>, <b>Anatomía</b> y <b>Holograma</b>.', 'La pestaña <b>Ilustración</b> conserva el mapa 2D de siempre.']);
    $T['ruta:neumologia/index'] = $g('Neumología', 'Control de asma y EPOC.', [
        'Registra disnea (mMRC), saturación y espirometría (FEV1, FVC).', 'Captura flujo pico y control del asma (ACT).', 'La gráfica de FEV1 y saturación muestra la tendencia.',
    ]);
    $T['ruta:nutricion/index'] = $g('Nutrición', 'Valoraciones antropométricas con su evolución.', [
        'En cada visita registra peso, % grasa y músculo, cintura y cadera.', 'El IMC se calcula solo; fija la meta de peso.',
        'Escribe el plan (kcal e indicaciones). Las gráficas muestran el avance contra la meta.',
    ]);
    $T['ruta:oftalmologia/index'] = $g('Oftalmología', 'Exámenes con agudeza visual, PIO, segmento anterior y fondo de ojo.', [
        'Registra la agudeza visual y la presión intraocular de cada ojo.', 'Describe segmento anterior y fondo de ojo.', 'La gráfica de PIO es clave para el seguimiento de glaucoma.',
    ]);
    $T['ruta:otorrino/index'] = $g('Otorrinolaringología', 'Exploración de oído, nariz y garganta.', [
        'Registra otoscopia y umbral auditivo por lado.', 'Anota acúfeno, vértigo, rinoscopia, grado amigdalino y laringoscopia.', 'La gráfica muestra si una hipoacusia progresa.',
    ]);
    $T['ruta:prenatal/index'] = $g('Control prenatal', 'El embarazo activo: semanas, fecha probable de parto y visitas.', [
        'Registra la <b>FUM</b>: las semanas de gestación y la FPP se calculan solas.', 'En cada visita captura peso, TA, FCF y altura uterina.', 'Las gráficas muestran la evolución del embarazo.',
    ]);
    $T['ruta:traumatologia/index'] = $g('Traumatología', 'Valoraciones por región y lado.', [
        'Registra mecanismo de lesión, dolor (EVA), arcos de movilidad, fuerza y estabilidad.', 'Anota pruebas especiales y el plan (conservador, rehabilitación, infiltración, cirugía).',
        'La gráfica del dolor muestra si el tratamiento funciona.',
    ]);
    $T['ruta:urologia/index'] = $g('Urología', 'Seguimiento de vías urinarias y próstata.', [
        'Registra IPSS y calidad de vida, PSA total y libre, volumen prostático, flujo máximo y residuo.', 'La gráfica de PSA e IPSS ayuda a decidir si vigilar, medicar o biopsiar.',
    ]);
    $T['ruta:psicologia/index'] = $g('Psicología', 'Bitácora de sesiones con escalas PHQ-9 y GAD-7.', [
        'En cada sesión escribe el enfoque, las notas y las tareas para el paciente.', 'Si aplicaste escalas, captura el puntaje de PHQ-9 (depresión) y GAD-7 (ansiedad).',
        'Marca el nivel de riesgo. La gráfica muestra la evolución de las escalas.',
    ], ['Un riesgo <b>alto</b> se resalta en rojo en la lista de sesiones.']);
    $T['ruta:psiquiatria/index'] = $g('Psiquiatría', 'Diagnóstico vigente, riesgo suicida, escalas y psicofármacos del paciente.', [
        'Empieza con la <b>valoración inicial</b>; cada consulta después es una <b>nota de seguimiento</b> (el diagnóstico se arrastra solo).',
        'En <b>Medicación</b> agrega cada fármaco; usa <b>Ajustar</b> para cambiar la dosis y <b>Suspender</b> con su motivo: así queda el historial completo.',
        'La pestaña <b>Monitoreo</b> te dice qué estudios pide cada fármaco activo (litemia, BH con clozapina, perfil metabólico…).',
        '<b>Resumen clínico</b> imprime diagnóstico, escalas y tratamiento para una interconsulta.',
    ], ['Si la última evaluación marcó riesgo suicida moderado o alto, aparece un aviso arriba en la ficha.', 'Los medicamentos controlados se marcan en amarillo: requieren receta especial.']);
    $T['ruta:psiquiatria/nota'] = $g('Nota psiquiátrica', 'Valoración inicial, seguimiento, urgencia o interconsulta.', [
        'Llena el examen mental por dominios; el botón <b>Todo sin alteraciones</b> marca lo normal y solo cambias lo alterado.',
        'Contesta el <b>tamizaje de riesgo suicida</b>: el nivel se calcula solo (puedes corregirlo con tu juicio clínico).',
        'Para PHQ-9 y GAD-7 usa <b>Calcular</b>: marcas cada reactivo y el total se llena solo.',
        'Escribe el código CIE-10 y el nombre del diagnóstico se completa.',
    ], ['Si el reactivo 9 del PHQ-9 es positivo, el sistema te pide completar el tamizaje de riesgo.']);
    $T['ruta:hospitalizacion/cuartos'] = $g('Cuartos y tarifas', 'Tus cuartos con su capacidad y cómo se cobran.', [
        'Agrega cada cuarto (suite, habitación compartida, internamiento de día) con sus camas y su tarifa.',
        'Elige cómo se cobra: por noche, por día de asistencia o por hora.',
        'Al ingresar a un paciente, la tarifa se copia a su estancia: cambiar el precio después no altera lo ya pactado.',
    ], ['Por noche: el día de ingreso cuenta y el de alta no (23 → 25 = 2 noches).']);
    $T['ruta:hospitalizacion/actividades'] = $g('Programa del día', 'Actividades grupales con pase de lista.', [
        'Programa la actividad (terapia grupal, yoga, danzaterapia…) y repítela de lunes a viernes por varias semanas.',
        'Al terminar, marca quién asistió: queda en la estancia de cada paciente.',
    ]);
    $T['ruta:admisiones/index'] = $g('Admisiones', 'Solicitudes de atención, muchas veces de un familiar, antes de que exista el paciente.', [
        'Las solicitudes de tu página llegan aquí y te avisamos por correo; las llamadas regístralas con "Registrar solicitud".',
        'Escribe por WhatsApp con un clic: el mensaje ya va redactado.',
        'Con "Siguiente paso" creas al paciente, agendas su valoración o lo ingresas a internamiento (con su familiar como responsable).',
        'Cada nota que agregas mueve la solicitud a "Contactadas".',
    ], ['Las urgentes aparecen primero con la etiqueta roja.']);
    $T['ruta:vacunacion/paciente'] = $g('Cartilla de vacunación', 'Las vacunas aplicadas y la próxima dosis de cada una.', [
        'Elige la vacuna y la fecha: la próxima dosis se calcula sola (puedes cambiarla).', 'Anota lote y dosis.', 'Imprime la cartilla para entregarla.',
    ]);
    $T['ruta:hospitalizacion/estancia'] = $g('Estancia del paciente', 'Todo lo de un internado: kardex, notas por turno, visitas, familiar responsable y su cuenta.', [
        '<b>Lo que toca</b>: las dosis pendientes de las últimas 24 h y las próximas 2 h. Márcalas como <b>Aplicada</b> u <b>Omitida</b>.',
        'Agrega <b>indicaciones</b> (medicamento, dosis, vía y cada cuántas horas) y el kardex programa las dosis solo.',
        'En <b>Evolución y signos</b> elige el tipo de nota (enfermería, médica, psicología) y el turno; filtra por tipo arriba de la lista.',
        'En la <b>Cuenta</b> ves cargos, abonado y saldo. Agrega extras (medicamentos, estudios, horas) y registra abonos con <b>Abonos y estado de cuenta</b>.',
        'Registra cada <b>visita</b> y mantén al día al <b>familiar responsable</b> y la <b>alta estimada</b>.',
        'Al terminar, <b>Dar de alta</b>: se carga lo que faltaba en la cuenta y queda el resumen para casa.',
    ], ['Las noches se cargan solas con la tarifa pactada al ingresar.', '"Cambiar de cuarto" respeta la tarifa anterior para las noches ya pasadas.', 'Usa "Imprimir kardex" para la hoja del turno.']);
    $T['hosp.cuenta'] = $g('Cuenta del internamiento', 'Una sola cuenta por estancia que se va llenando sola.', [
        'Cada noche (o cada día, en internamiento de día) se carga la tarifa pactada al ingresar.',
        'Agrega cargos extra con su cantidad y precio: medicamentos, estudios, consultas u horas.',
        'Los abonos se registran en <b>Abonos y estado de cuenta</b> y entran al corte de caja de quien cobra.',
    ], ['El saldo también aparece arriba, junto al nombre del paciente.']);
    $T['hosp.cuartos'] = 'ruta:hospitalizacion/cuartos';
    $T['psiq.riesgo'] = $g('Tamizaje de riesgo suicida', 'Seis preguntas con la estructura de la C-SSRS; el nivel se calcula solo.', [
        'Haz la 1 y la 2. Si la 2 es "Sí", continúa con la 3, 4 y 5. La 6 pregunta por conductas en los últimos 3 meses.',
        'Sí en la 4, 5 o 6 = riesgo <b>alto</b>; sí en la 3 = <b>moderado</b>; sí en la 1 o 2 = <b>bajo</b>.',
        'Si tu juicio clínico es distinto, elige el nivel en "Riesgo suicida (juicio clínico)": manda sobre el tamizaje.',
        'Anota factores de riesgo, protectores y el plan de seguridad acordado.',
    ], ['Un riesgo moderado o alto queda como aviso arriba de la ficha de Psiquiatría.', 'Es una herramienta de apoyo: no sustituye la valoración clínica.']);
    $T['psiq.escalas'] = $g('Escalas', 'Captura los puntajes y su interpretación sale sola; la gráfica muestra la evolución.', [
        '<b>PHQ-9</b> (depresión) y <b>GAD-7</b> (ansiedad): usa <b>Calcular</b> y marca cada reactivo de 0 a 3; el total se llena solo.',
        '<b>HAM-D</b> (depresión heteroaplicada), <b>YMRS</b> (manía) y <b>AUDIT</b> (alcohol): escribe el total.',
        'Deja vacías las que no aplicaste.',
    ], ['Si el reactivo 9 del PHQ-9 es positivo, el sistema te pide completar el tamizaje de riesgo.']);
    $T['psiq.medicacion'] = $g('Medicación y monitoreo', 'El historial completo de psicofármacos del paciente.', [
        '<b>Agregar medicamento</b>: escribe el fármaco (se sugiere de la lista), dosis, frecuencia e indicación.',
        '<b>Ajustar</b> cambia la dosis y guarda el esquema anterior en el historial con su motivo.',
        '<b>Suspender</b> lo cierra con su motivo (remisión, efectos adversos, falta de respuesta…).',
        'La pestaña <b>Monitoreo</b> lista los estudios que pide cada familia de fármacos activos (litemia, BH con clozapina, perfil metabólico…).',
    ], ['Los controlados (benzodiacepinas, estimulantes, hipnóticos) se marcan en amarillo: requieren receta especial.', 'El monitoreo es una guía de referencia; el criterio clínico manda.']);
    $T['ruta:pacientes/importar'] = $g('Importar pacientes', 'Pasa tus pacientes de otro sistema (Nimbo, Doctoralia, Excel) sin capturarlos uno por uno.', [
        'En tu sistema anterior busca la opción de <b>exportar pacientes</b>. Si te da un Excel, ábrelo y usa Archivo → Guardar como → <b>CSV UTF-8</b>.',
        'Sube el archivo: el sistema reconoce las columnas por su nombre (nombre, apellidos, celular, correo, fecha de nacimiento…).',
        'Revisa a qué campo va cada columna y corrige si hace falta; las que dejes en "No importar" se ignoran.',
        'Da clic en <b>Importar</b>: al final ves cuántos entraron y qué filas se saltaron y por qué.',
    ], ['No se duplica a nadie: si ya existe alguien con el mismo nombre y la misma fecha de nacimiento, correo o teléfono, se salta.',
        '¿Prefieres que lo hagamos nosotros? Escríbenos desde Ayuda y soporte.']);
    $T['ruta:pagos/index'] = $g('Mi suscripción', 'Tu plan, tu próximo cobro y cómo cambiar de plan.', [
        'Arriba ves tu plan actual y la fecha del próximo cobro.',
        'Para cambiar de plan elige el nuevo y confirma el pago con tarjeta (Mercado Pago).',
        'Lo que incluye cada plan aparece en su tarjeta.',
    ], ['Si algo no está en tu plan, el sistema te avisa y te trae aquí.']);
    $T['ruta:telemedicina/consulta'] = 'ruta:telemedicina/sala';
    $T['admision.siguiente'] = $g('Siguiente paso de una solicitud', 'Convierte la solicitud en atención real.', [
        '<b>Agendar valoración</b>: crea al paciente (si no existe) y abre la agenda con él elegido.',
        '<b>Ingresar a internamiento</b>: crea al paciente y abre el ingreso con el familiar como responsable.',
        '<b>Crear paciente</b>: solo da de alta su ficha; quien solicitó queda como contacto de emergencia.',
        'También puedes moverla de etapa a mano (por ejemplo, a "No procede").',
    ]);
    $T['servicio.publico'] = $g('Tus servicios en tu página', 'Cómo se ve este servicio para tus pacientes.', [
        'Con <b>Mostrar este servicio</b> apagado, no aparece en tu página (sigue disponible en presupuestos y cobros).',
        'La <b>foto</b> y la <b>descripción</b> salen en su ficha, que se abre al tocar la tarjeta.',
        'Si apagas <b>Mostrar el precio</b>, el paciente ve el servicio sin precio y puede preguntar por WhatsApp.',
    ]);

    /* ───────────── Óptica, laboratorio, ultrasonido, inventario ───────────── */
    $T['ruta:optica/graduacion'] = $g('Graduación', 'La receta oftálmica, igual que en papel: una fila por ojo.', [
        'Captura esfera, cilindro, eje y adición de OD y OI.', 'Cada revisión es una graduación nueva (no se sobrescribe la anterior).', 'Desde aquí creas el trabajo de óptica.',
    ]);
    $T['ruta:optica/trabajo'] = $g('Trabajo de óptica', 'La orden del par de lentes.', [
        'Elige la graduación: el catálogo muestra solo las micas que se pueden fabricar con esa receta.', 'Elige armazón y mica; el precio y los días de entrega salen solos.', 'Da seguimiento hasta la entrega.',
    ]);
    $T['ruta:optica/micas'] = $g('Catálogo de micas', 'Las micas que vendes con su rango de graduación, precio y días de entrega.', ['Agrega cada mica con su rango de esfera y cilindro.']);
    $T['ruta:laboratorio/orden'] = $g('Orden de laboratorio', 'Los estudios solicitados al paciente y sus resultados.', [
        'Agrega los estudios del catálogo: traen precio, unidad y valores de referencia.', 'Guarda la orden; cuando lleguen los resultados, captúralos o adjunta el PDF.',
    ]);
    $T['ruta:laboratorio/estudios'] = $g('Catálogo de estudios', 'Tus estudios con su unidad y valor de referencia.', ['Agrega el estudio una vez y ya no lo tecleas en cada orden.']);
    $T['ruta:laboratorio/ver'] = $g('Resultados', 'Captura los resultados de la orden.', ['Escribe cada valor y marca los que estén fuera de rango.', 'Imprime o adjunta el reporte.']);
    $T['ruta:imagenologia/estudio'] = $g('Estudio de ultrasonido', 'Crea el estudio a partir de un protocolo.', ['Elige paciente y protocolo.', 'Guarda; los renglones del protocolo se copian al estudio para capturarlos.']);
    $T['ruta:imagenologia/ver'] = $g('Informe de ultrasonido', 'Mediciones, hallazgos, impresión diagnóstica e imágenes.', [
        'Captura las mediciones y marca las anormales.', 'Escribe hallazgos e impresión diagnóstica.', 'Sube las imágenes y genera el informe con tu marca.',
    ]);
    $T['ruta:imagenologia/plantillas'] = $g('Protocolos de ultrasonido', 'Qué se mide y qué se describe en cada región.', ['Crea el protocolo con sus renglones.', 'Editarlo no cambia los informes ya emitidos.']);
    $T['ruta:inventario/producto'] = $g('Producto', 'Datos del producto, existencia y lotes.', ['Captura nombre, precio y existencia mínima para recibir la alerta.']);
    $T['ruta:inventario/movimiento'] = $g('Entrada o salida', 'Mueve el inventario por lote.', [
        '<b>Entrada</b>: suma al lote (lo crea si no existe) con su caducidad.', '<b>Salida</b>: descuenta primero del lote que caduca antes.',
    ]);
    $T['ruta:crm/campanas'] = $g('Campañas por WhatsApp', 'Mensajes a un grupo de pacientes, sin riesgo de bloqueo.', [
        'Elige el segmento (por ejemplo, sin visita en 6 meses).', 'Escribe el mensaje.', 'Se genera la lista: manda cada mensaje con su botón, uno por uno.',
    ]);
    $T['ruta:resenas/index'] = $g('Reseñas', 'Las opiniones de tus pacientes en tu página.', [
        'Invita a reseñar una cita atendida.', 'Responde en público para agradecer o aclarar.', 'Oculta solo reseñas abusivas (insultos, spam): se pide un motivo.',
    ]);
    $T['ruta:soporte/index'] = $g('Ayuda y soporte', 'Cómo contactarnos cuando necesites ayuda.', ['Escríbenos por WhatsApp o correo: te ayudamos a configurar, migrar datos o resolver dudas.']);
    $T['ruta:feedback/index'] = $g('Sugerencias', 'Cuéntanos qué mejorar del sistema.', ['Escribe tu comentario o sugerencia y envíalo. Puedes ver tus envíos anteriores.']);
    $T['ruta:usuarios/create'] = $g('Nuevo usuario', 'Dale acceso al sistema a alguien de tu equipo.', [
        'Escribe nombre, correo y contraseña.', 'Elige el rol: <b>Administrador</b>, <b>Médico</b> o <b>Recepción</b>.', 'Guarda: ya puede entrar con su correo.',
    ]);
    $T['ruta:usuarios/edit'] = $T['ruta:usuarios/create'];
    $T['ruta:medicos/edit'] = $g('Médico', 'Datos del médico: especialidad, cédula y perfil público.', ['Completa su especialidad y cédula: salen en recetas y en tu página.']);
    $T['ruta:servicios/servicio'] = $g('Servicio', 'Un servicio de tu catálogo.', ['Escribe nombre, categoría, precio y duración. La duración se usa al agendar.']);

    /* ───────────── Dental ───────────── */
    $T['ruta:dental/index'] = $g('Expediente dental', 'Todo lo dental del paciente: alertas, plan pendiente, periodonto, fotos y notas.', [
        'Si sale el aviso rojo <b>Antes de atender</b>, revisa los antecedentes de riesgo (anticoagulantes, alergias, bifosfonatos…).',
        'Las tarjetas son atajos: plan pendiente → carta dental, periodonto → periodontograma, fotos, riesgo de caries → historia clínica.',
        'Desde <b>Tratamientos por hacer</b> registras la nota de evolución de cada pieza.',
    ], ['Usa las pestañas de arriba para moverte entre carta, historia, fotos, periodontograma y evolución.']);
    $T['ruta:dental/carta'] = $g('Carta dental', 'El odontograma con dientes dibujados: corona, raíz, caras, encía y hueso.', [
        'Elige el modo arriba: <b>Planeado</b> (lo que hay que hacer), <b>Realizado</b> (lo que ya hiciste) o <b>Existente</b> (lo que el paciente ya trae).',
        'Elige un procedimiento en el panel derecho y toca la cara del diente en la vista oclusal (o el diente completo si es corona, endodoncia, extracción…).',
        'Sin procedimiento elegido, tocar un diente abre su <b>detalle</b>: vistas grandes, caries con clase C1–C4, pruebas endodónticas y periodonto.',
        'Abajo se arma el <b>plan de tratamiento</b> con precios de tu catálogo. Marca cada tratamiento como realizado al hacerlo.',
        'Da clic en <b>Guardar</b>. Cada guardado queda en el historial.',
    ], ['Botón <b>Arcada</b>: vista de la boca para seleccionar piezas.', 'Ctrl + clic selecciona varios dientes; Ctrl + Z deshace.',
        'La encía (roja) y el hueso (azul) se dibujan con el último periodontograma.']);
    $T['dental.modos'] = $g('Planeado, Realizado y Existente', 'Cada marca va en una capa distinta para que el plan y la historia no se mezclen.', [
        '<b>Existente</b>: lo que el paciente ya trae (caries, obturaciones, coronas, ausentes). Se pinta con el color del hallazgo.',
        '<b>Planeado</b>: lo que hay que hacer. Se pinta rayado en rojo y sale en el plan con su precio.',
        '<b>Realizado</b>: lo que ya hiciste. Se pinta punteado en azul; al marcarlo, el hallazgo de la cara se actualiza solo (una obturación deja la cara "obturada").',
    ], ['Con <b>Ver</b> filtras la carta para ver solo una capa.']);
    $T['dental.plan'] = $g('Plan de tratamiento', 'Lo planeado y lo realizado, con el precio de tu catálogo.', [
        'Cada fila es un tratamiento por pieza. El precio sale del servicio con el mismo nombre en tu catálogo.',
        '<b>Marcar realizado</b> lo pasa a realizado y actualiza el diente.',
        '<b>Presupuesto</b> crea un presupuesto en borrador con lo planeado (guarda la carta antes).',
    ], ['Si un precio sale en "—", da de alta el servicio en tu catálogo (por ejemplo "Corona" o "Resina").']);
    $T['dental.detalle'] = $g('Detalle de la pieza', 'Registra lo de un diente con calma, como en el sillón.', [
        'Elige las caras en la cruz o tocando el dibujo (vestibular arriba, lingual abajo).',
        '<b>Patología</b>: elige caries, fractura, desgaste, pigmentación, lesión apical o defecto de desarrollo. En caries indica profundidad, cavitación y clase C1–C4.',
        '<b>Vigilar</b> lo registra como hallazgo; <b>Tratar</b> además lo agrega al plan (obturación, o endodoncia si llega a pulpa).',
        '<b>Restauración</b> registra lo que ya trae; <b>Tratamiento</b> agrega al plan o como realizado; <b>Ausente</b> marca la pieza faltante.',
        'Anota pruebas endodónticas (frío, calor, percusión…) y una nota de la pieza.',
    ], ['El botón <b>3D</b> muestra la pieza en 3D con lo registrado (conductos, corona, implante, caries); gírala con el dedo o el mouse.',
        'Cambia de diente con la columna de números o con las flechas ↑ ↓. Esc cierra.', 'Recuerda dar <b>Guardar</b> en la carta al terminar.']);
    $T['ruta:dental/historia'] = $g('Historia clínica odontológica', 'Lo que necesitas saber antes de atender.', [
        'Marca los <b>antecedentes de riesgo</b>: salen como alerta roja en el expediente dental.',
        'Registra hábitos e higiene, ATM y apertura, tejidos blandos y oclusión (clase de Angle, overjet y overbite).',
        'Anota el índice de placa y el riesgo de caries.',
        'Guarda. Es una sola historia por paciente: actualízala en cada revisión.',
    ]);
    $T['ruta:dental/fotos'] = $g('Fotos clínicas', 'La serie fotográfica del caso, organizada por fecha y etapa.', [
        'Toca el recuadro para tomar fotos con el celular o elegirlas (puedes subir varias a la vez).',
        'Elige la vista (frontal, laterales, oclusales, sonrisa, radiografías…) y la etapa: inicial, en tratamiento, final o control.',
        'Da clic en <b>Subir</b>. Se reducen solas para que suban rápido.',
        'Toca una foto para verla grande, editar sus datos o eliminarla.',
    ], ['La lista "Serie fotográfica recomendada" te dice qué vistas faltan.']);
    $T['dental.comparar'] = $g('Comparar antes y después', 'Pon dos fotos lado a lado para mostrarle al paciente su avance.', [
        'Da clic en <b>Comparar antes / después</b>.', 'Toca primero la foto de antes y luego la de después.',
        'Arrastra el deslizador para descubrir el cambio, o cambia a <b>Lado a lado</b>.',
    ], ['Funciona mejor con fotos de la misma vista y el mismo encuadre.']);
    $T['ruta:dental/perio'] = $g('Periodontograma', 'El sondaje periodontal completo con sus índices.', [
        'Teclea la profundidad de sondaje (PS) y el margen gingival (MG) de cada sitio: el cursor avanza solo.',
        'Toca los puntos para marcar sangrado (rojo) y placa (azul).',
        'Captura movilidad y furca; marca los dientes ausentes con la ✕ bajo el número.',
        'Arriba se calculan sangrado, placa, PS media y sitios de 4 mm o más, comparados con el examen anterior.',
        'Escribe el diagnóstico y guarda.',
    ], ['MG positivo = recesión; negativo = agrandamiento. El NIC es PS + MG.', 'Para un "1" seguido de otro número (10, 11, 12) teclea rápido: el avance espera medio segundo.']);
    $T['ruta:dental/evolucion'] = $g('Notas de evolución', 'Qué se hizo, en qué pieza, con qué anestesia y materiales.', [
        'Toca un <b>pendiente del plan</b> para llenar pieza, caras y procedimiento de una vez.',
        'O elige las piezas en la rejilla y escribe el procedimiento.',
        'Anota anestesia, cartuchos, materiales, descripción, indicaciones y próxima cita.',
        'Deja marcado <b>Marcar como realizado en la carta dental</b> para que el plan se actualice solo.',
    ]);
    $T['ruta:odontograma/historial'] = $g('Historial del odontograma', 'Cada guardado de la carta queda aquí, con fecha y motivo.', ['Elige una versión para ver cómo estaba la boca en ese momento.']);
    $T['ruta:odontograma/index'] = $g('Odontograma clásico', 'La rejilla por caras. Usa los mismos datos que la carta dental.', [
        'Elige capa y estado, luego toca la cara del diente.', 'Guarda. La carta dental mostrará los mismos cambios.',
    ]);

    /* ───────────── Fisioterapia ───────────── */
    $T['ruta:fisioterapia/index'] = $g('Fisioterapia del paciente', 'Diagnóstico vigente, sesiones, dolor y apego al ejercicio en casa.', [
        'Empieza con la <b>Historia clínica</b> (valoración inicial).',
        'En cada sesión registra una <b>Nota de evolución</b> SOAP con la EVA al llegar y al salir.',
        'Arma el <b>Plan de ejercicios</b> y compártelo; el paciente marca en su portal los días que lo hace.',
        'Imprime el <b>Informe de evolución</b> para el médico que refirió, y da de alta al terminar.',
    ], ['La barra de 14 días muestra en verde los días que el paciente hizo su rutina.']);
    $T['ruta:fisioterapia/valoracion'] = $g('Historia clínica fisioterapéutica', 'Valoración inicial, reevaluación o alta.', [
        'Llena anamnesis, banderas rojas y dolor (EVA).',
        'Goniometría: elige la región y usa <b>Cargar movimientos</b>; el % del rango normal se calcula solo.',
        'Captura fuerza (0-5), pruebas especiales (sugeridas por región) y escalas funcionales.',
        'Escribe el diagnóstico fisioterapéutico, objetivos y plan, con sesiones estimadas.',
    ], ['En una reevaluación verás debajo de cada campo el valor anterior para comparar.']);
    $T['fisio.goniometria'] = $g('Goniometría', 'Arcos de movimiento comparados contra el rango normal.', [
        'Elige la región arriba y da clic en <b>Cargar movimientos de la región</b>.',
        'Captura el arco activo y pasivo en grados; el % del normal se pinta en verde, amarillo o rojo.',
        'Agrega filas libres para movimientos que no estén en la lista.',
    ]);
    $T['fisio.escalas'] = $g('Escalas funcionales', 'Miden cómo afecta el problema a la vida diaria.', [
        'Elige la escala (Oswestry, NDI, DASH, LEFS, Berg…) y escribe el puntaje.',
        'Repite la misma escala en la reevaluación: el informe compara inicial contra actual.',
    ]);
    $T['ruta:fisioterapia/nota'] = $g('Nota de evolución SOAP', 'Lo de cada sesión en formato SOAP.', [
        'Marca la EVA al llegar y al salir.', '<b>S</b>: lo que refiere el paciente. <b>O</b>: lo que mides hoy.',
        'Marca las intervenciones y escribe sus parámetros (o usa <b>Repetir la sesión anterior</b>).',
        '<b>A</b>: cómo respondió. <b>P</b>: qué sigue. Indica la tolerancia y guarda.',
    ]);
    $T['ruta:fisioterapia/plan'] = $g('Plan de ejercicios', 'La rutina en casa, armada con clics.', [
        'Busca en la biblioteca (derecha) y toca un ejercicio para agregarlo.',
        'Ajusta series, repeticiones, segundos, carga y lado; reordénalos con las flechas.',
        'Escribe las indicaciones generales y deja activado <b>Mostrar en el portal</b>.',
        'Guarda e imprime, o mándalo por WhatsApp desde la lista de planes.',
    ], ['<b>Partir de un plan anterior</b> copia un plan de otro paciente como protocolo.']);
    $T['ruta:fisioterapia/ejercicios'] = $g('Biblioteca de ejercicios', 'Tus ejercicios con instrucciones, video e imagen.', [
        'Edita los que vienen de base o agrega los tuyos.', 'Pega el enlace de un video de YouTube: la miniatura se usa sola en la hoja del paciente.',
        'Oculta los que no uses (no se borran de los planes ya hechos).',
    ]);

    return $T;
}
