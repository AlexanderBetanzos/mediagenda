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

    <!-- Identificación -->
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-card-text"></i> Identificación</span></div>
    <div class="col-md-3">
        <label class="form-label">CURP</label>
        <input type="text" name="curp" class="form-control text-uppercase" maxlength="18" value="<?= $v('curp') ?>" placeholder="18 caracteres">
    </div>
    <div class="col-md-3">
        <label class="form-label">RFC</label>
        <input type="text" name="rfc" class="form-control text-uppercase" maxlength="13" value="<?= $v('rfc') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Clave INE</label>
        <input type="text" name="ine" class="form-control text-uppercase" maxlength="20" value="<?= $v('ine') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Tipo de sangre</label>
        <select name="tipo_sangre" class="form-select">
            <?php $ts = $p['tipo_sangre'] ?? ''; foreach (['', 'O+','O-','A+','A-','B+','B-','AB+','AB-'] as $g): ?>
                <option value="<?= $g ?>" <?= $ts === $g ? 'selected' : '' ?>><?= $g ?: '—' ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Contacto de emergencia -->
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-telephone-plus"></i> Contacto de emergencia</span></div>
    <div class="col-md-5">
        <label class="form-label">Nombre</label>
        <input type="text" name="contacto_nombre" class="form-control" maxlength="120" value="<?= $v('contacto_nombre') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label">Teléfono</label>
        <input type="text" name="contacto_telefono" class="form-control" maxlength="40" value="<?= $v('contacto_telefono') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Parentesco</label>
        <input type="text" name="contacto_parentesco" class="form-control" maxlength="40" value="<?= $v('contacto_parentesco') ?>">
    </div>

    <!-- Antecedentes clínicos -->
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-clipboard2-pulse"></i> Antecedentes clínicos</span></div>
    <div class="col-md-6">
        <label class="form-label">Alergias</label>
        <textarea name="alergias" class="form-control" rows="2"><?= $v('alergias') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Enfermedades crónicas</label>
        <textarea name="enf_cronicas" class="form-control" rows="2"><?= $v('enf_cronicas') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Antecedentes personales</label>
        <textarea name="antecedentes" class="form-control" rows="2"><?= $v('antecedentes') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Antecedentes familiares</label>
        <textarea name="antecedentes_familiares" class="form-control" rows="2"><?= $v('antecedentes_familiares') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Cirugías</label>
        <textarea name="cirugias" class="form-control" rows="2"><?= $v('cirugias') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Vacunas</label>
        <textarea name="vacunas" class="form-control" rows="2"><?= $v('vacunas') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Hábitos</label>
        <textarea name="habitos" class="form-control" rows="2" placeholder="Tabaquismo, alcohol, ejercicio…"><?= $v('habitos') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label">Notas</label>
        <textarea name="notas" class="form-control" rows="2"><?= $v('notas') ?></textarea>
    </div>
</div>
