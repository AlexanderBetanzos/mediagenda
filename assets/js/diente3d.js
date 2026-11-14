/**
 * Diente 3D (three.js) para el detalle de pieza de la carta dental.
 *
 * La pieza se genera por código según su tipo (incisivo, canino, premolar,
 * molar; superior o inferior): corona con sus cúspides y raíces fundidas en
 * una sola superficie suave. Encima se pinta lo registrado: caries u
 * obturaciones por cara, corona, endodoncia (conductos visibles), implante,
 * ausente y extracción planeada.
 *
 *   window.Diente3D.montar(contenedor, { diente: 36, marcas: {C:{…}, O:{…}}, colores: {…} })
 *     → { actualizar(diente, marcas), destruir() }
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { MarchingCubes } from 'three/addons/objects/MarchingCubes.js';

const CEJ = 0.0;   // unión amelocementaria: corona hacia +y, raíz hacia −y

function claseDe(d) { const u = d % 10; return u <= 2 ? 'inc' : u === 3 ? 'can' : u <= 5 ? 'pre' : 'mol'; }
function esSup(d) { const q = Math.floor(d / 10); return q === 1 || q === 2; }

/* Primitivas de la pieza (unidades ~cm). x = mesiodistal, z = vestíbulo-lingual. */
function prims(d, conRaiz) {
    const c = claseDe(d), sup = esSup(d), u = d % 10, P = [];
    const el = (cx, cy, cz, rx, ry, rz, k = 0.12, z = 'corona') => P.push({ t: 'el', c: [cx, cy, cz], r: [rx, ry, rz], k, z });
    const rc = (a, b, r1, r2, k = 0.12, z = 'raiz') => P.push({ t: 'rc', a, b, r1, r2, k, z });
    if (c === 'inc') {
        const w = u === 1 ? 0.44 : 0.34;
        el(0, 0.45, 0, w, 0.5, 0.2, 0.15);
        el(0, 0.85, 0.02, w * 0.92, 0.12, 0.08, 0.15);
        el(0, 0.12, -0.03, w * 0.75, 0.2, 0.27, 0.18);             // cíngulo
        if (conRaiz) rc([0, 0.05, 0], [0, -1.35, -0.02], 0.25, 0.06);
    } else if (c === 'can') {
        el(0, 0.45, 0, 0.4, 0.52, 0.28, 0.15);
        el(0, 0.95, 0.03, 0.12, 0.12, 0.12, 0.2);                  // punta
        if (conRaiz) rc([0, 0.05, 0], [0, -1.75, -0.02], 0.28, 0.06);
    } else if (c === 'pre') {
        el(0, 0.4, 0, 0.36, 0.42, 0.42, 0.15);
        el(0, 0.76, 0.17, 0.16, 0.15, 0.14, 0.14);                 // cúspide vestibular
        el(0, 0.72, -0.17, 0.14, 0.12, 0.13, 0.14);                // cúspide lingual
        if (conRaiz) {
            if (sup && u === 4) { rc([0, 0.05, 0.12], [0, -1.35, 0.15], 0.17, 0.05); rc([0, 0.05, -0.12], [0, -1.3, -0.16], 0.16, 0.05); }
            else rc([0, 0.05, 0], [0, -1.4, 0], 0.27, 0.06);
        }
    } else {
        const w = u === 8 ? 0.48 : 0.54;
        el(0, 0.38, 0, w, 0.42, 0.5, 0.18);
        [[-1, 1], [1, 1], [-1, -1], [1, -1]].forEach(([sx, sz]) => el(sx * w * 0.48, 0.72, sz * 0.25, 0.2, 0.16, 0.19, 0.16));
        if (!sup && u === 6) el(w * 0.72, 0.62, 0.02, 0.13, 0.12, 0.13, 0.12); // cúspide distal
        if (conRaiz) {
            if (sup) {   // tres raíces: dos vestibulares y una palatina
                rc([-0.24, 0.05, 0.18], [-0.32, -1.2, 0.26], 0.17, 0.05);
                rc([0.24, 0.05, 0.18], [0.3, -1.15, 0.26], 0.16, 0.05);
                rc([0, 0.05, -0.2], [0, -1.3, -0.36], 0.19, 0.05);
            } else {     // dos raíces: mesial y distal
                rc([-0.27, 0.05, 0], [-0.36, -1.35, 0], 0.2, 0.05);
                rc([0.27, 0.05, 0], [0.34, -1.25, 0], 0.18, 0.05);
            }
        }
    }
    P.forEach(q => {
        const pad = q.k + 0.05;
        if (q.t === 'el') { q.min = q.c.map((v, i) => v - q.r[i] - pad); q.max = q.c.map((v, i) => v + q.r[i] + pad); }
        else { const r = Math.max(q.r1, q.r2) + pad; q.min = [0, 1, 2].map(i => Math.min(q.a[i], q.b[i]) - r); q.max = [0, 1, 2].map(i => Math.max(q.a[i], q.b[i]) + r); q.ba = [0, 1, 2].map(i => q.b[i] - q.a[i]); q.bb = q.ba[0] ** 2 + q.ba[1] ** 2 + q.ba[2] ** 2; }
    });
    return P;
}
function dPrim(q, x, y, z) {
    if (q.t === 'el') {
        const px = (x - q.c[0]) / q.r[0], py = (y - q.c[1]) / q.r[1], pz = (z - q.c[2]) / q.r[2];
        const k0 = Math.hypot(px, py, pz), k1 = Math.hypot(px / q.r[0], py / q.r[1], pz / q.r[2]);
        return k1 > 1e-9 ? k0 * (k0 - 1) / k1 : -Math.min(...q.r);
    }
    const a0 = x - q.a[0], a1 = y - q.a[1], a2 = z - q.a[2];
    let h = (a0 * q.ba[0] + a1 * q.ba[1] + a2 * q.ba[2]) / q.bb; h = h < 0 ? 0 : h > 1 ? 1 : h;
    return Math.hypot(a0 - q.ba[0] * h, a1 - q.ba[1] * h, a2 - q.ba[2] * h) - (q.r1 + (q.r2 - q.r1) * h);
}
function smin(a, b, k) { const h = Math.max(k - Math.abs(a - b), 0) / k; return Math.min(a, b) - h * h * k * 0.25; }

