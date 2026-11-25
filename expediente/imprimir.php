<?php
/**
 * Impresión del expediente clínico (NOM-004):
 *   ?id=PAC                 expediente completo
 *   ?id=PAC&modo=resumen    resumen clínico (para el paciente, otro médico o una autoridad)
 *   ?id=PAC&consulta=ID     una nota
 * Membrete del establecimiento (con CLUES si se capturó), ficha de
 * identificación, notas con fecha, hora, autor y cédula, notas aclaratorias,
 * estado del sello de integridad y de la firma electrónica, y línea de firma.
 */
require_once __DIR__ . '/../includes/comprobante.php';
require_once __DIR__ . '/../includes/ece.php';
require_login();
if (!has_role('medico', 'admin')) { flash('Solo el personal médico imprime el expediente.', 'warning'); redirect('/dashboard'); }
asegurar_ece();

$tid = (int) tenant_id();
$id = (int) ($_GET['id'] ?? 0);
$soloConsulta = (int) ($_GET['consulta'] ?? 0);
$modo = $soloConsulta ? 'nota' : (($_GET['modo'] ?? '') === 'resumen' ? 'resumen' : 'completo');

$st = db()->prepare('SELECT * FROM pacientes WHERE id = ? AND consultorio_id = ?');
$st->execute([$id, $tid]);
$p = $st->fetch();
if (!$p) { http_response_code(404); die('Paciente no encontrado.'); }

$sql = 'SELECT co.*, u.nombre med_nombre, u.cedula med_cedula, u.especialidad med_esp FROM consultas co JOIN usuarios u ON u.id = co.medico_id
        WHERE co.consultorio_id = ? AND co.paciente_id = ?';
$par = [$tid, $id];
if ($soloConsulta) { $sql .= ' AND co.id = ?'; $par[] = $soloConsulta; }
$sql .= ' ORDER BY co.fecha ' . ($modo === 'completo' ? 'ASC' : 'DESC');
if ($modo === 'resumen') $sql .= ' LIMIT 5';
try { $q = db()->prepare($sql); $q->execute($par); $cons = $q->fetchAll(); }
catch (Throwable $e) {   // sin columna cedula/especialidad
    $q = db()->prepare(str_replace(', u.cedula med_cedula, u.especialidad med_esp', '', $sql)); $q->execute($par); $cons = $q->fetchAll();
}
if ($soloConsulta && !$cons) { http_response_code(404); die('Nota no encontrada.'); }
$acl = ece_aclaraciones(array_column($cons, 'id'));

$meds = [];
try {
    $m = db()->prepare('SELECT nombre, dosis, frecuencia, via FROM paciente_medicamentos WHERE consultorio_id = ? AND paciente_id = ? AND activo = 1 ORDER BY nombre');
    $m->execute([$tid, $id]); $meds = $m->fetchAll();
} catch (Throwable $e) {}
$dx = [];
if ($modo === 'resumen') {
    $d = db()->prepare("SELECT diagnostico, MAX(fecha) f FROM consultas WHERE consultorio_id = ? AND paciente_id = ? AND diagnostico IS NOT NULL AND diagnostico <> '' GROUP BY diagnostico ORDER BY f DESC LIMIT 8");
    $d->execute([$tid, $id]); $dx = $d->fetchAll();
}

auditar('imprimir', 'paciente', $id, ['completo' => 'Expediente completo', 'resumen' => 'Resumen clínico', 'nota' => 'Nota #' . $soloConsulta][$modo]);

