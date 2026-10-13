<?php
/** Campos del formulario de paciente. Espera $p (array) y $errores (array). */
$p = $p ?? [];
$v = fn(string $k) => e($p[$k] ?? '');
?>
<div class="pf-grid">
<aside class="pf-side">
    <!-- Foto del paciente: subir del dispositivo o tomar con la cámara -->
    <div class="pf-card text-center">
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
        <?php /* Vista previa en vivo: se llena mientras escribes. */ ?>
        <div class="pf-preview">
            <div class="pf-prev-n" id="pfNombre"><?= e(trim(($p['nombre'] ?? '') . (es_veterinaria() ? '' : ' ' . ($p['apellidos'] ?? '')))) ?: et(es_veterinaria() ? 'Nueva mascota' : 'Nuevo paciente') ?></div>
            <div class="pf-prev-s" id="pfSub"></div>
        </div>
    </div>

    <div class="pf-card">
        <div class="d-flex align-items-center gap-3">
            <div class="pf-ring" id="pfRing" style="--p:0"><span id="pfPct">0%</span></div>
            <div>
                <div class="fw-semibold"><?= et('Expediente completo') ?></div>
                <div class="small text-muted"><?= et('Entre más datos, mejor atención. Solo lo marcado con * es obligatorio.') ?></div>
            </div>
        </div>
    </div>

    <div class="pf-card pf-tip">
        <div class="fw-semibold mb-1"><i class="bi bi-lightbulb-fill"></i> <?= et('Consejo') ?></div>
        <div class="small"><?= es_veterinaria()
            ? et('Anota el teléfono del propietario: ahí le llegan los recordatorios de cita por WhatsApp.')
            : et('Anota su teléfono para mandarle recordatorios por WhatsApp. Las alergias se resaltan en rojo en su expediente.') ?></div>
    </div>
</aside>

<div class="pf-main">
<section class="pf-sec">
    <div class="pf-sec-h"><i class="bi <?= es_veterinaria() ? 'bi-house-heart' : 'bi-person-vcard' ?>"></i> <?= es_veterinaria() ? et('Mascota') : et('Datos personales') ?></div>
    <div class="row g-3">

    <?php if (es_veterinaria()): asegurar_campos_veterinaria(); $sx = $p['sexo'] ?? ''; ?>
    <?php /* Veterinaria: la ficha es de la MASCOTA. "apellidos" guarda los del
             propietario (así se archiva en una veterinaria: "Pérez, Firulais"),
             y teléfono/correo son del dueño: los recordatorios le llegan a él. */ ?>
    <div class="col-md-5">
        <label class="form-label"><?= et('Nombre de la mascota') ?> *</label>
        <input type="text" name="nombre" class="form-control" required value="<?= $v('nombre') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Especie') ?> *</label>
        <select name="especie" class="form-select" required>
            <option value="">—</option>
            <?php foreach (especies_vet() as $es): ?>
                <option value="<?= e($es) ?>" <?= ($p['especie'] ?? '') === $es ? 'selected' : '' ?>><?= et($es) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label"><?= et('Sexo') ?></label>
        <select name="sexo" class="form-select">
            <option value="">—</option>
            <option value="M" <?= $sx === 'M' ? 'selected' : '' ?>><?= et('Macho') ?></option>
            <option value="F" <?= $sx === 'F' ? 'selected' : '' ?>><?= et('Hembra') ?></option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Raza') ?></label>
        <input type="text" name="raza" class="form-control" maxlength="80" value="<?= $v('raza') ?>" placeholder="<?= e(t('Ej. Labrador, mestizo…')) ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Color / señas') ?></label>
        <input type="text" name="color" class="form-control" maxlength="60" value="<?= $v('color') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Fecha de nacimiento') ?></label>
        <input type="date" name="fecha_nacimiento" class="form-control" value="<?= $v('fecha_nacimiento') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Esterilizado') ?></label>
        <?php $est = $p['esterilizado'] ?? ''; $est = $est === null ? '' : (string) $est; ?>
        <select name="esterilizado" class="form-select">
            <option value="">—</option>
            <option value="1" <?= $est === '1' ? 'selected' : '' ?>><?= et('Sí') ?></option>
            <option value="0" <?= $est === '0' ? 'selected' : '' ?>><?= et('No') ?></option>
        </select>
    </div>
    <div class="col-md-8">
        <label class="form-label"><?= et('Microchip') ?></label>
        <input type="text" name="microchip" class="form-control" maxlength="40" value="<?= $v('microchip') ?>">
    </div>

    </div>
