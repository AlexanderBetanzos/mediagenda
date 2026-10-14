<?php
/**
 * Mini-tutoriales del panel (el botón "?"), réplica de los de Khronos.
 *
 * Cada entrada se abre en un modal flotante:
 *   'clave' => ['titulo' => …, 'intro' => …, 'pasos' => [...], 'tips' => [...]]
 *
 * Claves:
 *   - 'page:<activo>'   guía de la pantalla (el "?" junto al título lo pone solo
 *                       includes/header.php según $activo).
 *   - 'pantalla.accion' guía de una operación; se coloca con ayuda_btn('…').
 *   - 'giro'            guía de la especialidad del consultorio (cambia según
 *                       su interfaz: psicología, dental, veterinaria…).
 *
 * Todo pasa por t(), así que el vocabulario del giro se aplica solo: en una
 * veterinaria "Registra al paciente" se lee "Registra a la mascota".
 * Se aceptan <b>, <i> y <br> (se imprimen tal cual); el resto, texto plano.
 * Si una clave no existe, ayuda_btn() no pinta nada.
 */

function ayuda_textos(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $vet = function_exists('es_veterinaria') && es_veterinaria();

    $T = [
        'page:dashboard' => [
            'titulo' => 'Dashboard',
            'intro'  => 'Tu día y tu mes de un vistazo: citas, pacientes, cobros y lo que necesita tu atención.',
            'pasos'  => [
                'Arriba tienes accesos rápidos: <b>Nueva cita</b>, <b>Nuevo paciente</b> y la <b>Agenda</b>.',
                '<b>Primeros pasos</b> te guía para dejar tu consultorio listo; cada punto se marca solo cuando lo completas.',
                '<b>Por confirmar · próximas 48 h</b>: manda el recordatorio por WhatsApp con un clic.',
                '<b>Por cobrar</b>: las facturas pendientes, de la más vieja a la más nueva.',
                '<b>Tus cortes de hoy</b>: al terminar tu turno, cierra tu corte de caja.',
            ],
            'tips'   => ['Las tarjetas son atajos: da clic en cualquiera para ir al detalle.'],
        ],

        'page:pacientes' => [
            'titulo' => 'Pacientes',
            'intro'  => 'Aquí viven todos tus pacientes: los das de alta, los buscas y entras a su expediente.',
            'pasos'  => $vet ? [
                'Da clic en <b>Nueva mascota</b> (botón azul, arriba a la derecha).',
                'Escribe el <b>nombre de la mascota</b> y elige su <b>especie</b>; la raza, el sexo y el color son opcionales.',
                'En <b>Propietario</b> anota los apellidos del dueño (obligatorio) y su teléfono: ahí le llegan los recordatorios.',
                'Si quieres, toma una foto con la cámara o súbela del celular.',
                'Da clic en <b>Guardar</b>: se abre su expediente, listo para su primera consulta.',
            ] : [
                'Da clic en <b>Nuevo paciente</b> (botón azul, arriba a la derecha).',
                'Llena <b>nombre</b> y <b>apellidos</b> (lo único obligatorio). Agrega teléfono para mandarle recordatorios por WhatsApp.',
                'Si quieres, toma su foto con la cámara o súbela del celular.',
                'Lo clínico (alergias, antecedentes, vacunas) puedes llenarlo ahora o después.',
                'Da clic en <b>Guardar</b>: se abre su expediente, listo para su primera consulta.',
            ],
            'tips'   => [
                $vet ? 'Puedes buscar por nombre de la mascota, propietario, especie, raza, teléfono o microchip.'
                     : 'Para encontrar a alguien escribe su nombre, teléfono o correo en el buscador.',
                'Las <b>alergias</b> salen en rojo en el expediente para que nadie las pase por alto.',
                'En la ficha, el botón <b>WhatsApp</b> le manda su próxima cita, el acceso a su portal (citas, recetas y estudios) y tu página para agendar. Revisa el texto antes con <b>Vista previa del WhatsApp</b>.',
            ],
        ],
        'pacientes.nuevo' => [
            'titulo' => 'Cómo registrar un paciente',
            'intro'  => 'Toma menos de un minuto. Solo lo marcado con * es obligatorio.',
            'pasos'  => $vet ? [
                'Nombre de la mascota y especie.',
                'Apellidos del propietario y su teléfono (para los recordatorios por WhatsApp).',
                'Opcional: raza, sexo, color, fecha de nacimiento, esterilizado y microchip.',
                'Opcional: alergias, enfermedades, cirugías y vacunas.',
                'Guardar.',
            ] : [
                'Nombre y apellidos.',
                'Teléfono (para los recordatorios por WhatsApp) y correo.',
                'Opcional: fecha de nacimiento, sexo, CURP, tipo de sangre y contacto de emergencia.',
                'Opcional: alergias, enfermedades crónicas, antecedentes y vacunas.',
                'Guardar.',
            ],
            'tips'   => ['Todo se puede editar después desde el expediente con el botón <b>Editar</b>.'],
        ],

        'page:citas' => [
            'titulo' => 'Agenda',
            'intro'  => 'Programa, confirma y da seguimiento a las citas. Cada médico ve su propia agenda.',
            'pasos'  => [
                '<b>Nueva cita</b>: elige paciente, médico, fecha y hora.',
                'Cambia el estado con un clic: programada → confirmada → atendida (o cancelada / no asistió).',
                'El botón verde de WhatsApp manda el recordatorio con un enlace para confirmar o cancelar.',
                'En el <b>calendario</b> puedes arrastrar una cita para moverla de día u hora.',
            ],
            'tips'   => ['Activa la <b>agenda en línea</b> en Configuración para que tus pacientes se agenden solos desde tu página.'],
        ],
        'citas.nueva' => [
            'titulo' => 'Cómo agendar una cita',
            'pasos'  => [
                'Elige al paciente (si es nuevo, regístralo primero en Pacientes).',
                'Elige al médico, la fecha y la hora. Solo aparecen horarios libres.',
                'Indica el motivo y, si es en línea, márcala como videoconsulta.',
                'Guardar. Queda como <b>programada</b> hasta que el paciente confirme.',
            ],
        ],
        'page:horarios' => [
            'titulo' => 'Horarios',
            'intro'  => 'Los días y horas en que atiende cada médico. La agenda en línea solo ofrece estos huecos.',
            'pasos'  => ['Elige al médico.', 'Marca los días y sus horas de atención.', 'Agrega bloqueos para vacaciones o días sin consulta.', 'Guardar.'],
        ],

        'page:expediente' => [
            'titulo' => 'Expediente',
            'intro'  => 'El historial clínico: consultas, diagnósticos, recetas, estudios y documentos de cada paciente.',
            'pasos'  => [
                'Entra al paciente y da clic en <b>Nueva consulta</b>.',
                'Registra motivo, signos vitales, exploración, diagnóstico y tratamiento.',
                'Desde el expediente también haces la receta, el cobro y abres las herramientas de tu especialidad.',
            ],
            'tips'   => ['Las <b>plantillas de consulta</b> te ahorran escribir lo mismo en cada visita.'],
        ],
        'page:recetas' => [
            'titulo' => 'Recetas',
            'intro'  => 'Recetas con tu marca, listas para imprimir o mandar.',
            'pasos'  => ['Elige al paciente.', 'Agrega cada medicamento con su dosis e indicaciones.', 'Guardar e imprimir.'],
            'tips'   => ['Tu logo y datos salen solos en la receta: configúralos en <b>Configuración → Marca</b>.'],
        ],

        'page:facturacion' => [
            'titulo' => 'Facturación y cobros',
            'intro'  => 'Registra lo que cobras y lleva el control de lo pagado y lo pendiente.',
            'pasos'  => [
                '<b>Nuevo cobro</b>: elige paciente, agrega los conceptos y el método de pago.',
                'Si ya pagó, márcalo como <b>pagada</b>; si no, queda <b>pendiente</b> y sale en el dashboard.',
                'Desde cada cobro puedes imprimir el comprobante.',
            ],
            'tips'   => ['Cada cobro pagado queda a nombre de quien lo registró y entra en su corte de caja.'],
        ],
        'page:corte' => [
            'titulo' => 'Corte de caja',
            'intro'  => 'El reporte por fechas de lo cobrado, y el cierre de caja de cada turno.',
            'pasos'  => [
                '<b>Cerrar mi corte</b>: revisa lo que cobraste desde tu último cierre.',
                'Captura el fondo con que abriste, los retiros y el efectivo que contaste.',
                'El sistema te dice si cuadra, falta o sobra. Indica cuánto dejas en caja para quien sigue.',
                'Al cerrar queda guardado sin cambios y se avisa al administrador.',
            ],
            'tips'   => ['El <b>Historial de cierres</b> muestra quién cerró, cuándo y con qué diferencia.'],
        ],
        'page:egresos' => [
            'titulo' => 'Egresos e ingresos',
            'intro'  => 'Registra tus gastos para conocer tu utilidad real: cobrado − egresos = balance.',
            'pasos'  => ['Captura fecha, categoría, concepto, monto y método de pago.', 'Los gastos en efectivo se restan solos del corte de caja.'],
        ],
        'page:presupuestos' => [
            'titulo' => 'Presupuestos',
            'intro'  => 'Cotiza tratamientos y lleva sus pagos parciales.',
            'pasos'  => ['Elige al paciente y agrega los servicios del catálogo.', 'Imprímelo o compártelo.', 'Registra los abonos conforme paga.'],
        ],
        'page:servicios' => [
            'titulo' => 'Catálogo de servicios',
            'intro'  => 'Tus servicios con su precio. Salen en tu página pública y en presupuestos y cobros.',
            'pasos'  => ['Agrega nombre, categoría y precio.', 'Desactiva los que ya no ofreces (no se borran).'],
        ],
        'page:pos' => [
            'titulo' => 'Punto de venta',
            'intro'  => 'Vende productos de tu inventario y servicios de mostrador.',
            'pasos'  => ['Agrega productos al ticket.', 'Elige método de pago y cobra.', 'El inventario se descuenta solo.'],
        ],
        'page:inventario' => [
            'titulo' => 'Inventario',
            'intro'  => 'Tus productos con su existencia; te avisa cuando algo se está acabando.',
            'pasos'  => ['Da de alta el producto con su precio.', 'Registra entradas cuando llegue mercancía.', 'Las ventas del punto de venta lo descuentan solas.'],
        ],

        'page:medicos' => [
            'titulo' => 'Médicos',
            'intro'  => 'Tu equipo clínico. Cada médico tiene su agenda, su horario y su perfil en tu página pública.',
            'pasos'  => [
                '<b>Nuevo médico</b>: nombre, especialidad y cédula.',
                'Dale acceso al sistema (correo y contraseña) o déjalo solo en la agenda.',
                'Configura su horario en <b>Horarios</b>.',
            ],
        ],
        'page:usuarios' => [
            'titulo' => 'Usuarios',
            'intro'  => 'Quién entra al sistema y qué puede ver.',
            'pasos'  => ['<b>Administrador</b>: todo.', '<b>Médico</b>: su agenda y sus pacientes.', '<b>Recepción</b>: agenda, pacientes y cobros.'],
        ],
        'page:configuracion' => [
            'titulo' => 'Configuración',
            'intro'  => 'Tu marca, tu página pública, la interfaz de tu especialidad y los recordatorios.',
            'pasos'  => [
                '<b>Interfaz de tu especialidad</b>: adapta el lenguaje y las herramientas del panel.',
                '<b>Marca</b>: nombre, logo y color. Salen en recetas, comprobantes y tu página.',
                '<b>Agenda en línea</b>: deja que tus pacientes se agenden solos.',
                '<b>Recordatorios por WhatsApp</b>: el mensaje que reciben antes de su cita.',
            ],
        ],
        'page:crm' => [
            'titulo' => 'CRM',
            'intro'  => 'Seguimiento y campañas a tus pacientes para que vuelvan.',
            'pasos'  => ['Filtra a quién escribirle.', 'Manda el mensaje por WhatsApp desde la lista.'],
        ],
        'page:reactivacion' => [
            'titulo' => 'Reactivación',
            'intro'  => 'Pacientes que ya vinieron pero llevan meses sin regresar y no tienen cita.',
            'pasos'  => ['Elige el periodo (3, 6 o 12 meses).', 'Escríbeles por WhatsApp o agéndales directamente.'],
        ],
        'page:vacunacion' => [
            'titulo' => 'Vacunación',
            'intro'  => 'La cartilla de vacunas de cada paciente y quién tiene una dosis vencida o por tocar.',
            'pasos'  => [
                'Para registrar una vacuna entra al paciente y abre <b>Vacunas</b> (en la ficha o en "Más acciones").',
                'Elige la vacuna: la <b>próxima dosis</b> se calcula sola con el refuerzo del catálogo (puedes cambiarla).',
                'En <b>Vacunación → Por aplicar</b> ves las vencidas y las que tocan pronto; el botón de WhatsApp manda el recordatorio.',
                'En <b>Catálogo</b> ajustas las vacunas y cada cuánto se refuerzan.',
            ],
            'tips'   => ['Desde la cartilla puedes imprimirla para entregarla al paciente.'],
        ],
        'page:laboratorio' => [
            'titulo' => 'Laboratorio',
            'intro'  => 'Órdenes de estudios y sus resultados en el expediente.',
            'pasos'  => ['Crea la orden desde el expediente.', 'Captura o adjunta los resultados cuando lleguen.'],
        ],
        'page:imagenologia' => [
            'titulo' => 'Ultrasonidos',
            'intro'  => 'Estudios de ultrasonido con informe e imágenes.',
            'pasos'  => ['Crea el estudio desde el expediente.', 'Agrega hallazgos e imágenes.', 'Imprime el informe con tu marca.'],
        ],
        'page:optica' => [
            'titulo' => 'Óptica',
            'intro'  => 'Graduaciones y trabajos de óptica (micas y armazones).',
            'pasos'  => ['Captura la graduación del paciente.', 'Crea el trabajo y dale seguimiento hasta la entrega.'],
        ],
        'page:documentos' => [
            'titulo' => 'Documentos',
            'intro'  => 'Constancias, consentimientos y otros documentos con tu marca, a partir de plantillas.',
            'pasos'  => ['Elige la plantilla.', 'Se llena con los datos del paciente.', 'Imprímelo o guárdalo en su expediente.'],
        ],
        'page:plantillas' => [
            'titulo' => 'Plantillas de consulta',
            'intro'  => 'Textos que se repiten en tus consultas, listos para insertarse con un clic.',
            'pasos'  => ['Crea la plantilla con su nombre y texto.', 'En la consulta, elígela y se inserta sola.'],
        ],
    ];

    /* Migración: el dueño casi siempre llega con sus pacientes en Excel, en
       otro sistema o en papel. Se lo pasamos nosotros. */
    $T['migrar'] = [
        'titulo' => '¿Tienes tus pacientes en Excel o en otro sistema?',
        'intro'  => 'No los captures uno por uno: nosotros te ayudamos a pasar tu información a tu panel.',
        'pasos'  => [
            'Junta lo que tengas: un Excel, un archivo exportado de tu sistema anterior o fotos de tus libretas.',
            'Escríbenos por <b>WhatsApp</b> o correo desde <b>Ayuda y soporte</b>.',
            'Te decimos qué columnas necesitamos (nombre, teléfono, fecha de nacimiento, alergias…) y lo cargamos por ti.',
            'Revisas que todo esté bien y listo: tus pacientes aparecen en tu panel.',
        ],
        'tips'   => ['También te acompañamos en una llamada para configurar tu consultorio a tu medida.'],
    ];

    /* Guía de la especialidad del consultorio (según su interfaz). */
    $G = [
        'medico' => ['Tu panel de medicina general', 'Todo lo de un consultorio: agenda, expediente, recetas y cobros.', [
            'Registra la consulta con signos vitales, diagnóstico y tratamiento.',
            'En el expediente, el menú <b>Especialidad</b> tiene herramientas por área (cardiología, dermatología, prenatal…).',
            'Las curvas de <b>Crecimiento</b> están a un clic para tus pacientes pediátricos.',
        ]],
        'dental' => ['Tu panel dental', 'Pensado para odontología: el odontograma va al frente.', [
            'Al registrar un paciente queda como <b>dental</b> automáticamente.',
            'En su expediente, el botón <b>Odontograma</b> marca piezas, caras y tratamientos, con historial.',
            'Usa <b>Presupuestos</b> para cotizar el plan de tratamiento y llevar los abonos.',
        ]],
        'psicologia' => ['Tu panel de psicología', 'El panel habla de <b>sesiones</b> y pone tu nota psicológica a un clic.', [
            'En el expediente del paciente, el botón <b>Psicología</b> abre el registro de sesión.',
            'Agenda las sesiones de seguimiento desde la misma ficha.',
            'La <b>videoconsulta</b> te sirve para sesiones en línea, sin instalar nada.',
            'Los <b>consentimientos</b> se generan y guardan en el expediente.',
        ]],
        'nutricion' => ['Tu panel de nutrición', 'Valoración nutricional y curvas de crecimiento al frente.', [
            'En el expediente, el botón <b>Nutrición</b> registra peso, medidas y composición.',
            'Las <b>curvas de crecimiento</b> muestran la evolución de tus pacientes pediátricos.',
            'Agenda el seguimiento al terminar cada consulta.',
        ]],
        'pediatria' => ['Tu panel de pediatría', 'Curvas de crecimiento a un clic en cada expediente.', [
            'En el expediente, <b>Crecimiento</b> grafica peso, talla y perímetro cefálico.',
            'Lleva las <b>vacunas</b> en los antecedentes del paciente.',
            'El teléfono del paciente es el de mamá o papá: ahí llegan los recordatorios.',
        ]],
        'oftalmologia' => ['Tu panel de oftalmología', 'Examen oftalmológico y graduación al frente.', [
            'En el expediente, <b>Oftalmología</b> registra el examen.',
            '<b>Graduación</b> captura la refracción y de ahí creas el trabajo de óptica.',
        ]],
        'estetica' => ['Tu panel de dermatología y estética', 'Dermatología, estética y mapa corporal al frente.', [
            'En el expediente, <b>Dermatología</b> y <b>Medicina estética</b> abren sus registros.',
            'El <b>mapa corporal</b> ubica lesiones o zonas tratadas.',
            'Usa <b>Presupuestos</b> para paquetes de sesiones.',
        ]],
        'veterinaria' => ['Tu panel veterinario', 'El panel habla de <b>mascotas</b> y <b>propietarios</b>.', [
            'Cada ficha es de una mascota: especie, raza, sexo, color, esterilizado y microchip.',
            'Los datos de contacto son del <b>propietario</b>: los recordatorios por WhatsApp y correo le llegan a él.',
            'Anota <b>vacunas</b> y <b>desparasitación</b> en el expediente.',
            'En la ficha tienes <b>Cirugía</b> y <b>Dermatología</b> al frente; laboratorio y ultrasonido, en el menú Especialidad.',
        ]],
        'clinica' => ['Tu panel de clínica', 'Varias especialidades: todas las herramientas a la mano.', [
            'Cada médico tiene su agenda y ve sus pacientes.',
            'En el expediente, el menú <b>Especialidad</b> tiene las herramientas de cada área.',
            'El corte de caja se cierra por persona; el general lo cierra el administrador.',
        ]],
    ];
    $g = function_exists('giro_actual') ? giro_actual() : 'medico';
    [$gt, $gi, $gp] = $G[$g] ?? $G['medico'];
    $T['giro'] = ['titulo' => $gt, 'intro' => $gi, 'pasos' => $gp,
                  'tips' => ['¿Tu práctica es otra? Cambia la interfaz en <b>Configuración → Interfaz de tu especialidad</b>.']];

    /* Todo pasa por t(): vocabulario del giro (mascota, sesión…) e idioma. */
    foreach ($T as &$h) {
        $h['titulo'] = t($h['titulo']);
        if (!empty($h['intro'])) $h['intro'] = t($h['intro']);
        foreach (['pasos', 'tips'] as $k) {
            if (!empty($h[$k])) $h[$k] = array_map('t', $h[$k]);
        }
    }
    unset($h);
    return $cache = $T;
}

/** Botón "?" que abre el mini-tutorial de $clave. Vacío si no hay texto. */
function ayuda_btn(string $clave, string $clase = ''): string
{
    $h = ayuda_textos()[$clave] ?? null;
    if (!$h) return '';
    return '<button type="button" class="btn-ayuda ' . e($clase) . '" data-ayuda="' . e($clave) . '" title="' . e(t('¿Cómo funciona?')) . '"'
         . ' aria-label="' . e(t('Ayuda') . ': ' . $h['titulo']) . '"><i class="bi bi-question-circle"></i></button>';
}
