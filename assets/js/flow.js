/* =====================================================================
 *  Flow del panel: que la interfaz se sienta viva y rápida.
 *  - Cifras que cuentan hacia arriba al aparecer.
 *  - Filas de tabla que entran en cascada y se abren con un clic.
 *  - Filtro instantáneo al escribir en el buscador de una lista.
 *  - Los <select> de un filtro aplican solos (sin pulsar "Buscar").
 *  - "/" o Ctrl/⌘+K enfocan el buscador global.
 *  - El menú conserva su scroll entre pantallas y precarga al pasar el cursor.
 *  Las transiciones entre pantallas son CSS (@view-transition, panel-clean.css).
 *  Sin dependencias. Respeta "reducir movimiento" del sistema.
 * ===================================================================== */
(function () {
    'use strict';
    var quieto = matchMedia('(prefers-reduced-motion: reduce)').matches;
    var main = document.querySelector('main');

    /* ── 1. Menú: conservar el scroll entre pantallas ───────────────────── */
    var menu = document.querySelector('#sidebarMenu .position-sticky');
    if (menu) {
        try {
            var y = sessionStorage.getItem('flowMenuY');
            if (y !== null) menu.scrollTop = +y;
            else { var act = menu.querySelector('.nav-link.active'); if (act) act.scrollIntoView({ block: 'center' }); }
        } catch (e) {}
        window.addEventListener('pagehide', function () {
            try { sessionStorage.setItem('flowMenuY', String(menu.scrollTop)); } catch (e) {}
        });
    }

    /* Al abrir "Más herramientas", bajar el menú lo justo para que se vea completo. */
    var mas = document.getElementById('navMas');
    if (mas && menu) mas.addEventListener('shown.bs.collapse', function () {
        var r = mas.getBoundingClientRect(), m = menu.getBoundingClientRect();
        if (r.bottom > m.bottom - 8) menu.scrollBy({ top: r.bottom - m.bottom + 24, behavior: quieto ? 'auto' : 'smooth' });
    });

    /* ── 2. Precarga al pasar el cursor (Chrome/Edge): navegar se siente
           instantáneo. Solo enlaces de navegación, nunca acciones. ────────── */
    if (HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
        var sr = document.createElement('script');
        sr.type = 'speculationrules';
        sr.textContent = JSON.stringify({ prefetch: [{
            source: 'document',
            where: { selector_matches: '.sidebar a.nav-link:not([href="#"]):not([data-bs-toggle]), .dash-tabs a, a.ui-stat' },
            eagerness: 'moderate'
        }] });
        document.head.appendChild(sr);
    }

    /* ── 3. Atajos al buscador global ────────────────────────────────────── */
    var top = document.querySelector('.top-search input');
    document.addEventListener('keydown', function (e) {
        if (!top) return;
        var t = e.target, escribe = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
        if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) { e.preventDefault(); top.focus(); top.select(); }
        else if (e.key === '/' && !escribe) { e.preventDefault(); top.focus(); }
        else if (e.key === 'Escape' && t === top) top.blur();
    });

    if (!main) return;

    /* ── 4. Cifras que cuentan ───────────────────────────────────────────── */
    var CIFRAS = '.ui-stat-n, .kpi3-n, .kpi2-n, .esp-v, .df-v, .cal-tile b, .ing-tot, .stat-card .stat-num';
    function contar(el) {
        if (el.dataset.flowN) return;
        el.dataset.flowN = '1';
        var txt = el.textContent.trim();
        var m = txt.match(/^([^\d-]*)(-?[\d,]*\d(?:\.\d+)?)(.*)$/);
        if (!m) return;
        var fin = parseFloat(m[2].replace(/,/g, ''));
        if (!isFinite(fin) || fin === 0) return;
        var dec = (m[2].split('.')[1] || '').length, comas = m[2].indexOf(',') >= 0 || fin >= 1000;
        var fmt = function (v) {
            return comas ? v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) : v.toFixed(dec);
        };
        var t0 = null, dur = Math.min(1400, 700 + Math.log10(Math.abs(fin) + 1) * 120);
        el.style.fontVariantNumeric = 'tabular-nums';
        function paso(ts) {
            if (t0 === null) t0 = ts;
            var p = Math.min(1, (ts - t0) / dur), k = 1 - Math.pow(2, -10 * p);   // ease-out expo
            el.textContent = m[1] + fmt(p < 1 ? fin * k : fin) + m[3];
            if (p < 1) requestAnimationFrame(paso);
        }
        el.textContent = m[1] + fmt(0) + m[3];
        requestAnimationFrame(paso);
    }
    if (!quieto) {
        var cifras = main.querySelectorAll(CIFRAS);
        if ('IntersectionObserver' in window) {
            var io = new IntersectionObserver(function (es) {
                es.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); contar(en.target); } });
            }, { threshold: .3 });
            cifras.forEach(function (c) { io.observe(c); });
        } else cifras.forEach(contar);
    }

    /* ── 5. Tablas: filas en cascada y fila completa clicable ────────────── */
    main.querySelectorAll('.card .table tbody').forEach(function (tb) {
        var filas = tb.querySelectorAll(':scope > tr');
        filas.forEach(function (tr, i) {
            if (!quieto && i < 40) { tr.style.animationDelay = Math.min(i * 28, 500) + 'ms'; tr.classList.add('flow-row'); }
            var a = tr.querySelector('a[href*="/ver?"], td:first-child a.fw-semibold[href]');
            if (a) { tr.classList.add('flow-click'); tr.dataset.href = a.href; }
        });
    });
    main.addEventListener('click', function (e) {
        var tr = e.target.closest('tr.flow-click');
        if (!tr || e.target.closest('a, button, input, select, textarea, label, form, .dropdown, [data-bs-toggle]')) return;
        if (window.getSelection && String(window.getSelection()).length) return;   // estaba seleccionando texto
        if (e.metaKey || e.ctrlKey) window.open(tr.dataset.href, '_blank');
        else location.href = tr.dataset.href;
    });

    /* ── 6. Filtros: búsqueda instantánea y selects que aplican solos ────── */
    var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    main.querySelectorAll(':scope > form[method="get"], :scope > form[method="GET"]').forEach(function (f) {
        f.querySelectorAll('select').forEach(function (s) {
            s.addEventListener('change', function () { f.requestSubmit ? f.requestSubmit() : f.submit(); });
        });
        var q = f.querySelector('input[type="search"], input[name="q"]');
        var tb = main.querySelector('.card .table tbody');
        if (!q || !tb) return;
        var filas = Array.prototype.slice.call(tb.querySelectorAll(':scope > tr')).filter(function (tr) { return !tr.querySelector('td[colspan]'); });
        if (!filas.length) return;
        var cols = filas[0].children.length;
        var aviso = document.createElement('tr');
        aviso.className = 'flow-aviso';
        aviso.innerHTML = '<td colspan="' + cols + '" class="text-center py-4"><i class="bi bi-search d-block fs-4 mb-1"></i>'
            + 'No hay coincidencias en esta página. Presiona <kbd>Enter</kbd> para buscar en todos.</td>';
        aviso.hidden = true;
        tb.appendChild(aviso);
        var textos = filas.map(function (tr) { return norm(tr.textContent); });
        q.addEventListener('input', function () {
            var v = norm(q.value.trim()), n = 0;
            filas.forEach(function (tr, i) {
                var ok = !v || textos[i].indexOf(v) >= 0;
                tr.hidden = !ok;
                if (ok) n++;
            });
            aviso.hidden = n > 0;
        });
    });
})();
