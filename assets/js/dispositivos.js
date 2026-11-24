/* =====================================================================
 *  Impresoras de tickets y escáneres, directo desde el navegador.
 *
 *  Sin programas que instalar en la PC (a diferencia de un "puente" local):
 *    · USB        → WebUSB         (Chrome/Edge en Windows, Mac, Android)
 *    · Serie/COM  → Web Serial     (USB-serie y Bluetooth clásico emparejado)
 *    · Bluetooth  → Web Bluetooth  (impresoras BLE, típicas portátiles)
 *    · Sistema    → diálogo de impresión con el ticket a 58/80 mm (cualquier
 *                   impresora con su controlador; funciona en todo navegador)
 *  Se habla ESC/POS: acentos (CP850 o 1252), negritas, tamaños, QR nativo,
 *  corte de papel y apertura de cajón de dinero.
 *
 *  Escáneres:
 *    · Lectores USB/Bluetooth tipo teclado: se detectan por la velocidad de
 *      tecleo (nadie escribe 10 caracteres en 150 ms) en cualquier pantalla.
 *    · Lectores en modo serie (COM): se conectan aquí y leen por línea.
 *    · Cámara del equipo: BarcodeDetector o jsQR como respaldo.
 *  Cada lectura dispara el evento `medios:escaneo` ({detail:{codigo, origen}}).
 *  Si ninguna pantalla lo atiende, se resuelve aquí (receta, producto…).
 *
 *  La configuración vive en ESTE navegador (localStorage): los aparatos están
 *  conectados a esta computadora, no al consultorio.
 * ===================================================================== */
