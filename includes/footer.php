        </main>
    </div>
</div>
<footer class="text-center text-muted py-3 small border-top mt-auto">
    <?= e(marca_nombre()) ?> &middot; <?= date('Y') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
