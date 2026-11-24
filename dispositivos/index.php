<?php
/**
 * Impresoras de tickets y escáneres de ESTA computadora.
 * Todo corre en el navegador (assets/js/dispositivos.js): no hay que instalar
 * programas en la PC. La configuración se guarda en el navegador porque los
 * aparatos están conectados a esta máquina, no al consultorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();

$titulo = t('Impresoras y escáneres');
$activo = 'dispositivos';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-printer text-brand"></i> <?= et('Impresoras y escáneres') ?></h1>
    <span class="small text-muted"><i class="bi bi-pc-display"></i> <?= et('Se configuran por computadora: lo que agregues aquí vale para este equipo y este navegador.') ?></span>
</div>

<div class="alert alert-warning d-none d-flex gap-2" id="dvNav">
    <i class="bi bi-browser-chrome fs-5"></i>
    <div><b id="dvNavT"></b><div class="small" id="dvNavD"></div></div>
</div>

<div class="row g-3">
<div class="col-xl-7">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="fw-semibold"><i class="bi bi-printer"></i> <?= et('Impresoras de tickets') ?></span>
        </div>
        <div class="card-body">
            <div id="dvImp"></div>
            <div class="small fw-semibold text-muted mt-3 mb-2" id="dvAddT"><?= et('Agregar impresora') ?></div>
            <div class="dv-add">
                <button type="button" class="dv-op" data-add="usb" data-rol="impresora"><i class="bi bi-usb-symbol"></i>
                    <span><b><?= et('USB') ?></b><small><?= et('Térmica conectada con cable USB.') ?></small><em data-no></em></span></button>
                <button type="button" class="dv-op" data-add="ble" data-rol="impresora"><i class="bi bi-bluetooth"></i>
                    <span><b><?= et('Bluetooth') ?></b><small><?= et('Portátiles y de mostrador con Bluetooth (BLE).') ?></small><em data-no></em></span></button>
                <button type="button" class="dv-op" data-add="serial" data-rol="impresora"><i class="bi bi-plug"></i>
                    <span><b><?= et('Puerto serie / COM') ?></b><small><?= et('Cable serie, USB-serie o Bluetooth ya emparejado en la PC.') ?></small><em data-no></em></span></button>
                <button type="button" class="dv-op" data-add="sistema" data-rol="impresora"><i class="bi bi-pc-display-horizontal"></i>
                    <span><b><?= et('Instalada en la PC') ?></b><small><?= et('Cualquier impresora con su controlador en esta computadora (tickets, láser o tinta).') ?></small></span></button>
            </div>
        </div>
    </div>

    <div class="card mb-3" id="dvDetCard" hidden>
        <div class="card-header fw-semibold"><i class="bi bi-search"></i> <?= et('Conectados y autorizados en este navegador') ?></div>
        <div class="card-body" id="dvDet"></div>
    </div>
</div>

<div class="col-xl-5">
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-upc-scan"></i> <?= et('Escáneres') ?></span>
            <button class="btn btn-outline-secondary btn-sm" data-disp-camara><i class="bi bi-camera"></i> <?= et('Usar la cámara') ?></button>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="opTeclado">
                <label class="form-check-label" for="opTeclado"><b><?= et('Lectores USB o Bluetooth tipo teclado') ?></b></label>
            </div>
            <p class="small text-muted"><?= et('La mayoría de los lectores de código de barras y QR funcionan así: no hay que agregarlos. Conéctalo y escanea en cualquier pantalla; el sistema reconoce la lectura por su velocidad.') ?></p>
            <div class="dv-prueba" id="dvPrueba">
                <i class="bi bi-upc-scan"></i>
                <div><div class="fw-semibold" id="dvCod"><?= et('Escanea cualquier código para probar') ?></div>
                    <div class="small text-muted" id="dvOrigen"><?= et('Aquí aparece lo que lea el lector.') ?></div></div>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small fw-semibold"><?= et('Lectores en modo serie (COM)') ?></span>
                <button class="btn btn-sm btn-outline-primary" data-add="serial" data-rol="escaner"><i class="bi bi-plus"></i> <?= et('Agregar') ?></button>
            </div>
            <div id="dvEsc" class="small"></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-sliders"></i> <?= et('Al cobrar') ?></div>
        <div class="card-body">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" id="opCobrar">
                <label class="form-check-label" for="opCobrar"><?= et('Imprimir el ticket solo al cobrar en Punto de venta') ?></label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="opCajon">
                <label class="form-check-label" for="opCajon"><?= et('Abrir el cajón de dinero en cobros en efectivo') ?></label>
            </div>
            <div class="form-text"><?= et('El cajón va conectado a la impresora de tickets (cable RJ11) y se abre con impresoras USB, serie o Bluetooth.') ?></div>
        </div>
    </div>

    <div class="accordion" id="dvAyuda">
        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#dvA1"><?= et('Mi impresora USB no aparece o no imprime (Windows)') ?></button></h2>
            <div id="dvA1" class="accordion-collapse collapse" data-bs-parent="#dvAyuda"><div class="accordion-body small">
                <p><?= et('En Windows, cuando la impresora tiene instalado su controlador, Windows la "aparta" y el navegador no puede hablarle directo. Tienes tres caminos:') ?></p>
                <ol class="mb-0">
                    <li><?= et('Agrégala como') ?> <b><?= et('Impresora instalada en la PC') ?></b>: <?= et('funciona siempre. Elige el papel de 58 u 80 mm en el cuadro de impresión.') ?></li>
                    <li><?= et('Si es Bluetooth, emparéjala en Windows y agrégala como') ?> <b><?= et('Puerto serie / COM') ?></b>.</li>
                    <li><?= et('Avanzado: cambia su controlador a WinUSB con la herramienta Zadig y agrégala como USB. Así se imprime sin el cuadro de diálogo y abre el cajón.') ?></li>
                </ol>
            </div></div>
        </div>
        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#dvA2"><?= et('Salen letras raras o sin acentos') ?></button></h2>
            <div id="dvA2" class="accordion-collapse collapse" data-bs-parent="#dvAyuda"><div class="accordion-body small">
                <?= et('Cambia "Acentos" en la impresora: prueba CP850, luego Windows-1252, y si ninguna sale bien usa "Sin acentos". Imprime un ticket de prueba después de cada cambio.') ?>
            </div></div>
        </div>
        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#dvA3"><?= et('Imprimir sin que aparezca el cuadro de impresión') ?></button></h2>
            <div id="dvA3" class="accordion-collapse collapse" data-bs-parent="#dvAyuda"><div class="accordion-body small">
                <?= et('Con USB, serie o Bluetooth el ticket sale directo. Con "Impresora instalada en la PC" el navegador siempre pregunta; en una caja dedicada puedes abrir Chrome con la opción --kiosk-printing para que imprima solo en la impresora predeterminada de Windows.') ?>
            </div></div>
        </div>
    </div>
</div>
</div>

<style>
.dv-add { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: .6rem; }
.dv-op { display: flex; gap: .7rem; align-items: flex-start; text-align: left; border: 1px solid rgba(127,127,127,.22); background: transparent;
         border-radius: 14px; padding: .75rem .85rem; color: inherit; transition: border-color .2s, background .2s; }
.dv-op > .bi { font-size: 1.25rem; color: var(--brand); margin-top: .05rem; }
.dv-op b { display: block; }
.dv-op small { display: block; color: var(--bs-secondary-color, #64748b); line-height: 1.35; }
.dv-op em { display: block; font-style: normal; font-size: .75rem; font-weight: 600; color: #b45309; margin-top: .25rem; }
.dv-op em:empty { display: none; }
.dv-op:hover:not(:disabled) { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 6%, transparent); }
.dv-op:disabled { opacity: .6; cursor: not-allowed; }
.dv-op:disabled > .bi { color: inherit; }
.dv-dev { border: 1px solid rgba(127,127,127,.2); border-radius: 14px; padding: .9rem 1rem; }
.dv-dev + .dv-dev { margin-top: .75rem; }
.dv-dev.pred { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 12%, transparent); }
.dv-ic { width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.2rem;
         color: var(--brand); background: color-mix(in srgb, var(--brand) 11%, transparent); flex: none; }
.dv-est { font-size: .72rem; font-weight: 600; border-radius: 999px; padding: .15rem .55rem; white-space: nowrap; }
.dv-est.ok { background: rgba(22,163,74,.12); color: #15803d; }
.dv-est.warn { background: rgba(217,119,6,.14); color: #b45309; }
.dv-est.off { background: rgba(127,127,127,.15); color: #64748b; }
.dv-prueba { display: flex; gap: .8rem; align-items: center; padding: .9rem 1rem; border-radius: 14px; border: 1px dashed rgba(127,127,127,.35); transition: background .3s, border-color .3s; }
.dv-prueba > .bi { font-size: 1.6rem; color: var(--brand); }
.dv-prueba.leido { background: rgba(22,163,74,.1); border-color: #16a34a; border-style: solid; }
#dvCod { word-break: break-all; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var D = window.MediosDisp;
    if (!D) return;
    var S = D.soporte;
    var TIPOS = { usb: ['bi-usb-symbol', 'USB'], ble: ['bi-bluetooth', 'Bluetooth'], serial: ['bi-plug', 'Serie / COM'], sistema: ['bi-pc-display-horizontal', 'Instalada en la PC'] };
    var ESTADOS = { conectada: ['ok', 'Conectada'], detectada: ['ok', 'Detectada'], lista: ['ok', 'Lista'], reconectar: ['warn', 'Volver a vincular'], ausente: ['off', 'No conectada'] };
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    /* ¿Qué navegador es? Safari y Firefox no permiten USB, serie ni Bluetooth;
       Brave los trae apagados. Se dice claro qué hacer en cada caso. */
    var ua = navigator.userAgent, nav = navigator.brave ? 'Brave' : /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera'
        : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'este navegador';
    var movilApple = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (!S.usb || !S.ble || !S.serial) {
        var falta = [!S.usb && 'USB', !S.ble && 'Bluetooth', !S.serial && 'puerto serie'].filter(Boolean).join(', ');
        var como = movilApple ? 'En iPhone y iPad ningún navegador lo permite (Apple lo bloquea). Usa una computadora o un celular Android con Chrome.'
            : nav === 'Brave' ? 'Brave los trae apagados. Ábrelo en Google Chrome o Microsoft Edge.'
            : nav === 'Safari' || nav === 'Firefox' ? 'Ábrelo en Google Chrome o Microsoft Edge en esta misma computadora.'
            : 'Actualiza el navegador o ábrelo en Google Chrome o Microsoft Edge.';
        document.getElementById('dvNavT').textContent = nav + ' no permite conectar impresoras por ' + falta + '.';
        document.getElementById('dvNavD').textContent = como + ' Mientras tanto, "Instalada en la PC" funciona aquí mismo.';
        document.getElementById('dvNav').classList.remove('d-none');
    }
    document.querySelectorAll('[data-add]').forEach(function (b) {
        var t = b.dataset.add;
        if ((t === 'usb' && !S.usb) || (t === 'serial' && !S.serial) || (t === 'ble' && !S.ble)) {
            b.disabled = true;
            var em = b.querySelector('[data-no]'); if (em) em.textContent = 'No disponible en ' + nav + ' · usa Chrome o Edge';
        }
        b.addEventListener('click', function () {
            D.agregar(t, b.dataset.rol).then(function (d) {
                pintar();
                if (d.rol === 'impresora') D.aviso('Impresora agregada', 'Imprime un ticket de prueba para revisar acentos y QR.');
            }).catch(function (e) {
                if (e && e.name === 'NotFoundError') return;   // cerró el selector sin elegir
                D.aviso('No se pudo agregar', esc(e && e.message || e));
            });
        });
    });

    function tarjeta(d) {
        var ic = TIPOS[d.tipo] || TIPOS.sistema;
        var h = '<div class="dv-dev' + (d.predeterminada ? ' pred' : '') + '" data-clave="' + esc(d.clave) + '">'
            + '<div class="d-flex gap-3 align-items-start"><span class="dv-ic"><i class="bi ' + ic[0] + '"></i></span><div class="flex-grow-1 min-w-0">'
            + '<div class="d-flex align-items-center gap-2 flex-wrap"><input class="form-control form-control-sm fw-semibold border-0 px-0 dv-nom" style="max-width:260px;background:transparent" value="' + esc(d.nombre) + '">'
            + '<span class="dv-est off" data-est>…</span>' + (d.predeterminada ? '<span class="badge text-bg-primary">Predeterminada</span>' : '') + '</div>'
            + '<div class="small text-muted">' + ic[1] + '</div>'
            + '<div class="row g-2 mt-1">'
            + '<div class="col-6 col-md-3"><label class="form-label small mb-0">Papel</label><select class="form-select form-select-sm" data-k="ancho"><option value="58"' + (d.ancho == 58 ? ' selected' : '') + '>58 mm</option><option value="80"' + (d.ancho == 80 ? ' selected' : '') + '>80 mm</option></select></div>'
            + (d.tipo !== 'sistema' ? '<div class="col-6 col-md-3"><label class="form-label small mb-0">Acentos</label><select class="form-select form-select-sm" data-k="codif">'
                + [['cp850', 'CP850'], ['1252', 'Windows-1252'], ['ascii', 'Sin acentos']].map(function (o) { return '<option value="' + o[0] + '"' + (d.codif === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></div>' : '')
            + (d.tipo === 'serial' ? '<div class="col-6 col-md-3"><label class="form-label small mb-0">Velocidad</label><select class="form-select form-select-sm" data-k="baud">'
                + [9600, 19200, 38400, 57600, 115200].map(function (b) { return '<option' + (+d.baud === b ? ' selected' : '') + '>' + b + '</option>'; }).join('') + '</select></div>' : '')
            + (d.tipo !== 'sistema' ? '<div class="col-12 d-flex gap-3 flex-wrap small">'
                + '<label class="form-check"><input class="form-check-input" type="checkbox" data-k="corte"' + (d.corte !== false ? ' checked' : '') + '> Cortar papel</label>'
                + '<label class="form-check"><input class="form-check-input" type="checkbox" data-k="cajon"' + (d.cajon ? ' checked' : '') + '> Tiene cajón de dinero</label></div>' : '')
            + '</div>'
            + '<div class="d-flex gap-2 flex-wrap mt-2">'
            + '<button class="btn btn-sm btn-outline-primary" data-acc="prueba"><i class="bi bi-receipt"></i> Ticket de prueba</button>'
            + (!d.predeterminada ? '<button class="btn btn-sm btn-outline-secondary" data-acc="pred"><i class="bi bi-star"></i> Predeterminada</button>' : '')
            + (d.cajon && d.tipo !== 'sistema' ? '<button class="btn btn-sm btn-outline-secondary" data-acc="cajon"><i class="bi bi-safe"></i> Abrir cajón</button>' : '')
            + (d.tipo === 'ble' ? '<button class="btn btn-sm btn-outline-secondary" data-acc="vincular"><i class="bi bi-bluetooth"></i> Volver a vincular</button>' : '')
            + '<button class="btn btn-sm btn-outline-danger ms-auto" data-acc="quitar"><i class="bi bi-trash"></i></button>'
            + '</div><div class="small mt-2" data-msg></div></div></div></div>';
        return h;
    }

    function pintar() {
        var l = D.lista(), imp = l.filter(function (d) { return d.rol === 'impresora'; }), esc_ = l.filter(function (d) { return d.rol === 'escaner'; });
        var box = document.getElementById('dvImp');
        box.innerHTML = imp.length ? imp.map(tarjeta).join('')
            : '<div class="text-center text-muted py-3"><i class="bi bi-printer fs-2 d-block mb-2"></i>Todavía no hay impresoras en esta computadora.<br>Elige abajo cómo está conectada: el navegador te muestra las que encuentre para que escojas.</div>';
        document.getElementById('dvAddT').textContent = imp.length ? 'Agregar otra impresora' : 'Agregar impresora';
        box.querySelectorAll('.dv-dev').forEach(function (el) {
            var clave = el.dataset.clave, d = l.find(function (x) { return x.clave === clave; });
            D.estado(d).then(function (s) { var e = ESTADOS[s] || ESTADOS.ausente, b = el.querySelector('[data-est]'); b.className = 'dv-est ' + e[0]; b.textContent = e[1]; });
            el.querySelector('.dv-nom').addEventListener('change', function () { D.actualizar(clave, { nombre: this.value.trim() || d.nombre }); });
            el.querySelectorAll('[data-k]').forEach(function (inp) {
                inp.addEventListener('change', function () {
                    var c = {}; c[inp.dataset.k] = inp.type === 'checkbox' ? inp.checked : (inp.dataset.k === 'codif' ? inp.value : +inp.value);
                    D.actualizar(clave, c); if (inp.dataset.k === 'cajon') pintar();
                });
            });
            el.querySelectorAll('[data-acc]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var msg = el.querySelector('[data-msg]'), acc = b.dataset.acc;
                    var ok = function (t) { msg.className = 'small mt-2 text-success'; msg.textContent = t; pintarEstado(el, d); };
                    var mal = function (e) { msg.className = 'small mt-2 text-danger'; msg.textContent = e && e.message || e; };
                    if (acc === 'prueba') { msg.className = 'small mt-2 text-muted'; msg.textContent = 'Enviando…'; D.prueba(clave).then(function () { ok('Ticket enviado.'); }, mal); }
                    if (acc === 'pred') { D.actualizar(clave, { predeterminada: true }); pintar(); }
                    if (acc === 'cajon') D.abrirCajon(clave).then(function () { ok('Cajón abierto.'); }, mal);
                    if (acc === 'quitar' && confirm('¿Quitar "' + d.nombre + '" de esta computadora?')) { D.quitar(clave); pintar(); }
                    if (acc === 'vincular') D.agregar('ble', 'impresora').then(function (n) {
                        if (n.clave !== clave) D.quitar(clave);
                        pintar();
                    }).catch(function (e) { if (e.name !== 'NotFoundError') mal(e); });
                });
            });
        });
        document.getElementById('dvEsc').innerHTML = esc_.length ? esc_.map(function (d) {
            return '<div class="d-flex align-items-center gap-2 py-1" data-clave="' + esc(d.clave) + '"><i class="bi bi-upc-scan"></i><span class="flex-grow-1">' + esc(d.nombre) + ' · ' + (d.baud || 9600) + '</span>'
                + '<span class="dv-est off" data-est>…</span><button class="btn btn-sm btn-link text-danger p-0" data-quitar><i class="bi bi-x-lg"></i></button></div>';
        }).join('') : '<span class="text-muted">' + (S.serial ? 'Ninguno. Solo si tu lector está configurado en modo serie.' : 'No disponible en este navegador.') + '</span>';
        document.querySelectorAll('#dvEsc [data-clave]').forEach(function (el) {
            var d = esc_.find(function (x) { return x.clave === el.dataset.clave; });
            D.estado(d).then(function (s) { var e = ESTADOS[s] || ESTADOS.ausente, b = el.querySelector('[data-est]'); b.className = 'dv-est ' + e[0]; b.textContent = e[1]; });
            el.querySelector('[data-quitar]').onclick = function () { D.quitar(d.clave); pintar(); };
        });
        D.detectados().then(function (ds) {
            var mias = {}; l.forEach(function (x) { mias[x.clave] = 1; });
            var nuevos = ds.filter(function (x) { return !mias[x.clave]; });
            document.getElementById('dvDetCard').hidden = !nuevos.length;
            document.getElementById('dvDet').innerHTML = nuevos.map(function (x, i) {
                return '<div class="d-flex align-items-center gap-2 py-1"><i class="bi ' + (TIPOS[x.tipo] || TIPOS.usb)[0] + '"></i><span class="flex-grow-1">' + esc(x.nombre) + '</span>'
                    + '<button class="btn btn-sm btn-outline-primary" data-adopt="' + i + '" data-rol="impresora">Usar como impresora</button>'
                    + (x.tipo === 'serial' ? '<button class="btn btn-sm btn-outline-secondary" data-adopt="' + i + '" data-rol="escaner">Escáner</button>' : '') + '</div>';
            }).join('');
            document.querySelectorAll('[data-adopt]').forEach(function (b) {
                b.onclick = function () { D.adoptar(nuevos[+b.dataset.adopt], b.dataset.rol); pintar(); };
            });
        });
    }
    function pintarEstado(el, d) {
        D.estado(d).then(function (s) { var e = ESTADOS[s] || ESTADOS.ausente, b = el.querySelector('[data-est]'); if (b) { b.className = 'dv-est ' + e[0]; b.textContent = e[1]; } });
    }

    // Opciones
    var o = D.opciones();
    [['opTeclado', 'escanerTeclado'], ['opCobrar', 'imprimirAlCobrar'], ['opCajon', 'cajonEfectivo']].forEach(function (p) {
        var el = document.getElementById(p[0]); el.checked = !!o[p[1]];
        el.addEventListener('change', function () { var c = {}; c[p[1]] = el.checked; D.guardarOpciones(c); });
    });

    // En esta pantalla las lecturas solo se muestran (no navegan).
    var ORIG = { teclado: 'Lector tipo teclado', serie: 'Lector en modo serie', camara: 'Cámara' };
    document.addEventListener('medios:escaneo', function (e) {
        e.preventDefault();
        document.getElementById('dvCod').textContent = e.detail.codigo;
        document.getElementById('dvOrigen').textContent = (ORIG[e.detail.origen] || e.detail.origen) + ' · ' + e.detail.codigo.length + ' caracteres · ' + new Date().toLocaleTimeString('es-MX');
        var p = document.getElementById('dvPrueba'); p.classList.add('leido'); setTimeout(function () { p.classList.remove('leido'); }, 1500);
    });
    document.addEventListener('medios:dispositivos', pintar);
    pintar();
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
