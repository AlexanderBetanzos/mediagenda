<?php
/**
 * Pantalla de turnos para la TV de la sala de espera.
 *
 * Se alimenta del flujo de citas/sala: "Pasar a consulta" (y "Volver a
 * llamar") guarda atencion_en = NOW(); aquí el llamado más reciente se
 * anuncia en grande con un timbre. Abajo, quién está en espera.
 *
 * Privacidad: en una pantalla pública solo sale el nombre y la inicial del
 * apellido ("María G."), nunca el nombre completo ni el motivo.
 *
 * Se abre en el navegador de la TV con la sesión de recepción y se actualiza
 * sola (?json=1 cada 5 s), sin recargar la página.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('citas');

$tid = (int) tenant_id();

/** "María Guadalupe García López" → "María G." (o el nombre de la mascota). */
function turno_nombre(array $c): string
{
    $n = trim(explode(' ', trim((string) $c['nombre']))[0] ?? '');
    if (function_exists('es_veterinaria') && es_veterinaria()) return (string) $c['nombre'];
    $a = trim((string) $c['apellidos']);
    return $n . ($a !== '' ? ' ' . mb_strtoupper(mb_substr($a, 0, 1)) . '.' : '');
}

function turnos_datos(int $tid): array
{
    $st = db()->prepare(
        "SELECT c.id, c.estado, c.hora, c.checkin_en, c.atencion_en, p.nombre, p.apellidos, u.nombre medico
         FROM citas c JOIN pacientes p ON p.id = c.paciente_id LEFT JOIN usuarios u ON u.id = c.medico_id
         WHERE c.consultorio_id = ? AND c.fecha = CURDATE() AND c.estado IN ('esperando','en_consulta')
         ORDER BY c.atencion_en DESC, c.checkin_en ASC"
    );
    $st->execute([$tid]);
    $llamados = $espera = [];
    foreach ($st->fetchAll() as $c) {
        $fila = ['id' => (int) $c['id'], 'n' => turno_nombre($c), 'm' => (string) ($c['medico'] ?? '')];
        if ($c['estado'] === 'en_consulta' && $c['atencion_en']) {
            $fila['t'] = strtotime($c['atencion_en']);
            $llamados[] = $fila;
        } elseif ($c['estado'] === 'esperando') {
            $fila['min'] = $c['checkin_en'] ? max(0, (int) round((time() - strtotime($c['checkin_en'])) / 60)) : 0;
            $espera[] = $fila;
        }
    }
    usort($llamados, fn ($a, $b) => $b['t'] <=> $a['t']);
    return ['llamados' => array_slice($llamados, 0, 4), 'espera' => array_slice($espera, 0, 10), 'ahora' => time()];
}

if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(turnos_datos($tid), JSON_UNESCAPED_UNICODE);
    exit;
}

$marca  = marca_nombre();
$acento = color_acento();
$logo   = (string) cfg('marca_logo');
$datos  = turnos_datos($tid);
?>
<!doctype html>
<html lang="<?= e(idioma_actual()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(t('Turnos')) ?> · <?= e($marca) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root { --cl: <?= $acento ?>; --cl-d: color-mix(in srgb, <?= $acento ?> 70%, #000); --on: <?= color_texto_sobre($acento) ?>; }
* { box-sizing: border-box; margin: 0; }
html, body { height: 100%; }
body { font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Inter", "Segoe UI", Roboto, sans-serif; color: #1d1d1f; overflow: hidden;
       background: radial-gradient(1200px 700px at 0% 0%, color-mix(in srgb, var(--cl) 18%, transparent), transparent 60%),
                   radial-gradient(900px 600px at 100% 100%, color-mix(in srgb, var(--cl) 12%, transparent), transparent 60%), #eef1f6;
       display: grid; grid-template-rows: auto 1fr auto; }
