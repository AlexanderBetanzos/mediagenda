<?php
/** Tarjeta "Recetas recientes" del dashboard (como "My prescriptions"). Espera $recetasRec. */
?>
<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-capsule text-brand"></i> <?= et('Recetas recientes') ?></span>
        <a href="<?= BASE_URL ?>/recetas/index" class="small"><?= et('Ver todas') ?></a>
    </div>
    <div class="card-body py-2">
        <?php foreach ($recetasRec as $r): ?>
        <div class="rx-row">
            <span class="rx-ic"><i class="bi bi-file-earmark-medical"></i></span>
            <span class="min-w-0 flex-grow-1">
                <span class="d-block fw-semibold text-truncate"><?= e($r['nombre'] . ' ' . $r['apellidos']) ?></span>
                <span class="d-block small text-muted text-truncate"><?= e(fmt_fecha(substr((string) $r['fecha'], 0, 10))) ?><?= $r['diagnostico'] ? ' · ' . e($r['diagnostico']) : '' ?></span>
            </span>
            <a href="<?= BASE_URL ?>/recetas/ver?id=<?= (int) $r['id'] ?>" class="rx-go" title="<?= e(t('Ver e imprimir')) ?>"><i class="bi bi-printer"></i></a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
