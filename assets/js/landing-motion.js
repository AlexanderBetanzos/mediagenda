/* =====================================================================
 *  Landing — movimiento ("flow"): aparición al hacer scroll, contadores,
 *  la cita del mock que se confirma sola y barra de lectura. Sin
 *  dependencias. Si el usuario pidió reducir movimiento no se activa nada
 *  y la página queda estática (el CSS solo oculta bajo html.motion).
 * ===================================================================== */
(function () {
    'use strict';

    // html.motion lo pone el <head> de index.php (antes de pintar, para que el
    // hero no parpadee) junto con un seguro que la quita si este archivo no
    // llega. Aquí llegamos: se cancela el seguro.
    if (!document.documentElement.classList.contains('motion')) return;
    clearTimeout(window.__lpMotionSafe);

    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    // Cuenta de 0 al valor final respetando prefijo/sufijo: "$48k", "16", "0%".
    function countUp(el, ms) {
        var m = (el.textContent || '').match(/^(\D*)(\d+)(.*)$/);
        if (!m || el.dataset.counted) return;
        el.dataset.counted = '1';
        var pre = m[1], to = parseInt(m[2], 10), post = m[3], t0 = null;
        if (!to) return;
        function step(ts) {
            if (t0 === null) t0 = ts;
            var k = Math.min(1, (ts - t0) / ms), e = 1 - Math.pow(1 - k, 3);
            el.textContent = pre + Math.round(to * e) + post;
            if (k < 1) requestAnimationFrame(step);
        }
        el.textContent = pre + '0' + post;
        requestAnimationFrame(step);
    }

    ready(function () {
        // ── Aparición al entrar en pantalla ──────────────────────────────
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                en.target.classList.add('is-in');
                en.target.querySelectorAll('[data-count]').forEach(function (c) { countUp(c, 1400); });
                io.unobserve(en.target);
            });
        }, { rootMargin: '0px 0px -12% 0px', threshold: 0.12 });

        // Los hijos de un [data-stagger] entran uno tras otro.
        document.querySelectorAll('[data-stagger]').forEach(function (g) {
            Array.prototype.forEach.call(g.children, function (c, i) {
                if (!c.hasAttribute('data-reveal')) c.setAttribute('data-reveal', '');
                c.style.setProperty('--d', i);
            });
        });
        document.querySelectorAll('[data-reveal]').forEach(function (el) { io.observe(el); });

        // ── Hero: contadores y la cita que se confirma sola ──────────────
        setTimeout(function () {
            document.querySelectorAll('.lp-hero [data-count]').forEach(function (c) { countUp(c, 1600); });
        }, 700);

        var flip = document.querySelector('.lp-flip'), toast = document.querySelector('.lp-toast');
        if (flip) {
            setTimeout(function () {
                flip.textContent = 'Confirmada';
                flip.classList.remove('bg-secondary');
                flip.classList.add('bg-info', 'is-pop');
                if (toast) {
                    toast.classList.add('is-on');
                    setTimeout(function () { toast.classList.remove('is-on'); }, 4200);
                }
            }, 2600);
        }

        // ── Barra de lectura ─────────────────────────────────────────────
        var bar = document.createElement('div');
        bar.className = 'lp-read';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
        var ticking = false;
        function paint() {
            var h = document.documentElement.scrollHeight - innerHeight;
            bar.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, scrollY / h) : 0) + ')';
            ticking = false;
        }
        addEventListener('scroll', function () {
            if (!ticking) { ticking = true; requestAnimationFrame(paint); }
        }, { passive: true });
        paint();
    });
})();
