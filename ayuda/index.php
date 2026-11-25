<?php
/**
 * Centro de ayuda público: /ayuda (inicio) y /ayuda/<slug> (artículo).
 * Menú lateral por secciones, búsqueda con ⌘K, modo claro/oscuro/sistema,
 * índice "En esta página", anterior/siguiente y "¿Te fue útil?".
 * Contenido: includes/centro_ayuda.php.
 */
require_once __DIR__ . '/../includes/centro_ayuda.php';
track_pageview('publico');

$arts = ca_articulos();
$slug = preg_replace('/[^a-z0-9-]/', '', (string) ($_GET['a'] ?? ''));
$art = $slug !== '' ? ($arts[$slug] ?? null) : null;
if ($slug !== '' && !$art) http_response_code(404);

$marca = 'MediAgenda';
$cuerpo = $art ? ca_cuerpo($slug) : '';
preg_match_all('/<h2 id="([^"]+)"[^>]*>(.*?)<\/h2>/s', $cuerpo, $toc, PREG_SET_ORDER);
$slugs = array_keys($arts);
$pos = $art ? array_search($slug, $slugs, true) : false;
$prev = $pos !== false && $pos > 0 ? $slugs[$pos - 1] : null;
$next = $pos !== false && $pos < count($slugs) - 1 ? $slugs[$pos + 1] : null;
$titulo = $art ? $art['titulo'] . ' · ' . $marca : ($slug !== '' ? 'Artículo no encontrado' : 'Centro de ayuda') . ' · ' . $marca;
$desc = $art ? $art['desc'] : 'Guías paso a paso para usar MediAgenda: pacientes, agenda, expediente clínico, recetas, cobros, facturación y más.';
?>
<!doctype html>
<html lang="es" data-tema="sistema">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <script>(function(){var t='sistema';try{t=localStorage.getItem('ca.tema')||'sistema'}catch(e){}document.documentElement.dataset.tema=t;})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
    :root{--brand:#0f8dbf;--brand-d:#0b6e96;--brand-l:#e6f4fa;--bg:#fff;--bg2:#f6f9fc;--panel:#fff;--ink:#0f1d2b;--mut:#5b6b7c;--line:#e6ecf2;--hover:#f1f6fa;--code:#eef2f6}
    html[data-tema=oscuro]{--bg:#0b1219;--bg2:#0f1822;--panel:#111c27;--ink:#e6eef5;--mut:#93a4b5;--line:#1e2b38;--hover:#162432;--brand-l:rgba(15,141,191,.16);--code:#1a2836}
    @media (prefers-color-scheme:dark){html[data-tema=sistema]{--bg:#0b1219;--bg2:#0f1822;--panel:#111c27;--ink:#e6eef5;--mut:#93a4b5;--line:#1e2b38;--hover:#162432;--brand-l:rgba(15,141,191,.16);--code:#1a2836}}
    *{box-sizing:border-box}
    body{margin:0;font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--ink);font-size:15px;line-height:1.6}
    a{color:var(--brand)} a:hover{color:var(--brand-d)}
    .ca{display:grid;grid-template-columns:290px 1fr;min-height:100vh}
    .ca-side{position:sticky;top:0;height:100vh;overflow:auto;border-right:1px solid var(--line);background:var(--bg2);padding:18px 14px 70px;display:flex;flex-direction:column}
    .ca-logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:1.15rem;color:var(--ink);text-decoration:none;padding:4px 8px 14px}
    .ca-logo .ic{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#0b6e96,#0f8dbf);color:#fff;display:inline-flex;align-items:center;justify-content:center}
    .ca-logo small{font-weight:500;color:var(--mut);font-size:.8rem}
    .ca-buscar{display:flex;align-items:center;gap:8px;width:100%;border:1px solid var(--line);background:var(--panel);color:var(--mut);border-radius:10px;padding:8px 10px;font:inherit;font-size:.9rem;cursor:pointer;margin-bottom:14px}
    .ca-buscar kbd{margin-left:auto;font:600 .72rem Inter;border:1px solid var(--line);border-radius:6px;padding:1px 6px;color:var(--mut)}
    .ca-sec{margin-top:14px}
    .ca-sec-t{display:flex;gap:9px;align-items:center;font-weight:650;font-size:.9rem;padding:6px 8px;color:var(--ink)}
    .ca-sec-t i{color:var(--mut)}
    .ca-side a.it,.ca-grp>summary{display:block;color:var(--mut);text-decoration:none;font-size:.89rem;padding:5px 10px 5px 34px;border-radius:8px;cursor:pointer;list-style:none}
    .ca-side a.it:hover,.ca-grp>summary:hover{background:var(--hover);color:var(--ink)}
    .ca-side a.it.on{background:var(--brand-l);color:var(--brand);font-weight:600}
    .ca-grp>summary::-webkit-details-marker{display:none}
    .ca-grp>summary{position:relative}
    .ca-grp>summary:after{content:"\F285";font-family:bootstrap-icons;position:absolute;right:10px;font-size:.7rem;transition:transform .15s}
    .ca-grp[open]>summary:after{transform:rotate(90deg)}
    .ca-grp a.it{padding-left:48px}
    .ca-temas{position:fixed;bottom:14px;left:14px;display:flex;gap:2px;border:1px solid var(--line);background:var(--panel);border-radius:99px;padding:3px}
    .ca-temas button{border:0;background:none;color:var(--mut);width:30px;height:26px;border-radius:99px;cursor:pointer}
    .ca-temas button.on{background:var(--hover);color:var(--ink)}
    .ca-main{min-width:0}
    .ca-top{display:none;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--bg);z-index:5}
    .ca-top button{border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:8px;padding:6px 10px}
    .ca-wrap{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:48px;max-width:1120px;margin:0 auto;padding:40px 40px 80px}
    .ca-wrap.home{grid-template-columns:minmax(0,1fr)}
    .ca-bc{font-size:.85rem;color:var(--mut);margin-bottom:10px}
    .ca-bc a{color:var(--mut);text-decoration:none}
    h1.ca-h1{font-size:2.1rem;font-weight:800;letter-spacing:-.03em;margin:0 0 6px;line-height:1.15}
    .ca-desc{color:var(--mut);font-size:1.05rem;margin:0 0 26px}
    article h2{font-size:1.35rem;font-weight:750;letter-spacing:-.02em;margin:2.2rem 0 .7rem;scroll-margin-top:20px}
    article h3{font-size:1.05rem;font-weight:650;margin:1.4rem 0 .5rem}
    article p,article li{color:var(--ink)}
    .ca-lead{font-size:1.05rem}
    .paso{display:flex;gap:12px;margin:.7rem 0;align-items:flex-start}
    .paso .n{flex:none;width:28px;height:28px;border-radius:50%;background:var(--brand-l);color:var(--brand);font-weight:700;display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-top:1px}
    .ca-nota,.nota{background:var(--brand-l);border:1px solid color-mix(in srgb,var(--brand) 25%,transparent);border-radius:12px;padding:12px 16px;margin:1.1rem 0;font-size:.94rem}
    .aviso{background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);border-radius:12px;padding:12px 16px;margin:1.1rem 0;font-size:.94rem}
    .ca-nota-t{font-weight:650;margin-bottom:4px}
    .ca-nota ul{padding-left:1.1rem}
    .ui{font-weight:600;background:var(--code);border-radius:6px;padding:1px 6px;font-size:.9em;white-space:nowrap}
    .ca-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin:14px 0}
    .ca-card{display:flex;flex-direction:column;gap:4px;border:1px solid var(--line);border-radius:14px;padding:16px;text-decoration:none;color:var(--ink);background:var(--panel);transition:border-color .15s,transform .15s}
    a.ca-card:hover{border-color:var(--brand);transform:translateY(-1px);color:var(--ink)}
    .ca-card i{color:var(--brand);font-size:1.3rem}
    .ca-card b{font-weight:650}
    .ca-card span{color:var(--mut);font-size:.88rem;line-height:1.45}
    .ca-toc{position:sticky;top:30px;align-self:start;font-size:.85rem}
    .ca-toc .t{font-weight:650;margin-bottom:8px;display:flex;gap:6px;align-items:center}
    .ca-toc a{display:block;color:var(--mut);text-decoration:none;padding:4px 0 4px 12px;border-left:1px solid var(--line)}
    .ca-toc a:hover,.ca-toc a.on{color:var(--brand);border-left-color:var(--brand)}
    .ca-util{margin-top:48px;padding-top:20px;border-top:1px solid var(--line);display:flex;align-items:center;gap:10px;flex-wrap:wrap;color:var(--mut);font-size:.92rem}
    .ca-util button{border:1px solid var(--line);background:var(--panel);color:var(--ink);border-radius:99px;padding:5px 14px;cursor:pointer;font:inherit}
    .ca-util button:hover{border-color:var(--brand)}
    .ca-pn{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:22px}
    .ca-pn a{border:1px solid var(--line);border-radius:12px;padding:12px 16px;text-decoration:none;color:var(--ink)}
    .ca-pn a:hover{border-color:var(--brand)}
    .ca-pn small{display:block;color:var(--mut)}
    .ca-pn .sig{text-align:right;grid-column:2}
    .ca-hero{background:radial-gradient(700px 260px at 80% 0,rgba(15,141,191,.16),transparent 70%);border:1px solid var(--line);border-radius:20px;padding:40px;margin-bottom:34px}
    .ca-hero h1{font-size:2.4rem;font-weight:800;letter-spacing:-.035em;margin:0 0 8px}
    .ca-hero .ca-buscar{max-width:560px;padding:13px 16px;font-size:1rem;margin:20px 0 0}
    .ca-h2{font-size:1.2rem;font-weight:750;margin:34px 0 4px}
    .ca-pie{color:var(--mut);font-size:.82rem;margin-top:60px;border-top:1px solid var(--line);padding-top:16px}
    /* búsqueda */
    .ca-modal{position:fixed;inset:0;background:rgba(5,12,20,.55);display:none;align-items:flex-start;justify-content:center;padding:10vh 16px;z-index:50}
    .ca-modal.on{display:flex}
    .ca-mbox{width:100%;max-width:640px;background:var(--panel);border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35)}
    .ca-mbox input{width:100%;border:0;border-bottom:1px solid var(--line);background:transparent;color:var(--ink);font:inherit;font-size:1.05rem;padding:16px 18px;outline:none}
    .ca-res{max-height:55vh;overflow:auto;padding:6px}
    .ca-res a{display:block;padding:10px 12px;border-radius:10px;text-decoration:none;color:var(--ink)}
    .ca-res a.on,.ca-res a:hover{background:var(--hover)}
    .ca-res small{color:var(--mut);display:block;font-size:.8rem}
    .ca-res mark{background:rgba(15,141,191,.22);color:inherit;border-radius:3px}
    .ca-vacio{padding:22px;color:var(--mut);text-align:center;font-size:.92rem}
    @media (max-width:1200px){.ca-wrap{grid-template-columns:minmax(0,1fr)}.ca-toc{display:none}}
    @media (max-width:900px){.ca{grid-template-columns:1fr}.ca-side{position:fixed;inset:0 auto 0 0;width:300px;z-index:40;transform:translateX(-100%);transition:transform .2s}.ca-side.on{transform:none;box-shadow:0 0 0 100vmax rgba(5,12,20,.5)}.ca-top{display:flex}.ca-wrap{padding:24px 16px 60px}.ca-hero{padding:24px}.ca-hero h1{font-size:1.8rem}.ca-temas{left:auto;right:14px}}
    </style>