</section>
<section class="pf-sec">
    <div class="pf-sec-h"><i class="bi bi-person-heart"></i> <?= et('Propietario') ?></div>
    <div class="row g-3">
    <div class="col-md-6">
        <label class="form-label"><?= et('Nombre del propietario') ?></label>
        <input type="text" name="propietario" class="form-control" maxlength="120" value="<?= $v('propietario') ?>">
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Apellidos del propietario') ?> *</label>
        <input type="text" name="apellidos" class="form-control" required value="<?= $v('apellidos') ?>">
    </div>
    <div class="col-md-4">
        <label class="form-label"><?= et('Teléfono del propietario') ?></label>
        <input type="text" name="telefono" class="form-control" value="<?= $v('telefono') ?>">
        <div class="form-text"><?= et('Aquí le llegan los recordatorios por WhatsApp.') ?></div>
    </div>
    <div class="col-md-8">
        <label class="form-label"><?= et('Correo del propietario') ?></label>
        <input type="email" name="email" class="form-control" value="<?= $v('email') ?>">
    </div>
    <div class="col-12">
        <label class="form-label"><?= et('Dirección') ?></label>
        <input type="text" name="direccion" class="form-control" value="<?= $v('direccion') ?>">
    </div>
    <?php else: ?>
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
            <?php $tp = $p['tipo'] ?? (giro()['tipo'] ?? 'medico'); ?>
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
    </div>
</section>
<section class="pf-sec">
    <div class="pf-sec-h"><i class="bi bi-card-text"></i> <?= et('Identificación') ?> <span class="pf-opc"><?= et('Opcional') ?></span></div>
    <div class="row g-3">
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
    </div>
</section>
<section class="pf-sec">
    <div class="pf-sec-h"><i class="bi bi-telephone-plus"></i> <?= et('Contacto de emergencia') ?> <span class="pf-opc"><?= et('Opcional') ?></span></div>
    <div class="row g-3">
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

    <?php endif; ?>

    <!-- Antecedentes clínicos -->
    </div>
</section>
<section class="pf-sec">
    <div class="pf-sec-h"><i class="bi bi-clipboard2-pulse"></i> <?= et('Antecedentes clínicos') ?> <span class="pf-opc"><?= et('Opcional') ?></span></div>
    <div class="row g-3">
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
    <?php if (!es_veterinaria()): ?>
    <div class="col-md-6">
        <label class="form-label"><?= et('Antecedentes familiares') ?></label>
        <textarea name="antecedentes_familiares" class="form-control" rows="2"><?= $v('antecedentes_familiares') ?></textarea>
    </div>
    <?php endif; ?>
    <div class="col-md-6">
        <label class="form-label"><?= et('Cirugías') ?></label>
        <textarea name="cirugias" class="form-control" rows="2"><?= $v('cirugias') ?></textarea>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Vacunas') ?></label>
        <textarea name="vacunas" class="form-control" rows="2"><?= $v('vacunas') ?></textarea>
    </div>
    <div class="col-md-6">
        <?php if (es_veterinaria()): ?>
        <label class="form-label"><?= et('Alimentación y desparasitación') ?></label>
        <textarea name="habitos" class="form-control" rows="2" placeholder="<?= et('Croquetas, dieta, última desparasitación…') ?>"><?= $v('habitos') ?></textarea>
        <?php else: ?>
        <label class="form-label"><?= et('Hábitos') ?></label>
        <textarea name="habitos" class="form-control" rows="2" placeholder="<?= et('Tabaquismo, alcohol, ejercicio…') ?>"><?= $v('habitos') ?></textarea>
        <?php endif; ?>
    </div>
    <div class="col-md-6">
        <label class="form-label"><?= et('Notas') ?></label>
        <textarea name="notas" class="form-control" rows="2"><?= $v('notas') ?></textarea>
    </div>
    </div>
</section>
</div><!-- /.pf-main -->
</div><!-- /.pf-grid -->

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

