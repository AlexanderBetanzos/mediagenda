/**
 * Cuerpo humano 3D (three.js) para el mapa corporal.
 *
 * El cuerpo se construye por código (sin modelos externos): cada parte es una
 * superficie de revolución con su perfil anatómico, lo que permite saber en
 * qué zona cae un clic (cabeza, tórax, corazón, hígado, brazos…) y colorearla
 * al pasar el cursor.
 *
 *   window.Cuerpo3D.montar(contenedor, {
 *       sexo: 'F'|'M', modo: 'piel'|'anatomia',
 *       pins: [{ id, x, y, z, color, titulo, region }],
 *       regiones: { clave: 'Etiqueta' },
 *       onPick: ({x, y, z, region}) => {}, onPin: (id) => {}
 *   }) → { vista(nombre), modo(m), fantasma(p|null), destacar(id) }
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { MarchingCubes } from 'three/addons/objects/MarchingCubes.js';

const V = (x, y, z) => new THREE.Vector3(x, y, z);

/* ── Cuerpo como campo de distancia (SDF) con uniones suaves ──
 * Cada parte es un cono redondeado o un elipsoide; se funden con smooth-min
 * y la superficie se extrae con marching cubes: una sola malla orgánica,
 * sin costuras en hombros, caderas ni muñecas. */
