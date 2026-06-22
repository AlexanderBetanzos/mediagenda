<?php
/** Campos del formulario de cita. Espera $c (array). */
$c = $c ?? [];
$val = fn(string $k, $def='') => e($c[$k] ?? $def);

$pacientes = db()->prepare('SELECT id, nombre, apellidos FROM pacientes WHERE consultorio_id = ? ORDER BY apellidos, nombre');
$pacientes->execute([tenant_id()]);
$pacientes = $pacientes->fetchAll();
$medicos   = db()->prepare("SELECT id, nombre, especialidad FROM usuarios WHERE rol='medico' AND activo=1 AND consultorio_id = ? ORDER BY nombre");
$medicos->execute([tenant_id()]);
$medicos = $medicos->fetchAll();
?>
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label"><?= et('Paciente') ?> *</label>
        <select name="paciente_id" class="form-select" required>
            <option value=""><?= et('— Selecciona —') ?></option>
            <?php foreach ($pacientes as $p): ?>
                <option value="<?= $p['id'] ?>" <?= (string)($c['paciente_id']??'')===(string)$p['id']?'selected':'' ?>>
                    <?= e($p['apellidos'].', '.$p['nombre']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Médico / Dentista') ?> *</label>
        <select name="medico_id" class="form-select" required>
            <option value=""><?= et('— Selecciona —') ?></option>
            <?php foreach ($medicos as $m): ?>
                <option value="<?= $m['id'] ?>" <?= (string)($c['medico_id']??'')===(string)$m['id']?'selected':'' ?>>
                    <?= e($m['nombre']) ?><?= $m['especialidad'] ? ' ('.e($m['especialidad']).')' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Fecha') ?> *</label>
        <input type="date" name="fecha" class="form-control" required value="<?= $val('fecha', date('Y-m-d')) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Hora') ?> *</label>
        <input type="time" name="hora" class="form-control" required value="<?= $val('hora', '09:00') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Duración (min)') ?></label>
        <input type="number" name="duracion" class="form-control" min="5" step="5" value="<?= $val('duracion', '30') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Tipo') ?></label>
        <select name="tipo" class="form-select">
            <?php $tp = $c['tipo'] ?? 'medica'; ?>
            <option value="medica" <?= $tp==='medica'?'selected':'' ?>><?= et('Médica') ?></option>
            <option value="dental" <?= $tp==='dental'?'selected':'' ?>><?= et('Dental') ?></option>
        </select>
    </div>
    <div class="col-md-8">
        <label class="form-label"><?= et('Motivo') ?></label>
        <input type="text" name="motivo" class="form-control" value="<?= $val('motivo') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Estado') ?></label>
        <select name="estado" class="form-select">
            <?php $st = $c['estado'] ?? 'programada'; ?>
            <?php foreach (['programada','confirmada','atendida','cancelada','no_asistio'] as $es): ?>
                <option value="<?= $es ?>" <?= $st===$es?'selected':'' ?>><?= estado_label($es) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label"><?= et('Notas') ?></label>
        <textarea name="notas" class="form-control" rows="2"><?= $val('notas') ?></textarea>
    </div>

    <?php if (!empty($mostrar_recurrencia)): ?>
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-arrow-repeat"></i> <?= et('Repetición') ?></span></div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Repetir') ?></label>
        <select name="repetir" class="form-select">
            <?php $rp = $c['repetir'] ?? 'no'; foreach (['no'=>'No se repite','semanal'=>'Cada semana','quincenal'=>'Cada 2 semanas','mensual'=>'Cada mes'] as $k=>$lbl): ?>
                <option value="<?= $k ?>" <?= $rp===$k?'selected':'' ?>><?= et($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Número de citas') ?></label>
        <input type="number" name="repeticiones" class="form-control" min="1" max="52" value="<?= $val('repeticiones', '1') ?>">
        <div class="form-text"><?= et('Incluye la primera. Ej. 4 = la cita + 3 repeticiones.') ?></div>
    </div>
    <?php endif; ?>
</div>
