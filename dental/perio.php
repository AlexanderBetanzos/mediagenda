<?php
/**
 * Periodontograma: 6 sitios por diente (vestibular y lingual/palatino:
 * distal, medio, mesial). Profundidad de sondaje (PS), margen gingival (MG,
 * positivo = recesión), sangrado al sondaje, placa, movilidad y furca.
 * La gráfica dibuja el margen (azul) y el fondo de la bolsa (rojo); el NIC
 * es PS + MG. Captura rápida: el cursor avanza solo al teclear cada valor.
 */
require __DIR__ . '/_comun.php';

$tid = tenant_id();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$ex  = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM dental_perio WHERE id = ? AND paciente_id = ? AND consultorio_id = ?');
    $st->execute([$id, $pid, $tid]);
    $ex = $st->fetch();
    if (!$ex) { http_response_code(404); die('Periodontograma no encontrado.'); }
}

/** Limpia lo que llega del editor: solo dientes FDI y valores en rango. */
$limpiar = function (array $in): array {
    $out = [];
    foreach (dientes_fdi() as $d) {
        $t = $in[$d] ?? $in[(string) $d] ?? null;
        if (!is_array($t)) continue;
        $n = fn ($v, $min, $max) => ($v === '' || $v === null || !is_numeric($v)) ? '' : (string) max($min, min($max, (int) $v));
        $r = ['ps' => [], 'mg' => [], 'bop' => [], 'pl' => []];
        for ($i = 0; $i < 6; $i++) {
            $r['ps'][]  = $n($t['ps'][$i] ?? '', 0, 20);
            $r['mg'][]  = $n($t['mg'][$i] ?? '', -10, 15);
            $r['bop'][] = !empty($t['bop'][$i]) ? 1 : 0;
            $r['pl'][]  = !empty($t['pl'][$i]) ? 1 : 0;
        }
        $r['mov'] = $n($t['mov'] ?? '', 0, 3);
        $r['furca'] = $n($t['furca'] ?? '', 0, 3);
        $r['aus'] = !empty($t['aus']) ? 1 : 0;
        $out[$d] = $r;
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['accion'] ?? '') === 'borrar' && $ex) {
        db()->prepare('DELETE FROM dental_perio WHERE id = ? AND consultorio_id = ?')->execute([$id, $tid]);
        flash(t('Periodontograma eliminado.'));
        redirect('/dental/perio?paciente_id=' . $pid);
    }
    $datos = $limpiar(json_decode($_POST['datos'] ?? '{}', true) ?: []);
    $ix = perio_indices($datos);
    $fila = [preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d'), json_encode($datos),
             $ix['bop'], $ix['placa'], $ix['ps'], $ix['s4'], $ix['s6'],
             mb_substr(trim((string) ($_POST['diagnostico'] ?? '')), 0, 255) ?: null, trim((string) ($_POST['notas'] ?? '')) ?: null];
    if ($ex) {
        db()->prepare('UPDATE dental_perio SET fecha=?, datos=?, bop_pct=?, placa_pct=?, ps_media=?, sitios4=?, sitios6=?, diagnostico=?, notas=? WHERE id = ? AND consultorio_id = ?')
            ->execute([...$fila, $id, $tid]);
    } else {
        db()->prepare('INSERT INTO dental_perio (consultorio_id, paciente_id, creado_por, fecha, datos, bop_pct, placa_pct, ps_media, sitios4, sitios6, diagnostico, notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $pid, $u['id'], ...$fila]);
        $id = (int) db()->lastInsertId();
    }
    auditar($ex ? 'editar' : 'crear', 'dental_perio', $id, 'Paciente #' . $pid);
    flash(t('Periodontograma guardado.'));
    redirect('/dental/perio?paciente_id=' . $pid . '&id=' . $id);
}

$lista = db()->prepare('SELECT id, fecha, bop_pct, placa_pct, ps_media, sitios4, sitios6, diagnostico FROM dental_perio WHERE paciente_id = ? AND consultorio_id = ? ORDER BY fecha DESC, id DESC');
$lista->execute([$pid, $tid]);
$lista = $lista->fetchAll();