const RC = 'rc', EL = 'el';
function prims(F) {
    const P = [];
    const rc = (a, b, r1, r2, parte, k = 0.035) => P.push({ t: RC, a, b, r1, r2, parte, k });
    const el = (c, r, parte, k = 0.035) => P.push({ t: EL, c, r, parte, k });
    // Cabeza
    el([0, 1.605, 0.006], [0.088, 0.112, 0.102], 'cabeza', 0.02);
    el([0, 1.545, 0.03], [0.064, 0.05, 0.068], 'cabeza', 0.04);
    el([0, 1.6, 0.1], [0.014, 0.024, 0.018], 'cabeza', 0.012);
    [-1, 1].forEach(s => el([s * 0.088, 1.598, -0.004], [0.012, 0.028, 0.02], 'oreja', 0.01));
    // Cuello y trapecios
    rc([0, 1.42, -0.012], [0, 1.545, 0.0], 0.06, 0.05, 'cuello', 0.04);
    rc([-0.15, 1.425, -0.02], [0.15, 1.425, -0.02], 0.055, 0.055, 'torso', 0.06);
    // Tronco
    if (F) {
        el([0, 1.32, -0.005], [0.15, 0.13, 0.092], 'torso', 0.06);
        el([0, 1.15, 0.0], [0.122, 0.13, 0.085], 'torso', 0.07);
        el([0, 0.975, -0.012], [0.162, 0.108, 0.096], 'torso', 0.07);
        [-1, 1].forEach(s => el([s * 0.068, 1.28, 0.06], [0.062, 0.058, 0.055], 'torso', 0.04));
    } else {
        el([0, 1.32, -0.005], [0.168, 0.14, 0.1], 'torso', 0.06);
        el([0, 1.15, 0.0], [0.136, 0.13, 0.086], 'torso', 0.07);
        el([0, 0.978, -0.012], [0.145, 0.1, 0.09], 'torso', 0.07);
        [-1, 1].forEach(s => el([s * 0.074, 1.33, 0.055], [0.078, 0.055, 0.045], 'torso', 0.04));
    }
    [-1, 1].forEach(s => el([s * (F ? 0.07 : 0.064), 0.925, -0.055], F ? [0.08, 0.088, 0.068] : [0.07, 0.08, 0.06], 'torso', 0.05));
    // Brazos
    [-1, 1].forEach(s => {
        el([s * 0.188, 1.395, -0.01], [0.062, 0.07, 0.062], 'brazo', 0.04);
        rc([s * 0.198, 1.37, -0.012], [s * 0.25, 1.12, -0.022], F ? 0.05 : 0.059, F ? 0.038 : 0.044, 'brazo', 0.03);
        rc([s * 0.25, 1.12, -0.022], [s * 0.28, 0.87, 0.026], F ? 0.04 : 0.047, F ? 0.026 : 0.03, 'brazo', 0.02);
        el([s * 0.288, 0.805, 0.034], [0.025, 0.058, 0.045], 'mano', 0.02);
        rc([s * 0.29, 0.78, 0.036], [s * 0.295, 0.705, 0.042], 0.017, 0.011, 'mano', 0.015);
        rc([s * 0.29, 0.78, 0.06], [s * 0.296, 0.708, 0.06], 0.015, 0.01, 'mano', 0.012);
        rc([s * 0.29, 0.78, 0.012], [s * 0.294, 0.716, 0.018], 0.015, 0.01, 'mano', 0.012);
        rc([s * 0.272, 0.81, 0.064], [s * 0.266, 0.765, 0.078], 0.012, 0.009, 'mano', 0.012);
    });
    // Piernas
    [-1, 1].forEach(s => {
        rc([s * 0.086, 0.94, 0.0], [s * 0.097, 0.5, 0.012], F ? 0.094 : 0.09, 0.05, 'pierna', 0.06);
        el([s * 0.097, 0.5, 0.02], [0.046, 0.05, 0.048], 'pierna', 0.03);
        rc([s * 0.097, 0.48, 0.006], [s * 0.097, 0.3, -0.016], 0.048, 0.054, 'pierna', 0.04);
        rc([s * 0.097, 0.3, -0.016], [s * 0.097, 0.085, -0.008], 0.052, 0.026, 'pierna', 0.04);
        rc([s * 0.098, 0.06, -0.03], [s * 0.102, 0.03, 0.125], 0.034, 0.026, 'pie', 0.03);
        el([s * 0.1, 0.035, 0.07], [0.04, 0.026, 0.06], 'pie', 0.03);
    });
    P.forEach(q => {   // caja envolvente para no evaluar lo lejano
        const pad = q.k + 0.02;
        if (q.t === EL) { q.min = q.c.map((v, i) => v - q.r[i] - pad); q.max = q.c.map((v, i) => v + q.r[i] + pad); }
        else { const r = Math.max(q.r1, q.r2) + pad; q.min = [0, 1, 2].map(i => Math.min(q.a[i], q.b[i]) - r); q.max = [0, 1, 2].map(i => Math.max(q.a[i], q.b[i]) + r); }
        if (q.t === RC) { q.ba = [0, 1, 2].map(i => q.b[i] - q.a[i]); q.bb = q.ba[0] ** 2 + q.ba[1] ** 2 + q.ba[2] ** 2; }
    });
    return P;
}
function dPrim(q, x, y, z) {
    if (q.t === EL) {
        const px = (x - q.c[0]) / q.r[0], py = (y - q.c[1]) / q.r[1], pz = (z - q.c[2]) / q.r[2];
        const k0 = Math.sqrt(px * px + py * py + pz * pz);
        const k1 = Math.sqrt((px / q.r[0]) ** 2 + (py / q.r[1]) ** 2 + (pz / q.r[2]) ** 2);
        return k1 > 1e-9 ? k0 * (k0 - 1) / k1 : -Math.min(...q.r);
    }
    const pa0 = x - q.a[0], pa1 = y - q.a[1], pa2 = z - q.a[2];
    let h = (pa0 * q.ba[0] + pa1 * q.ba[1] + pa2 * q.ba[2]) / q.bb; h = h < 0 ? 0 : h > 1 ? 1 : h;
    const dx = pa0 - q.ba[0] * h, dy = pa1 - q.ba[1] * h, dz = pa2 - q.ba[2] * h;
    return Math.sqrt(dx * dx + dy * dy + dz * dz) - (q.r1 + (q.r2 - q.r1) * h);
}
function smin(a, b, k) { const h = Math.max(k - Math.abs(a - b), 0) / k; return Math.min(a, b) - h * h * k * 0.25; }
function campo(P, x, y, z) {
    let d = 1;
    for (let i = 0; i < P.length; i++) {
        const q = P[i];
        if (x < q.min[0] || x > q.max[0] || y < q.min[1] || y > q.max[1] || z < q.min[2] || z > q.max[2]) continue;
        d = smin(d, dPrim(q, x, y, z), q.k);
    }
    return d;
}

