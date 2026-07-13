<?php
/** Campos del formulario de paciente. Espera $p (array) y $errores (array). */
$p = $p ?? [];
$v = fn(string $k) => e($p[$k] ?? '');
?>
<div class="row g-3">
    <!-- Foto del paciente: subir del dispositivo o tomar con la cámara -->
    <div class="col-12 text-center pb-2">
        <label class="form-label d-block"><?= et('Foto del paciente') ?></label>
        <div class="d-inline-flex flex-column align-items-center">
            <?php $fotoUrl = foto_paciente_url($p); ?>
            <img id="fotoPreview" src="<?= e($fotoUrl) ?>" alt="foto" class="rounded-circle border <?= $fotoUrl ? '' : 'd-none' ?>" style="width:110px;height:110px;object-fit:cover">
            <div id="fotoPlaceholder" class="rounded-circle border d-flex align-items-center justify-content-center <?= $fotoUrl ? 'd-none' : '' ?>" style="width:110px;height:110px;background:rgba(127,127,127,.14)"><i class="bi bi-person fs-1 text-muted"></i></div>
            <div class="d-flex flex-column gap-2 mt-2" style="max-width:340px;width:100%">
                <input type="file" name="foto" id="fotoInput" class="form-control form-control-sm" accept="image/*" capture="environment">
                <button type="button" id="fotoCamBtn" class="btn btn-outline-secondary btn-sm"><i class="bi bi-camera"></i> <?= et('Tomar foto con la cámara') ?></button>
                <?php if ($fotoUrl): ?>
                <div class="form-check text-start">
                    <input class="form-check-input" type="checkbox" name="quitar_foto" value="1" id="quitarFoto">
                    <label class="form-check-label small text-muted" for="quitarFoto"><?= et('Quitar la foto al guardar') ?></label>
                </div>
                <?php endif; ?>
            </div>
            <div id="fotoCam" class="mt-3 d-none" style="max-width:340px;width:100%">
                <div class="input-group input-group-sm mb-2">
                    <select id="fotoCamSelect" class="form-select form-select-sm"><option><?= et('Abriendo cámara…') ?></option></select>
                    <button type="button" id="fotoCamRefresh" class="btn btn-outline-secondary" title="<?= e(t('Actualizar cámaras')) ?>"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
                <video id="fotoVideo" autoplay playsinline muted class="rounded border d-block w-100" style="background:#000"></video>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" id="fotoSnap" class="btn btn-primary btn-sm"><i class="bi bi-camera-fill"></i> <?= et('Capturar') ?></button>
                    <button type="button" id="fotoCamCancel" class="btn btn-outline-secondary btn-sm"><?= et('Cancelar') ?></button>
                </div>
                <div id="fotoCamErr" class="small text-danger mt-1 d-none"></div>
            </div>
            <canvas id="fotoCanvas" class="d-none"></canvas>
            <div class="form-text"><?= et('Sube una imagen del dispositivo o tómala con la cámara (PC o celular).') ?></div>
        </div>
    </div>

    <div class="col-md-6">
        <label class="form-label"><?= et('Nombre(s)') ?> *</label>
        <input type="text" name="nombre" class="form-control" required value="<?= $v('nombre') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Apellidos') ?> *</label>
        <input type="text" name="apellidos" class="form-control" required value="<?= $v('apellidos') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Fecha de nacimiento') ?></label>
        <input type="date" name="fecha_nacimiento" class="form-control" value="<?= $v('fecha_nacimiento') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Sexo') ?></label>
        <select name="sexo" class="form-select">
            <?php $sx = $p['sexo'] ?? ''; ?>
            <option value="">—</option>
            <option value="M" <?= $sx === 'M' ? 'selected' : '' ?>><?= et('Masculino') ?></option>
            <option value="F" <?= $sx === 'F' ? 'selected' : '' ?>><?= et('Femenino') ?></option>
            <option value="O" <?= $sx === 'O' ? 'selected' : '' ?>><?= et('Otro') ?></option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Tipo de paciente') ?> *</label>
        <select name="tipo" class="form-select" required>
            <?php $tp = $p['tipo'] ?? 'medico'; ?>
            <option value="medico" <?= $tp === 'medico' ? 'selected' : '' ?>><?= e(tipo_paciente_label('medico')) ?></option>
            <option value="dental" <?= $tp === 'dental' ? 'selected' : '' ?>><?= e(tipo_paciente_label('dental')) ?></option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Teléfono') ?></label>
        <input type="text" name="telefono" class="form-control" value="<?= $v('telefono') ?>">
    </div>
    <div class="col-md-8">
        <label class="form-label"><?= et('Correo electrónico') ?></label>
        <input type="email" name="email" class="form-control" value="<?= $v('email') ?>">
    </div>
    <div class="col-12">
        <label class="form-label"><?= et('Dirección') ?></label>
        <input type="text" name="direccion" class="form-control" value="<?= $v('direccion') ?>">
    </div>

    <!-- Identificación -->
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-card-text"></i> <?= et('Identificación') ?></span></div>
    <div class="col-md-3">
        <label class="form-label">CURP</label>
        <input type="text" name="curp" class="form-control text-uppercase" maxlength="18" value="<?= $v('curp') ?>" placeholder="<?= et('18 caracteres') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">RFC</label>
        <input type="text" name="rfc" class="form-control text-uppercase" maxlength="13" value="<?= $v('rfc') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Clave INE') ?></label>
        <input type="text" name="ine" class="form-control text-uppercase" maxlength="20" value="<?= $v('ine') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Tipo de sangre') ?></label>
        <select name="tipo_sangre" class="form-select">
            <?php $ts = $p['tipo_sangre'] ?? ''; foreach (['', 'O+','O-','A+','A-','B+','B-','AB+','AB-'] as $g): ?>
                <option value="<?= $g ?>" <?= $ts === $g ? 'selected' : '' ?>><?= $g ?: '—' ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Contacto de emergencia -->
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-telephone-plus"></i> <?= et('Contacto de emergencia') ?></span></div>
    <div class="col-md-5">
        <label class="form-label"><?= et('Nombre') ?></label>
        <input type="text" name="contacto_nombre" class="form-control" maxlength="120" value="<?= $v('contacto_nombre') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Teléfono') ?></label>
        <input type="text" name="contacto_telefono" class="form-control" maxlength="40" value="<?= $v('contacto_telefono') ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Parentesco') ?></label>
        <input type="text" name="contacto_parentesco" class="form-control" maxlength="40" value="<?= $v('contacto_parentesco') ?>">
    </div>

    <!-- Antecedentes clínicos -->
    <div class="col-12"><hr class="my-1"><span class="fw-semibold small text-muted text-uppercase"><i class="bi bi-clipboard2-pulse"></i> <?= et('Antecedentes clínicos') ?></span></div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Alergias') ?></label>
        <textarea name="alergias" class="form-control" rows="2"><?= $v('alergias') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Enfermedades crónicas') ?></label>
        <textarea name="enf_cronicas" class="form-control" rows="2"><?= $v('enf_cronicas') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Antecedentes personales') ?></label>
        <textarea name="antecedentes" class="form-control" rows="2"><?= $v('antecedentes') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Antecedentes familiares') ?></label>
        <textarea name="antecedentes_familiares" class="form-control" rows="2"><?= $v('antecedentes_familiares') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Cirugías') ?></label>
        <textarea name="cirugias" class="form-control" rows="2"><?= $v('cirugias') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Vacunas') ?></label>
        <textarea name="vacunas" class="form-control" rows="2"><?= $v('vacunas') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Hábitos') ?></label>
        <textarea name="habitos" class="form-control" rows="2" placeholder="<?= et('Tabaquismo, alcohol, ejercicio…') ?>"><?= $v('habitos') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Notas') ?></label>
        <textarea name="notas" class="form-control" rows="2"><?= $v('notas') ?></textarea>
    </div>
