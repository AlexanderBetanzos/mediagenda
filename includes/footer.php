        </main>
    </div>
</div>
<footer class="text-center text-muted py-3 small border-top mt-auto">
    <?= e(marca_nombre()) ?> &middot; <?= date('Y') ?>
</footer>
<?php require_once __DIR__ . '/ayuda.php'; ?>
<!-- Ayuda: un solo modal para todos los "?" y el botón flotante (ver includes/ayuda.php) -->
<div class="modal fade" id="ayudaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content ayuda-modal">
            <div class="modal-header">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2"><i class="bi bi-question-circle-fill text-brand"></i> <span id="ayudaTitulo"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('Cerrar')) ?>"></button>
            </div>
            <div class="modal-body" id="ayudaCuerpo"></div>
            <div class="modal-footer justify-content-between">
                <span class="small text-muted"><?= et('¿Sigues con dudas?') ?></span>
                <span class="d-flex gap-2">
                    <a href="https://wa.me/<?= e(SOPORTE_WHATSAPP) ?>?text=<?= rawurlencode(t('Hola, necesito ayuda con') . ' ' . marca_nombre()) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-success"><i class="bi bi-whatsapp"></i> <?= et('Soporte') ?></a>
                    <a href="<?= BASE_URL ?>/soporte/index" class="btn btn-sm btn-outline-secondary"><i class="bi bi-headset"></i> <?= et('Ayuda y soporte') ?></a>
                </span>
            </div>
        </div>
    </div>
</div>
<button type="button" class="ayuda-fab" data-ayuda="<?= e('page:' . ($activo ?? '')) ?>" title="<?= e(t('Ayuda')) ?>" aria-label="<?= e(t('Ayuda')) ?>"><i class="bi bi-question-lg"></i></button>
<style>
.btn-ayuda { border: 0; background: transparent; padding: 0 .1rem; line-height: 1; color: var(--brand); opacity: .75; font-size: 1.05rem; cursor: pointer; transition: opacity .2s, transform .2s; }
.btn-ayuda:hover { opacity: 1; transform: scale(1.12); }
.ayuda-fab { position: fixed; right: 20px; bottom: 20px; z-index: 1040; width: 52px; height: 52px; border-radius: 50%; border: 0;
             background: var(--brand); color: #fff; font-size: 1.45rem; display: flex; align-items: center; justify-content: center;
             box-shadow: 0 10px 26px color-mix(in srgb, var(--brand) 45%, transparent); transition: transform .2s ease; }
.ayuda-fab:hover { transform: scale(1.08); }
@media print { .ayuda-fab { display: none; } }
.ayuda-modal { border-radius: 20px; border: 0; }
.ayuda-intro { font-size: .98rem; }
.ayuda-lbl { font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .65; margin-bottom: .4rem; }
.ayuda-pasos { padding-left: 1.2rem; } .ayuda-pasos li { margin-bottom: .45rem; }
.ayuda-tips { padding-left: 1.1rem; font-size: .92rem; } .ayuda-tips li { margin-bottom: .3rem; }
.ayuda-temas { display: grid; gap: .5rem; }
.ayuda-tema { text-align: left; border: 1px solid rgba(127,127,127,.2); background: transparent; border-radius: 14px; padding: .7rem .9rem; color: inherit; }
.ayuda-tema:hover { border-color: var(--brand); }
.ayuda-tema.destacado { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 7%, transparent); }
.ayuda-tema.especial { background: color-mix(in srgb, var(--brand) 4%, transparent); }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<?php /* Ordenar tablas por encabezado. Necesita el formato de fecha del
         consultorio: "13/07/2026" ordenado como texto quedaría mal. */ ?>
