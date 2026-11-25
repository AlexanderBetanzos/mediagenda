<?php
/**
 * Centro de ayuda público (/ayuda): navegación, artículos y búsqueda.
 *
 * El contenido NO se escribe dos veces: cada artículo junta las guías que ya
 * existen dentro del sistema (botón "?" de cada pantalla, includes/ayuda.php y
 * ayuda_rutas.php). Así lo que lee el cliente aquí es exactamente lo que ve en
 * el sistema, y al corregir una guía se corrige en los dos lados.
 * Los artículos largos (introducción, primeros pasos, planes, expediente)
 * viven en ayuda/_contenido/<slug>.php.
 *
 * Un artículo cuyas guías no existen (p. ej. se quitó una pantalla) no aparece.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/ayuda.php';
require_once __DIR__ . '/mercadopago.php';   // planes_mp() en el artículo de planes

/**
 * Secciones del menú. Cada artículo: [slug, título, claves de guía | 'custom', descripción].
 * Un elemento ['grupo' => título, 'items' => [...]] se muestra plegable.
 */
function ca_secciones(): array
{
    return [
        ['inicio', 'Inicio', 'bi-house', [
            ['introduccion', 'Introducción', 'custom', 'Qué es MediAgenda y cómo está organizado.'],
            ['primeros-pasos', 'Primeros pasos', 'custom', 'Deja tu consultorio listo en una tarde.'],
            ['planes', 'Planes y prueba gratis', 'custom', 'Qué incluye cada plan y cómo funciona la prueba.'],
            ['migrar', 'Migrar desde otro sistema', ['ruta:migrar/index'], 'Trae pacientes, citas, historial y documentos de Nimbo, Doctoralia o Excel.'],
        ]],
        ['pacientes', 'Pacientes', 'bi-people', [
            ['busqueda-de-pacientes', 'Búsqueda de pacientes', ['page:pacientes'], 'Encuentra a cualquier paciente en segundos.'],
            ['alta-de-pacientes', 'Alta de pacientes', ['pacientes.nuevo', 'ruta:pacientes/create', 'ruta:pacientes/edit'], 'Registra pacientes con su CURP y datos completos.'],
            ['ficha-del-paciente', 'La ficha del paciente', ['ruta:pacientes/ver', 'paciente.medicamentos', 'paciente.archivos'], 'Todo lo del paciente en una pantalla.'],
            ['importar-pacientes', 'Importar pacientes', ['ruta:pacientes/importar'], 'Sube tu lista de pacientes desde Excel o CSV.'],
            ['portal-del-paciente', 'Portal del paciente', ['paciente.portal'], 'Sus citas, recetas y estudios, 24/7.'],
            ['admisiones', 'Admisiones y turnos', ['ruta:admisiones/index', 'admision.siguiente'], 'Recepción de pacientes que llegan sin cita.'],
        ]],
        ['agenda', 'Agendas y citas', 'bi-calendar3', [
            ['introduccion-agenda', 'Introducción', ['page:citas'], 'La agenda del consultorio.'],
            ['crear-citas', 'Crear y mover citas', ['citas.nueva', 'ruta:citas/create', 'ruta:citas/edit'], 'Agenda, reprograma y cambia el estado de las citas.'],
            ['calendario', 'Calendario', ['ruta:citas/calendario'], 'Vista por día, semana y mes.'],
            ['horarios', 'Horarios de atención', ['page:horarios', 'ruta:citas/horarios'], 'Cuándo atiende cada médico.'],
            ['sala-de-espera', 'Sala de espera', ['ruta:citas/sala'], 'Quién llegó y quién sigue.'],
            ['cuestionarios-previos', 'Cuestionarios previos', ['ruta:cuestionarios/index'], 'El paciente responde antes de llegar.'],
            ['recordatorios', 'Recordatorios por WhatsApp', ['ruta:citas/recordar', 'ruta:mensajes/index'], 'Manuales desde tu número o automáticos con WhatsApp incluido.'],
        ]],
        ['expediente', 'Expedientes clínicos', 'bi-folder2-open', [
            ['introduccion-expediente', 'Introducción', ['page:expediente', 'ruta:expediente/index'], 'Cómo se organiza el expediente.'],
            ['expediente-clinico', 'Expediente clínico electrónico', 'custom', 'Notas, aclaraciones, firma, impresión y QR, paso a paso.'],
            ['consultas', 'Consultas', ['paciente.consulta', 'paciente.ia'], 'Registra la nota médica, con dictado e IA.'],
            ['firma-electronica', 'Firma electrónica (e.firma)', ['ruta:usuarios/efirma'], 'Firma tus notas con tu e.firma del SAT.'],
            ['imprimir-expediente', 'Imprimir expediente', ['ruta:expediente/imprimir'], 'Expediente completo, resumen clínico o una nota.'],
            ['grupo' => 'Recetas', 'items' => [
                ['recetas', 'Receta médica', ['page:recetas', 'ruta:recetas/create', 'ruta:recetas/ver'], 'Recetas con tu membrete, QR y envío por WhatsApp.'],
                ['medicamentos-y-favoritas', 'Medicamentos y favoritas', ['ruta:recetas/medicamentos'], 'Tu catálogo para recetar más rápido.'],
            ]],
            ['plantillas', 'Plantillas de consulta', ['page:plantillas'], 'Notas prellenadas por tipo de consulta.'],
            ['documentos', 'Documentos y consentimientos', ['page:documentos', 'ruta:documentos/nuevo', 'ruta:documentos/plantillas', 'ruta:consentimientos/nuevo'], 'Constancias, referencias y consentimientos firmados.'],
            ['grupo' => 'Estudios y servicios', 'items' => [
                ['laboratorio', 'Laboratorio', ['page:laboratorio', 'ruta:laboratorio/orden', 'ruta:laboratorio/ver', 'ruta:laboratorio/estudios'], 'Órdenes y resultados.'],
                ['ultrasonido', 'Ultrasonido', ['page:imagenologia', 'ruta:imagenologia/estudio', 'ruta:imagenologia/ver', 'ruta:imagenologia/plantillas'], 'Estudios con informe e imágenes.'],
                ['vacunacion', 'Vacunación', ['page:vacunacion', 'ruta:vacunacion/paciente'], 'Cartilla y recordatorios de vacunas.'],
                ['hospitalizacion', 'Internamiento', ['page:hospitalizacion', 'ruta:hospitalizacion/estancia', 'ruta:hospitalizacion/cuartos', 'ruta:hospitalizacion/actividades', 'hosp.cuartos', 'hosp.cuenta'], 'Cuartos, estancias, kardex y cuenta.'],
            ]],
        ]],
        ['especialidades', 'Especialidades', 'bi-heart-pulse', [
            ['odontologia', 'Odontología', ['ruta:dental/index', 'ruta:dental/carta', 'ruta:odontograma/index', 'dental.modos', 'dental.plan', 'ruta:dental/perio', 'ruta:dental/historia', 'ruta:dental/evolucion', 'ruta:dental/fotos'], 'Odontograma, periodontograma, plan de tratamiento y fotos.'],
            ['fisioterapia', 'Fisioterapia', ['ruta:fisioterapia/index', 'ruta:fisioterapia/valoracion', 'fisio.goniometria', 'fisio.escalas', 'ruta:fisioterapia/plan', 'ruta:fisioterapia/nota', 'ruta:fisioterapia/ejercicios'], 'Valoración, plan, notas SOAP y ejercicios en casa.'],
            ['psicologia', 'Psicología', ['ruta:psicologia/index'], 'Sesiones y seguimiento.'],
            ['psiquiatria', 'Psiquiatría', ['ruta:psiquiatria/index', 'ruta:psiquiatria/nota', 'psiq.escalas', 'psiq.riesgo', 'psiq.medicacion'], 'Escalas, riesgo y medicación.'],
            ['nutricion', 'Nutrición', ['ruta:nutricion/index'], 'Mediciones y planes.'],
            ['pediatria', 'Pediatría y crecimiento', ['ruta:crecimiento/index', 'ruta:prenatal/index'], 'Curvas de crecimiento y control prenatal.'],
            ['oftalmologia-y-optica', 'Oftalmología y óptica', ['ruta:oftalmologia/index', 'page:optica', 'ruta:optica/graduacion', 'ruta:optica/micas', 'ruta:optica/trabajo'], 'Graduaciones, micas y trabajos de óptica.'],
            ['estetica', 'Medicina estética', ['ruta:estetica/index', 'ruta:mapacorporal/index'], 'Tratamientos y mapa corporal.'],
            ['otras-especialidades', 'Otras especialidades', ['ruta:cardiologia/index', 'ruta:dermatologia/index', 'ruta:endocrinologia/index', 'ruta:gastroenterologia/index', 'ruta:neumologia/index', 'ruta:otorrino/index', 'ruta:urologia/index', 'ruta:traumatologia/index', 'ruta:cirugia/index'], 'Cardiología, dermatología, endocrinología y más.'],
        ]],
        ['telemedicina', 'Telemedicina', 'bi-camera-video', [
            ['videoconsulta', 'Videoconsulta', ['ruta:telemedicina/consulta', 'ruta:telemedicina/sala'], 'Atiende a distancia sin instalar nada.'],
        ]],
        ['caja', 'Caja y facturación', 'bi-receipt', [
            ['cobros', 'Cobros', ['page:facturacion', 'ruta:facturacion/create', 'ruta:facturacion/ver'], 'Cobra, registra abonos y entrega comprobantes.'],
            ['presupuestos', 'Presupuestos', ['page:presupuestos', 'ruta:presupuestos/edit', 'ruta:presupuestos/ver'], 'Cotiza tratamientos y cóbralos en línea.'],
            ['corte-de-caja', 'Corte de caja', ['page:corte', 'ruta:corte/cerrar', 'ruta:corte/cierre', 'ruta:corte/cierres'], 'Cierra tu turno con el dinero cuadrado.'],
            ['egresos', 'Egresos e ingresos', ['page:egresos'], 'Gastos y otros ingresos del consultorio.'],
            ['facturacion-electronica', 'Facturación electrónica (CFDI)', ['ruta:timbres/index'], 'Timbres, datos fiscales y CSD.'],
            ['catalogo-de-servicios', 'Catálogo de servicios', ['page:servicios', 'ruta:servicios/servicio', 'servicio.publico'], 'Servicios, precios y lo que se ve en tu página.'],
            ['grupo' => 'Farmacia', 'items' => [
                ['punto-de-venta', 'Punto de venta', ['page:pos'], 'Vende productos con lector de código de barras.'],
                ['inventario', 'Inventario', ['page:inventario', 'ruta:inventario/producto', 'ruta:inventario/movimiento'], 'Existencias y alertas de stock.'],
            ]],
            ['impresoras-y-escaneres', 'Impresoras y escáneres', ['ruta:dispositivos/index'], 'Impresora de tickets y lector de códigos.'],
        ]],
        ['crecimiento', 'Tu página y pacientes', 'bi-graph-up-arrow', [
            ['resenas', 'Reseñas', ['ruta:resenas/index'], 'Pide y publica las opiniones de tus pacientes.'],
            ['crm', 'CRM y campañas', ['page:crm', 'ruta:crm/campanas'], 'Seguimiento y mensajes a grupos de pacientes.'],
            ['reactivacion', 'Reactivación de pacientes', ['page:reactivacion'], 'Recupera a quienes dejaron de venir.'],
        ]],
        ['administracion', 'Administración', 'bi-gear', [
            ['configuracion', 'Configuración', ['page:configuracion'], 'Datos del consultorio, marca, recordatorios y seguridad.'],
            ['medicos', 'Médicos', ['page:medicos', 'ruta:medicos/edit'], 'Cédula, especialidad, foto y horarios.'],
            ['sucursales', 'Sucursales y recursos', ['ruta:sucursales/index'], 'Agenda por sede, consultorio, cuarto, sillón o equipo.'],
            ['personal-y-roles', 'Personal y roles', ['page:usuarios', 'ruta:usuarios/create', 'ruta:usuarios/edit'], 'Usuarios y permisos de tu equipo.'],
            ['mensajes-whatsapp', 'Mensajes de WhatsApp', ['ruta:mensajes/index'], 'WhatsApp incluido: saldo y paquetes.'],
            ['bitacora', 'Bitácora', ['ruta:auditoria/index'], 'Quién entró, qué vio y qué cambió.'],
            ['seguridad-de-la-cuenta', 'Seguridad de la cuenta', ['ruta:usuarios/seguridad'], 'Verificación en dos pasos y contraseña.'],
            ['suscripcion', 'Mi suscripción', ['ruta:pagos/index'], 'Tu plan y tus pagos.'],
        ]],
        ['soporte', 'Soporte', 'bi-life-preserver', [
            ['soporte', 'Ayuda y soporte técnico', ['ruta:soporte/index', 'ruta:feedback/index'], 'Cómo contactarnos y enviarnos comentarios.'],
        ]],
    ];
}

