<?php
/**
 * Micrositio público de un consultorio: su propia página por slug.
 *   /?c=<slug>   (o el bonito /c/<slug> vía .htaccess)
 *
 * Diseño: la marca del consultorio manda. Todo el color sale de su acento
 * (botones, iconos, bandas), con movimiento al hacer scroll como la landing.
 * Pensado para verse bien AUNQUE el consultorio no haya llenado todo: el hero es
 * un degradado sólido con o sin foto, y siempre hay tarjetas y datos que mostrar.
 *
 * Se configura desde el dashboard: titular, foto, "sobre nosotros", marca y
 * contacto. Lo demás sale solo de la data: servicios, equipo y horario.
 */

tenant_forzar((int) $con['id']);

$marca    = marca_nombre();
$lema     = cfg('marca_lema');
$titular  = cfg('web_titular') ?: $marca;
$acerca   = cfg('web_acerca');
/* La foto entra en un atributo style como url(). e() ya escapa las comillas
   —ENT_QUOTES— así que no se puede romper el atributo, pero además se exige
   que sea http(s) o una ruta del propio sitio: en una página pública no
   conviene depender de una sola capa. */
$foto     = (string) cfg('web_foto');
if ($foto !== '' && !preg_match("#^(https?://|/)[^\s'\"()]+$#i", $foto)) { $foto = ''; }
$dir      = cfg('direccion');
$tel      = cfg('telefono');
$correo   = cfg('email');
$acento   = color_acento();
$reservar = agenda_online_activa();
// Agendar vive en su propia página (evita que refrescar reenvíe el formulario).
$urlAgendar = BASE_URL . '/agenda/reservar?c=' . rawurlencode($con['slug']);