<script>window.APP_FORMATO_FECHA = <?= json_encode(cfg('formato_fecha', 'd/m/Y')) ?>;</script>
<script src="<?= asset('assets/js/tabla-orden.js') ?>"></script>
<script>
/* Guarda la preferencia de tema del usuario y la aplica al instante. */
function setTema(pref) {
    document.cookie = 'tema=' + pref + ';path=<?= BASE_URL ?>/;max-age=31536000;samesite=Lax';
    var resolved = pref === 'auto'
        ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : pref;
    var el = document.documentElement;
    el.classList.remove('app-dark', 'app-light');
    el.classList.add(resolved === 'light' ? 'app-light' : 'app-dark');
    if (resolved === 'light') { el.setAttribute('data-bs-theme', 'light'); }
    else { el.removeAttribute('data-bs-theme'); }
}
/* Guarda el idioma del usuario y recarga para aplicarlo. */
/* ── Ayuda: modal flotante de los "?" y centro de ayuda (de Khronos) ── */
(function () {
    var AYUDA = <?= json_encode(ayuda_textos(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    var PAGINA = <?= json_encode('page:' . ($activo ?? '')) ?>;
    var el = document.getElementById('ayudaModal');
    if (!el || !window.bootstrap) return;
    var modal = bootstrap.Modal.getOrCreateInstance(el);
    var titulo = document.getElementById('ayudaTitulo'), cuerpo = document.getElementById('ayudaCuerpo');
    var lista = function (xs, tag, cls) { return '<' + tag + ' class="' + cls + '">' + xs.map(function (x) { return '<li>' + x + '</li>'; }).join('') + '</' + tag + '>'; };

    function mostrar(clave) {
        var h = AYUDA[clave];
        if (!h) return centro();
        titulo.textContent = h.titulo;
        var html = '';
        if (h.intro) html += '<p class="ayuda-intro">' + h.intro + '</p>';
        if (h.pasos && h.pasos.length) html += '<div class="ayuda-lbl"><?= e(t('Paso a paso')) ?></div>' + lista(h.pasos, 'ol', 'ayuda-pasos');
        if (h.tips && h.tips.length) html += '<div class="ayuda-lbl mt-3"><i class="bi bi-lightbulb-fill text-warning"></i> <?= e(t('Ten en cuenta')) ?></div>' + lista(h.tips, 'ul', 'ayuda-tips');
        html += '<button type="button" class="btn btn-link btn-sm px-0 mt-3" data-ayuda-centro><i class="bi bi-grid"></i> <?= e(t('Ver todos los temas')) ?></button>';
        cuerpo.innerHTML = html;
        modal.show();
    }
    /* Centro de ayuda: la guía de esta pantalla, la de tu especialidad, la de
       migración y todas las demás, con buscador. */
    function centro() {
        titulo.textContent = <?= json_encode(t('Centro de ayuda'), JSON_UNESCAPED_UNICODE) ?>;
        var orden = [PAGINA, 'giro', 'migrar', 'pacientes.nuevo', 'citas.nueva'];
        Object.keys(AYUDA).forEach(function (k) { if (orden.indexOf(k) < 0) orden.push(k); });
        var items = orden.filter(function (k) { return AYUDA[k]; }).map(function (k) {
            var dest = k === PAGINA ? ' destacado' : (k === 'giro' || k === 'migrar' ? ' especial' : '');
            return '<button type="button" class="ayuda-tema' + dest + '" data-ayuda="' + k + '">'
                 + '<span class="fw-semibold">' + AYUDA[k].titulo + '</span>'
                 + (AYUDA[k].intro ? '<span class="d-block small text-muted">' + AYUDA[k].intro.replace(/<[^>]+>/g, '') + '</span>' : '')
                 + '</button>';
        }).join('');
        cuerpo.innerHTML = '<input type="search" class="form-control mb-3" id="ayudaBuscar" placeholder="<?= e(t('¿Qué quieres hacer? Ej. agregar paciente, cobrar…')) ?>">'
            + '<div class="ayuda-temas">' + items + '</div>';
        modal.show();
        var q = document.getElementById('ayudaBuscar');
        q.addEventListener('input', function () {
            var v = q.value.trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            cuerpo.querySelectorAll('.ayuda-tema').forEach(function (b) {
                var txt = b.textContent.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                b.hidden = v !== '' && txt.indexOf(v) < 0;
            });
        });
        setTimeout(function () { q.focus(); }, 300);
    }
    document.addEventListener('click', function (e) {
        var c = e.target.closest('[data-ayuda-centro]');
        if (c) { e.preventDefault(); return centro(); }
        var b = e.target.closest('[data-ayuda]');
        if (!b) return;
        e.preventDefault();
        mostrar(b.getAttribute('data-ayuda'));
    });
})();

function setIdioma(lang) {
    document.cookie = 'lang=' + lang + ';path=<?= BASE_URL ?>/;max-age=31536000;samesite=Lax';
    location.reload();
}
/* Reloj del topbar (hora + fecha), localizado al idioma activo (estilo GymOS). */
(function () {
    var t = document.getElementById('clkTime'), d = document.getElementById('clkDate');
    if (!t || !d) return;
    var lang = (document.documentElement.getAttribute('lang') || 'es').slice(0, 2);
    var L = {
        es: { d: ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'],
              m: ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'],
              fmt: function (n) { return this.d[n.getDay()] + ', ' + n.getDate() + ' ' + this.m[n.getMonth()] + ' ' + n.getFullYear(); } },
        en: { d: ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],
              m: ['January','February','March','April','May','June','July','August','September','October','November','December'],
              fmt: function (n) { return this.d[n.getDay()] + ', ' + this.m[n.getMonth()] + ' ' + n.getDate() + ', ' + n.getFullYear(); } }
    };
    var loc = L[lang] || L.es;
    var p = function (x) { return x < 10 ? '0' + x : '' + x; };
    function tick() {
        var n = new Date();
        t.textContent = p(n.getHours()) + ':' + p(n.getMinutes()) + ':' + p(n.getSeconds());
        d.textContent = loc.fmt(n);
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
</body>
</html>