/* Extrae la superficie y la hornea en una geometría fija, con la parte del
 * cuerpo de cada vértice (para resaltar y para saber la zona de un clic). */
function construir(sexo, MarchingCubes, res = 96) {
    const P = prims(sexo === 'F');
    const C = [0, 0.9, 0], E = [0.36, 0.93, 0.2];      // centro y semiejes del volumen
    const mc = new MarchingCubes(res, new THREE.MeshBasicMaterial(), false, false, 900000);
    mc.isolation = 0;
    const half = res / 2, f = mc.field;
    for (let k = 0; k < res; k++) {
        const z = C[2] + E[2] * (k - half) / half;
        for (let j = 0; j < res; j++) {
            const y = C[1] + E[1] * (j - half) / half;
            for (let i = 0; i < res; i++) {
                const x = C[0] + E[0] * (i - half) / half;
                f[i + j * res + k * res * res] = -campo(P, x, y, z);
            }
        }
    }
    mc.update();
    const n = mc.count, pos = new Float32Array(n * 3), nor = new Float32Array(n * 3);
    for (let v = 0; v < n; v++) {
        for (let a = 0; a < 3; a++) pos[v * 3 + a] = C[a] + E[a] * mc.positionArray[v * 3 + a];
        const nx = mc.normalArray[v * 3] / E[0], ny = mc.normalArray[v * 3 + 1] / E[1], nz = mc.normalArray[v * 3 + 2] / E[2];
        const l = Math.hypot(nx, ny, nz) || 1;
        nor[v * 3] = nx / l; nor[v * 3 + 1] = ny / l; nor[v * 3 + 2] = nz / l;
    }
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    g.setAttribute('normal', new THREE.BufferAttribute(nor, 3));
    // Parte de cada vértice: la primitiva más cercana.
    const partes = ['cabeza', 'oreja', 'cuello', 'torso', 'brazo', 'mano', 'pierna', 'pie'];
    const idx = new Uint8Array(n), col = new Float32Array(n * 3).fill(1);
    for (let v = 0; v < n; v++) {
        const x = pos[v * 3], y = pos[v * 3 + 1], z = pos[v * 3 + 2];
        let best = 1e9, pb = 'torso';
        for (const q of P) {
            if (x < q.min[0] || x > q.max[0] || y < q.min[1] || y > q.max[1] || z < q.min[2] || z > q.max[2]) continue;
            const d = dPrim(q, x, y, z); if (d < best) { best = d; pb = q.parte; }
        }
        idx[v] = partes.indexOf(pb);
    }
    g.setAttribute('color', new THREE.BufferAttribute(col, 3));
    g.computeBoundingSphere();
    mc.geometry.dispose();
    return { geo: g, idx, partes };
}

/* Zona anatómica de un punto (coordenadas del cuerpo: y hacia arriba,
 * el paciente mira a +z, su izquierda es +x). */
function zonaDe(parte, p) {
    if (parte === 'cabeza') {
        if (p.z > 0.06 && p.y > 1.6 && p.y < 1.66) return 'ojos';
        if (Math.abs(p.x) > 0.08 && p.y > 1.55 && p.y < 1.64) return 'oidos';
        return 'cabeza';
    }
    if (parte === 'oreja') return 'oidos';
    if (parte === 'cuello') return 'garganta';
    if (parte === 'brazo') return 'brazos';
    if (parte === 'mano') return 'manos';
    if (parte === 'pierna') return 'piernas';
    if (parte === 'pie') return 'pies';
    if (parte === 'torso') {
        const atras = p.z < -0.02;
        if (p.y < 0.99) return 'pelvis';
        if (atras) return (p.y > 1.06 && p.y < 1.17 && Math.abs(p.x) > 0.03 && Math.abs(p.x) < 0.12) ? 'rinones' : 'espalda';
        if (p.y >= 1.21) {
            if (p.x > -0.01 && p.x < 0.085 && p.y < 1.36) return 'corazon';
            if (Math.abs(p.x) > 0.075) return 'pulmones';
            return 'torax';
        }
        if (p.y >= 1.11) { if (p.x < -0.015) return 'higado'; if (p.x > 0.015) return 'estomago'; }
        return 'abdomen';
    }
    return 'general';
}

