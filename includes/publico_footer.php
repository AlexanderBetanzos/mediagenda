</div><!-- /.pub-wrap -->

<footer class="landing-footer py-4">
    <div class="container text-center small">
        <div class="fw-semibold mb-1"><i class="bi bi-heart-pulse-fill"></i> <?= e(marca_nombre()) ?></div>
        <div>
            <?php if (cfg('direccion')): ?><?= e(cfg('direccion')) ?><?php endif; ?>
            <?php if (cfg('telefono')): ?> · <?= e(cfg('telefono')) ?><?php endif; ?>
            <?php if (cfg('email')): ?> · <?= e(cfg('email')) ?><?php endif; ?>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Alterna claro/oscuro y guarda la preferencia en la misma cookie que el resto del sitio. */
(function () {
    var boton = document.getElementById('lpTema');
    if (!boton) return;
    boton.addEventListener('click', function () {
        var el = document.documentElement;
        var oscuro = !el.classList.contains('lp-dark');
        el.classList.toggle('lp-dark', oscuro);
        if (oscuro) { el.setAttribute('data-bs-theme', 'dark'); }
        else { el.removeAttribute('data-bs-theme'); }
        document.cookie = 'tema=' + (oscuro ? 'dark' : 'light') +
                          ';path=<?= BASE_URL ?>/;max-age=31536000;samesite=Lax';
    });
})();
</script>
</body>
</html>