/** Lista plana de artículos visibles, en orden: slug => [titulo, sección, claves, desc, grupo]. */
function ca_articulos(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $T = ayuda_textos();
    $out = [];
    foreach (ca_secciones() as [$sid, $stit, $sic, $items]) {
        foreach ($items as $it) {
            $lista = isset($it['grupo']) ? array_map(fn ($x) => $x + [4 => $it['grupo']], $it['items']) : [$it + [4 => null]];
            foreach ($lista as [$slug, $tit, $claves, $desc, $grupo]) {
                if ($claves !== 'custom') {
                    $claves = array_values(array_filter($claves, fn ($k) => isset($T[$k])));
                    if (!$claves) continue;
                } elseif (!is_file(__DIR__ . '/../ayuda/_contenido/' . $slug . '.php')) continue;
                $out[$slug] = ['titulo' => $tit, 'seccion' => $sid, 'seccion_t' => $stit, 'icono' => $sic, 'claves' => $claves, 'desc' => $desc, 'grupo' => $grupo];
            }
        }
    }
    return $cache = $out;
}

function ca_url(string $slug = ''): string { return BASE_URL . '/ayuda' . ($slug !== '' ? '/' . $slug : ''); }

/** id de ancla a partir de un título. */
function ca_ancla(string $s): string
{
    $s = strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** HTML del cuerpo de un artículo hecho de guías. */
function ca_cuerpo_guias(array $claves): string
{
    $T = ayuda_textos();
    $varias = count($claves) > 1;
    $h = '';
    foreach ($claves as $i => $k) {
        $g = $T[$k];
        if ($varias) $h .= '<h2 id="' . e(ca_ancla($g['titulo'])) . '">' . e($g['titulo']) . '</h2>';
        if (!empty($g['intro'])) $h .= '<p' . (!$varias && $i === 0 ? ' class="ca-lead"' : '') . '>' . $g['intro'] . '</p>';
        if (!empty($g['pasos'])) {
            foreach (array_values($g['pasos']) as $n => $p) $h .= '<div class="paso"><span class="n">' . ($n + 1) . '</span><div>' . $p . '</div></div>';
        }
        if (!empty($g['tips'])) {
            $h .= '<div class="ca-nota"><div class="ca-nota-t"><i class="bi bi-lightbulb"></i> ' . et('Consejos') . '</div><ul class="mb-0">';
            foreach ($g['tips'] as $t) $h .= '<li>' . $t . '</li>';
            $h .= '</ul></div>';
        }
    }
    return $h;
}

/**
 * Capturas de pantalla de un artículo: assets/img/ayuda/capturas.json
 * { "slug": [["archivo.webp", "Pie de foto"], …] }. Se muestran en orden:
 * la primera bajo la introducción y el resto al final de cada sección.
 */
function ca_capturas(string $slug): array
{
    static $mapa = null;
    if ($mapa === null) {
        $f = __DIR__ . '/../assets/img/ayuda/capturas.json';
        $mapa = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }
    $out = [];
    foreach ($mapa[$slug] ?? [] as [$archivo, $pie]) {
        if (is_file(__DIR__ . '/../assets/img/ayuda/' . basename($archivo))) $out[] = [BASE_URL . '/assets/img/ayuda/' . rawurlencode(basename($archivo)), $pie];
    }
    return $out;
}

function ca_figura(array $c): string
{
    return '<figure class="ca-fig"><img src="' . e($c[0]) . '" alt="' . e($c[1]) . '" loading="lazy" data-zoom><figcaption>' . e($c[1]) . '</figcaption></figure>';
}

/** Cuerpo de cualquier artículo. */
function ca_cuerpo(string $slug): string
{
    $a = ca_articulos()[$slug] ?? null;
    if (!$a) return '';
    if ($a['claves'] !== 'custom') {
        $html = ca_cuerpo_guias($a['claves']);
    } else {
        ob_start();
        include __DIR__ . '/../ayuda/_contenido/' . $slug . '.php';
        $html = (string) ob_get_clean();
    }
    $caps = ca_capturas($slug);
    if (!$caps) return $html;
    // Primera captura después del primer párrafo; las demás, antes de cada <h2> siguiente y al final.
    $primera = array_shift($caps);
    $pos = strpos($html, '</p>');
    $html = $pos !== false ? substr($html, 0, $pos + 4) . ca_figura($primera) . substr($html, $pos + 4) : ca_figura($primera) . $html;
    $partes = preg_split('/(?=<h2 )/', $html);
    foreach ($partes as $i => &$p) { if ($i > 1 && $caps) $p = ca_figura(array_shift($caps)) . $p; }
    unset($p);
    return implode('', $partes) . implode('', array_map('ca_figura', $caps));
}

/** Índice de búsqueda: [{t, s, u, x}] con el texto plano de cada artículo. */
function ca_indice_busqueda(): array
{
    $idx = [];
    foreach (ca_articulos() as $slug => $a) {
        $txt = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', ca_cuerpo($slug))), ENT_QUOTES, 'UTF-8')));
        $idx[] = ['t' => $a['titulo'], 's' => $a['seccion_t'], 'u' => ca_url($slug), 'd' => $a['desc'], 'x' => mb_substr($txt, 0, 2500)];
    }
    return $idx;
}

/** Retroalimentación "¿Te fue útil?" (migración perezosa). */
function asegurar_ayuda_util(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS ayuda_util (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(80) NOT NULL,
        util TINYINT(1) NOT NULL,
        comentario VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