function malla(P, res = 72) {
    const C = [0, -0.4, 0], E = [0.75, 1.5, 0.7];
    const mc = new MarchingCubes(res, new THREE.MeshBasicMaterial(), false, false, 400000);
    mc.isolation = 0;
    const half = res / 2, f = mc.field;
    for (let k = 0; k < res; k++) {
        const z = C[2] + E[2] * (k - half) / half;
        for (let j = 0; j < res; j++) {
            const y = C[1] + E[1] * (j - half) / half;
            for (let i = 0; i < res; i++) {
                const x = C[0] + E[0] * (i - half) / half;
                let dd = 1;
                for (const q of P) {
                    if (x < q.min[0] || x > q.max[0] || y < q.min[1] || y > q.max[1] || z < q.min[2] || z > q.max[2]) continue;
                    dd = smin(dd, dPrim(q, x, y, z), q.k);
                }
                f[i + j * res + k * res * res] = -dd;
            }
        }
    }
    mc.update();
    const n = mc.count, pos = new Float32Array(n * 3), nor = new Float32Array(n * 3);
    for (let v = 0; v < n; v++) {
        for (let a = 0; a < 3; a++) pos[v * 3 + a] = C[a] + E[a] * mc.positionArray[v * 3 + a];
        const nx = mc.normalArray[v * 3] / E[0], ny = mc.normalArray[v * 3 + 1] / E[1], nz = mc.normalArray[v * 3 + 2] / E[2], l = Math.hypot(nx, ny, nz) || 1;
        nor[v * 3] = nx / l; nor[v * 3 + 1] = ny / l; nor[v * 3 + 2] = nz / l;
    }
    mc.geometry.dispose();
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    g.setAttribute('normal', new THREE.BufferAttribute(nor, 3));
    g.setAttribute('color', new THREE.BufferAttribute(new Float32Array(n * 3), 3));
    return g;
}

/* Cara de la corona a la que pertenece un punto (vista anatómica: mesial
 * hacia −x, vestibular hacia +z). */
function caraDe(x, y, z) {
    if (y < CEJ + 0.02) return null;
    if (y > 0.62 && Math.abs(x) < 0.32 && Math.abs(z) < 0.3) return 'O';
    const ax = Math.abs(x), az = Math.abs(z);
    if (ax > az) return x < 0 ? 'M' : 'D';
    return z > 0 ? 'V' : 'L';
}

