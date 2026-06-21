        </main>
    </div>
</div>
<footer class="text-center text-muted py-3 small border-top mt-auto">
    <?= e(marca_nombre()) ?> &middot; <?= date('Y') ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
</script>
</body>
</html>
