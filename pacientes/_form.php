<?php
/** Campos del formulario de paciente. Espera $p (array) y $errores (array). */
$p = $p ?? [];
$v = fn(string $k) => e($p[$k] ?? '');
?>
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Nombre(s) *</label>
        <input type="text" name="nombre" class="form-control" required value="<?= $v('nombre') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">Apellidos *</label>
        <input type="text" name="apellidos" class="form-control" required value="<?= $v('apellidos') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Fecha de nacimiento</label>
        <input type="date" name="fecha_nacimiento" class="form-control" value="<?= $v('fecha_nacimiento') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Sexo</label>
        <select name="sexo" class="form-select">
            <?php $sx = $p['sexo'] ?? ''; ?>
            <option value="">—</option>
            <option value="M" <?= $sx === 'M' ? 'selected' : '' ?>>Masculino</option>
            <option value="F" <?= $sx === 'F' ? 'selected' : '' ?>>Femenino</option>
            <option value="O" <?= $sx === 'O' ? 'selected' : '' ?>>Otro</option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Tipo de paciente *</label>
        <select name="tipo" class="form-select" required>
            <?php $tp = $p['tipo'] ?? 'medico'; ?>
            <option value="medico" <?= $tp === 'medico' ? 'selected' : '' ?>>Médico</option>
            <option value="dental" <?= $tp === 'dental' ? 'selected' : '' ?>>Dental</option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Teléfono</label>
        <input type="text" name="telefono" class="form-control" value="<?= $v('telefono') ?>">
    </div>
    <div class="col-md-8">
        <label class="form-label">Correo electrónico</label>
        <input type="email" name="email" class="form-control" value="<?= $v('email') ?>">
    </div>
    <div class="col-12">
        <label class="form-label">Dirección</label>
        <input type="text" name="direccion" class="form-control" value="<?= $v('direccion') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label">Alergias</label>
        <textarea name="alergias" class="form-control" rows="2"><?= $v('alergias') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Antecedentes médicos</label>
        <textarea name="antecedentes" class="form-control" rows="2"><?= $v('antecedentes') ?></textarea>
    </div>
    <div class="col-12">
        <label class="form-label">Notas</label>
        <textarea name="notas" class="form-control" rows="2"><?= $v('notas') ?></textarea>
    </div>
</div>