</head>
<body>
<div class="ca">
<aside class="ca-side" id="caSide">
    <a class="ca-logo" href="<?= ca_url() ?>"><span class="ic"><i class="bi bi-calendar2-plus"></i></span><span><?= e($marca) ?><br><small>Centro de ayuda</small></span></a>
    <button class="ca-buscar" type="button" data-buscar><i class="bi bi-search"></i> Buscar o preguntar… <kbd>⌘K</kbd></button>
    <a class="it" style="padding-left:10px" href="<?= BASE_URL ?>/"><i class="bi bi-box-arrow-up-right me-1"></i> Ir a <?= e($marca) ?></a>
    <a class="it" style="padding-left:10px" href="<?= BASE_URL ?>/seguridad"><i class="bi bi-shield-check me-1"></i> Seguridad</a>
    <?php foreach (ca_secciones() as [$sid, $stit, $sic, $items]):
        $visibles = array_filter($items, fn ($it) => isset($it['grupo']) ? (bool) array_filter($it['items'], fn ($x) => isset($arts[$x[0]])) : isset($arts[$it[0]]));
        if (!$visibles) continue; ?>
    <div class="ca-sec">
        <div class="ca-sec-t"><i class="bi <?= $sic ?>"></i> <?= e($stit) ?></div>
        <?php foreach ($visibles as $it):
            if (isset($it['grupo'])):
                $hijos = array_filter($it['items'], fn ($x) => isset($arts[$x[0]]));
                $abierto = (bool) array_filter($hijos, fn ($x) => $x[0] === $slug); ?>
            <details class="ca-grp" <?= $abierto ? 'open' : '' ?>><summary><?= e($it['grupo']) ?></summary>
                <?php foreach ($hijos as $x): ?><a class="it <?= $x[0] === $slug ? 'on' : '' ?>" href="<?= ca_url($x[0]) ?>"><?= e($x[1]) ?></a><?php endforeach; ?>
            </details>
        <?php else: ?>
            <a class="it <?= $it[0] === $slug ? 'on' : '' ?>" href="<?= ca_url($it[0]) ?>"><?= e($it[1]) ?></a>
        <?php endif; endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="ca-temas" role="group" aria-label="Tema">
        <button type="button" data-tema="sistema" title="Sistema"><i class="bi bi-display"></i></button>
        <button type="button" data-tema="claro" title="Claro"><i class="bi bi-sun"></i></button>
        <button type="button" data-tema="oscuro" title="Oscuro"><i class="bi bi-moon"></i></button>
    </div>
