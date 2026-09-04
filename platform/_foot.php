</main>
<footer class="text-center text-muted py-3 small border-top">
    <?= e(APP_NAME) ?> · <?= et('Consola de plataforma') ?> · <?= date('Y') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= asset('assets/js/app.js') ?>"></script>
<script>
/* Guarda la preferencia de tema (misma cookie que el panel del consultorio,
   así la consola y el panel se ven igual) y la aplica al instante. */
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
    document.querySelectorAll('[onclick^="setTema"]').forEach(function (a) {
        a.classList.toggle('active', a.getAttribute('onclick').indexOf("'" + pref + "'") > -1);
    });
}
</script>
<script>
(function () {
    var t = document.getElementById('clkTime'), d = document.getElementById('clkDate');
    if (!t || !d) return;
    var dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var p = function (x) { return x < 10 ? '0' + x : '' + x; };
    function tick() { var n = new Date();
        t.textContent = p(n.getHours()) + ':' + p(n.getMinutes()) + ':' + p(n.getSeconds());
        d.textContent = dias[n.getDay()] + ', ' + n.getDate() + ' ' + meses[n.getMonth()] + ' ' + n.getFullYear(); }
    tick(); setInterval(tick, 1000);
})();
</script>
</body>
</html>