$datos = $ex ? (json_decode((string) $ex['datos'], true) ?: []) : [];
if (!$ex) {
    // Nuevo: los dientes ausentes (o extraídos) del odontograma ya vienen marcados.
    $od = odo_cargar($pid)['marcas'];
    foreach ($od as $d => $porCara) {
        $c = $porCara['C'] ?? [];
        if (($c['existente'] ?? '') === 'ausente' || ($c['realizado'] ?? '') === 'extraccion') $datos[$d] = ['aus' => 1];
    }
}
// Examen anterior para comparar.
$prev = null;
foreach ($lista as $l) if (!$ex || ($l['fecha'] < $ex['fecha'] || ($l['fecha'] === $ex['fecha'] && (int) $l['id'] < (int) $ex['id']))) { $prev = $l; break; }

$titulo = t('Periodontograma') . ' · ' . $pac['nombre'];
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
dental_encabezado('Periodontograma', 'bi-rulers', $pac, $pid, 'perio');
?>
<style>
.pg-wrap{overflow-x:auto}
.pg{border-collapse:separate;border-spacing:0;min-width:980px;width:100%;font-size:.72rem}
.pg td,.pg th{padding:0;text-align:center}
.pg th.rl{text-align:right;padding-right:.4rem;font-weight:600;color:var(--bs-secondary-color);white-space:nowrap;width:78px}
.pg .tn{font-weight:700;font-size:.8rem;padding:.2rem 0}
.pg .gapc{width:10px}
.pg .tc{border-left:1px solid var(--bs-border-color-translucent)}
.pg .tc.aus{opacity:.3}
.trip{display:flex;gap:1px;justify-content:center}
.trip input{width:19px;height:21px;padding:0;text-align:center;border:1px solid var(--bs-border-color);border-radius:3px;font-size:.75rem;background:var(--bs-body-bg);color:var(--bs-body-color)}
.trip input:focus{outline:2px solid #2563eb;border-color:#2563eb}
.trip input.w4{background:#fee2e2;color:#991b1b;font-weight:700}.trip input.w6{background:#dc2626;color:#fff;font-weight:700}
.dots{display:flex;gap:3px;justify-content:center;padding:2px 0}
.dot{width:12px;height:12px;border-radius:50%;border:1.5px solid #cbd5e1;cursor:pointer;background:var(--bs-body-bg)}
.dot.b.on{background:#dc2626;border-color:#dc2626}.dot.p.on{background:#2563eb;border-color:#2563eb}
.sm{width:40px;height:21px;padding:0 2px;font-size:.72rem;border:1px solid var(--bs-border-color);border-radius:3px;background:var(--bs-body-bg);color:var(--bs-body-color)}
.ausb{font-size:.75rem;border:0;background:none;color:var(--bs-secondary-color);cursor:pointer;opacity:.55;padding:0}.ausb:hover{opacity:1;color:#dc2626}
.pg-chart{height:74px}
.pg-chart svg{width:100%;height:74px;display:block}
.pg-sep td{height:10px;border-bottom:2px solid var(--bs-border-color)}
.kpi{border:1px solid var(--bs-border-color);border-radius:.6rem;padding:.6rem .8rem}
.kpi .t{font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:var(--bs-secondary-color);font-weight:700}
.kpi .v{font-size:1.25rem;font-weight:700}
</style>

<form method="post" id="pgForm">
<?= csrf_field() ?><input type="hidden" name="paciente_id" value="<?= $pid ?>"><?php if ($ex): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
<input type="hidden" name="datos" id="pgDatos">
<div class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <div><label class="form-label small mb-0"><?= et('Examen') ?></label>
        <select class="form-select form-select-sm" onchange="location.href='<?= BASE_URL ?>/dental/perio?paciente_id=<?= $pid ?>'+(this.value?'&id='+this.value:'')">
            <option value=""><?= et('Nuevo periodontograma') ?></option>
            <?php foreach ($lista as $l): ?><option value="<?= (int) $l['id'] ?>" <?= $ex && (int) $ex['id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e(fmt_fecha($l['fecha'])) ?> · BOP <?= $l['bop_pct'] !== null ? (float) $l['bop_pct'] . '%' : '—' ?></option><?php endforeach; ?>
        </select></div>
    <div><label class="form-label small mb-0"><?= et('Fecha') ?></label><input type="date" name="fecha" class="form-control form-control-sm" value="<?= e($ex['fecha'] ?? date('Y-m-d')) ?>"></div>
    <div class="form-check ms-2 mb-1"><input class="form-check-input" type="checkbox" id="pgAuto" checked><label class="form-check-label small" for="pgAuto"><?= et('Avance automático') ?></label></div>
    <div class="ms-auto d-flex gap-2">
        <?php if ($ex): ?><a href="<?= BASE_URL ?>/dental/imprimir<?= $P ?>&modo=perio&id=<?= $id ?>" target="_blank" class="btn btn-sm btn-light"><i class="bi bi-printer"></i></a>
        <button class="btn btn-sm btn-outline-danger" name="accion" value="borrar" onclick="return confirm('<?= e(t('¿Eliminar este periodontograma?')) ?>')"><i class="bi bi-trash"></i></button><?php endif; ?>
        <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar') ?></button>
    </div>
</div>

<div class="row g-2 mb-3" id="pgKpis"></div>

<div class="card mb-3"><div class="card-body p-2 pg-wrap"><table class="pg" id="pgSup"></table></div></div>
<div class="card mb-3"><div class="card-body p-2 pg-wrap"><table class="pg" id="pgInf"></table></div></div>

<div class="card mb-4"><div class="card-body row g-2">
    <div class="col-12 col-md-5"><label class="form-label small"><?= et('Diagnóstico periodontal') ?></label><input type="text" name="diagnostico" class="form-control" maxlength="255" list="dlPerio" value="<?= e($ex['diagnostico'] ?? '') ?>">
        <datalist id="dlPerio"><option value="Salud periodontal"><option value="Gingivitis inducida por placa"><option value="Periodontitis estadio I"><option value="Periodontitis estadio II"><option value="Periodontitis estadio III"><option value="Periodontitis estadio IV"></datalist></div>
    <div class="col-12 col-md-7"><label class="form-label small"><?= et('Notas y plan periodontal') ?></label><input type="text" name="notas" class="form-control" value="<?= e($ex['notas'] ?? '') ?>"></div>
    <div class="col-12 small text-muted"><i class="bi bi-keyboard"></i> <?= et('Teclea los valores y el cursor avanza solo. Clic en los puntos para sangrado (rojo) y placa (azul). MG positivo = recesión; negativo = agrandamiento.') ?></div>
</div></div>
</form>

<script>
(function () {
var D = <?= json_encode((object) $datos) ?>;
var PREV = <?= json_encode($prev ? ['fecha' => fmt_fecha($prev['fecha']), 'bop' => $prev['bop_pct'], 'placa' => $prev['placa_pct'], 'ps' => $prev['ps_media'], 's4' => $prev['sitios4'], 's6' => $prev['sitios6']] : null) ?>;
var T = <?= json_encode(['bop' => t('Sangrado (BOP)'), 'placa' => t('Placa'), 'ps' => t('PS media'), 's4' => t('Sitios ≥ 4 mm'), 's6' => t('Sitios ≥ 6 mm'), 'nic' => t('NIC máximo'),
    'vest' => t('Vestibular'), 'pal' => t('Palatino'), 'ling' => t('Lingual'), 'mov' => t('Movilidad'), 'furca' => t('Furca'), 'sang' => t('Sangrado'), 'plac' => t('Placa'),
    'mg' => t('Margen (MG)'), 'ps2' => t('Sondaje (PS)'), 'aus' => t('Marcar ausente'), 'pres' => t('Marcar presente'), 'antes' => t('antes')], JSON_UNESCAPED_UNICODE) ?>;
var SUP = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28], INF = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];

function t(d) { d = String(d); if (!D[d]) D[d] = {}; var x = D[d]; ['ps','mg','bop','pl'].forEach(function (k) { if (!Array.isArray(x[k])) x[k] = ['', '', '', '', '', ''].map(function () { return k === 'bop' || k === 'pl' ? 0 : ''; }); }); return x; }
function mesialDer(d) { var q = Math.floor(d / 10); return q === 1 || q === 4; }
// Índices de sitio de izquierda a derecha en pantalla para la cara v (0..2) o l (3..5).
function orden(d, lado) { var b = lado === 'v' ? 0 : 3; return mesialDer(d) ? [b, b + 1, b + 2] : [b + 2, b + 1, b]; }
function multirr(d) { var u = d % 10; return u >= 6 || (Math.floor(d / 10) <= 2 && u === 4); }

function filaNums(dientes) { return '<tr><th class="rl"></th>' + dientes.map(function (d, i) { return (i === 8 ? '<td class="gapc"></td>' : '') + '<td class="tc tn" data-col="' + d + '">' + d + '</td>'; }).join('') + '</tr>'; }
function fila(dientes, lbl, fn) { return '<tr><th class="rl">' + lbl + '</th>' + dientes.map(function (d, i) { return (i === 8 ? '<td class="gapc"></td>' : '') + '<td class="tc" data-col="' + d + '">' + fn(d) + '</td>'; }).join('') + '</tr>'; }
function trip(d, lado, k) { return '<div class="trip">' + orden(d, lado).map(function (s) { return '<input inputmode="numeric" data-d="' + d + '" data-k="' + k + '" data-s="' + s + '" value="' + (t(d)[k][s] === '' ? '' : t(d)[k][s]) + '">'; }).join('') + '</div>'; }
function dots(d, lado, k) { return '<div class="dots">' + orden(d, lado).map(function (s) { return '<span class="dot ' + (k === 'bop' ? 'b' : 'p') + (t(d)[k][s] ? ' on' : '') + '" data-d="' + d + '" data-k="' + k + '" data-s="' + s + '"></span>'; }).join('') + '</div>'; }
function chartRow(dientes, lado, sup) { var top = sup === (lado === 'v'); return '<tr><th class="rl"></th><td colspan="17" class="pg-chart"><svg data-chart="' + lado + '" data-sup="' + (sup ? 1 : 0) + '" data-top="' + (top ? 1 : 0) + '" viewBox="0 0 1000 74" preserveAspectRatio="none"></svg></td></tr>'; }

function arcada(id, dientes, sup) {
    var L = sup ? T.pal : T.ling, h = '';
    var bloque = function (lado, nombre) {
        var rows = [
            fila(dientes, T.sang, function (d) { return dots(d, lado, 'bop'); }),
            fila(dientes, T.plac, function (d) { return dots(d, lado, 'pl'); }),
            fila(dientes, T.mg, function (d) { return trip(d, lado, 'mg'); }),
            fila(dientes, T.ps2, function (d) { return trip(d, lado, 'ps'); }),
        ];
        if (sup !== (lado === 'v')) rows.reverse();
        return '<tr><th class="rl text-uppercase" style="font-size:.62rem">' + nombre + '</th><td colspan="17"></td></tr>' + (sup === (lado === 'v') ? '' : chartRow(dientes, lado, sup)) + rows.join('') + (sup === (lado === 'v') ? chartRow(dientes, lado, sup) : '');
    };
    h += fila(dientes, T.mov, function (d) { return '<select class="sm" data-d="' + d + '" data-k="mov"><option></option><option>0</option><option>1</option><option>2</option><option>3</option></select>'; });
    h += fila(dientes, T.furca, function (d) { return multirr(d) ? '<select class="sm" data-d="' + d + '" data-k="furca"><option></option><option>0</option><option>1</option><option>2</option><option>3</option></select>' : ''; });
    h += bloque(sup ? 'v' : 'l', sup ? T.vest : L);
    h += filaNums(dientes);
    h += fila(dientes, '', function (d) { return '<button type="button" class="ausb" data-aus="' + d + '" title="' + (t(d).aus ? T.pres : T.aus) + '"><i class="bi ' + (t(d).aus ? 'bi-arrow-counterclockwise' : 'bi-x-circle') + '"></i></button>'; });
    h += bloque(sup ? 'l' : 'v', sup ? L : T.vest);
    var tb = document.getElementById(id); tb.innerHTML = h;
    tb.querySelectorAll('select[data-k]').forEach(function (s) { s.value = t(s.dataset.d)[s.dataset.k] || ''; });
}

/* Gráfica: margen (azul) y fondo de bolsa (rojo) por sitio; 0 = unión amelocementaria. */
function charts() {
    document.querySelectorAll('svg[data-chart]').forEach(function (svg) {
        var lado = svg.dataset.chart, sup = svg.dataset.sup === '1', dientes = sup ? SUP : INF;
        // La corona mira a los números; la raíz (y la bolsa) crece hacia afuera.
        var top = svg.dataset.top === '1', k = 4.2, y0 = top ? 60 : 14, sg = top ? -1 : 1;
        var colW = 1000 / 16.3, h = '', gm = [], pb = [];
        for (var g = -2; g <= 12; g += 2) { var yy = y0 + sg * g * k; if (yy > 0 && yy < 74) h += '<line x1="0" x2="1000" y1="' + yy + '" y2="' + yy + '" stroke="' + (g === 0 ? '#94a3b8' : '#e2e8f0') + '" stroke-width="' + (g === 0 ? 1 : .6) + '"/>'; }
        dientes.forEach(function (d, i) {
            var x0 = i * colW + (i >= 8 ? colW * .3 : 0), x = t(d);
            if (x.aus) { gm.push(null); pb.push(null); h += '<rect x="' + (x0 + 3) + '" y="0" width="' + (colW - 6) + '" height="74" fill="#f1f5f9"/>'; return; }
            h += '<rect x="' + (x0 + colW * .18) + '" y="' + (top ? 0 : y0) + '" width="' + (colW * .64) + '" height="' + (top ? y0 : 74 - y0) + '" rx="10" fill="#f7ecd9" opacity=".55"/>';
            orden(d, lado).forEach(function (s, j) {
                var px = x0 + colW * (.2 + j * .3);
                var mg = x.mg[s] === '' ? null : +x.mg[s], ps = x.ps[s] === '' ? null : +x.ps[s];
                var ym = y0 + sg * (mg || 0) * k;
                gm.push(mg === null && ps === null ? null : [px, ym]);
                pb.push(ps === null ? null : [px, ym + sg * ps * k, ps]);
            });
        });
        function linea(pts, color) {
            var d = '', on = false;
            pts.forEach(function (p) { if (!p) { on = false; return; } d += (on ? 'L' : 'M') + p[0].toFixed(1) + ',' + p[1].toFixed(1) + ' '; on = true; });
            return '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round"/>';
        }
        pb.forEach(function (p, i) { if (p && gm[i] && p[2] >= 4) h += '<line x1="' + p[0] + '" x2="' + p[0] + '" y1="' + gm[i][1] + '" y2="' + p[1] + '" stroke="' + (p[2] >= 6 ? '#dc2626' : '#f87171') + '" stroke-width="5" opacity=".45"/>'; });
        h += linea(gm, '#2563eb') + linea(pb, '#dc2626');
        svg.innerHTML = h;
    });
}

function indices() {
    var sit = 0, b = 0, p = 0, n = 0, sum = 0, s4 = 0, s6 = 0, nic = 0;
    SUP.concat(INF).forEach(function (d) {
        var x = t(d); if (x.aus) return;
        for (var i = 0; i < 6; i++) {
            sit++; if (x.bop[i]) b++; if (x.pl[i]) p++;
            if (x.ps[i] !== '') { var v = +x.ps[i]; n++; sum += v; if (v >= 4) s4++; if (v >= 6) s6++; nic = Math.max(nic, v + (+x.mg[i] || 0)); }
        }
    });
    var r = { bop: sit ? Math.round(b * 1000 / sit) / 10 : 0, placa: sit ? Math.round(p * 1000 / sit) / 10 : 0, ps: n ? Math.round(sum * 100 / n) / 100 : 0, s4: s4, s6: s6, nic: nic };
    var cards = [['bop', '%', r.bop >= 10 ? 'danger' : 'success'], ['placa', '%', r.placa >= 20 ? 'warning' : 'success'], ['ps', ' mm', ''], ['s4', '', r.s4 ? 'warning' : ''], ['s6', '', r.s6 ? 'danger' : ''], ['nic', ' mm', '']];
    document.getElementById('pgKpis').innerHTML = cards.map(function (c) {
        var ant = PREV && PREV[c[0]] != null ? '<div class="small text-muted">' + T.antes + ' ' + (+PREV[c[0]]) + c[1] + '</div>' : '';
        return '<div class="col-6 col-md-2"><div class="kpi"><div class="t">' + T[c[0]] + '</div><div class="v ' + (c[2] ? 'text-' + c[2] : '') + '">' + r[c[0]] + c[1] + '</div>' + ant + '</div></div>';
    }).join('');
}
function pintarInput(i) { var v = +i.value; i.classList.toggle('w4', i.dataset.k === 'ps' && i.value !== '' && v >= 4 && v < 6); i.classList.toggle('w6', i.dataset.k === 'ps' && i.value !== '' && v >= 6); }
function todo() { arcada('pgSup', SUP, true); arcada('pgInf', INF, false); marcarAus(); charts(); indices(); document.querySelectorAll('.trip input').forEach(pintarInput); }
function marcarAus() { document.querySelectorAll('[data-col]').forEach(function (td) { td.classList.toggle('aus', !!t(td.dataset.col).aus); }); }

var inputs = function () { return Array.prototype.slice.call(document.querySelectorAll('.trip input')); };
var espera = null;
document.addEventListener('input', function (ev) {
    var i = ev.target; if (!i.matches('.trip input')) return;
    i.value = i.value.replace(i.dataset.k === 'mg' ? /[^\d-]/g : /\D/g, '').slice(0, 3);
    t(i.dataset.d)[i.dataset.k][+i.dataset.s] = i.value === '-' ? '' : i.value;
    pintarInput(i); charts(); indices();
    if (!document.getElementById('pgAuto').checked || i.value === '' || i.value === '-') return;
    clearTimeout(espera);
    var go = function () { var all = inputs(), k = all.indexOf(i); if (all[k + 1]) { all[k + 1].focus(); all[k + 1].select(); } };
    if (/^-?1$/.test(i.value)) espera = setTimeout(go, 650); else go();   // "1" puede ser 10-12
});
document.addEventListener('keydown', function (ev) {
    var i = ev.target; if (!i.matches || !i.matches('.trip input')) return;
    var all = inputs(), k = all.indexOf(i);
    if (ev.key === 'Enter') { ev.preventDefault(); if (all[k + 1]) all[k + 1].focus(); }
    if (ev.key === 'ArrowRight' && i.selectionStart === i.value.length && all[k + 1]) { ev.preventDefault(); all[k + 1].focus(); }
    if (ev.key === 'ArrowLeft' && i.selectionStart === 0 && all[k - 1]) { ev.preventDefault(); all[k - 1].focus(); }
});
document.addEventListener('focusin', function (ev) { if (ev.target.matches && ev.target.matches('.trip input')) ev.target.select(); });
document.addEventListener('click', function (ev) {
    var dt = ev.target.closest('.dot');
    if (dt) { var x = t(dt.dataset.d), s = +dt.dataset.s; x[dt.dataset.k][s] = x[dt.dataset.k][s] ? 0 : 1; dt.classList.toggle('on'); indices(); return; }
    var a = ev.target.closest('[data-aus]');
    if (a) { var y = t(a.dataset.aus); y.aus = y.aus ? 0 : 1; todo(); }
});
document.addEventListener('change', function (ev) { var s = ev.target; if (s.matches('select[data-k]')) t(s.dataset.d)[s.dataset.k] = s.value; });
document.getElementById('pgForm').addEventListener('submit', function () { document.getElementById('pgDatos').value = JSON.stringify(D); });
todo();
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
