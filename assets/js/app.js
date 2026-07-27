/* =====================================================================
 *  MediOS — Experiencia "tipo app": barra de progreso al navegar, spinner
 *  en botones al enviar formularios y transiciones suaves. Sin dependencias.
 * ===================================================================== */
(function () {
    'use strict';

    // ── Barra de progreso superior (estilo YouTube/GitHub) ──────────────
    var bar = document.createElement('div');
    bar.className = 'app-progress';
    var attached = false;
    function attach() { if (!attached && document.body) { document.body.appendChild(bar); attached = true; } }
    if (document.body) attach();
    else document.addEventListener('DOMContentLoaded', attach);

    function start() { attach(); bar.classList.remove('done'); void bar.offsetWidth; bar.classList.add('run'); }
    function done()  { if (!attached) return; bar.classList.remove('run'); bar.classList.add('done');
        setTimeout(function () { bar.classList.remove('done'); }, 450); }

    // Navegación por enlaces internos (misma pestaña).
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        var a = e.target.closest && e.target.closest('a');
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#') return;
        if (a.target && a.target !== '_self') return;
        if (a.hasAttribute('download') || a.dataset.bsToggle) return;
        if (/^(javascript:|mailto:|tel:|https?:\/\/wa\.me)/i.test(href)) return;
        // Enlace externo a otro dominio: no mostramos loader interno.
        try { var u = new URL(a.href, location.href); if (u.origin !== location.origin) return; } catch (err) {}
        start();
    }, true);

    // Envío de formularios: barra + spinner en el botón que envió.
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;               // p. ej. confirm() cancelado
        var form = e.target;
        if (form.getAttribute('target') === '_blank') return;
        start();
        var btn = e.submitter || form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
        // No usamos disabled (excluiría name/value del envío); solo estilo.
        if (btn && btn.classList) btn.classList.add('is-loading');
    }, true);

    // Al volver (bfcache) o terminar de cargar, cerrar la barra y limpiar spinners.
    window.addEventListener('pageshow', function () {
        done();
        document.querySelectorAll('.btn.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
    });
    window.addEventListener('load', done);
    // Fallback: si algo tarda mucho, no dejar la barra "corriendo" indefinida.
    window.addEventListener('beforeunload', start);
})();
