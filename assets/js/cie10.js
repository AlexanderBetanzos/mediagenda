/* =====================================================================
 *  Autocompletado CIE-10 en los campos de diagnóstico.
 *  Funciona en cualquier input/textarea name="diagnostico" o con
 *  data-cie10. Busca por código ("J02") o por palabras ("faringitis aguda")
 *  y escribe "J02.9 Faringitis aguda, no especificada". En un textarea se
 *  pueden poner varios diagnósticos, uno por renglón o separados con ";".
 *  Sigue siendo texto libre: el catálogo ayuda, no obliga.
 * ===================================================================== */
(function () {
    'use strict';
    var BASE = (window.MEDIOS_DISP && window.MEDIOS_DISP.base) || '';
    var campos = document.querySelectorAll('textarea[name="diagnostico"], input[name="diagnostico"], [data-cie10]');
    if (!campos.length) return;

    var caja = document.createElement('div');
    caja.className = 'cie-box';
    caja.hidden = true;
    caja.setAttribute('role', 'listbox');
    document.body.appendChild(caja);
    var st = document.createElement('style');
    st.textContent = '.cie-box{position:absolute;z-index:2100;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#0f1d2b);border:1px solid rgba(127,127,127,.25);'
        + 'border-radius:12px;box-shadow:0 14px 40px rgba(15,40,60,.18);max-height:320px;overflow:auto;font-size:.9rem}'
        + '.cie-op{display:flex;gap:.6rem;padding:.5rem .75rem;cursor:pointer;align-items:baseline}'
        + '.cie-op b{font-family:ui-monospace,Menlo,Consolas,monospace;color:var(--brand,#0f8dbf);min-width:3.6rem}'
        + '.cie-op.on,.cie-op:hover{background:color-mix(in srgb,var(--brand,#0f8dbf) 10%,transparent)}'
        + '.cie-pie{font-size:.7rem;color:var(--bs-secondary-color,#64748b);padding:.4rem .75rem;border-top:1px solid rgba(127,127,127,.15)}'
        + '.cie-tip{font-size:.75rem;color:var(--bs-secondary-color,#64748b);margin-top:.2rem}';
    document.head.appendChild(st);

    var activo = null, ops = [], sel = -1, timer = null, ultimaQ = '', ctrl = null, sinCatalogo = false;

    // Tramo que se está escribiendo: desde el último salto de línea o ";" hasta el cursor.
    function tramo(el) {
        var v = el.value, fin = el.selectionStart == null ? v.length : el.selectionStart;
        var ini = Math.max(v.lastIndexOf('\n', fin - 1), v.lastIndexOf(';', fin - 1)) + 1;
        return { ini: ini, fin: fin, txt: v.slice(ini, fin).replace(/^\s+/, '') };
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function cerrar() { caja.hidden = true; ops = []; sel = -1; }
    function colocar(el) {
        var r = el.getBoundingClientRect();
        caja.style.left = (r.left + scrollX) + 'px';
        caja.style.top = (r.bottom + scrollY + 4) + 'px';
        caja.style.width = Math.max(320, r.width) + 'px';
    }
    function pintar(lista, credito) {
        ops = lista; sel = lista.length ? 0 : -1;
        if (!lista.length) { cerrar(); return; }
        caja.innerHTML = lista.map(function (o, i) {
            return '<div class="cie-op' + (i === 0 ? ' on' : '') + '" role="option" data-i="' + i + '"><b>' + esc(o.codigo) + '</b><span>' + esc(o.nombre) + '</span></div>';
        }).join('') + '<div class="cie-pie">' + esc(credito || '') + '</div>';
        colocar(activo);
        caja.hidden = false;
    }
    function marcar(i) {
        var els = caja.querySelectorAll('.cie-op');
        if (!els.length) return;
        sel = (i + els.length) % els.length;
        els.forEach(function (e, k) { e.classList.toggle('on', k === sel); });
        els[sel].scrollIntoView({ block: 'nearest' });
    }
    function elegir(i) {
        var o = ops[i], el = activo;
        if (!o || !el) return;
        var t = tramo(el), v = el.value;
        var nuevo = o.codigo + ' ' + o.nombre;
        var antes = v.slice(0, t.ini), despues = v.slice(t.fin);
        if (antes && !/[\n;]\s*$/.test(antes)) antes = antes.replace(/\s*$/, '');
        if (antes && /;$/.test(antes)) antes += ' ';
        el.value = antes + nuevo + despues;
        var pos = (antes + nuevo).length;
        try { el.setSelectionRange(pos, pos); } catch (e) {}
        el.dispatchEvent(new Event('input', { bubbles: true }));
        cerrar();
        el.focus();
    }
    function buscar(el) {
        var q = tramo(el).txt.trim();
        if (q.length < 2 || sinCatalogo || /^[A-Z]\d{2}(\.\w{1,2})? \S/.test(q)) { cerrar(); return; }   // ya elegido
        if (q === ultimaQ && !caja.hidden) return;
        ultimaQ = q;
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        fetch(BASE + '/cie10/buscar?q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctrl.signal })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (r.vacio) { sinCatalogo = true; cerrar(); return; }
                if (activo === el) pintar(r.r || [], r.credito);
            }).catch(function () {});
    }

    // La pista solo aparece si el catálogo ya está cargado en el sistema.
    fetch(BASE + '/cie10/buscar?q=', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
        sinCatalogo = !!r.vacio;
        if (sinCatalogo) return;
        campos.forEach(function (el) {
            var tip = document.createElement('div');
            tip.className = 'cie-tip';
            tip.innerHTML = '<i class="bi bi-search"></i> Escribe el diagnóstico o su código CIE-10 para buscarlo en el catálogo.';
            el.insertAdjacentElement('afterend', tip);
        });
    }).catch(function () {});

    campos.forEach(function (el) {
        el.setAttribute('autocomplete', 'off');
        el.addEventListener('input', function () { activo = el; clearTimeout(timer); timer = setTimeout(function () { buscar(el); }, 180); });
        el.addEventListener('keydown', function (e) {
            if (caja.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); marcar(sel + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); marcar(sel - 1); }
            else if (e.key === 'Enter' || e.key === 'Tab') { if (sel >= 0) { e.preventDefault(); elegir(sel); } }
            else if (e.key === 'Escape') { cerrar(); }
        });
        el.addEventListener('blur', function () { setTimeout(function () { if (document.activeElement !== el) cerrar(); }, 150); });
    });
    caja.addEventListener('mousedown', function (e) {
        var op = e.target.closest('.cie-op');
        if (op) { e.preventDefault(); elegir(+op.dataset.i); }
    });
    addEventListener('resize', function () { if (!caja.hidden && activo) colocar(activo); });
})();
