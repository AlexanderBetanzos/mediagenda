</main>
<footer class="text-center text-muted py-3 small border-top">
    <?= e(APP_NAME) ?> · <?= et('Consola de plataforma') ?> · <?= date('Y') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