(function () {
    'use strict';
    var CFG = window.MEDIOS_DISP || { base: '', csrf: '' };
    var LS  = 'medios.dispositivos.v1';
    var OPT = 'medios.dispositivos.opciones.v1';

    /* ── Almacenamiento ─────────────────────────────────────────────── */
    function leer(k, def) { try { var v = JSON.parse(localStorage.getItem(k)); return v == null ? def : v; } catch (e) { return def; } }
    function escribir(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    function lista() { return leer(LS, []); }
    function guardarLista(l) { escribir(LS, l); emitir('medios:dispositivos'); }
    function opciones() {
        return Object.assign({ imprimirAlCobrar: false, cajonEfectivo: false, escanerTeclado: true }, leer(OPT, {}));
    }
    function guardarOpciones(o) { escribir(OPT, Object.assign(opciones(), o)); }
    function emitir(n, d) { document.dispatchEvent(new CustomEvent(n, { detail: d })); }

    var soporte = {
        usb: 'usb' in navigator,
        serial: 'serial' in navigator,
        ble: 'bluetooth' in navigator,
        camara: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia),
        detector: 'BarcodeDetector' in window
    };

    /* ── Identidad de cada aparato (para reconocerlo al volver) ─────── */
    function hex(n) { return ('0000' + (n || 0).toString(16)).slice(-4); }
    function claveUsb(d) { return 'usb:' + hex(d.vendorId) + ':' + hex(d.productId) + ':' + (d.serialNumber || ''); }
    function claveSerial(p, i) {
        var f = p.getInfo ? p.getInfo() : {};
        if (f.bluetoothServiceClassId) return 'serial:bt:' + f.bluetoothServiceClassId + ':' + (i || 0);
        return 'serial:' + hex(f.usbVendorId) + ':' + hex(f.usbProductId) + ':' + (i || 0);
    }
    function nombreUsb(d) { return [d.manufacturerName, d.productName].filter(Boolean).join(' ') || ('USB ' + hex(d.vendorId) + ':' + hex(d.productId)); }
    function nombreSerial(p) {
        var f = p.getInfo ? p.getInfo() : {};
        if (f.bluetoothServiceClassId) return 'Bluetooth (puerto serie)';
        return f.usbVendorId ? 'Serie USB ' + hex(f.usbVendorId) + ':' + hex(f.usbProductId) : 'Puerto serie';
    }

    /* ── Conexiones abiertas (por clave) ────────────────────────────── */
    var abiertos = {};

    // USB: busca la interfaz con un endpoint de salida "bulk" (clase impresora primero).
    function abrirUsb(dev) {
        var p = dev.opened ? Promise.resolve() : dev.open();
        return p.then(function () {
            if (!dev.configuration) return dev.selectConfiguration(1);
        }).then(function () {
            var mejor = null;
            dev.configuration.interfaces.forEach(function (itf) {
                itf.alternates.forEach(function (alt) {
                    alt.endpoints.forEach(function (ep) {
                        if (ep.direction === 'out' && ep.type === 'bulk') {
                            var cand = { itf: itf.interfaceNumber, ep: ep.endpointNumber, impresora: alt.interfaceClass === 7 };
                            if (!mejor || (cand.impresora && !mejor.impresora)) mejor = cand;
                        }
                    });
                });
            });
            if (!mejor) throw new Error('El aparato USB no tiene una salida de datos para imprimir.');
            return dev.claimInterface(mejor.itf).catch(function (e) {
                if (/claim|protected|access/i.test(String(e))) {
                    throw new Error('Windows tiene tomada la impresora con su controlador. Usa la opción "Impresora del sistema" o "Serie", o cambia el controlador a WinUSB.');
                }
                throw e;
            }).then(function () { return mejor; });
        }).then(function (m) {
            return {
                escribir: function (bytes) {
                    var i = 0, T = 4096;
                    function sig() {
                        if (i >= bytes.length) return Promise.resolve();
                        var trozo = bytes.slice(i, i + T); i += T;
                        return dev.transferOut(m.ep, trozo).then(sig);
                    }
                    return sig();
                },
                cerrar: function () { return dev.close().catch(function () {}); }
            };
        });
    }

    function abrirSerial(port, baud, alLeer) {
        var p = port.readable ? Promise.resolve() : port.open({ baudRate: +baud || 9600 });
        return p.then(function () {
            var lector = null, activo = true;
            if (alLeer && port.readable) {
                var dec = new TextDecoder(), resto = '';
                lector = port.readable.getReader();
                (function bucle() {
                    lector.read().then(function (r) {
                        if (r.done || !activo) return;
                        resto += dec.decode(r.value, { stream: true });
                        var partes = resto.split(/\r\n|\r|\n/);
                        resto = partes.pop();
                        partes.forEach(function (l) { l = l.trim(); if (l) alLeer(l); });
                        bucle();
                    }).catch(function () {});
                })();
            }
            return {
                escribir: function (bytes) {
                    var w = port.writable.getWriter();
                    return w.write(bytes).finally(function () { w.releaseLock(); });
                },
                cerrar: function () {
                    activo = false;
                    var c = lector ? lector.cancel().catch(function () {}).then(function () { lector.releaseLock(); }) : Promise.resolve();
                    return c.then(function () { return port.close(); }).catch(function () {});
                }
            };
        });
    }

    // Servicios BLE que usan las impresoras térmicas más comunes.
    var BLE_SERVICIOS = [
        '000018f0-0000-1000-8000-00805f9b34fb', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
        '49535343-fe7d-4ae5-8fa9-9fafd205e455', '0000ff00-0000-1000-8000-00805f9b34fb',
        '0000ffe0-0000-1000-8000-00805f9b34fb', '0000fee7-0000-1000-8000-00805f9b34fb',
        '0000ae30-0000-1000-8000-00805f9b34fb'
    ];
    function abrirBle(dev) {
        return dev.gatt.connect().then(function (srv) { return srv.getPrimaryServices(); }).then(function (svcs) {
            return Promise.all(svcs.map(function (s) { return s.getCharacteristics().catch(function () { return []; }); }));
        }).then(function (grupos) {
            var ch = null;
            grupos.forEach(function (g) { g.forEach(function (c) {
                if (!ch && (c.properties.writeWithoutResponse || c.properties.write)) ch = c;
            }); });
            if (!ch) throw new Error('No se encontró por dónde mandarle datos a esta impresora Bluetooth.');
            return {
                escribir: function (bytes) {
                    var i = 0, T = 180;
                    function sig() {
                        if (i >= bytes.length) return Promise.resolve();
                        var trozo = bytes.slice(i, i + T); i += T;
                        var w = ch.properties.writeWithoutResponse && ch.writeValueWithoutResponse
                            ? ch.writeValueWithoutResponse(trozo) : ch.writeValue(trozo);
                        return w.then(function () { return new Promise(function (r) { setTimeout(r, 20); }); }).then(sig);
                    }
                    return sig();
                },
                cerrar: function () { try { dev.gatt.disconnect(); } catch (e) {} return Promise.resolve(); }
            };
        });
    }

    /* ── Buscar el objeto real (USBDevice, SerialPort…) de un aparato guardado ── */
    function encontrar(d) {
        if (d.tipo === 'usb' && soporte.usb) {
            return navigator.usb.getDevices().then(function (ds) {
                return ds.find(function (x) { return claveUsb(x) === d.clave; }) || null;
            });
        }
        if (d.tipo === 'serial' && soporte.serial) {
            return navigator.serial.getPorts().then(function (ps) {
                var cuenta = {};
                for (var i = 0; i < ps.length; i++) {
                    var base = claveSerial(ps[i], 0).replace(/:\d+$/, '');
                    cuenta[base] = (cuenta[base] || 0);
                    if (base + ':' + cuenta[base] === d.clave) return ps[i];
                    cuenta[base]++;
                }
                return null;
            });
        }
        if (d.tipo === 'ble' && soporte.ble) {
            if (abiertos[d.clave] && abiertos[d.clave].obj) return Promise.resolve(abiertos[d.clave].obj);
            if (!navigator.bluetooth.getDevices) return Promise.resolve(null);
            return navigator.bluetooth.getDevices().then(function (ds) {
                return ds.find(function (x) { return 'ble:' + x.id === d.clave; }) || null;
            }).catch(function () { return null; });
        }
        return Promise.resolve(d.tipo === 'sistema' ? true : null);
    }

    function conexion(d) {
        var a = abiertos[d.clave];
        if (a && a.con) return Promise.resolve(a.con);
        return encontrar(d).then(function (obj) {
            if (!obj) throw new Error('No encuentro "' + d.nombre + '" en esta computadora. Revisa que esté prendida y conectada, o vuelve a agregarla.');
            var p = d.tipo === 'usb' ? abrirUsb(obj)
                  : d.tipo === 'serial' ? abrirSerial(obj, d.baud, d.rol === 'escaner' ? function (l) { escaneo(l, 'serie'); } : null)
                  : abrirBle(obj);
            return p.then(function (con) { abiertos[d.clave] = { con: con, obj: obj }; return con; });
        });
    }

    function estado(d) {
        if (d.tipo === 'sistema') return Promise.resolve('lista');
        if (abiertos[d.clave] && abiertos[d.clave].con) return Promise.resolve('conectada');
        return encontrar(d).then(function (o) { return o ? 'detectada' : (d.tipo === 'ble' ? 'reconectar' : 'ausente'); })
            .catch(function () { return 'ausente'; });
    }

    /* ── Agregar aparatos (requieren un clic del usuario) ───────────── */
    function agregar(tipo, rol) {
        rol = rol || 'impresora';
        var p;
        if (tipo === 'usb') {
            p = navigator.usb.requestDevice({ filters: [] }).then(function (dev) {
                return { clave: claveUsb(dev), tipo: 'usb', nombre: nombreUsb(dev) };
            });
        } else if (tipo === 'serial') {
            p = navigator.serial.requestPort({ allowedBluetoothServiceClassIds: ['00001101-0000-1000-8000-00805f9b34fb'] })
                .catch(function (e) { if (e && e.name === 'TypeError') return navigator.serial.requestPort(); throw e; })
                .then(function (port) {
                    return navigator.serial.getPorts().then(function (ps) {
                        var base = claveSerial(port, 0).replace(/:\d+$/, ''), n = 0;
                        for (var i = 0; i < ps.length; i++) {
                            if (ps[i] === port) break;
                            if (claveSerial(ps[i], 0).replace(/:\d+$/, '') === base) n++;
                        }
                        return { clave: base + ':' + n, tipo: 'serial', nombre: nombreSerial(port), baud: 9600 };
                    });
                });
        } else if (tipo === 'ble') {
            p = navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BLE_SERVICIOS }).then(function (dev) {
                var d = { clave: 'ble:' + dev.id, tipo: 'ble', nombre: dev.name || 'Impresora Bluetooth' };
                abiertos[d.clave] = { obj: dev };
                return d;
            });
        } else {
            p = Promise.resolve({ clave: 'sistema:' + Date.now(), tipo: 'sistema', nombre: 'Impresora del sistema' });
        }
        return p.then(function (d) {
            var l = lista().filter(function (x) { return x.clave !== d.clave; });
            d.rol = rol;
            if (rol === 'impresora') {
                d.ancho = 58; d.codif = 'cp850'; d.corte = true; d.cajon = false;
                d.predeterminada = !l.some(function (x) { return x.rol === 'impresora' && x.predeterminada; });
            }
            l.push(d);
            guardarLista(l);
            if (rol === 'escaner') conexion(d).catch(function () {});
            return d;
        });
    }
    function actualizar(clave, cambios) {
        var l = lista();
        l.forEach(function (x) {
            if (cambios.predeterminada && x.rol === 'impresora') x.predeterminada = false;
            if (x.clave === clave) Object.assign(x, cambios);
        });
        guardarLista(l);
        if (abiertos[clave] && ('baud' in cambios)) { abiertos[clave].con && abiertos[clave].con.cerrar(); delete abiertos[clave]; }
    }
    function quitar(clave) {
        var a = abiertos[clave];
        if (a && a.con) a.con.cerrar();
        delete abiertos[clave];
        var l = lista(), d = l.find(function (x) { return x.clave === clave; });
        l = l.filter(function (x) { return x.clave !== clave; });
        if (d && d.predeterminada) { var otra = l.find(function (x) { return x.rol === 'impresora'; }); if (otra) otra.predeterminada = true; }
        guardarLista(l);
        // Olvidar el permiso del navegador cuando se puede.
        if (d && a && a.obj && a.obj.forget) a.obj.forget().catch(function () {});
    }
    function predeterminada() {
        var l = lista().filter(function (x) { return x.rol === 'impresora'; });
        return l.find(function (x) { return x.predeterminada; }) || l[0] || null;
    }

    /* ── ESC/POS ────────────────────────────────────────────────────── */
    var CP850 = { 'Ç':0x80,'ü':0x81,'é':0x82,'â':0x83,'ä':0x84,'à':0x85,'ç':0x87,'ê':0x88,'ë':0x89,'è':0x8a,'ï':0x8b,'î':0x8c,
        'ì':0x8d,'Ä':0x8e,'É':0x90,'ô':0x93,'ö':0x94,'ò':0x95,'û':0x96,'ù':0x97,'Ö':0x99,'Ü':0x9a,'á':0xa0,'í':0xa1,'ó':0xa2,
        'ú':0xa3,'ñ':0xa4,'Ñ':0xa5,'ª':0xa6,'º':0xa7,'¿':0xa8,'¡':0xad,'Á':0xb5,'Â':0xb6,'À':0xb7,'Í':0xd6,'Ó':0xe0,'Ú':0xe9,
        '°':0xf8,'·':0xfa };
    var SUST = { '—':'-', '–':'-', '“':'"', '”':'"', '‘':"'", '’':"'", '…':'...', '€':'EUR', '•':'*', '×':'x' };
    function sinAcentos(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function codificar(txt, codif) {
        var out = [];
        String(txt).split('').forEach(function (ch) {
            if (SUST[ch]) ch = SUST[ch];
            for (var k = 0; k < ch.length; k++) {
                var c = ch[k], n = c.charCodeAt(0);
                if (n < 128) { out.push(n); continue; }
                if (codif === 'cp850' && CP850[c] != null) { out.push(CP850[c]); continue; }
                if (codif === '1252' && n < 256) { out.push(n); continue; }
                var a = sinAcentos(c);
                out.push(a.charCodeAt(0) < 128 ? a.charCodeAt(0) : 63);
            }
        });
        return out;
    }

    function Ticket(dev) {
        this.dev = dev || {};
        this.cols = (this.dev.ancho == 80) ? 48 : 32;
        this.b = [0x1b, 0x40];   // ESC @ (reinicia)
        if (this.dev.codif === 'cp850') this.b.push(0x1b, 0x74, 2);
        else if (this.dev.codif === '1252') this.b.push(0x1b, 0x74, 16);
        this.html = [];
        this._al = 'l';
    }
    Ticket.prototype = {
        raw: function (arr) { Array.prototype.push.apply(this.b, arr); return this; },
        alinear: function (a) { this._al = a; return this.raw([0x1b, 0x61, a === 'c' ? 1 : a === 'r' ? 2 : 0]); },
        negrita: function (on) { this._neg = !!on; return this.raw([0x1b, 0x45, on ? 1 : 0]); },
        tamano: function (n) { this._tam = n; return this.raw([0x1d, 0x21, n === 2 ? 0x11 : n === 'w' ? 0x10 : 0]); },
        texto: function (t) {
            this.raw(codificar(t, this.dev.codif || 'cp850'));
            this.html.push('<div class="' + this._al + (this._neg ? ' b' : '') + (this._tam === 2 ? ' g' : '') + '">' + esc(t) + '</div>');
            return this;
        },
        linea: function (t) { return this.texto(t == null ? '' : t).raw([0x0a]); },
        separador: function (c) { var s = Array(this.cols + 1).join(c || '-'); this.raw(codificar(s, 'ascii')).raw([0x0a]); this.html.push('<hr>'); return this; },
        // Dos columnas: texto a la izquierda y monto a la derecha.
        par: function (izq, der) {
            izq = String(izq); der = String(der);
            var ancho = this.cols - der.length - 1;
            var lineas = partir(izq, ancho);
            for (var i = 0; i < lineas.length; i++) {
                var l = lineas[i];
                if (i === lineas.length - 1) l = l + Array(Math.max(1, this.cols - l.length - der.length) + 1).join(' ') + der;
                this.raw(codificar(l, this.dev.codif || 'cp850')).raw([0x0a]);
            }
            this.html.push('<div class="par"><span>' + esc(izq) + '</span><span>' + esc(der) + '</span></div>');
            return this;
        },
        envolver: function (t) { var self = this; partir(String(t), this.cols).forEach(function (l) { self.linea(l); }); return this; },
        qr: function (dato, tam) {
            var d = codificar(dato, 'ascii'), L = d.length + 3;
            this.raw([0x1d,0x28,0x6b,0x04,0x00,0x31,0x41,0x32,0x00])              // modelo 2
                .raw([0x1d,0x28,0x6b,0x03,0x00,0x31,0x43, tam || 6])               // tamaño del módulo
                .raw([0x1d,0x28,0x6b,0x03,0x00,0x31,0x45,0x31])                    // corrección M
                .raw([0x1d,0x28,0x6b, L & 0xff, (L >> 8) & 0xff, 0x31,0x50,0x30]).raw(d)
                .raw([0x1d,0x28,0x6b,0x03,0x00,0x31,0x51,0x30]).raw([0x0a]);       // imprimir
            this.html.push('<div class="c qr" data-qr="' + esc(dato) + '"></div>');
            return this;
        },
        avanzar: function (n) { return this.raw([0x1b, 0x64, n || 3]); },
        cortar: function () { return this.raw([0x1d, 0x56, 0x42, 0x00]); },
        cajon: function () { return this.raw([0x1b, 0x70, 0x00, 0x19, 0xfa]); },
        bytes: function () { return new Uint8Array(this.b); }
    };
    function partir(t, n) {
        var out = [], palabras = t.split(/\s+/), l = '';
        palabras.forEach(function (p) {
            while (p.length > n) { if (l) { out.push(l); l = ''; } out.push(p.slice(0, n)); p = p.slice(n); }
            if (!l) l = p; else if ((l + ' ' + p).length <= n) l += ' ' + p; else { out.push(l); l = p; }
        });
        if (l || !out.length) out.push(l);
        return out;
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    /* Construye un ticket a partir de una descripción simple:
       { titulo, subtitulo[], lineas:[[texto, monto]|'texto'|'---'], total:[etq, monto],
         pie[], qr, abrirCajon } */
    function armar(dev, doc) {
        var t = new Ticket(dev);
        t.alinear('c');
        if (doc.encabezado) { t.negrita(1).tamano(2).envolver(doc.encabezado).tamano(0).negrita(0); }
        (doc.datos || []).forEach(function (l) { if (l) t.envolver(l); });
        if (doc.titulo) { t.linea('').negrita(1).envolver(doc.titulo).negrita(0); }
        (doc.subtitulo || []).forEach(function (l) { if (l) t.envolver(l); });
        t.alinear('l').separador();
        (doc.lineas || []).forEach(function (l) {
            if (l === '---') t.separador();
            else if (Array.isArray(l)) t.par(l[0], l[1]);
            else t.envolver(l);
        });
        if (doc.total) { t.separador().negrita(1).tamano('w'); t.cols = Math.floor(t.cols / 2); t.par(doc.total[0], doc.total[1]); t.cols *= 2; t.tamano(0).negrita(0); }
        (doc.despues || []).forEach(function (l) { if (Array.isArray(l)) t.par(l[0], l[1]); else t.envolver(l); });
        t.alinear('c');
        if (doc.qr) { t.linea(''); t.qr(doc.qr); }
        (doc.pie || []).forEach(function (l) { if (l) t.envolver(l); });
        t.avanzar(dev.corte === false ? 4 : 3);
        if (dev.corte !== false) t.cortar();
        if (doc.abrirCajon && dev.cajon) t.cajon();
        return t;
    }

    /* Impresión por el diálogo del sistema: el mismo ticket en HTML a 58/80 mm. */
    function imprimirSistema(dev, t) {
        var mm = dev.ancho == 80 ? 80 : 58;
        var f = document.createElement('iframe');
        f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
        document.body.appendChild(f);
        var css = '@page{size:' + mm + 'mm auto;margin:0}body{margin:0;padding:2mm 3mm;width:' + (mm - 6) + 'mm;font:12px/1.35 ui-monospace,Menlo,Consolas,monospace;color:#000}'
            + '.c{text-align:center}.r{text-align:right}.b{font-weight:700}.g{font-size:17px;font-weight:700}hr{border:0;border-top:1px dashed #000;margin:4px 0}'
            + '.par{display:flex;justify-content:space-between;gap:6px}.par span:last-child{white-space:nowrap}.qr svg{width:34mm;height:34mm}';
        var doc = f.contentDocument;
        doc.open();
        doc.write('<!doctype html><html><head><meta charset="utf-8"><style>' + css + '</style></head><body>' + t.html.join('') + '</body></html>');
        doc.close();
        var qrs = doc.querySelectorAll('[data-qr]');
        if (qrs.length && typeof window.qrcode === 'function') {
            qrs.forEach(function (el) { var q = window.qrcode(0, 'M'); q.addData(el.getAttribute('data-qr')); q.make(); el.innerHTML = q.createSvgTag({ cellSize: 3, margin: 0, scalable: true }); });
        }
        return new Promise(function (res) {
            setTimeout(function () {
                f.contentWindow.focus(); f.contentWindow.print();
                setTimeout(function () { f.remove(); res(); }, 1500);
            }, 150);
        });
    }

    /* Punto único para imprimir: usa la predeterminada (o la indicada). */
    function imprimir(doc, clave) {
        var dev = clave ? lista().find(function (x) { return x.clave === clave; }) : predeterminada();
        if (!dev) return Promise.reject(new Error('No hay impresora de tickets configurada en esta computadora.'));
        var t = armar(dev, doc);
        if (dev.tipo === 'sistema') return imprimirSistema(dev, t);
        return conexion(dev).then(function (con) { return con.escribir(t.bytes()); }).catch(function (e) {
            delete abiertos[dev.clave];   // se reintenta limpio la próxima vez
            throw e;
        });
    }
    function abrirCajon(clave) {
        var dev = clave ? lista().find(function (x) { return x.clave === clave; }) : predeterminada();
        if (!dev || dev.tipo === 'sistema') return Promise.reject(new Error('El cajón se abre con una impresora conectada por USB, serie o Bluetooth.'));
        var t = new Ticket(dev).cajon();
        return conexion(dev).then(function (con) { return con.escribir(t.bytes()); });
    }
    function prueba(clave) {
        var dev = lista().find(function (x) { return x.clave === clave; }) || {};
        var ahora = new Date();
        return imprimir({
            encabezado: CFG.marca || 'MediOS',
            titulo: 'Ticket de prueba',
            subtitulo: [ahora.toLocaleDateString('es-MX') + ' ' + ahora.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })],
            lineas: ['Impresora: ' + (dev.nombre || ''), 'Papel: ' + (dev.ancho || 58) + ' mm', '---',
                     'Acentos: áéíóú ñÑ ¿? ¡!', ['Consulta general', '$500.00'], ['Paracetamol 500 mg tabletas', '$45.00']],
            total: ['TOTAL', '$545.00'],
            qr: CFG.urlBase || location.origin,
            pie: ['Si ves bien los acentos y el QR,', 'la impresora quedó lista.']
        }, clave);
    }

    /* ── Escáneres ──────────────────────────────────────────────────── */
    var ultimo = { codigo: '', t: 0 };
    function escaneo(codigo, origen) {
        codigo = String(codigo).trim();
        if (!codigo) return;
        var ahora = Date.now();
        if (codigo === ultimo.codigo && ahora - ultimo.t < 800) return;   // rebote del lector
        ultimo = { codigo: codigo, t: ahora };
        var ev = new CustomEvent('medios:escaneo', { detail: { codigo: codigo, origen: origen }, cancelable: true });
        document.dispatchEvent(ev);
        if (!ev.defaultPrevented) resolverGlobal(codigo);
    }

    // Lector tipo teclado: ráfaga rápida terminada en Enter.
    (function teclado() {
        var RAPIDO = 45, buf = '', t0 = 0, primero = 0, campo = null, valorAntes = null;
        function editable(el) { return el && (el.isContentEditable || /^(INPUT|TEXTAREA)$/.test(el.tagName)); }
        document.addEventListener('keydown', function (e) {
            if (!opciones().escanerTeclado) return;
            var el = document.activeElement, ahora = Date.now();
            if (el && el.tagName === 'SELECT') { buf = ''; return; }
            // Un campo marcado como lector (data-escaner) recibe el código tal cual.
            if (el && el.hasAttribute && el.hasAttribute('data-escaner')) { buf = ''; return; }
            if (e.key === 'Enter') {
                var cod = buf, prom = cod.length > 1 ? (t0 - primero) / (cod.length - 1) : 999;
                buf = '';
                // Dentro de un campo se exige más (alguien que teclea rápido no es un lector).
                var enCampo = !!campo;
                if (cod.length >= (enCampo ? 6 : 4) && prom < (enCampo ? 25 : RAPIDO)) {
                    e.preventDefault(); e.stopImmediatePropagation();
                    if (campo && valorAntes !== null && campo === el) {   // deshace lo que el lector "tecleó" en el campo
                        campo.value = valorAntes; campo.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    escaneo(cod, 'teclado');
                }
                campo = null; valorAntes = null;
                return;
            }
            if (e.key.length !== 1 || e.ctrlKey || e.altKey || e.metaKey) return;
            if (t0 && ahora - t0 > RAPIDO) buf = '';
            if (!buf) { primero = ahora; campo = editable(el) ? el : null; valorAntes = campo ? campo.value : null; }
            buf += e.key; t0 = ahora;
            // En plena ráfaga fuera de un campo, que las teclas no disparen atajos ("/" del buscador).
            if (buf.length >= 3 && !editable(el)) { e.preventDefault(); e.stopImmediatePropagation(); }
        }, true);
    })();

    // Los lectores en modo serie ya autorizados se reconectan solos en cada pantalla.
    function reconectarEscaneres() {
        lista().filter(function (d) { return d.rol === 'escaner' && d.tipo === 'serial'; })
            .forEach(function (d) { conexion(d).catch(function () {}); });
    }

    // Qué hacer con un código que ninguna pantalla atendió.
    function resolverGlobal(codigo) {
        var m = codigo.match(/\/rx\?t=([a-f0-9]{32})/i);
        if (m) { location.href = (CFG.base || '') + '/rx?t=' + m[1]; return; }
        if (/^https?:\/\//i.test(codigo)) {
            try { if (new URL(codigo).origin === location.origin) { location.href = codigo; return; } } catch (e) {}
            aviso('Código leído', '<code>' + esc(codigo) + '</code>');
            return;
        }
        fetch((CFG.base || '') + '/dispositivos/codigo?c=' + encodeURIComponent(codigo), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r && r.tipo === 'producto') {
                    aviso(r.nombre, 'Precio ' + r.precio + ' · Existencia ' + r.stock,
                        [['Ver en inventario', r.url], r.pos ? ['Vender', r.pos] : null]);
                } else if (r && r.tipo === 'paciente') {
                    location.href = r.url;
                } else {
                    aviso('Código leído', '<code>' + esc(codigo) + '</code> no corresponde a ningún producto.',
                        [['Buscar paciente', (CFG.base || '') + '/pacientes/index?q=' + encodeURIComponent(codigo)]]);
                }
            }).catch(function () { aviso('Código leído', '<code>' + esc(codigo) + '</code>'); });
    }

    function aviso(titulo, cuerpo, botones) {
        var box = document.getElementById('dispAviso');
        if (!box) {
            box = document.createElement('div');
            box.id = 'dispAviso';
            box.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2000;max-width:min(92vw,420px);'
                + 'background:var(--bs-body-bg,#fff);color:inherit;border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,.25);padding:.85rem 1rem;'
                + 'border:1px solid rgba(127,127,127,.2)';
            document.body.appendChild(box);
        }
        box.innerHTML = '<div class="d-flex gap-2 align-items-start"><i class="bi bi-upc-scan text-brand fs-5"></i><div class="flex-grow-1">'
            + '<div class="fw-semibold">' + esc(titulo) + '</div><div class="small text-muted">' + cuerpo + '</div>'
            + '<div class="d-flex gap-2 mt-2">' + (botones || []).filter(Boolean).map(function (b) {
                return '<a class="btn btn-sm btn-outline-primary" href="' + esc(b[1]) + '">' + esc(b[0]) + '</a>'; }).join('')
            + '</div></div><button type="button" class="btn-close" aria-label="Cerrar"></button></div>';
        box.hidden = false;
        box.querySelector('.btn-close').onclick = function () { box.hidden = true; };
        clearTimeout(box._t);
        box._t = setTimeout(function () { box.hidden = true; }, 7000);
    }

    /* Escanear con la cámara (modal). Devuelve una promesa con el código. */
    function cargarJsQR() {
        if (window.jsQR) return Promise.resolve();
        return new Promise(function (res, rej) {
            var s = document.createElement('script');
            s.src = (CFG.base || '') + '/assets/js/vendor/jsQR.js';
            s.onload = res; s.onerror = rej;
            document.head.appendChild(s);
        });
    }
    function camara() {
        if (!soporte.camara) return Promise.reject(new Error('Este navegador no permite usar la cámara.'));
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;z-index:2050;background:rgba(15,23,42,.82);display:flex;align-items:center;justify-content:center;padding:16px';
        modal.innerHTML = '<div style="background:var(--bs-body-bg,#fff);border-radius:18px;padding:14px;max-width:440px;width:100%">'
            + '<div class="d-flex justify-content-between align-items-center mb-2"><b><i class="bi bi-camera"></i> Escanear con la cámara</b>'
            + '<button type="button" class="btn-close" aria-label="Cerrar"></button></div>'
            + '<video playsinline muted style="width:100%;border-radius:12px;background:#000;aspect-ratio:4/3;object-fit:cover"></video>'
            + '<div class="small text-muted mt-2" id="camMsg">Apunta al código QR o de barras.</div></div>';
        document.body.appendChild(modal);
        var video = modal.querySelector('video'), stream = null, vivo = true, canvas = document.createElement('canvas');
        function cerrar() { vivo = false; if (stream) stream.getTracks().forEach(function (t) { t.stop(); }); modal.remove(); }
        return new Promise(function (res, rej) {
            modal.querySelector('.btn-close').onclick = function () { cerrar(); rej(new Error('cancelado')); };
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
                stream = s; video.srcObject = s; return video.play();
            }).then(function () {
                var det = null;
                if (soporte.detector) {
                    try { det = new BarcodeDetector({ formats: ['qr_code', 'ean_13', 'ean_8', 'code_128', 'code_39', 'upc_a', 'upc_e', 'itf', 'data_matrix'] }); } catch (e) { det = null; }
                }
                var listo = det ? Promise.resolve() : cargarJsQR().then(function () {
                    modal.querySelector('#camMsg').textContent = 'Apunta al código QR. (Para códigos de barras usa un lector o Chrome en Android/Mac.)';
                });
                return listo.then(function () {
                    (function cuadro() {
                        if (!vivo) return;
                        var hecho = function (c) { if (c) { cerrar(); res(c); } else requestAnimationFrame(cuadro); };
                        if (video.readyState < 2) return requestAnimationFrame(cuadro);
                        if (det) {
                            det.detect(video).then(function (r) { hecho(r[0] && r[0].rawValue); }).catch(function () { requestAnimationFrame(cuadro); });
                        } else {
                            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
                            var cx = canvas.getContext('2d', { willReadFrequently: true });
                            cx.drawImage(video, 0, 0);
                            var img = cx.getImageData(0, 0, canvas.width, canvas.height);
                            var q = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
                            setTimeout(function () { hecho(q && q.data); }, 120);
                        }
                    })();
                });
            }).catch(function (e) { cerrar(); rej(new Error('No se pudo abrir la cámara: ' + (e && e.message || e))); });
        });
    }
    function escanearCamara() {
        return camara().then(function (c) { escaneo(c, 'camara'); return c; });
    }

    /* Botones genéricos: data-disp-camara abre la cámara; data-ticket imprime un ticket JSON. */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-disp-camara]');
        if (b) { e.preventDefault(); escanearCamara().catch(function (err) { if (err.message !== 'cancelado') aviso('Cámara', esc(err.message)); }); return; }
        var t = e.target.closest('[data-ticket]');
        if (t) {
            e.preventDefault();
            var doc; try { doc = JSON.parse(t.getAttribute('data-ticket')); } catch (x) { return; }
            doc.abrirCajon = false;   // reimprimir no abre el cajón
            var txt = t.innerHTML;
            t.disabled = true;
            imprimir(doc).then(function () {
                t.innerHTML = '<i class="bi bi-check-lg"></i> Enviado';
                setTimeout(function () { t.innerHTML = txt; t.disabled = false; }, 1800);
            }).catch(function (err) {
                t.innerHTML = txt; t.disabled = false;
                aviso('No se pudo imprimir', esc(err.message), [['Impresoras y escáneres', (CFG.base || '') + '/dispositivos/index']]);
            });
        }
    });

    window.MediosDisp = {
        soporte: soporte, lista: lista, opciones: opciones, guardarOpciones: guardarOpciones,
        agregar: agregar, actualizar: actualizar, quitar: quitar, estado: estado, predeterminada: predeterminada,
        imprimir: imprimir, prueba: prueba, abrirCajon: abrirCajon, escanearCamara: escanearCamara, escaneo: escaneo,
        aviso: aviso,
        detectados: function () {
            var out = [];
            var a = soporte.usb ? navigator.usb.getDevices().then(function (ds) { ds.forEach(function (d) { out.push({ clave: claveUsb(d), tipo: 'usb', nombre: nombreUsb(d) }); }); }) : Promise.resolve();
            var b = soporte.serial ? navigator.serial.getPorts().then(function (ps) {
                var cuenta = {};
                ps.forEach(function (p) { var base = claveSerial(p, 0).replace(/:\d+$/, ''); cuenta[base] = cuenta[base] || 0;
                    out.push({ clave: base + ':' + cuenta[base]++, tipo: 'serial', nombre: nombreSerial(p) }); });
            }) : Promise.resolve();
            var c = soporte.ble && navigator.bluetooth.getDevices ? navigator.bluetooth.getDevices().then(function (ds) {
                ds.forEach(function (d) { out.push({ clave: 'ble:' + d.id, tipo: 'ble', nombre: d.name || 'Bluetooth' }); }); }).catch(function () {}) : Promise.resolve();
            return Promise.all([a, b, c]).then(function () { return out; });
        },
        adoptar: function (d, rol) {
            var l = lista().filter(function (x) { return x.clave !== d.clave; });
            d = Object.assign({}, d, { rol: rol || 'impresora' });
            if (d.rol === 'impresora') {
                Object.assign(d, { ancho: 58, codif: 'cp850', corte: true, cajon: false });
                d.predeterminada = !l.some(function (x) { return x.rol === 'impresora' && x.predeterminada; });
            }
            if (d.tipo === 'serial') d.baud = 9600;
            l.push(d); guardarLista(l);
        }
    };

    if (soporte.serial) {
        navigator.serial.addEventListener('connect', reconectarEscaneres);
        navigator.serial.addEventListener('disconnect', function () { abiertos = {}; emitir('medios:dispositivos'); });
    }
    if (soporte.usb) {
        navigator.usb.addEventListener('connect', function () { emitir('medios:dispositivos'); });
        navigator.usb.addEventListener('disconnect', function (e) {
            delete abiertos[claveUsb(e.device)]; emitir('medios:dispositivos');
        });
    }
    reconectarEscaneres();
})();