</aside>

<div class="ca-main">
    <div class="ca-top">
        <button type="button" id="caMenu" aria-label="Menú"><i class="bi bi-list"></i></button>
        <strong><?= e($marca) ?></strong>
        <button type="button" data-buscar style="margin-left:auto" aria-label="Buscar"><i class="bi bi-search"></i></button>
    </div>

    <?php if (!$art && $slug === ''): /* ───────── Inicio ───────── */ ?>
    <div class="ca-wrap home">
        <div>
        <div class="ca-hero">
            <h1>¿En qué te ayudamos?</h1>
            <p class="ca-desc" style="margin:0">Guías paso a paso para sacarle todo el provecho a <?= e($marca) ?>: pacientes, agenda, expediente clínico, recetas, cobros y más.</p>
            <button class="ca-buscar" type="button" data-buscar><i class="bi bi-search"></i> Busca un tema: "receta", "recordatorios", "e.firma"… <kbd>⌘K</kbd></button>
        </div>

        <div class="ca-h2">Comienza aquí</div>
        <div class="ca-cards">
            <?php foreach (['introduccion' => 'bi-compass', 'primeros-pasos' => 'bi-flag', 'migrar' => 'bi-box-arrow-in-down', 'planes' => 'bi-stars'] as $s => $ic): if (!isset($arts[$s])) continue; ?>
            <a class="ca-card" href="<?= ca_url($s) ?>"><i class="bi <?= $ic ?>"></i><b><?= e($arts[$s]['titulo']) ?></b><span><?= e($arts[$s]['desc']) ?></span></a>
            <?php endforeach; ?>
        </div>

        <div class="ca-h2">Artículos más consultados</div>
        <div class="ca-cards">
            <?php foreach (['recetas', 'expediente-clinico', 'crear-citas', 'recordatorios', 'videoconsulta', 'facturacion-electronica'] as $s): if (!isset($arts[$s])) continue; ?>
            <a class="ca-card" href="<?= ca_url($s) ?>"><i class="bi <?= e($arts[$s]['icono']) ?>"></i><b><?= e($arts[$s]['titulo']) ?></b><span><?= e($arts[$s]['desc']) ?></span></a>
            <?php endforeach; ?>
        </div>

        <div class="ca-h2">Explora por categoría</div>
        <div class="ca-cards">
            <?php foreach (ca_secciones() as [$sid, $stit, $sic, $_]):
                $de = array_filter($arts, fn ($a) => $a['seccion'] === $sid);
                if (!$de || $sid === 'inicio') continue; $primero = array_key_first($de); ?>
            <a class="ca-card" href="<?= ca_url($primero) ?>"><i class="bi <?= $sic ?>"></i><b><?= e($stit) ?></b><span><?= count($de) ?> artículos · <?= e(implode(', ', array_slice(array_column($de, 'titulo'), 0, 3))) ?>…</span></a>
            <?php endforeach; ?>
        </div>

        <div class="ca-h2">¿Necesitas ayuda?</div>
        <div class="ca-cards">
            <a class="ca-card" href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>?text=<?= rawurlencode('Hola, necesito ayuda con MediAgenda.') ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i><b>Soporte por WhatsApp</b><span><?= e(SOPORTE_TEL) ?> · <?= e(SOPORTE_HORARIO) ?></span></a>
            <a class="ca-card" href="mailto:<?= e(SOPORTE_EMAIL) ?>"><i class="bi bi-envelope"></i><b>Correo</b><span><?= e(SOPORTE_EMAIL) ?></span></a>
            <a class="ca-card" href="<?= BASE_URL ?>/seguridad"><i class="bi bi-shield-check"></i><b>Seguridad y privacidad</b><span>Cómo protegemos el expediente y los datos de tus pacientes.</span></a>
        </div>
        <div class="ca-pie"><?= count($arts) ?> artículos · Los mismos textos que ves en el botón <i class="bi bi-question-circle"></i> dentro del sistema.</div>
        </div>
    </div>

    <?php elseif (!$art): /* ───────── 404 ───────── */ ?>
    <div class="ca-wrap home"><div>
        <h1 class="ca-h1">No encontramos ese artículo</h1>
        <p class="ca-desc">Puede que haya cambiado de nombre. Búscalo o vuelve al inicio.</p>
        <button class="ca-buscar" style="max-width:420px" type="button" data-buscar><i class="bi bi-search"></i> Buscar… <kbd>⌘K</kbd></button>
        <p><a href="<?= ca_url() ?>">← Centro de ayuda</a></p>
    </div></div>

    <?php else: /* ───────── Artículo ───────── */ ?>
    <div class="ca-wrap">
        <div>
            <div class="ca-bc"><a href="<?= ca_url() ?>">Ayuda</a> / <?= e($art['seccion_t']) ?><?= $art['grupo'] ? ' / ' . e($art['grupo']) : '' ?></div>
            <h1 class="ca-h1"><?= e($art['titulo']) ?></h1>
            <p class="ca-desc"><?= e($art['desc']) ?></p>
            <article><?= $cuerpo ?></article>

            <div class="ca-util" id="caUtil">
                <span>¿Te fue útil este artículo?</span>
                <button type="button" data-util="1"><i class="bi bi-hand-thumbs-up"></i> Sí</button>
                <button type="button" data-util="0"><i class="bi bi-hand-thumbs-down"></i> No</button>
            </div>
            <div class="ca-pn">
                <?php if ($prev): ?><a href="<?= ca_url($prev) ?>"><small>← Anterior</small><?= e($arts[$prev]['titulo']) ?></a><?php endif; ?>
                <?php if ($next): ?><a class="sig" href="<?= ca_url($next) ?>"><small>Siguiente →</small><?= e($arts[$next]['titulo']) ?></a><?php endif; ?>
            </div>
            <div class="ca-pie">¿No encontraste lo que buscabas? <a href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>?text=<?= rawurlencode('Hola, tengo una duda sobre "' . $art['titulo'] . '" en MediAgenda.') ?>" target="_blank" rel="noopener">Escríbenos por WhatsApp</a>.</div>
        </div>
        <?php if (count($toc) > 1): ?>
        <nav class="ca-toc" id="caToc"><div class="t"><i class="bi bi-list-ul"></i> En esta página</div>
            <?php foreach ($toc as $h): ?><a href="#<?= e($h[1]) ?>"><?= e(strip_tags($h[2])) ?></a><?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<div class="ca-modal" id="caModal" role="dialog" aria-modal="true" aria-label="Buscar">
    <div class="ca-mbox">
        <input type="search" id="caQ" placeholder="Buscar en la ayuda…" autocomplete="off">
        <div class="ca-res" id="caRes"><div class="ca-vacio">Escribe para buscar entre <?= count($arts) ?> artículos.</div></div>
    </div>
