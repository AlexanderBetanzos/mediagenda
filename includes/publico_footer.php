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
</body>
</html>