function montar(cont, op = {}) {
    cont.innerHTML = '';
    const escena = new THREE.Scene();
    const cam = new THREE.PerspectiveCamera(32, cont.clientWidth / cont.clientHeight, 0.05, 50);
    cam.position.set(2.4, 0.6, 5.2);
    const ren = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    ren.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    ren.setSize(cont.clientWidth, cont.clientHeight);
    ren.outputColorSpace = THREE.SRGBColorSpace; ren.toneMapping = THREE.ACESFilmicToneMapping; ren.toneMappingExposure = 1.1;
    cont.appendChild(ren.domElement);
    escena.add(new THREE.HemisphereLight(0xffffff, 0xd6d3ff, 1.2));
    const l1 = new THREE.DirectionalLight(0xffffff, 2.4); l1.position.set(2, 3, 3); escena.add(l1);
    const l2 = new THREE.DirectionalLight(0xc7d2fe, 1.4); l2.position.set(-3, 1, -2); escena.add(l2);

    const grupo = new THREE.Group(); escena.add(grupo);
    const ctl = new OrbitControls(cam, ren.domElement);
    ctl.target.set(0, -0.25, 0); ctl.enableDamping = true; ctl.enablePan = false; ctl.minDistance = 2.5; ctl.maxDistance = 9;
    ctl.autoRotate = true; ctl.autoRotateSpeed = 1.6; ctl.update();
    ren.domElement.addEventListener('pointerdown', () => { ctl.autoRotate = false; });

    const COL = Object.assign({ plan: '#e11d48', hecho: '#2563eb' }, op.colores || {});
    let piezas = [];
    function limpiar() { piezas.forEach(o => { grupo.remove(o); o.traverse?.(x => { x.geometry?.dispose(); x.material?.dispose?.(); }); }); piezas = []; }
    const agregar = o => { grupo.add(o); piezas.push(o); return o; };

    function construir(d, m) {
        limpiar();
        m = m || {};
        const C = m.C || {};
        const ausente = C.existente === 'ausente';
        const implante = [C.existente, C.realizado, C.requerido].includes('implante');
        const endo = [C.existente, C.realizado, C.requerido].find(x => x === 'endodoncia') ? (C.requerido === 'endodoncia' ? COL.plan : C.realizado === 'endodoncia' ? COL.hecho : (op.hall?.endodoncia || '#6f42c1')) : null;
        const coronaCol = ['existente', 'realizado', 'requerido'].reduce((acc, k) => (C[k] === 'corona' || C[k] === 'protesis') ? (k === 'requerido' ? COL.plan : k === 'realizado' ? COL.hecho : (C[k] === 'corona' ? '#f4c95d' : '#9ca3af')) : acc, null);

        const P = prims(d, !implante);
        const g = malla(P);
        // Colores por vértice: esmalte, raíz y lo registrado por cara.
        const pos = g.attributes.position.array, col = g.attributes.color.array;
        const esmalte = new THREE.Color('#f8fafc'), raiz = new THREE.Color('#ead7b5'), tmp = new THREE.Color();
        const caraCol = {};
        ['O', 'M', 'D', 'V', 'L'].forEach(k => {
            const x = m[k] || {};
            if (x.requerido) caraCol[k] = new THREE.Color(COL.plan);
            else if (x.realizado) caraCol[k] = new THREE.Color(COL.hecho);
            else if (x.existente && op.hall?.[x.existente]) caraCol[k] = new THREE.Color(op.hall[x.existente]);
        });
        for (let v = 0; v < pos.length / 3; v++) {
            const x = pos[v * 3], y = pos[v * 3 + 1], z = pos[v * 3 + 2];
            if (y < CEJ) tmp.copy(raiz).lerp(esmalte, Math.max(0, (y + 0.15) / 0.15) * 0.4);
            else {
                tmp.copy(coronaCol ? new THREE.Color(coronaCol) : esmalte);
                const k = caraDe(x, y, z);
                if (!coronaCol && k && caraCol[k]) tmp.lerp(caraCol[k], 0.85);
            }
            col[v * 3] = tmp.r; col[v * 3 + 1] = tmp.g; col[v * 3 + 2] = tmp.b;
        }
        const mat = new THREE.MeshPhysicalMaterial({ vertexColors: true, roughness: coronaCol ? 0.25 : 0.32, clearcoat: 0.8, clearcoatRoughness: 0.2,
            metalness: coronaCol === '#f4c95d' ? 0.55 : 0.02, sheen: 0.3, transparent: !!endo || ausente, opacity: ausente ? 0.18 : endo ? 0.62 : 1, depthWrite: !(endo || ausente) });
        const diente = agregar(new THREE.Mesh(g, mat));
        if (ausente) agregar(new THREE.Mesh(g, new THREE.MeshBasicMaterial({ color: 0x94a3b8, wireframe: true, transparent: true, opacity: 0.25 })));

        // Conductos de la endodoncia
        if (endo && !implante) {
            const mt = new THREE.MeshStandardMaterial({ color: endo, emissive: endo, emissiveIntensity: 0.5 });
            P.filter(q => q.z === 'raiz').forEach(q => {
                const curva = new THREE.LineCurve3(new THREE.Vector3(...q.a).setY(0.55), new THREE.Vector3(...q.b).multiplyScalar(0.92));
                agregar(new THREE.Mesh(new THREE.TubeGeometry(curva, 12, 0.035, 10), mt));
            });
            const camara = new THREE.Mesh(new THREE.SphereGeometry(0.16, 20, 14), mt); camara.position.set(0, 0.42, 0); camara.scale.set(1.4, 0.7, 1.2); agregar(camara);
        }
        // Implante: tornillo de titanio en lugar de raíces
        if (implante) {
            const ti = new THREE.MeshPhysicalMaterial({ color: C.requerido === 'implante' ? COL.plan : 0xb8c2cc, metalness: 0.9, roughness: 0.28, clearcoat: 0.5 });
            const tornillo = new THREE.Group();
            tornillo.add(new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.13, 1.3, 32), ti));
            for (let i = 0; i < 9; i++) { const r = new THREE.Mesh(new THREE.TorusGeometry(0.205 - i * 0.008, 0.035, 10, 40), ti); r.rotation.x = Math.PI / 2; r.position.y = 0.55 - i * 0.14; tornillo.add(r); }
            const pilar = new THREE.Mesh(new THREE.CylinderGeometry(0.15, 0.2, 0.25, 24), ti); pilar.position.y = 0.75; tornillo.add(pilar);
            tornillo.position.y = -0.7; agregar(tornillo);
        }
        // Margen gingival (rojo) y cresta ósea (azul), como en la carta.
        const cl = claseDe(d), sx = cl === 'mol' ? 0.6 : cl === 'inc' ? 0.36 : cl === 'can' ? 0.4 : 0.42, sz = cl === 'mol' ? 0.55 : cl === 'inc' ? 0.27 : cl === 'can' ? 0.32 : 0.44;
        [[CEJ + 0.04, 0xe11d48, 0.018, 1.0], [CEJ - 0.22, 0x60a5fa, 0.024, 0.55]].forEach(([y, c, r, o]) => {
            const t = new THREE.Mesh(new THREE.TorusGeometry(1, r, 10, 72), new THREE.MeshBasicMaterial({ color: c, transparent: true, opacity: o }));
            t.rotation.x = Math.PI / 2; t.position.y = y; t.scale.set(sx, sz, 1); agregar(t);
        });
        // Extracción planeada: anillo rojo
        if (C.requerido === 'extraccion') {
            const a = new THREE.Mesh(new THREE.TorusGeometry(1.0, 0.025, 8, 80), new THREE.MeshBasicMaterial({ color: COL.plan })); a.rotation.x = Math.PI / 2; a.position.y = 0.3; agregar(a);
            mat.color = new THREE.Color(1, 0.82, 0.84);
        }
        // Superiores: corona hacia abajo, como en boca.
        grupo.rotation.z = esSup(d) ? Math.PI : 0;
        ctl.target.set(0, esSup(d) ? 0.25 : -0.25, 0); ctl.update();
        return diente;
    }
    construir(op.diente, op.marcas);

    let vivo = true;
    (function cuadro() { if (!vivo) return; requestAnimationFrame(cuadro); ctl.update(); ren.render(escena, cam); })();
    const ro = new ResizeObserver(() => { const w = cont.clientWidth, h = cont.clientHeight; if (!w || !h) return; cam.aspect = w / h; cam.updateProjectionMatrix(); ren.setSize(w, h); });
    ro.observe(cont);
    return {
        actualizar(d, m) { construir(d, m); },
        destruir() { vivo = false; ro.disconnect(); limpiar(); ren.dispose(); cont.innerHTML = ''; },
    };
}

window.Diente3D = { montar };
window.dispatchEvent(new Event('diente3d:listo'));