header { display: flex; align-items: center; justify-content: space-between; padding: 2.2vh 3vw; }
.brand { display: flex; align-items: center; gap: 1rem; font-weight: 800; font-size: 3.2vh; letter-spacing: -.02em; }
.brand img { height: 6vh; max-width: 20vw; object-fit: contain; border-radius: 12px; background: #fff; padding: 4px; }
.brand .bi { font-size: 5vh; color: var(--cl); }
.reloj { text-align: right; }
.reloj .h { font-size: 5vh; font-weight: 800; letter-spacing: -.03em; font-variant-numeric: tabular-nums; line-height: 1; }
.reloj .f { font-size: 2vh; opacity: .65; text-transform: capitalize; }
main { display: grid; grid-template-columns: 1.55fr 1fr; gap: 2.5vw; padding: 0 3vw 2vh; min-height: 0; }
.panel { background: rgba(255,255,255,.82); border-radius: 3vh; padding: 3vh 2.5vw; box-shadow: 0 20px 60px rgba(20,30,60,.12); min-height: 0; display: flex; flex-direction: column; }
.lbl { font-size: 2.1vh; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: var(--cl); display: flex; align-items: center; gap: .6rem; }
.llamado { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-radius: 2.5vh; margin-top: 2vh;
           background: linear-gradient(150deg, color-mix(in srgb, var(--cl) 80%, #fff), var(--cl-d)); color: var(--on); padding: 3vh 2vw; position: relative; overflow: hidden; }
.llamado::after { content: ''; position: absolute; width: 60vh; height: 60vh; border-radius: 50%; background: rgba(255,255,255,.1); right: -20vh; top: -25vh; }
.llamado .pasa { font-size: 3vh; font-weight: 600; opacity: .9; }
.llamado .nom { font-size: 11vh; font-weight: 850; letter-spacing: -.04em; line-height: 1.02; margin: 1vh 0; word-break: break-word; }
.llamado .con { font-size: 3.4vh; font-weight: 600; opacity: .95; }
.llamado.vacio .nom { font-size: 5vh; opacity: .85; }
@keyframes pulso { 0%, 100% { transform: scale(1); } 15% { transform: scale(1.04); } 30% { transform: scale(1); } 45% { transform: scale(1.03); } }
@keyframes brillo { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--cl) 70%, transparent); } 100% { box-shadow: 0 0 0 4vh transparent; } }
.llamado.nuevo { animation: pulso 1.6s ease, brillo 1.2s ease 3; }
.previos { display: grid; gap: 1vh; margin-top: 2vh; }
.previo { display: flex; justify-content: space-between; align-items: center; padding: 1.4vh 1.4vw; border-radius: 1.6vh; background: color-mix(in srgb, var(--cl) 8%, #fff); font-size: 2.6vh; }
.previo b { font-weight: 750; } .previo span { opacity: .65; }
.espera { list-style: none; padding: 0; margin-top: 2vh; display: grid; gap: 1.1vh; overflow: hidden; }
.espera li { display: flex; align-items: center; gap: 1.2vw; padding: 1.6vh 1.2vw; border-radius: 1.6vh; background: #fff; box-shadow: 0 4px 14px rgba(20,30,60,.06); font-size: 2.9vh; }
.espera .num { width: 5vh; height: 5vh; flex: none; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 2.3vh;
               color: var(--cl); background: color-mix(in srgb, var(--cl) 12%, #fff); }
.espera .q { flex: 1; min-width: 0; } .espera .q b { display: block; font-weight: 750; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.espera .q small { display: block; font-size: 2vh; opacity: .6; }
.espera .min { font-size: 2vh; opacity: .6; white-space: nowrap; }
.vacia { opacity: .55; font-size: 2.6vh; margin-top: 3vh; text-align: center; }
footer { padding: 1.4vh 3vw; font-size: 2.1vh; display: flex; justify-content: space-between; opacity: .7; }
#activar { position: fixed; inset: 0; background: rgba(15,20,35,.72); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center;
           gap: 2vh; cursor: pointer; z-index: 10; text-align: center; padding: 2rem; }
#activar .bi { font-size: 10vh; }
#activar b { font-size: 4vh; } #activar span { font-size: 2.4vh; opacity: .8; }
@media (max-width: 900px) { main { grid-template-columns: 1fr; overflow: auto; } body { overflow: auto; } }
</style>
</head>
<body>
<header>
    <div class="brand">
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><i class="bi bi-heart-pulse-fill"></i><?php endif; ?>
        <span><?= e($marca) ?></span>
    </div>
    <div class="reloj"><div class="h" id="hora">--:--</div><div class="f" id="fecha"></div></div>
</header>

<main>
    <section class="panel">
        <div class="lbl"><i class="bi bi-megaphone-fill"></i> <?= et('Pasa a consulta') ?></div>
        <div class="llamado vacio" id="llamado">
            <div class="pasa" id="llPasa"></div>
            <div class="nom" id="llNom"><?= et('Bienvenido') ?></div>
            <div class="con" id="llCon"><?= et('En un momento te llamamos') ?></div>
        </div>
        <div class="previos" id="previos"></div>
    </section>
    <section class="panel">
        <div class="lbl"><i class="bi bi-people-fill"></i> <?= et('En espera') ?> <span id="nEsp"></span></div>
        <ul class="espera" id="espera"></ul>
        <div class="vacia" id="espVacia" hidden><?= et('Nadie en espera por ahora') ?></div>
    </section>
</main>

<footer>
    <span><i class="bi bi-info-circle"></i> <?= et('Cuando aparezca tu nombre, pasa con tu médico.') ?></span>
    <span><?= et('Gracias por tu paciencia') ?></span>
</footer>

<?php /* Los navegadores no dejan sonar audio hasta que alguien toca la página:
         un toque al encender la TV activa el timbre (y pantalla completa). */ ?>
<div id="activar" role="button" tabindex="0">
    <i class="bi bi-tv"></i>
    <b><?= et('Toca para activar la pantalla de turnos') ?></b>
    <span><?= et('Activa el sonido del llamado y la pantalla completa.') ?></span>
</div>

<script>
(function () {
    var URL = <?= json_encode(BASE_URL . '/citas/pantalla?json=1') ?>;
    var T = { pasa: <?= json_encode(t('Turno de'), JSON_UNESCAPED_UNICODE) ?>, con: <?= json_encode(t('Pasa con'), JSON_UNESCAPED_UNICODE) ?>,
              hace: <?= json_encode(t('hace'), JSON_UNESCAPED_UNICODE) ?>, esp: <?= json_encode(t('esperando'), JSON_UNESCAPED_UNICODE) ?>,
              bien: <?= json_encode(t('Bienvenido'), JSON_UNESCAPED_UNICODE) ?>, mom: <?= json_encode(t('En un momento te llamamos'), JSON_UNESCAPED_UNICODE) ?> };
    var ultimo = null, ctx = null;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };

    function timbre() {
        if (!ctx) return;
        [[880, 0], [660, .32]].forEach(function (n) {
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine'; o.frequency.value = n[0];
            g.gain.setValueAtTime(0.0001, ctx.currentTime + n[1]);
            g.gain.exponentialRampToValueAtTime(0.5, ctx.currentTime + n[1] + 0.03);
            g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + n[1] + 0.6);
            o.connect(g); g.connect(ctx.destination); o.start(ctx.currentTime + n[1]); o.stop(ctx.currentTime + n[1] + 0.65);
        });
    }

    function pinta(d) {
        var ll = d.llamados || [], es = d.espera || [];
        var box = $('llamado');
        if (ll.length) {
            var a = ll[0];
            box.classList.remove('vacio');
            $('llPasa').textContent = T.pasa;
            $('llNom').textContent = a.n;
            $('llCon').textContent = a.m ? T.con + ' ' + a.m : '';
            var clave = a.id + '@' + a.t;
            if (ultimo !== null && clave !== ultimo) {            // llamado nuevo o "volver a llamar"
                box.classList.remove('nuevo'); void box.offsetWidth; box.classList.add('nuevo');
                timbre(); setTimeout(timbre, 1600);
            }
            ultimo = clave;
        } else {
            box.classList.add('vacio');
            $('llPasa').textContent = ''; $('llNom').textContent = T.bien; $('llCon').textContent = T.mom;
            ultimo = ultimo === null ? '' : ultimo;
        }
        $('previos').innerHTML = ll.slice(1).map(function (x) {
            var m = Math.max(0, Math.round((d.ahora - x.t) / 60));
            return '<div class="previo"><b>' + esc(x.n) + '</b><span>' + esc(x.m) + ' · ' + T.hace + ' ' + m + ' min</span></div>';
        }).join('');
        $('espera').innerHTML = es.map(function (x, i) {
            return '<li><span class="num">' + (i + 1) + '</span><span class="q"><b>' + esc(x.n) + '</b>' + (x.m ? '<small>' + esc(x.m) + '</small>' : '') + '</span>'
                 + '<span class="min">' + T.esp + ' ' + x.min + ' min</span></li>';
        }).join('');
        $('nEsp').textContent = es.length ? '(' + es.length + ')' : '';
        $('espVacia').hidden = es.length > 0;
    }

    function cargar() {
        fetch(URL, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw r; return r.json(); })
            .then(pinta)
            .catch(function () { /* sin red: se reintenta en el siguiente ciclo */ });
    }

    function reloj() {
        var n = new Date();
        $('hora').textContent = n.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
        $('fecha').textContent = n.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' });
    }

    $('activar').addEventListener('click', function () {
        try { ctx = new (window.AudioContext || window.webkitAudioContext)(); timbre(); } catch (e) {}
        if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(function () {});
        this.remove();
    });

    pinta(<?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>);
    reloj(); setInterval(reloj, 1000);
    setInterval(cargar, 5000);
})();
</script>
</body>
</html>