</div>

<script>
(function () {
    // Foto del paciente: subir del dispositivo o tomar con la cámara (mismo input[name=foto]).
    var input = document.getElementById('fotoInput');
    if (!input) return;
    var preview = document.getElementById('fotoPreview'), placeholder = document.getElementById('fotoPlaceholder');
    var camBtn = document.getElementById('fotoCamBtn'), cam = document.getElementById('fotoCam');
    var video = document.getElementById('fotoVideo'), snap = document.getElementById('fotoSnap');
    var cancel = document.getElementById('fotoCamCancel'), canvas = document.getElementById('fotoCanvas');
    var errBox = document.getElementById('fotoCamErr'), camSelect = document.getElementById('fotoCamSelect');
    var camRefresh = document.getElementById('fotoCamRefresh'), stream = null;

    function showPreview(url) { preview.src = url; preview.classList.remove('d-none'); if (placeholder) placeholder.classList.add('d-none'); }
    input.addEventListener('change', function () { var f = input.files && input.files[0]; if (f) showPreview(URL.createObjectURL(f)); });

    function stopStream() { if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; } }
    function stopCam() { stopStream(); cam.classList.add('d-none'); }
    function startStream(deviceId) {
        stopStream();
        var c = deviceId ? { video: { deviceId: { exact: deviceId } }, audio: false } : { video: { facingMode: 'user' }, audio: false };
        return navigator.mediaDevices.getUserMedia(c).then(function (s) { stream = s; video.srcObject = s; });
    }
    function listCameras() {
        if (!navigator.mediaDevices.enumerateDevices) return Promise.resolve();
        return navigator.mediaDevices.enumerateDevices().then(function (devs) {
            var cams = devs.filter(function (d) { return d.kind === 'videoinput'; });
            camSelect.innerHTML = '';
            if (!cams.length) { var o = document.createElement('option'); o.textContent = 'Sin cámaras'; camSelect.appendChild(o); return; }
            cams.forEach(function (d, i) { var o = document.createElement('option'); o.value = d.deviceId; o.textContent = d.label || ('Cámara ' + (i + 1)); camSelect.appendChild(o); });
            var cur = stream && stream.getVideoTracks()[0] && stream.getVideoTracks()[0].getSettings();
            if (cur && cur.deviceId) camSelect.value = cur.deviceId;
        });
    }
    camBtn.addEventListener('click', function () {
        errBox.classList.add('d-none');
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            errBox.textContent = 'Tu navegador no permite usar la cámara aquí. Usa "Elegir archivo".'; errBox.classList.remove('d-none'); return;
        }
        cam.classList.remove('d-none');
        startStream(null).then(listCameras).catch(function () {
            errBox.textContent = 'No se pudo abrir la cámara. Revisa los permisos del navegador.'; errBox.classList.remove('d-none');
        });
    });
    camSelect.addEventListener('change', function () { if (camSelect.value) startStream(camSelect.value).then(listCameras).catch(function () {}); });
    camRefresh.addEventListener('click', function () { (stream ? Promise.resolve() : startStream(null)).then(listCameras).catch(function () {}); });
    cancel.addEventListener('click', stopCam);
    snap.addEventListener('click', function () {
        if (!stream) return;
        var w = video.videoWidth || 480, h = video.videoHeight || 480;
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);
        canvas.toBlob(function (blob) {
            if (!blob) return;
            var file = new File([blob], 'foto_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            try { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch (e) {}
            showPreview(URL.createObjectURL(blob)); stopCam();
        }, 'image/jpeg', 0.9);
    });
    window.addEventListener('beforeunload', stopCam);
})();
</script>
