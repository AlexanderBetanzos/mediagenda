</main>
<footer class="pt-pie">
    <i class="bi bi-lock-fill"></i> <?= e(marca_nombre()) ?> · <?= et('Portal del paciente') ?>
    <?php if (cfg('telefono')): ?> · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', cfg('telefono'))) ?>"><?= e(cfg('telefono')) ?></a><?php endif; ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