</div>

<script>
(function () {
    var IDX = <?= json_encode(ca_indice_busqueda(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    IDX.forEach(function (a) { a.nt = norm(a.t); a.nx = norm(a.d + ' ' + a.x); });

    /* Tema */
    function tema(t) { document.documentElement.dataset.tema = t; try { localStorage.setItem('ca.tema', t); } catch (e) {}
        document.querySelectorAll('.ca-temas button').forEach(function (b) { b.classList.toggle('on', b.dataset.tema === t); }); }
    document.querySelectorAll('.ca-temas button').forEach(function (b) { b.addEventListener('click', function () { tema(b.dataset.tema); }); });
    tema(document.documentElement.dataset.tema);

    /* Menú en celular */
    var side = document.getElementById('caSide'), mb = document.getElementById('caMenu');
    if (mb) mb.addEventListener('click', function (e) { e.stopPropagation(); side.classList.toggle('on'); });
    document.addEventListener('click', function (e) { if (side.classList.contains('on') && !side.contains(e.target)) side.classList.remove('on'); });

    /* Búsqueda */
    var modal = document.getElementById('caModal'), q = document.getElementById('caQ'), res = document.getElementById('caRes'), sel = 0;
    function abrir() { modal.classList.add('on'); q.value = ''; pinta(); setTimeout(function () { q.focus(); }, 10); }
    function cerrar() { modal.classList.remove('on'); }
    document.querySelectorAll('[data-buscar]').forEach(function (b) { b.addEventListener('click', abrir); });
    modal.addEventListener('click', function (e) { if (e.target === modal) cerrar(); });
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); modal.classList.contains('on') ? cerrar() : abrir(); }
        else if (e.key === '/' && !/input|textarea/i.test(document.activeElement.tagName)) { e.preventDefault(); abrir(); }
        else if (e.key === 'Escape') cerrar();
    });
    function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function resaltar(txt, ps) {
        var out = esc(txt);
        ps.forEach(function (p) { if (p.length < 2) return; out = out.replace(new RegExp('(' + p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>'); });
        return out;
    }
    function fragmento(a, ps) {
        var i = -1; ps.some(function (p) { i = a.nx.indexOf(p); return i >= 0; });
        var src = a.d + ' ' + a.x;
        if (i < 0) return a.d;
        var ini = Math.max(0, i - 50); return (ini ? '…' : '') + src.substr(ini, 150) + '…';
    }
    function pinta() {
        var t = norm(q.value.trim()), ps = t.split(/\s+/).filter(Boolean);
        if (!ps.length) { res.innerHTML = '<div class="ca-vacio">Escribe para buscar entre ' + IDX.length + ' artículos.</div>'; return; }
        var r = IDX.map(function (a) {
            var s = 0;
            for (var k = 0; k < ps.length; k++) {
                var p = ps[k], st = a.nt.indexOf(p) >= 0 ? 10 : 0, sx = a.nx.indexOf(p) >= 0 ? 2 : 0;
                if (!st && !sx) return null;
                s += st + sx + (a.nt.indexOf(p) === 0 ? 3 : 0);
            }
            return { a: a, s: s };
        }).filter(Boolean).sort(function (x, y) { return y.s - x.s; }).slice(0, 12);
        sel = 0;
        if (!r.length) { res.innerHTML = '<div class="ca-vacio">Sin resultados para "' + esc(q.value) + '". <a href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>" target="_blank" rel="noopener">Pregúntanos por WhatsApp</a>.</div>'; return; }
        res.innerHTML = r.map(function (o, i) {
            var origPs = q.value.trim().split(/\s+/);
            return '<a href="' + o.a.u + '" class="' + (i === 0 ? 'on' : '') + '"><b>' + resaltar(o.a.t, origPs) + '</b><small>' + esc(o.a.s) + '</small><small>' + resaltar(fragmento(o.a, ps), origPs) + '</small></a>';
        }).join('');
    }
    q.addEventListener('input', pinta);
    q.addEventListener('keydown', function (e) {
        var ls = res.querySelectorAll('a'); if (!ls.length) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); ls[sel].classList.remove('on'); sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + ls.length) % ls.length; ls[sel].classList.add('on'); ls[sel].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'Enter') { e.preventDefault(); location.href = ls[sel].href; }
    });

    /* Índice "En esta página": marca la sección visible */
    var toc = document.getElementById('caToc');
    if (toc && 'IntersectionObserver' in window) {
        var links = {}; toc.querySelectorAll('a').forEach(function (a) { links[a.getAttribute('href').slice(1)] = a; });
        var io = new IntersectionObserver(function (en) { en.forEach(function (x) { if (x.isIntersecting) { toc.querySelectorAll('a').forEach(function (a) { a.classList.remove('on'); }); var l = links[x.target.id]; if (l) l.classList.add('on'); } }); }, { rootMargin: '0px 0px -70% 0px' });
        document.querySelectorAll('article h2[id]').forEach(function (h) { io.observe(h); });
    }

    /* ¿Te fue útil? */
    var util = document.getElementById('caUtil');
    if (util) util.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = new FormData(); f.append('slug', <?= json_encode($slug) ?>); f.append('util', b.dataset.util); f.append('csrf', <?= json_encode(csrf_token()) ?>);
            fetch('<?= BASE_URL ?>/ayuda/util', { method: 'POST', body: f, credentials: 'same-origin' });
            util.innerHTML = b.dataset.util === '1' ? '<span><i class="bi bi-heart-fill" style="color:#e11d48"></i> ¡Gracias! Nos alegra que te sirviera.</span>'
                : '<span>Gracias. ¿Qué le faltó? </span><a href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>?text=' + encodeURIComponent('El artículo "<?= e($art['titulo'] ?? '') ?>" no me resolvió la duda: ') + '" target="_blank" rel="noopener">Cuéntanos por WhatsApp</a>';
        });
    });
})();
</script>
</body>
</html>