$servicios = [];
try {
    $st = db()->prepare('SELECT nombre, categoria, precio FROM servicios
                         WHERE consultorio_id = ? AND activo = 1 ORDER BY categoria, nombre');
    $st->execute([(int) $con['id']]);
    foreach ($st->fetchAll() as $s) { $servicios[$s['categoria'] ?: t('Servicios')][] = $s; }
} catch (Throwable $e) {}

// Equipo = el catálogo de médicos (rol 'medico'), igual que /medicos/index. NO
// se incluye 'admin': el dueño/administrador es personal, no necesariamente un
// médico que atiende. Cada médico trae SU propio horario (cada quien su agenda).
$medicos = [];
try {
    asegurar_perfil_medico();
    $st = db()->prepare("SELECT id, nombre, especialidad, cedula, foto, semblanza FROM usuarios
                         WHERE consultorio_id = ? AND activo = 1 AND rol = 'medico' ORDER BY nombre");
    $st->execute([(int) $con['id']]);
    $medicos = $st->fetchAll();
    foreach ($medicos as &$m) { $m['horario'] = horario_atencion_texto((int) $con['id'], (int) $m['id']); }
    unset($m);
} catch (Throwable $e) {}

$nServicios = array_sum(array_map('count', $servicios));
$especialidades = array_values(array_unique(array_filter(array_map(fn($m) => $m['especialidad'] ?: null, $medicos))));
$anios = max(0, (int) floor((time() - strtotime($con['creado_en'] ?? 'now')) / 31556952));

// El WhatsApp de la página lo pone el admin en Configuración. Si no puso uno
// dedicado, se usa el teléfono general.
$wa = '';
$waNum = cfg('whatsapp') ?: $tel;
if ($waNum) {
    $num = preg_replace('/\D+/', '', $waNum);
    if (strlen($num) <= 10) $num = cfg('pais_lada', '52') . $num;
    $wa = 'https://wa.me/' . $num . '?text=' . rawurlencode(t('Hola') . ', ' . t('me interesa información de') . ' ' . $marca . '.');
}

// Tarjetas de beneficios (widgets pastel). Ciertas para cualquier consultorio.
$benes = [
    ['bi-clipboard2-pulse', t('Expediente digital'), t('Tu historial, recetas y estudios, siempre a la mano y seguros.'), 'coral'],
    ['bi-calendar-heart',   $reservar ? t('Agenda en línea') : t('Atención puntual'),
        $reservar ? t('Aparta tu cita cuando quieras, sin llamadas ni esperas.') : t('Respetamos tu tiempo: consulta a la hora acordada.'), 'sage'],
    ['bi-shield-check',     t('Atención de confianza'), t('Un equipo que te conoce y da seguimiento a tu tratamiento.'), 'teal'],
];

// Enlaces de sección para el header (estilo GymOS). Solo los que existen.
$navLinks = [];
if ($servicios)       $navLinks[] = [t('Servicios'), '#servicios'];
if ($medicos)         $navLinks[] = [t('Equipo'), '#equipo'];
$navLinks[] = [t('Contacto'), '#contacto'];


// Reseñas: se leen aquí arriba porque el hero también presume el promedio.
/* Solo se pinta la sección si hay reseñas publicadas. Una sección de opiniones
   vacía —"Sé el primero en opinar"— resta en vez de sumar: le dice al paciente
   que nadie ha venido. Mejor que no exista hasta que tenga algo que enseñar. */
$resResumen = resenas_resumen((int) $con['id']);
$resLista   = $resResumen['total'] ? resenas_publicas((int) $con['id'], 9) : [];

/* Iniciales del médico SIN el título: "Dr Jorge Perez" → "JP". Antes salía
   "D" para todos, porque se tomaba la primera letra del nombre completo. */
$iniciales = function (string $nombre): string {
    $p = preg_split('/\s+/u', trim(preg_replace('/^(dra?|lic|mtr[oa]|psic|nut|odont|ing)\.?\s+/iu', '', trim($nombre))));
    $ini = mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr($p[1], 0, 1) : '');
    return mb_strtoupper($ini ?: mb_substr($nombre, 0, 1));
};

// Ícono según la categoría del servicio (heurística por palabras); genérico si no cuadra.
$catIcon = function (string $c): string {
    $c = mb_strtolower($c);
    foreach ([
        'cirug' => 'bi-scissors', 'consult' => 'bi-clipboard2-pulse', 'dental' => 'bi-emoji-smile',
        'odont' => 'bi-emoji-smile', 'endodon' => 'bi-emoji-smile', 'limpiez' => 'bi-stars',
        'estud' => 'bi-clipboard2-data', 'labor' => 'bi-eyedropper', 'imagen' => 'bi-camera',
        'rayos' => 'bi-camera', 'estét' => 'bi-gem', 'prevent' => 'bi-shield-check',
        'urgen' => 'bi-heart-pulse', 'pediatr' => 'bi-emoji-laughing', 'ginec' => 'bi-gender-female',
        'ópt' => 'bi-eyeglasses', 'prótes' => 'bi-tools', 'restaur' => 'bi-bandaid', 'procedim' => 'bi-bandaid',
    ] as $k => $ic) { if (str_contains($c, $k)) return $ic; }
    return 'bi-plus-circle';
};

$titulo = $marca;
$indexable = true;
$motion = true;   // aparición al hacer scroll (landing-motion.css/js)
include __DIR__ . '/publico_header.php';
?>

<style>
    /* ── Micrositio: la marca del consultorio manda ──────────────────────
       Todo sale de --cl (el acento que eligió el consultorio): botones,
       iconos, bandas y tintes. Nada de azul MediOS ni pasteles ajenos.
       --on-cl es el texto legible encima (blanco u oscuro según el color). */
    body.lp { overflow-x: clip; }
    .pub-wrap { max-width: none; padding: 0; }
    .clx { --cl: <?= $acento ?>; --cl-d: color-mix(in srgb, <?= $acento ?> 72%, #000);
           --on-cl: <?= color_texto_sobre($acento) ?>;
           --tint: color-mix(in srgb, <?= $acento ?> 7%, #fff);
           --ink: #1d1d1f; --mut: #6e6e73; --line: rgba(0,0,0,.06);
           font-family: var(--font-ui); color: var(--ink); }
    /* Cada banda ocupa todo el ancho, aunque el contenido vaya a 1200px. */
    .clx > header, .clx > section { width: 100vw; margin-left: calc(50% - 50vw); }
    .clx section { padding: 5.5rem 1.25rem; }
    .clx .wrap { max-width: 1200px; margin: 0 auto; }
    .clx .head { text-align: center; margin-bottom: 3rem; }
    .clx h2.t { font-family: var(--font-display); font-weight: 650; letter-spacing: -.035em; line-height: 1.08;
                font-size: clamp(1.9rem, 4vw, 2.8rem); margin: .5rem 0 0; color: var(--ink); }
    .clx .sub { color: var(--mut); max-width: 56ch; margin: .9rem auto 0; font-size: 1.05rem; line-height: 1.55; }
    .clx .eyebrow { display: inline-flex; align-items: center; gap: .4rem; color: var(--cl); font-weight: 650;
                    font-size: .76rem; letter-spacing: .14em; text-transform: uppercase;
                    background: color-mix(in srgb, var(--cl) 9%, #fff); padding: .38rem .85rem; border-radius: 999px; }

    .clx .btn-cta, .clx .btn-gho { border-radius: 980px; padding: .9rem 1.7rem; font-weight: 600; letter-spacing: -.01em;
        text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; font-size: 1rem;
        transition: background .25s ease, color .25s ease, transform .2s ease, box-shadow .25s ease; }
    .clx .btn-cta { background: var(--cl); color: var(--on-cl); border: 0;
                    box-shadow: 0 10px 26px color-mix(in srgb, var(--cl) 35%, transparent); }
    .clx .btn-cta:hover { background: var(--cl-d); color: #fff; transform: translateY(-2px);
                          box-shadow: 0 14px 32px color-mix(in srgb, var(--cl) 42%, transparent); }
    .clx .btn-gho { background: rgba(255,255,255,.85); color: var(--ink); border: 1px solid var(--line);
                    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }
    .clx .btn-gho:hover { background: #fff; color: var(--cl); transform: translateY(-2px); }
    .clx .btn-gho .bi-whatsapp { color: #25d366; }
    .clx .btn-cta:active, .clx .btn-gho:active { transform: scale(.97); }

    /* ── Hero ───────────────────────────────────────────────────────────
       Sin foto: fondo claro con dos manchas del acento que se mueven lento.
       Con foto: la foto es el banner y un degradado blanco (izquierda→derecha
       en escritorio, de arriba abajo en móvil) garantiza que el texto se lea. */
    .clx .hero { position: relative; overflow: hidden;
                 background: radial-gradient(1200px 600px at 85% -10%, color-mix(in srgb, var(--cl) 16%, transparent), transparent 60%),
                             linear-gradient(170deg, var(--tint) 0%, #fff 70%); }
    .clx .hero .orb { position: absolute; border-radius: 50%; filter: blur(60px); pointer-events: none; z-index: 0;
                      background: color-mix(in srgb, var(--cl) 26%, transparent); }
    .clx .hero .orb.a { width: 460px; height: 460px; right: -120px; top: -140px; }
    .clx .hero .orb.b { width: 320px; height: 320px; left: -140px; bottom: -160px; opacity: .6; }
    @keyframes clOrb { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-40px,30px) scale(1.08); } }
    html.motion .clx .hero .orb.a { animation: clOrb 16s ease-in-out infinite; }
    html.motion .clx .hero .orb.b { animation: clOrb 20s ease-in-out infinite reverse; }

    .clx .hero.con-foto { background: var(--foto) center / cover no-repeat; }
    .clx .hero.con-foto .orb { display: none; }
    .clx .hero.con-foto::after { content: ''; position: absolute; inset: 0; z-index: 0; pointer-events: none;
        background: linear-gradient(100deg, rgba(255,255,255,.97) 0%, rgba(255,255,255,.93) 36%,
                    rgba(255,255,255,.70) 52%, rgba(255,255,255,.28) 68%, rgba(255,255,255,0) 86%); }
    @media (max-width: 991.98px) {
        .clx .hero.con-foto::after { background: linear-gradient(180deg, rgba(255,255,255,.95) 0%,
                                     rgba(255,255,255,.88) 60%, rgba(255,255,255,.72) 100%); }
    }
    .clx .hero .wrap { position: relative; z-index: 1; min-height: 640px; display: flex; align-items: center;
                       padding: 4.5rem 0 5rem; }
    .clx .hero .logo { max-height: 64px; max-width: 240px; object-fit: contain; background: #fff;
                       border-radius: 16px; padding: .5rem .8rem; box-shadow: 0 6px 20px rgba(15,30,60,.10); }
    .clx .hero h1 { font-family: var(--font-display); font-weight: 700; letter-spacing: -.045em; line-height: 1.02;
                    font-size: clamp(2.6rem, 6vw, 4.6rem); margin: 1.3rem 0 1rem; color: var(--ink); }
    .clx .hero h1 .ac { color: var(--cl); }
    .clx .hero .lead { font-size: 1.18rem; color: #4a4a4f; max-width: 38ch; line-height: 1.5; letter-spacing: -.012em; }
    .clx .hero .tags { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1.6rem; }
    .clx .hero .tag { display: inline-flex; align-items: center; gap: .4rem; background: rgba(255,255,255,.9);
                      border: 1px solid var(--line); border-radius: 999px; padding: .42rem .9rem;
                      font-size: .85rem; font-weight: 550; color: var(--ink); box-shadow: 0 2px 8px rgba(15,30,60,.05); }
    .clx .hero .tag .bi { color: var(--cl); }
    .clx .hero .tag .star { color: #f5a524; }

    /* Tarjeta de datos del hero: vidrio blanco que flota. */
    .clx .hero-info { background: rgba(255,255,255,.92); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
                      border: 1px solid rgba(255,255,255,.7); border-radius: 26px; padding: 1.8rem;
                      box-shadow: 0 4px 14px rgba(15,30,60,.06), 0 30px 70px rgba(15,30,60,.16); }
    html.motion .clx .hero-info { animation: lpUp .9s .35s cubic-bezier(.22,1,.36,1) both, lpBob 7s 1.6s ease-in-out infinite; }
    .clx .hero-info .hi-t { font-weight: 650; font-size: .76rem; letter-spacing: .12em; text-transform: uppercase;
                            color: var(--cl); margin-bottom: .6rem; }
    .clx .hero-info .hi-row { display: flex; align-items: center; gap: 1rem; padding: .85rem 0; border-top: 1px solid var(--line); }
    .clx .hero-info .hi-row:first-of-type { border-top: 0; }
    .clx .hero-info .hi-ic { width: 48px; height: 48px; flex-shrink: 0; border-radius: 15px; display: flex;
                             align-items: center; justify-content: center; font-size: 1.25rem;
                             background: linear-gradient(135deg, var(--cl), var(--cl-d)); color: var(--on-cl);
                             box-shadow: 0 8px 18px color-mix(in srgb, var(--cl) 30%, transparent); }
    .clx .hero-info .hi-n { font-family: var(--font-display); font-weight: 700; font-size: 1.45rem; line-height: 1.1;
                            letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
    .clx .hero-info .hi-l { font-size: .86rem; color: var(--mut); }
    .clx .hero-info .btn-cta { width: 100%; justify-content: center; margin-top: 1.1rem; }
    html.motion .clx .hero-text > * { animation: lpUp .9s cubic-bezier(.22,1,.36,1) both; }
    html.motion .clx .hero-text > :nth-child(2) { animation-delay: .1s; }
    html.motion .clx .hero-text > :nth-child(3) { animation-delay: .2s; }
    html.motion .clx .hero-text > :nth-child(4) { animation-delay: .3s; }
    html.motion .clx .hero-text > :nth-child(5) { animation-delay: .4s; }

    /* ── Beneficios: tarjetas con el tinte de la marca ───────────────── */
    .clx .benes { padding-top: 0; margin-top: -3rem; position: relative; z-index: 2; background: transparent; }
    .clx .bene { background: #fff; border: 1px solid var(--line); border-radius: 24px; padding: 1.9rem; height: 100%;
                 box-shadow: 0 2px 8px rgba(0,0,0,.03), 0 18px 44px rgba(15,30,60,.08);
                 transition: transform .4s cubic-bezier(.22,1,.36,1), box-shadow .4s ease; }
    .clx .bene:hover { transform: translateY(-6px); box-shadow: 0 4px 12px rgba(0,0,0,.04), 0 28px 60px rgba(15,30,60,.13); }
    .clx .bene .ic { width: 56px; height: 56px; border-radius: 17px; display: flex; align-items: center; justify-content: center;
                     font-size: 1.5rem; margin-bottom: 1.1rem; color: var(--cl); background: color-mix(in srgb, var(--cl) 11%, #fff);
                     transition: transform .45s cubic-bezier(.34,1.56,.64,1); }
    .clx .bene:hover .ic { transform: scale(1.1) rotate(-6deg); }
    .clx .bene h5 { font-family: var(--font-display); font-weight: 650; letter-spacing: -.022em; font-size: 1.15rem; }
    .clx .bene p { color: var(--mut); font-size: .95rem; line-height: 1.5; margin: 0; }

    /* ── Sobre nosotros ──────────────────────────────────────────────── */
    .clx .about { background: #fff; }
    .clx .about blockquote { max-width: 780px; margin: 0 auto; text-align: center; font-family: var(--font-display);
                             font-size: clamp(1.15rem, 2.2vw, 1.45rem); line-height: 1.55; letter-spacing: -.015em; color: #3a3a3f; }
    .clx .about blockquote::before { content: "\201C"; display: block; font-size: 4.5rem; line-height: .6; color: var(--cl); opacity: .35; margin-bottom: .6rem; }

    /* ── Servicios: rejilla compacta con filtro por categoría ────────────
       Antes era una fila por categoría con 1–2 tarjetas y mucho hueco: con
       10 categorías la página no acababa nunca. Ahora es una sola rejilla
       pareja y unas pastillas filtran (sin JS se ven todos). */
    .clx .servs { background: linear-gradient(180deg, var(--tint) 0%, #fff 100%); }
    .clx .sv-chips { display: flex; gap: .5rem; overflow-x: auto; padding: .2rem .2rem 1rem; margin-bottom: 1rem;
                     scrollbar-width: none; justify-content: safe center; }
    .clx .sv-chips::-webkit-scrollbar { display: none; }
    .clx .sv-chips[hidden] { display: none; }
    .clx .sv-chip { flex: none; border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 999px;
                    padding: .55rem 1.05rem; font-weight: 550; font-size: .9rem; cursor: pointer;
                    transition: background .2s ease, color .2s ease, border-color .2s ease; }
    .clx .sv-chip:hover { border-color: var(--cl); color: var(--cl); }
    .clx .sv-chip.on { background: var(--cl); border-color: var(--cl); color: var(--on-cl); }
    .clx .sv-chip small { opacity: .6; margin-left: .25rem; }
    .clx .sv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; }
    @media (max-width: 575.98px) { .clx .sv-grid { grid-template-columns: 1fr; gap: .7rem; } }
    .clx .sv { display: flex; align-items: center; gap: 1rem; background: #fff; border: 1px solid var(--line);
               border-radius: 20px; padding: 1.1rem 1.2rem; color: var(--ink); text-decoration: none;
               box-shadow: 0 1px 4px rgba(0,0,0,.03), 0 10px 26px rgba(15,30,60,.05);
               transition: transform .35s cubic-bezier(.22,1,.36,1), box-shadow .35s ease, border-color .35s ease; }
    .clx .sv[hidden] { display: none; }
    .clx a.sv:hover { transform: translateY(-3px); border-color: color-mix(in srgb, var(--cl) 35%, transparent);
                      box-shadow: 0 4px 12px rgba(0,0,0,.04), 0 18px 40px rgba(15,30,60,.10); color: var(--ink); }
    .clx .sv .si { width: 50px; height: 50px; flex: none; border-radius: 15px; display: flex; align-items: center;
                   justify-content: center; font-size: 1.3rem; color: var(--cl); background: color-mix(in srgb, var(--cl) 10%, #fff); }
    .clx .sv .tx { flex: 1; min-width: 0; }
    .clx .sv .ct { font-size: .72rem; font-weight: 650; letter-spacing: .08em; text-transform: uppercase; color: var(--mut); }
    .clx .sv .n { font-weight: 600; line-height: 1.3; letter-spacing: -.01em; }
    .clx .sv .p { text-align: right; flex: none; }
    .clx .sv .p small { display: block; font-size: .7rem; color: var(--mut); line-height: 1; }
    .clx .sv .p b { font-family: var(--font-display); font-weight: 700; font-size: 1.12rem; letter-spacing: -.02em;
                    color: var(--cl); font-variant-numeric: tabular-nums; }
    .clx .sv .go { flex: none; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center;
                   justify-content: center; background: var(--tint); color: var(--cl);
                   transition: background .25s ease, color .25s ease, transform .3s ease; }
    .clx a.sv:hover .go { background: var(--cl); color: var(--on-cl); transform: translateX(3px); }

    /* ── Equipo ──────────────────────────────────────────────────────── */
    .clx .team { background: #fff; }
    .clx .medcard { position: relative; background: #fff; border: 1px solid var(--line); border-radius: 26px;
                    overflow: hidden; height: 100%; display: flex; flex-direction: column;
                    box-shadow: 0 2px 8px rgba(0,0,0,.03), 0 18px 44px rgba(15,30,60,.08);
                    transition: transform .4s cubic-bezier(.22,1,.36,1), box-shadow .4s ease; }
    .clx .medcard:hover { transform: translateY(-6px); box-shadow: 0 4px 12px rgba(0,0,0,.04), 0 28px 60px rgba(15,30,60,.13); }
    .clx .medcard .cover { height: 96px; background: linear-gradient(135deg, var(--cl), var(--cl-d)); position: relative; }
    .clx .medcard .cover::after { content: ''; position: absolute; inset: 0;
        background: radial-gradient(circle at 85% 0%, rgba(255,255,255,.28), transparent 55%); }
    .clx .medcard .av, .clx .medcard .av-img { width: 104px; height: 104px; border-radius: 50%; margin: -52px auto 0;
        position: relative; z-index: 1; border: 5px solid #fff; box-shadow: 0 10px 26px rgba(15,30,60,.15); }
    .clx .medcard .av-img { object-fit: cover; display: block; }
    .clx .medcard .av { display: flex; align-items: center; justify-content: center; font-family: var(--font-display);
                        font-weight: 700; font-size: 2.1rem; letter-spacing: -.02em; color: var(--cl);
                        background: color-mix(in srgb, var(--cl) 10%, #fff); }
    .clx .medcard .bd { padding: 1rem 1.6rem 1.6rem; text-align: center; flex: 1; display: flex; flex-direction: column; }
    .clx .medcard .nm { font-family: var(--font-display); font-weight: 650; font-size: 1.2rem; letter-spacing: -.02em; }
    .clx .medcard .sp { display: inline-block; margin-top: .35rem; font-size: .82rem; font-weight: 600; color: var(--cl);
                        background: color-mix(in srgb, var(--cl) 9%, #fff); border-radius: 999px; padding: .25rem .75rem; }
    .clx .medcard .ced { font-size: .8rem; color: var(--mut); margin-top: .55rem; }
    .clx .medcard .ced .bi { color: #16a34a; }
    .clx .medcard .sem { font-size: .92rem; color: var(--mut); line-height: 1.55; margin: .9rem 0 0; }
    .clx .medhor { margin-top: 1.1rem; background: var(--tint); border-radius: 16px; padding: .85rem 1rem; text-align: left; font-size: .86rem; }
    .clx .medhor-t { color: var(--cl); font-weight: 650; font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; margin-bottom: .35rem; }
    .clx .medhor .d-flex + .d-flex { margin-top: .15rem; }
    .clx .medcard .btn-cta { margin-top: auto; justify-content: center; padding: .75rem 1.2rem; font-size: .95rem; }
    .clx .medcard .sp-btn { height: 1.1rem; }

    /* ── Reseñas ─────────────────────────────────────────────────────── */
    .clx .revs { background: linear-gradient(180deg, #fff 0%, var(--tint) 100%); }
    .clx .rs-prom { font-family: var(--font-display); font-weight: 700; font-size: 2.6rem; line-height: 1;
                    letter-spacing: -.045em; font-variant-numeric: tabular-nums; }
    .clx .rs-star { color: #f5a524; letter-spacing: .08rem; }
    .clx .rs-card { position: relative; background: #fff; border: 1px solid var(--line); border-radius: 24px; padding: 1.8rem;
                    box-shadow: 0 2px 8px rgba(0,0,0,.03), 0 14px 36px rgba(15,30,60,.06); }
    .clx .rs-card::before { content: "\201C"; position: absolute; right: 1.4rem; top: .4rem; font-family: Georgia, serif;
                            font-size: 4.5rem; line-height: 1; color: var(--cl); opacity: .12; }
    .clx .rs-txt { font-size: 1rem; line-height: 1.6; letter-spacing: -.008em; }
    .clx .rs-autor { display: flex; align-items: center; gap: .6rem; font-weight: 600; font-size: .92rem; }
    .clx .rs-autor .ini { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
                          font-size: .85rem; font-weight: 700; color: var(--cl); background: color-mix(in srgb, var(--cl) 10%, #fff); }
    .clx .rs-med { font-weight: 400; color: var(--mut); }
    .clx .rs-resp { margin-top: 1rem; padding: .8rem 1rem; border-radius: 14px; background: var(--tint); font-size: .9rem; color: var(--mut); }

    /* ── Contacto: datos a la izquierda, mapa a la derecha ───────────── */
    .clx .contact { background: #fff; }
    .clx .ct-item { display: flex; align-items: center; gap: 1rem; background: #fff; border: 1px solid var(--line);
                    border-radius: 20px; padding: 1.1rem 1.2rem; color: var(--ink); text-decoration: none; margin-bottom: .8rem;
                    box-shadow: 0 1px 4px rgba(0,0,0,.03), 0 10px 26px rgba(15,30,60,.05);
                    transition: transform .3s ease, border-color .3s ease; }
    .clx a.ct-item:hover { transform: translateX(4px); border-color: color-mix(in srgb, var(--cl) 35%, transparent); color: var(--ink); }
    .clx .ct-item .ic { width: 50px; height: 50px; flex: none; border-radius: 15px; display: flex; align-items: center;
                        justify-content: center; font-size: 1.3rem; color: var(--cl); background: color-mix(in srgb, var(--cl) 10%, #fff); }
    .clx .ct-item .ic.wa { color: #fff; background: #25d366; }
    .clx .ct-item .lb { font-size: .74rem; font-weight: 650; letter-spacing: .08em; text-transform: uppercase; color: var(--mut); }
    .clx .ct-item .vl { font-weight: 600; word-break: break-word; }
    .clx .cl-map { border-radius: 26px; overflow: hidden; height: 100%; min-height: 380px; background: var(--tint);
                   box-shadow: 0 4px 14px rgba(15,30,60,.06), 0 24px 56px rgba(15,30,60,.10); }
    .clx .cl-map iframe { display: block; width: 100%; height: 100%; min-height: 380px; border: 0; }

    /* ── Banda final de reserva ──────────────────────────────────────── */
    .clx .book { position: relative; overflow: hidden; color: var(--on-cl); text-align: center;
                 background: linear-gradient(125deg, var(--cl-d), var(--cl)); }
    .clx .book::before, .clx .book::after { content: ''; position: absolute; border-radius: 50%; pointer-events: none;
                                            background: rgba(255,255,255,.10); }
    .clx .book::before { width: 420px; height: 420px; right: -120px; top: -180px; }
    .clx .book::after  { width: 300px; height: 300px; left: -100px; bottom: -150px; }
    .clx .book .wrap { position: relative; z-index: 1; }
    .clx .book h2.t, .clx .book .sub { color: inherit; }
    .clx .book .sub { opacity: .88; }
    .clx .book .eyebrow { background: rgba(255,255,255,.16); color: inherit; }
    .clx .book .btn-cta { background: #fff; color: var(--cl-d); box-shadow: 0 16px 40px rgba(0,0,0,.22); font-size: 1.05rem; padding: 1rem 2rem; }
    .clx .book .btn-cta:hover { background: #fff; color: var(--cl); }
</style>

<div class="clx">

<!-- ===== HERO ===== -->
<header class="hero<?= $foto ? ' con-foto' : '' ?>"
        <?php if ($foto): ?>style="--foto:url('<?= e($foto) ?>')"<?php endif; ?>>
    <span class="orb a" aria-hidden="true"></span><span class="orb b" aria-hidden="true"></span>
    <div class="wrap px-3 px-lg-4">
        <div class="row align-items-center g-5 w-100 m-0">
            <div class="col-lg-7 hero-text">
                <div>
                    <?php if (cfg('marca_logo')): ?>
                        <img src="<?= e(cfg('marca_logo')) ?>" alt="<?= e($marca) ?>" class="logo">
                    <?php else: ?>
                        <span class="eyebrow"><i class="bi bi-heart-pulse-fill"></i> <?= e($marca) ?></span>
                    <?php endif; ?>
                </div>
                <h1><?= e($titular) ?></h1>
                <p class="lead"><?= e($lema ?: t('Cuidamos de tu salud con atención cercana y profesional.')) ?></p>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <?php if ($reservar): ?>
                        <a href="<?= e($urlAgendar) ?>" class="btn-cta"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
                    <?php endif; ?>
                    <?php if ($wa): ?>
                        <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn-gho"><i class="bi bi-whatsapp"></i> WhatsApp</a>
                    <?php elseif ($tel): ?>
                        <a href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>" class="btn-gho"><i class="bi bi-telephone"></i> <?= e($tel) ?></a>
                    <?php endif; ?>
                </div>
                <?php /* Etiquetas con datos reales: especialidades del equipo y el
                         promedio de reseñas (solo si existen). */ ?>
                <?php if ($especialidades || $resResumen['total']): ?>
                <div class="tags">
                    <?php if ($resResumen['total']): ?>
                        <span class="tag"><i class="bi bi-star-fill star"></i> <?= number_format($resResumen['promedio'], 1) ?> · <?= $resResumen['total'] ?> <?= $resResumen['total'] === 1 ? et('reseña') : et('reseñas') ?></span>
                    <?php endif; ?>
                    <?php foreach (array_slice($especialidades, 0, 4) as $esp): ?>
                        <span class="tag"><i class="bi bi-check2-circle"></i> <?= e($esp) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-5">
                <div class="hero-info">
                    <div class="hi-t"><i class="bi bi-shield-check"></i> <?= et('Atención profesional') ?></div>
                    <?php if (count($medicos)): ?>
                    <div class="hi-row"><div class="hi-ic"><i class="bi bi-person-badge"></i></div>
                        <div><div class="hi-n" data-count><?= count($medicos) ?></div><div class="hi-l"><?= count($medicos) === 1 ? et('especialista a tu servicio') : et('especialistas a tu servicio') ?></div></div></div>
                    <?php endif; ?>
                    <?php if ($nServicios): ?>
                    <div class="hi-row"><div class="hi-ic"><i class="bi bi-clipboard2-pulse"></i></div>
                        <div><div class="hi-n" data-count><?= $nServicios ?></div><div class="hi-l"><?= et('servicios disponibles') ?></div></div></div>
                    <?php endif; ?>
                    <?php if ($anios >= 1): ?>
                    <div class="hi-row"><div class="hi-ic"><i class="bi bi-award"></i></div>
                        <div><div class="hi-n"><?= $anios ?>+ <?= et('años') ?></div><div class="hi-l"><?= et('cuidando tu salud') ?></div></div></div>
                    <?php endif; ?>
                    <?php if ($reservar): ?>
                    <div class="hi-row"><div class="hi-ic"><i class="bi bi-calendar-check"></i></div>
                        <div><div class="hi-n" style="font-size:1.05rem"><?= et('Agenda en línea') ?></div><div class="hi-l"><?= et('Aparta tu cita 24/7') ?></div></div></div>
                    <a href="<?= e($urlAgendar) ?>" class="btn-cta"><i class="bi bi-calendar-plus"></i> <?= et('Agendar ahora') ?></a>
                    <?php elseif ($tel): ?>
                    <div class="hi-row"><div class="hi-ic"><i class="bi bi-telephone"></i></div>
                        <div><div class="hi-n" style="font-size:1.05rem"><?= e($tel) ?></div><div class="hi-l"><?= et('Llámanos para tu cita') ?></div></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- ===== BENEFICIOS ===== -->
<section class="benes">
    <div class="wrap">
        <div class="row g-4" data-stagger>
            <?php foreach ($benes as [$ic, $t1, $t2]): ?>
            <div class="col-md-4">
                <div class="bene">
                    <span class="ic"><i class="bi <?= $ic ?>"></i></span>
                    <h5><?= e($t1) ?></h5>
                    <p><?= e($t2) ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== SOBRE NOSOTROS ===== -->
<?php if ($acerca): ?>
<section class="about">
    <div class="wrap">
        <div class="head" data-reveal>
            <span class="eyebrow"><?= et('Sobre nosotros') ?></span>
            <h2 class="t"><?= et('Conócenos') ?></h2>
        </div>
        <blockquote data-reveal><?= nl2br(e($acerca)) ?></blockquote>
    </div>
</section>
<?php endif; ?>

<!-- ===== SERVICIOS ===== -->
<?php if ($servicios): ?>
<section id="servicios" class="servs">
    <div class="wrap">
        <div class="head" data-reveal>
            <span class="eyebrow"><?= et('Lo que ofrecemos') ?></span>
            <h2 class="t"><?= et('Nuestros servicios') ?></h2>
        </div>
        <?php if (count($servicios) > 1): /* El filtro solo tiene sentido con 2+ categorías; lo enciende el JS. */ ?>
        <div class="sv-chips" role="tablist" hidden>
            <button type="button" class="sv-chip on" data-cat=""><?= et('Todos') ?> <small><?= $nServicios ?></small></button>
            <?php foreach ($servicios as $categoria => $items): ?>
            <button type="button" class="sv-chip" data-cat="<?= e($categoria) ?>"><?= e($categoria) ?> <small><?= count($items) ?></small></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="sv-grid">
            <?php foreach ($servicios as $categoria => $items): foreach ($items as $s):
                $tag = $reservar ? 'a' : 'div'; ?>
            <<?= $tag ?> class="sv" data-cat="<?= e($categoria) ?>"<?= $reservar ? ' href="' . e($urlAgendar) . '"' : '' ?>>
                <span class="si"><i class="bi <?= $catIcon((string) $categoria) ?>"></i></span>
                <span class="tx">
                    <span class="ct d-block"><?= e($categoria) ?></span>
                    <span class="n d-block"><?= e($s['nombre']) ?></span>
                </span>
                <?php if ((float) $s['precio'] > 0): ?>
                    <span class="p"><small><?= et('Desde') ?></small><b><?= fmt_money($s['precio']) ?></b></span>
                <?php endif; ?>
                <?php if ($reservar): ?><span class="go" aria-hidden="true"><i class="bi bi-arrow-right"></i></span><?php endif; ?>
            </<?= $tag ?>>
            <?php endforeach; endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== EQUIPO ===== -->
<?php if ($medicos): ?>
<section id="equipo" class="team">
    <div class="wrap">
        <div class="head" data-reveal>
            <span class="eyebrow"><?= et('Quién te atiende') ?></span>
            <h2 class="t"><?= et('Nuestro equipo') ?></h2>
        </div>
        <div class="row g-4 justify-content-center" data-stagger>
            <?php foreach ($medicos as $m): ?>
            <div class="col-sm-6 col-lg-4">
                <div class="medcard">
                    <div class="cover"></div>
                    <?php if (!empty($m['foto'])): ?>
                        <img src="<?= e($m['foto']) ?>" alt="<?= e($m['nombre']) ?>" class="av-img" loading="lazy">
                    <?php else: ?>
                        <div class="av"><?= e($iniciales((string) $m['nombre'])) ?></div>
                    <?php endif; ?>
                    <div class="bd">
                        <div class="nm"><?= e($m['nombre']) ?></div>
                        <?php if ($m['especialidad']): ?><div><span class="sp"><?= e($m['especialidad']) ?></span></div><?php endif; ?>
                        <?php if (!empty($m['cedula'])): ?>
                            <div class="ced"><i class="bi bi-patch-check-fill"></i> <?= et('Céd. Prof.') ?> <?= e($m['cedula']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($m['semblanza'])): ?>
                            <p class="sem"><?= e($m['semblanza']) ?></p>
                        <?php endif; ?>
                        <?php /* Cada médico con SU propio horario (cada quien su agenda). */ ?>
                        <?php if (!empty($m['horario'])): ?>
                        <div class="medhor">
                            <div class="medhor-t"><i class="bi bi-clock"></i> <?= et('Horario') ?></div>
                            <?php foreach ($m['horario'] as $h): ?>
                            <div class="d-flex justify-content-between gap-2">
                                <span><?= e($h['dias']) ?></span><span class="text-muted text-end"><?= e($h['horas']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="medhor text-muted text-center"><?= et('Consulta con cita') ?></div>
                        <?php endif; ?>
                        <?php if ($reservar): ?>
                            <div class="sp-btn"></div>
                            <a href="<?= e($urlAgendar) ?>" class="btn-cta"><i class="bi bi-calendar-plus"></i> <?= et('Agendar cita') ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== RESEÑAS ===== -->
<?php if ($resLista): ?>
<section id="resenas" class="revs">
    <div class="wrap">
        <div class="head" data-reveal>
            <span class="eyebrow"><?= et('Opiniones') ?></span>
            <h2 class="t"><?= et('Lo que dicen nuestros pacientes') ?></h2>
            <div class="d-flex align-items-center justify-content-center gap-2 mt-3">
                <span class="rs-prom"><?= number_format($resResumen['promedio'], 1) ?></span>
                <span>
                    <span class="rs-star d-block"><?= resena_estrellas($resResumen['promedio']) ?></span>
                    <span class="small text-muted"><?= $resResumen['total'] ?> <?= $resResumen['total'] === 1 ? et('reseña') : et('reseñas') ?></span>
                </span>
            </div>
            <p class="sub"><?= et('Todas provienen de pacientes que sí tuvieron consulta aquí.') ?></p>
        </div>
        <div class="row g-4" data-stagger>
            <?php foreach ($resLista as $rs): ?>
            <div class="col-md-6 col-lg-4">
                <div class="rs-card h-100">
                    <div class="rs-star mb-2"><?= resena_estrellas((float) $rs['estrellas']) ?></div>
                    <?php if ($rs['comentario']): ?>
                        <p class="rs-txt">“<?= e($rs['comentario']) ?>”</p>
                    <?php endif; ?>
                    <div class="rs-autor">
                        <span class="ini"><?= e(mb_strtoupper(mb_substr((string) $rs['autor'], 0, 1))) ?></span>
                        <span>
                            <?= e($rs['autor']) ?>
                            <?php if ($rs['medico']): ?>
                                <span class="rs-med">· <?= et('con') ?> <?= e($rs['medico']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($rs['respuesta']): ?>
                    <div class="rs-resp">
                        <span class="fw-semibold"><?= e($marca) ?>:</span> <?= e($rs['respuesta']) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== CONTACTO ===== -->
<?php
$mapaSrc = mapa_embed_url(cfg('web_mapa', ''), (string) $dir);
$irUrl   = $mapaSrc ? mapa_ir_url(cfg('web_mapa', ''), (string) $dir) : '';
$hayDatos = $correo || $tel || $dir || $wa;
?>
<?php if ($hayDatos || $mapaSrc): ?>
<section id="contacto" class="contact">
    <div class="wrap">
        <div class="head" data-reveal>
            <span class="eyebrow"><?= et('Visítanos') ?></span>
            <h2 class="t"><?= et('Contacto') ?></h2>
        </div>
        <div class="row g-4 align-items-stretch">
            <?php if ($hayDatos): ?>
            <div class="<?= $mapaSrc ? 'col-lg-5' : 'col-lg-8 mx-auto' ?>" data-stagger>
                <?php if ($wa): ?>
                <a class="ct-item" href="<?= e($wa) ?>" target="_blank" rel="noopener"><span class="ic wa"><i class="bi bi-whatsapp"></i></span>
                    <span><span class="lb d-block">WhatsApp</span><span class="vl"><?= et('Escríbenos') ?></span></span></a>
                <?php endif; ?>
                <?php if ($tel): ?>
                <a class="ct-item" href="tel:<?= e(preg_replace('/\s+/', '', $tel)) ?>"><span class="ic"><i class="bi bi-telephone"></i></span>
                    <span><span class="lb d-block"><?= et('Teléfono') ?></span><span class="vl"><?= e($tel) ?></span></span></a>
                <?php endif; ?>
                <?php if ($correo): ?>
                <a class="ct-item" href="mailto:<?= e($correo) ?>"><span class="ic"><i class="bi bi-envelope"></i></span>
                    <span><span class="lb d-block"><?= et('Correo') ?></span><span class="vl"><?= e($correo) ?></span></span></a>
                <?php endif; ?>
                <?php if ($dir): ?>
                <a class="ct-item" href="https://maps.google.com/?q=<?= rawurlencode($dir) ?>" target="_blank" rel="noopener"><span class="ic"><i class="bi bi-geo-alt"></i></span>
                    <span><span class="lb d-block"><?= et('Ubicación') ?></span><span class="vl"><?= e($dir) ?></span></span></a>
                <?php endif; ?>
                <?php if ($irUrl): ?>
                    <?php /* En un móvil esto abre la app de mapas con la ruta trazada. */ ?>
                    <a href="<?= e($irUrl) ?>" target="_blank" rel="noopener" class="btn-cta mt-2"><i class="bi bi-signpost-split"></i> <?= et('Cómo llegar') ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php /* Mapa embebido, sin API key: coordenadas del enlace de Maps o dirección. */ ?>
            <?php if ($mapaSrc): ?>
            <div class="<?= $hayDatos ? 'col-lg-7' : 'col-12' ?>" data-reveal="right">
                <div class="cl-map">
                    <iframe title="<?= e($marca) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen
                            src="<?= e($mapaSrc) ?>"></iframe>
                </div>
                <?php if ($irUrl && !$hayDatos): ?>
                <div class="text-center mt-4">
                    <a href="<?= e($irUrl) ?>" target="_blank" rel="noopener" class="btn-cta"><i class="bi bi-signpost-split"></i> <?= et('Cómo llegar') ?></a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===== RESERVA (lleva a la página dedicada) ===== -->
<?php if ($reservar): ?>
<section class="book">
    <div class="wrap" data-reveal="zoom">
        <span class="eyebrow"><?= et('Reserva en línea') ?></span>
        <h2 class="t mb-2"><?= et('Agenda tu cita') ?></h2>
        <p class="sub mb-4"><?= et('Elige el médico, el día y la hora que mejor te queden. Sin llamadas y sin esperas.') ?></p>
        <a href="<?= e($urlAgendar) ?>" class="btn-cta"><i class="bi bi-calendar-plus"></i> <?= et('Agendar mi cita') ?></a>
    </div>
</section>
<?php endif; ?>

</div><!-- /.clx -->

<script>
/* Filtro de servicios por categoría. Sin JS las pastillas no aparecen y se
   ven todos los servicios. */
(function () {
    var bar = document.querySelector('.sv-chips');
    if (!bar) return;
    bar.hidden = false;
    var cards = document.querySelectorAll('.sv-grid .sv');
    bar.addEventListener('click', function (e) {
        var b = e.target.closest('.sv-chip');
        if (!b) return;
        bar.querySelectorAll('.sv-chip').forEach(function (x) { x.classList.toggle('on', x === b); });
        var cat = b.getAttribute('data-cat');
        cards.forEach(function (c) { c.hidden = !!cat && c.getAttribute('data-cat') !== cat; });
    });
})();
</script>

<?php include __DIR__ . '/publico_footer.php'; ?>