function crearRenderer(cont) {
    const r = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    r.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    r.setSize(cont.clientWidth, cont.clientHeight);
    r.outputColorSpace = THREE.SRGBColorSpace;
    r.toneMapping = THREE.ACESFilmicToneMapping;
    r.toneMappingExposure = 1.05;
    cont.appendChild(r.domElement);
    return r;
}

const MATS = {
    piel:     { color: 0xf0cdb8, roughness: 0.62, sheen: 0.5, sheenColor: 0xffd9c7, clearcoat: 0.05 },
    anatomia: { color: 0xb5473a, roughness: 0.48, sheen: 0.8, sheenColor: 0xff9a8a, clearcoat: 0.25 },
    holograma:{ color: 0x8fb4ff, roughness: 0.25, metalness: 0.1, transparent: true, opacity: 0.55, clearcoat: 0.6 },
};

function montar(cont, op = {}) {
    cont.innerHTML = '';
    cont.style.position = cont.style.position || 'relative';
    const escena = new THREE.Scene();
    const cam = new THREE.PerspectiveCamera(30, cont.clientWidth / cont.clientHeight, 0.05, 20);
    cam.position.set(0, 1.05, 4.1);
    const ren = crearRenderer(cont);

    // Luz de estudio: ambiente, clave, relleno y contraluz
    escena.add(new THREE.HemisphereLight(0xffffff, 0xd9d4ff, 1.1));
    const clave = new THREE.DirectionalLight(0xffffff, 2.2); clave.position.set(1.6, 2.6, 2.4); escena.add(clave);
    const relleno = new THREE.DirectionalLight(0xdfe6ff, 0.9); relleno.position.set(-2, 1.2, 1.5); escena.add(relleno);
    const contra = new THREE.DirectionalLight(0xa5b4fc, 1.6); contra.position.set(0, 2, -2.5); escena.add(contra);

    // Sombra de contacto suave
    const cv = document.createElement('canvas'); cv.width = cv.height = 128;
    const cx = cv.getContext('2d'), gr = cx.createRadialGradient(64, 64, 4, 64, 64, 62);
    gr.addColorStop(0, 'rgba(49,46,129,.32)'); gr.addColorStop(1, 'rgba(49,46,129,0)');
    cx.fillStyle = gr; cx.fillRect(0, 0, 128, 128);
    const sombra = new THREE.Mesh(new THREE.PlaneGeometry(1.1, 0.7), new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(cv), transparent: true, depthWrite: false }));
    sombra.rotation.x = -Math.PI / 2; sombra.position.y = 0.002; escena.add(sombra);
    // Plataforma
    const base = new THREE.Mesh(new THREE.RingGeometry(0.42, 0.44, 64), new THREE.MeshBasicMaterial({ color: 0x6366f1, transparent: true, opacity: 0.35 }));
    base.rotation.x = -Math.PI / 2; base.position.y = 0.003; escena.add(base);

    const cuerpo = new THREE.Group(); escena.add(cuerpo);
    let modo = op.modo || 'piel';
    const B = construir(op.sexo, MarchingCubes, op.resolucion || 96);
    const malla = new THREE.Mesh(B.geo, new THREE.MeshPhysicalMaterial(Object.assign({ vertexColors: true }, MATS[modo])));
    malla.material.color.setHex(0xffffff);
    cuerpo.add(malla);
    const mallas = [malla];
        function pintar(parteResaltada) {
        const c = B.geo.attributes.color, base = new THREE.Color(MATS[modo].color), hi = base.clone().lerp(new THREE.Color(modo === 'anatomia' ? 0xffd2c4 : 0x818cf8), 0.38);
        const ip = parteResaltada == null ? -1 : B.partes.indexOf(parteResaltada);
        for (let v = 0; v < B.idx.length; v++) { const k = B.idx[v] === ip ? hi : base; c.array[v * 3] = k.r; c.array[v * 3 + 1] = k.g; c.array[v * 3 + 2] = k.b; }
        c.needsUpdate = true;
    }
    pintar(null);

    const ctl = new OrbitControls(cam, ren.domElement);
    ctl.target.set(0, 0.95, 0); ctl.enableDamping = true; ctl.dampingFactor = 0.08;
    ctl.minDistance = 1.2; ctl.maxDistance = 5.5; ctl.minPolarAngle = 0.35; ctl.maxPolarAngle = 2.1;
    ctl.enablePan = true; ctl.screenSpacePanning = true; ctl.autoRotate = true; ctl.autoRotateSpeed = 0.9;
    ctl.update();
    const parar = () => { ctl.autoRotate = false; };
    ren.domElement.addEventListener('pointerdown', parar);

    // Marcadores
    const capa = document.createElement('div'); capa.className = 'c3d-capa'; cont.appendChild(capa);
    const pins = [];
    const geoPin = new THREE.SphereGeometry(0.014, 20, 14), geoHalo = new THREE.SphereGeometry(0.03, 20, 14);
    function ponerPin(p, fantasma) {
        const col = new THREE.Color(p.color || '#2563eb');
        const g = new THREE.Group(); g.position.set(+p.x, +p.y, +p.z);
        g.add(new THREE.Mesh(geoPin, new THREE.MeshStandardMaterial({ color: col, emissive: col, emissiveIntensity: 0.6, roughness: 0.3 })));
        const halo = new THREE.Mesh(geoHalo, new THREE.MeshBasicMaterial({ color: col, transparent: true, opacity: 0.25, depthWrite: false }));
        g.add(halo); cuerpo.add(g);
        let et = null;
        if (p.titulo) {
            et = document.createElement('button'); et.type = 'button'; et.className = 'c3d-et' + (fantasma ? ' fant' : '');
            et.innerHTML = '<span style="background:' + (p.color || '#2563eb') + '"></span>' + escapar(p.titulo);
            if (p.id && op.onPin) et.addEventListener('click', () => op.onPin(p.id));
            capa.appendChild(et);
        }
        const o = { p, g, halo, et, fantasma }; pins.push(o); return o;
    }
    (op.pins || []).forEach(p => ponerPin(p));
    let fant = null;

    // Selección / zona bajo el cursor
    const ray = new THREE.Raycaster(), ptr = new THREE.Vector2();
    const tip = document.createElement('div'); tip.className = 'c3d-tip'; cont.appendChild(tip);
    let encima = null, abajo = null;
    function tocar(ev) {
        const r = ren.domElement.getBoundingClientRect();
        ptr.set(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1);
        ray.setFromCamera(ptr, cam);
        const h = ray.intersectObjects(mallas, false)[0];
        if (!h) return null;
        const p = cuerpo.worldToLocal(h.point.clone());
        const parte = B.partes[B.idx[h.face.a]] || 'torso';
        return { malla: h.object, parte, p, region: zonaDe(parte, p), ev };
    }
    let ultimaParte = null;
    function resaltar(parte, region) {
        if (parte !== ultimaParte) { ultimaParte = parte; pintar(parte || null); }
        if (region && op.regiones) { tip.textContent = op.regiones[region] || region; tip.style.opacity = 1; } else tip.style.opacity = 0;
    }
    ren.domElement.addEventListener('pointermove', ev => {
        const t = tocar(ev);
        encima = t; resaltar(t && t.parte, t && t.region);
        if (t) { const r = cont.getBoundingClientRect(); tip.style.left = (ev.clientX - r.left + 14) + 'px'; tip.style.top = (ev.clientY - r.top + 10) + 'px'; }
        ren.domElement.style.cursor = t ? 'crosshair' : 'grab';
    });
    ren.domElement.addEventListener('pointerleave', () => resaltar(null));
    ren.domElement.addEventListener('pointerdown', ev => { abajo = [ev.clientX, ev.clientY]; });
    ren.domElement.addEventListener('pointerup', ev => {
        if (!abajo || Math.hypot(ev.clientX - abajo[0], ev.clientY - abajo[1]) > 5) return;
        const t = tocar(ev); if (!t || !op.onPick) return;
        const d = { x: +t.p.x.toFixed(3), y: +t.p.y.toFixed(3), z: +t.p.z.toFixed(3), region: t.region };
        api.fantasma(d); op.onPick(d);
    });

    // Bucle
    const vp = new THREE.Vector3();
    let vivo = true, t0 = performance.now();
    function cuadro() {
        if (!vivo) return;
        requestAnimationFrame(cuadro);
        ctl.update();
        const t = (performance.now() - t0) / 1000;
        const w = cont.clientWidth, h = cont.clientHeight;
        pins.forEach((o, i) => {
            const s = 1 + 0.35 * Math.sin(t * 3 + i);
            o.halo.scale.setScalar(s); o.halo.material.opacity = 0.32 - 0.12 * s + 0.1;
            if (!o.et) return;
            o.g.getWorldPosition(vp);
            const n = vp.clone().sub(cam.position).normalize(), haciaCam = o.g.position.clone().setY(0).normalize();
            vp.project(cam);
            const visible = vp.z < 1 && Math.abs(vp.x) < 1.1 && Math.abs(vp.y) < 1.1;
            // Se atenúa si el punto queda del otro lado del cuerpo.
            const delante = haciaCam.lengthSq() === 0 || haciaCam.dot(cam.position.clone().setY(0).normalize()) > -0.2;
            o.et.style.transform = 'translate(' + ((vp.x + 1) / 2 * w + 10) + 'px,' + ((1 - vp.y) / 2 * h - 14) + 'px)';
            o.et.style.opacity = visible ? (delante ? 1 : 0.28) : 0;
        });
        ren.render(escena, cam);
    }
    cuadro();
    const ro = new ResizeObserver(() => {
        const w = cont.clientWidth, h = cont.clientHeight; if (!w || !h) return;
        cam.aspect = w / h; cam.updateProjectionMatrix(); ren.setSize(w, h);
    });
    ro.observe(cont);

    const api = {
        vista(n) {
            parar();
            const d = cam.position.distanceTo(ctl.target);
            const ang = { frente: 0, espalda: Math.PI, derecha: -Math.PI / 2, izquierda: Math.PI / 2 }[n] ?? 0;
            cam.position.set(Math.sin(ang) * d, ctl.target.y + 0.1, Math.cos(ang) * d);
            ctl.update();
        },
        reiniciar() { ctl.target.set(0, 0.95, 0); cam.position.set(0, 1.05, 4.1); ctl.autoRotate = true; ctl.update(); },
        modo(m) {
            modo = m; malla.material.dispose();
            malla.material = new THREE.MeshPhysicalMaterial(Object.assign({ vertexColors: true }, MATS[m])); malla.material.color.setHex(0xffffff);
            pintar(ultimaParte);
        },
        fantasma(p) {
            if (fant) { cuerpo.remove(fant.g); if (fant.et) fant.et.remove(); pins.splice(pins.indexOf(fant), 1); fant = null; }
            if (p) fant = ponerPin({ x: p.x, y: p.y, z: p.z, color: '#2563eb', titulo: op.regiones ? (op.regiones[p.region] || p.region) : '' }, true);
        },
        destacar(id) { pins.forEach(o => { if (o.et) o.et.classList.toggle('on', o.p.id === id); }); },
        destruir() { vivo = false; ro.disconnect(); ren.dispose(); cont.innerHTML = ''; },
    };
    return api;
}

function escapar(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

window.Cuerpo3D = { montar, zonaDe };
window.dispatchEvent(new Event('cuerpo3d:listo'));
