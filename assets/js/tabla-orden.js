/**
 * Panel: ordenamiento por columna (flechitas asc/desc) + filtro en vivo.
 * Misma API que GymOS (assets/js/admin-tables.js), para no tener que recordar
 * dos convenciones distintas:
 *
 *   · Se aplica solo a todas las tablas del panel.
 *   · Los <th> vacíos, "Acciones" o con data-no-sort no se ordenan.
 *   · Buscador: <input data-filter-table="#idDeLaTabla">.
 *
 * Lo que aquí es distinto de GymOS, y por qué:
 *   · El formato de fecha es configurable por consultorio (cfg('formato_fecha'),
 *     d/m/Y por omisión), así que no se puede asumir dd/mm/aaaa. El footer lo
 *     publica en window.APP_FORMATO_FECHA y aquí se parsea con ese formato.
 *   · Las celdas vacías ("—") se van siempre al final, sea asc o desc.
 *   · Una celda puede llevar data-orden="valor" cuando el texto visible no
 *     sirve para ordenar (p. ej. una barra de avance).
 *   · Las tablas de captura (renglones de una orden, del POS, de una receta)
 *     no se ordenan: mover filas mientras alguien escribe estorba.
 */
(function () {
    'use strict';

    var VACIO = /^[—–-]?$/;   // "—", "-" o cadena vacía

    /* ---------------------------------------------------------------- */
    /*  Lectura de valores                                              */
    /* ---------------------------------------------------------------- */

    /** Texto por el que se ordena una celda (data-orden manda sobre el texto). */
    function texto(td) {
        if (!td) return '';
        if (td.dataset && td.dataset.orden !== undefined) return td.dataset.orden.trim();
        return (td.textContent || '').replace(/\s+/g, ' ').trim();
    }

    /** "$1,199.00" / "12.5%" / "1,234" -> número. null si no lo es. */
    function comoNumero(s) {
        if (!/^[\s$€£%+\-\d.,]+$/.test(s) || !/\d/.test(s)) return null;
        // number_format() imprime la coma como separador de miles y el punto
        // como decimal ("1,199.00"): quitar las comas es lo correcto aquí.
        var n = parseFloat(s.replace(/[\s$€£%,]/g, ''));
        return isNaN(n) ? null : n;
    }

    /** Fecha en el formato del consultorio (o ISO). Devuelve timestamp o null. */
    function comoFecha(s) {
        var iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
        if (iso) return Date.UTC(+iso[1], +iso[2] - 1, +iso[3]);

        var f = (typeof window !== 'undefined' && window.APP_FORMATO_FECHA) || 'd/m/Y';
        var orden = [];
        var re = '';
        for (var i = 0; i < f.length; i++) {
            var c = f.charAt(i);
            if (c === 'd' || c === 'j')      { orden.push('d'); re += '(\\d{1,2})'; }
            else if (c === 'm' || c === 'n') { orden.push('m'); re += '(\\d{1,2})'; }
            else if (c === 'Y')              { orden.push('Y'); re += '(\\d{4})'; }
            else if (c === 'y')              { orden.push('y'); re += '(\\d{2})'; }
            else                             { re += c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
        }
        if (!orden.length) return null;

        var m = new RegExp('^' + re + '$').exec(s);
        if (!m) return null;

        var p = { d: 1, m: 1, Y: 1970 };
        for (var k = 0; k < orden.length; k++) {
            var v = parseInt(m[k + 1], 10);
            if (orden[k] === 'y') { p.Y = v + (v < 70 ? 2000 : 1900); }
            else                  { p[orden[k]] = v; }
        }
        return Date.UTC(p.Y, p.m - 1, p.d);
    }

    /** "14:30" -> minutos desde medianoche. null si no es hora. */
    function comoHora(s) {
        var m = /^(\d{1,2}):(\d{2})$/.exec(s);
        return m ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : null;
    }

    /**
     * Tipo de la columna, decidido UNA vez con una muestra de sus celdas: por
     * columna y no por celda, para que una fila rara no parta el criterio de
     * orden a la mitad de la tabla.
     */
    function tipoColumna(filas, i) {
        var vistos = 0, numeros = 0, fechas = 0, horas = 0;
        for (var f = 0; f < filas.length && vistos < 12; f++) {
            var s = texto(filas[f].cells[i]);
            if (VACIO.test(s)) continue;
            vistos++;
            if (comoNumero(s) !== null)      numeros++;
            else if (comoFecha(s) !== null)  fechas++;
            else if (comoHora(s) !== null)   horas++;
        }
        if (!vistos)            return 'texto';
        if (fechas  === vistos) return 'fecha';
        if (horas   === vistos) return 'hora';
        if (numeros === vistos) return 'numero';
        return 'texto';
    }

    function valor(td, tipo) {
        var s = texto(td);
        if (VACIO.test(s)) return null;
        if (tipo === 'numero') return comoNumero(s);
        if (tipo === 'fecha')  return comoFecha(s);
        if (tipo === 'hora')   return comoHora(s);
        return s;
    }

    /* ---------------------------------------------------------------- */
    /*  Ordenar                                                          */
    /* ---------------------------------------------------------------- */

    var colador = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });

    function sortTable(table, colIndex, dir) {
        var tbody = table.tBodies[0];
        if (!tbody) return;

        // Las filas de "no hay nada aquí" son un colspan: no se ordenan.
        var filas = Array.prototype.slice.call(tbody.rows).filter(function (r) {
            return r.cells.length > colIndex && r.cells.length > 1;
        });

        var tipo = tipoColumna(filas, colIndex);
        var signo = dir === 'asc' ? 1 : -1;

        filas.sort(function (a, b) {
            var va = valor(a.cells[colIndex], tipo);
            var vb = valor(b.cells[colIndex], tipo);
            if (va === null && vb === null) return 0;
            if (va === null) return 1;      // los vacíos, siempre al final
            if (vb === null) return -1;
            var res = (tipo === 'texto') ? colador.compare(va, vb) : (va - vb);
            return signo * res;
        });

        filas.forEach(function (r) { tbody.appendChild(r); });
    }

    /* ---------------------------------------------------------------- */
    /*  Activación                                                       */
    /* ---------------------------------------------------------------- */

    function enhance(table) {
        var head = table.tHead;
        var tbody = table.tBodies[0];
        if (!head || !head.rows.length || !tbody) return;
        if (table.hasAttribute('data-no-sort')) return;

        // Tablas de captura: llevan campos editables visibles. Los ocultos (el
        // CSRF de los botones de acción) no cuentan: esas sí son de consulta.
        if (tbody.querySelector('input:not([type="hidden"]), select, textarea')) return;

        var ths = head.rows[head.rows.length - 1].cells;
        Array.prototype.forEach.call(ths, function (th, i) {
            var label = th.textContent.trim();
            if (th.hasAttribute('data-no-sort') || label === '' || /acci[oó]n/i.test(label)) return;

            th.classList.add('th-orden');
            th.title = 'Ordenar';
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');

            var arrow = document.createElement('span');
            arrow.className = 'sort-arrow';
            arrow.textContent = '↕';
            th.appendChild(arrow);

            function clic() {
                var dir = th.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';

                Array.prototype.forEach.call(ths, function (o) {
                    o.removeAttribute('data-sort-dir');
                    o.classList.remove('th-orden-activo');
                    var a = o.querySelector('.sort-arrow');
                    if (a) a.textContent = '↕';
                });

                th.setAttribute('data-sort-dir', dir);
                th.classList.add('th-orden-activo');
                arrow.textContent = dir === 'asc' ? '▲' : '▼';
                sortTable(table, i, dir);
            }

            th.addEventListener('click', clic);
            th.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); clic(); }
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /*  Filtro en vivo: <input data-filter-table="#tabla">                */
    /* ---------------------------------------------------------------- */

    function filterRows(table, q) {
        q = q.toLowerCase().trim();
        var tbody = table.tBodies[0];
        if (!tbody) return;
        Array.prototype.forEach.call(tbody.rows, function (r) {
            if (r.cells.length <= 1) return;   // fila de estado vacío
            r.style.display = (!q || r.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table').forEach(enhance);

        document.querySelectorAll('input[data-filter-table]').forEach(function (input) {
            var table = document.querySelector(input.getAttribute('data-filter-table'));
            if (!table) return;
            input.addEventListener('input', function () { filterRows(table, input.value); });
        });
    });
})();