<style>
.pf-grid { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 1.25rem; align-items: start; }
@media (max-width: 991.98px) { .pf-grid { grid-template-columns: 1fr; } }
.pf-side { position: sticky; top: 90px; display: grid; gap: 1rem; }
@media (max-width: 991.98px) { .pf-side { position: static; } }
.pf-card, .pf-sec { background: var(--bs-body-bg); border-radius: 18px; padding: 1.2rem; box-shadow: 0 1px 4px rgba(0,0,0,.04), 0 10px 28px rgba(15,30,60,.06); }
body.ui-glass .pf-card, body.ui-glass .pf-sec { background: var(--g-glass-2, #fff); box-shadow: var(--g-shadow-sm); backdrop-filter: var(--g-blur-sm); -webkit-backdrop-filter: var(--g-blur-sm); }
.pf-sec + .pf-sec { margin-top: 1rem; }
.pf-sec-h { display: flex; align-items: center; gap: .55rem; font-weight: 650; letter-spacing: -.01em; margin-bottom: 1rem; font-size: 1.02rem; }
.pf-sec-h .bi { width: 34px; height: 34px; border-radius: 11px; display: inline-flex; align-items: center; justify-content: center;
                color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, transparent); font-size: 1.05rem; }
.pf-opc { margin-left: auto; font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; opacity: .55;
          border: 1px solid rgba(127,127,127,.3); border-radius: 999px; padding: .15rem .55rem; }
.pf-side #fotoPreview, .pf-side #fotoPlaceholder { width: 120px !important; height: 120px !important; box-shadow: 0 10px 26px rgba(15,30,60,.15); border: 4px solid #fff !important; }
.pf-preview { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(127,127,127,.15); }
.pf-prev-n { font-weight: 700; font-size: 1.15rem; letter-spacing: -.02em; word-break: break-word; }
.pf-prev-s { font-size: .85rem; opacity: .65; min-height: 1.2em; }
.pf-ring { --p: 0; width: 62px; height: 62px; flex: none; border-radius: 50%; display: flex; align-items: center; justify-content: center;
           background: conic-gradient(var(--brand) calc(var(--p) * 1%), rgba(127,127,127,.15) 0); position: relative; transition: background .3s; }
.pf-ring::before { content: ''; position: absolute; inset: 6px; border-radius: 50%; background: var(--bs-body-bg); }
.pf-ring span { position: relative; font-weight: 750; font-size: .88rem; }
.pf-tip { background: color-mix(in srgb, var(--brand) 7%, var(--bs-body-bg)) !important; }
.pf-tip .bi { color: #f59e0b; }
.pf-bar { position: sticky; bottom: 12px; z-index: 20; margin-top: 1.25rem; display: flex; justify-content: flex-end; align-items: center; gap: .6rem;
          padding: .7rem .9rem; border-radius: 999px; background: rgba(255,255,255,.85); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
          box-shadow: 0 10px 30px rgba(15,30,60,.14); }
html.app-dark .pf-bar { background: rgba(30,30,36,.85); }
.pf-bar .pf-bar-txt { margin-right: auto; font-size: .85rem; opacity: .7; padding-left: .4rem; }
.pf-main .form-control:focus, .pf-main .form-select:focus { border-color: var(--brand); box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 22%, transparent); }
</style>
<script>
/* Vista previa y avance del expediente, en vivo. */
(function () {
    var main = document.querySelector('.pf-main');
    if (!main) return;
    var form = main.closest('form');
    var vet = <?= es_veterinaria() ? 'true' : 'false' ?>;
    var q = function (n) { var el = form.elements[n]; return el ? (el.value || '').trim() : ''; };
    var nombre = document.getElementById('pfNombre'), sub = document.getElementById('pfSub');
    var ring = document.getElementById('pfRing'), pct = document.getElementById('pfPct');
    var vacio = nombre.textContent;
    function edad(f) {
        if (!f) return '';
        var n = new Date(f), h = new Date(), a = h.getFullYear() - n.getFullYear();
        if (h < new Date(h.getFullYear(), n.getMonth(), n.getDate())) a--;
        return a >= 0 ? a + (a === 1 ? ' año' : ' años') : '';
    }
    function pinta() {
        var nm = vet ? q('nombre') : (q('nombre') + ' ' + q('apellidos')).trim();
        nombre.textContent = nm || vacio;
        var partes = vet ? [q('especie'), q('raza'), edad(q('fecha_nacimiento'))] : [edad(q('fecha_nacimiento')), q('telefono')];
        if (vet && (q('propietario') || q('apellidos'))) partes.push('Dueño: ' + (q('propietario') + ' ' + q('apellidos')).trim());
        sub.textContent = partes.filter(Boolean).join(' · ');
        var campos = main.querySelectorAll('input:not([type=hidden]):not([type=file]):not([type=checkbox]), select, textarea');
        var llenos = 0;
        campos.forEach(function (c) { if ((c.value || '').trim() !== '') llenos++; });
        var p = campos.length ? Math.round(llenos / campos.length * 100) : 0;
        ring.style.setProperty('--p', p); pct.textContent = p + '%';
    }
    form.addEventListener('input', pinta);
    form.addEventListener('change', pinta);
    pinta();
})();
</script>