$tipoDoc = ['completo' => t('Expediente clínico'), 'resumen' => t('Resumen clínico'), 'nota' => t('Nota médica')][$modo];
$folio = 'EXP-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
$nombre = trim($p['nombre'] . ' ' . $p['apellidos']);
$sexo = ['M' => t('Masculino'), 'F' => t('Femenino'), 'O' => t('Otro')][$p['sexo'] ?? ''] ?? '';
$titulo = $tipoDoc . ' · ' . $nombre;
$activo = 'pacientes';
include __DIR__ . '/../includes/header.php';
echo comprobante_estilos();
?>
<style>
.hoja-doc h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:700;margin:18px 0 8px}
.nota-ece{border:1px solid #e5e9f0;border-radius:10px;padding:12px 14px;margin-bottom:12px;page-break-inside:avoid}
.nota-ece .enc{display:flex;justify-content:space-between;gap:8px;font-size:.85rem;border-bottom:1px solid #eef1f5;padding-bottom:6px;margin-bottom:8px}
.nota-ece dl{display:grid;grid-template-columns:130px 1fr;gap:4px 10px;margin:0;font-size:.88rem}
.nota-ece dt{color:#64748b;font-weight:600}
.nota-ece dd{margin:0;white-space:pre-line}
.sello{font-size:.72rem;color:#64748b;margin-top:8px;font-family:ui-monospace,Menlo,monospace;word-break:break-all}
.firma-linea{margin-top:34px;text-align:center;font-size:.82rem}
.firma-linea .l{border-top:1px solid #334155;width:260px;margin:0 auto 4px}
</style>
<div class="d-flex justify-content-between align-items-center mb-3 no-print flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= $id ?>" class="btn btn-light btn-sm"><i class="bi bi-arrow-left"></i> <?= et('Volver al expediente') ?></a>
    <div class="btn-group btn-group-sm">
        <a href="?id=<?= $id ?>" class="btn btn-outline-secondary <?= $modo === 'completo' ? 'active' : '' ?>"><?= et('Expediente completo') ?></a>
        <a href="?id=<?= $id ?>&modo=resumen" class="btn btn-outline-secondary <?= $modo === 'resumen' ? 'active' : '' ?>"><?= et('Resumen clínico') ?></a>
    </div>
    <button onclick="print()" class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> <?= et('Imprimir / PDF') ?></button>
</div>

<div class="hoja-doc"><div class="franja"></div><div class="cuerpo">
    <?= comprobante_membrete($tipoDoc, $folio, e(fmt_fecha(date('Y-m-d'))) . ' ' . date('H:i')) ?>

    <h3><?= et('Ficha de identificación') ?></h3>
    <div class="caja-suave small">
        <strong style="font-size:1rem"><?= e($nombre) ?></strong><br>
        <?= e(implode(' · ', array_filter([
            $p['fecha_nacimiento'] ? t('Nacimiento') . ': ' . fmt_fecha($p['fecha_nacimiento']) . ' (' . edad($p['fecha_nacimiento']) . ')' : '',
            $sexo, !empty($p['curp']) ? 'CURP ' . $p['curp'] : '', !empty($p['tipo_sangre']) ? t('Sangre') . ' ' . $p['tipo_sangre'] : '',
        ]))) ?><br>
        <?= e(implode(' · ', array_filter([$p['direccion'] ?? '', $p['telefono'] ?? '']))) ?>
    </div>

    <?php if ($modo !== 'nota'): ?>
    <h3><?= et('Antecedentes y alertas') ?></h3>
    <dl class="small" style="display:grid;grid-template-columns:170px 1fr;gap:4px 10px">
        <?php foreach (['alergias' => 'Alergias', 'enf_cronicas' => 'Enfermedades crónicas', 'antecedentes' => 'Personales patológicos',
                        'antecedentes_familiares' => 'Heredofamiliares', 'cirugias' => 'Quirúrgicos', 'habitos' => 'No patológicos / hábitos'] as $k => $l):
            if (empty($p[$k])) continue; ?>
            <dt class="text-muted"><?= et($l) ?></dt><dd class="mb-0 <?= $k === 'alergias' ? 'text-danger fw-semibold' : '' ?>"><?= nl2br(e($p[$k])) ?></dd>
        <?php endforeach; ?>
    </dl>
    <?php if ($meds): ?>
    <h3><?= et('Medicamentos actuales') ?></h3>
    <div class="small"><?= e(implode(' · ', array_map(fn ($m) => trim($m['nombre'] . ' ' . $m['dosis'] . ' ' . $m['frecuencia'] . ' ' . $m['via']), $meds))) ?></div>
    <?php endif; ?>
    <?php if ($dx): ?>
    <h3><?= et('Diagnósticos registrados') ?></h3>
    <ul class="small mb-0"><?php foreach ($dx as $d): ?><li><?= e($d['diagnostico']) ?> <span class="text-muted">(<?= e(fmt_fecha($d['f'])) ?>)</span></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php endif; ?>

    <h3><?= $modo === 'resumen' ? et('Últimas notas') : ($modo === 'nota' ? et('Nota') : et('Notas médicas')) ?></h3>
    <?php if (!$cons): ?><p class="small text-muted"><?= et('Sin notas registradas.') ?></p><?php endif; ?>
    <?php foreach ($cons as $c):
        $vit = array_filter([$c['peso'] ? 'Peso ' . (float) $c['peso'] . ' kg' : '', $c['estatura'] ? 'Talla ' . (float) $c['estatura'] . ' cm' : '',
            $c['presion'] ? 'TA ' . $c['presion'] : '', $c['temperatura'] ? 'Temp ' . (float) $c['temperatura'] . ' °C' : '',
            !empty($c['frecuencia_cardiaca']) ? 'FC ' . $c['frecuencia_cardiaca'] : '', !empty($c['frecuencia_respiratoria']) ? 'FR ' . $c['frecuencia_respiratoria'] : '',
            !empty($c['saturacion']) ? 'SpO₂ ' . $c['saturacion'] . '%' : '', !empty($c['glucosa']) ? 'Glucosa ' . (float) $c['glucosa'] : '']);
        $sello = ece_estado_sello($c); $firma = ece_estado_firma($c); ?>
    <div class="nota-ece">
        <div class="enc"><strong><?= e(fmt_fecha($c['fecha'])) ?> · <?= e(date('H:i', strtotime($c['fecha']))) ?></strong>
            <span><?= e($c['med_nombre']) ?><?= !empty($c['med_cedula']) ? ' · ' . et('Céd.') . ' ' . e($c['med_cedula']) : '' ?></span></div>
        <dl>
            <?php foreach (['motivo' => 'Motivo', 'exploracion' => 'Exploración', 'diagnostico' => 'Diagnóstico', 'pronostico' => 'Pronóstico',
                            'tratamiento' => 'Tratamiento', 'receta' => 'Receta', 'notas' => 'Notas'] as $k => $l):
                if (empty($c[$k])) continue; ?>
                <dt><?= et($l) ?></dt><dd><?= e($c[$k]) ?></dd>
            <?php endforeach; ?>
            <?php if ($vit): ?><dt><?= et('Signos vitales') ?></dt><dd><?= e(implode(' · ', $vit)) ?></dd><?php endif; ?>
        </dl>
        <?php foreach ($acl[(int) $c['id']] ?? [] as $a): ?>
            <div class="small mt-2 p-2" style="border-left:3px solid #d97706;background:#fffbeb"><strong><?= et('Nota aclaratoria') ?></strong> · <?= e(fmt_fecha($a['creado_en'])) ?> <?= e(date('H:i', strtotime($a['creado_en']))) ?> · <?= e($a['med_nombre'] ?? '') ?><br><?= nl2br(e($a['texto'])) ?></div>
        <?php endforeach; ?>
        <div class="sello">
            <?php if ($sello !== 'sin_sello'): ?><?= et('Sello SHA-256') ?>: <?= e($c['sello']) ?> · <?= $sello === 'integra' ? et('íntegra') : '<b style="color:#dc2626">' . et('ALTERADA') . '</b>' ?><br><?php endif; ?>
            <?php if ($firma === 'valida'): ?><?= et('Firmada electrónicamente (e.firma) por') ?> <?= e($c['med_nombre']) ?> · <?= et('certificado') ?> <?= e($c['firma_cert']) ?> · <?= e(fmt_fecha($c['firma_en'])) ?> <?= e(date('H:i', strtotime($c['firma_en']))) ?>
            <?php elseif ($firma === 'invalida'): ?><b style="color:#dc2626"><?= et('Firma electrónica no válida') ?></b><?php endif; ?>
        </div>
        <?php if ($modo === 'nota' && $firma !== 'valida'): ?>
        <div class="firma-linea"><div class="l"></div><?= e($c['med_nombre']) ?><?= !empty($c['med_cedula']) ? '<br>' . et('Cédula profesional') . ' ' . e($c['med_cedula']) : '' ?></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($modo !== 'nota'): $u = current_user(); ?>
    <div class="firma-linea"><div class="l"></div><?= e($u['nombre']) ?><br><span class="text-muted"><?= et('Nombre y firma de quien expide') ?></span></div>
    <?php endif; ?>
    <?php if ($modo !== 'nota' && array_key_exists('consent_compartir', $p) && empty($p['consent_compartir'])): ?>
    <div class="alert alert-warning small no-print mt-3"><i class="bi bi-exclamation-triangle"></i> <?= et('El paciente no ha autorizado compartir su información con terceros. Entrégalo solo al paciente o con su autorización por escrito.') ?></div>
    <?php endif; ?>
    <div class="pie-doc"><?= et('Documento confidencial. Contiene datos personales sensibles protegidos por la ley; su uso está limitado al fin para el que se entrega.') ?>
        <?= et('Expedido el') ?> <?= e(fmt_fecha(date('Y-m-d'))) ?> <?= date('H:i') ?>.</div>
</div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
