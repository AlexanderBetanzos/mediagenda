<?php
/**
 * Detalle de un estudio de ultrasonido: capturar las mediciones del protocolo,
 * redactar el informe (técnica, hallazgos, impresión diagnóstica) y subir las
 * capturas del ecógrafo.
 *
 * Las imágenes NO se guardan aquí: se suben al expediente del paciente
 * (guardar_archivo_expediente) y se marcan con img_estudio_id. Por eso quedan
 * en el expediente y el paciente las ve en su portal, igual que laboratorio.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo('imagenologia');

$id = (int) ($_GET['id'] ?? 0);
$u  = current_user();

$st = db()->prepare(
    'SELECT e.*, p.nombre AS pac_nombre, p.apellidos AS pac_ape, p.telefono AS pac_tel,
            COALESCE(p.foto_mime, p.foto) AS pac_foto, p.fecha_nacimiento,
            u.nombre AS med_nombre, pl.preparacion
     FROM img_estudios e
     JOIN pacientes p ON p.id = e.paciente_id
     LEFT JOIN usuarios u ON u.id = e.medico_id
     LEFT JOIN img_plantillas pl ON pl.id = e.plantilla_id
     WHERE e.id = ? AND e.consultorio_id = ?'
);
$st->execute([$id, tenant_id()]);
$o = $st->fetch();
if (!$o) { flash('Estudio no encontrado.', 'warning'); redirect('/imagenologia/index'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $accion = $_POST['accion'] ?? '';

    /* Cambio de estado. Al informar y al entregar se sella la fecha. */
    if ($accion === 'estado') {
        $nuevo = (string) ($_POST['estado'] ?? '');
        if (!isset(usg_estados()[$nuevo])) {
            flash('Estado no válido.', 'warning');
            redirect('/imagenologia/ver?id=' . $id);
        }
        db()->prepare(
            "UPDATE img_estudios
                SET estado = ?,
                    informado_en = IF(? = 'informado', NOW(), informado_en),
                    entregado_en = IF(? = 'entregado', NOW(), entregado_en)
              WHERE id = ? AND consultorio_id = ?"
        )->execute([$nuevo, $nuevo, $nuevo, $id, tenant_id()]);
        auditar('img_estudio_estado', 'img_estudio', $id, $o['folio'] . ' -> ' . $nuevo);
        flash('Estudio marcado como ' . mb_strtolower(usg_estado_label($nuevo)) . '.');
        redirect('/imagenologia/ver?id=' . $id);
    }

    /* Captura del informe: mediciones + narrativa. */
    if ($accion === 'informe') {
        $valores  = $_POST['valor']   ?? [];
        $anormal  = $_POST['anormal'] ?? [];
        $up = db()->prepare(
            'UPDATE img_hallazgos SET valor = ?, anormal = ? WHERE id = ? AND estudio_id = ?'
        );
        foreach ($valores as $hid => $valor) {
            $hid   = (int) $hid;
            $valor = trim((string) $valor);
            $up->execute([$valor !== '' ? mb_substr($valor, 0, 255) : null,
                          isset($anormal[$hid]) ? 1 : 0, $hid, $id]);
        }

        $impresion = trim((string) ($_POST['impresion'] ?? ''));
        db()->prepare(
            'UPDATE img_estudios SET tecnica = ?, hallazgos = ?, impresion = ?, recomendaciones = ?
             WHERE id = ? AND consultorio_id = ?'
        )->execute([
            trim((string) ($_POST['tecnica'] ?? ''))         ?: null,
            trim((string) ($_POST['hallazgos'] ?? ''))       ?: null,
            $impresion                                       ?: null,
            trim((string) ($_POST['recomendaciones'] ?? '')) ?: null,
            $id, tenant_id(),
        ]);

        // La impresión diagnóstica es lo que cierra el informe: en cuanto
        // existe, el estudio deja de estar pendiente de reportar.
        if ($impresion !== '' && in_array($o['estado'], ['programado', 'realizado'], true)) {
            db()->prepare(
                "UPDATE img_estudios SET estado = 'informado', informado_en = NOW()
                 WHERE id = ? AND consultorio_id = ?"
            )->execute([$id, tenant_id()]);
        }
        auditar('img_estudio_informe', 'img_estudio', $id, $o['folio']);
        flash('Informe guardado.');
        redirect('/imagenologia/ver?id=' . $id);
    }

    /* Capturas del ecógrafo. Se suben varias de golpe: el estudio rara vez
       deja una sola imagen, y $_FILES las entrega como arrays paralelos. */
    if ($accion === 'imagenes') {
        $f    = $_FILES['imagenes'] ?? null;
        $desc = trim((string) ($_POST['descripcion'] ?? '')) ?: ($o['nombre'] . ' ' . $o['folio']);
        $ok = $err = 0;
        $ultimoError = '';

        foreach ((array) ($f['name'] ?? []) as $i => $nombreArchivo) {
            $uno = [
                'name'     => $nombreArchivo,
                'type'     => $f['type'][$i]     ?? '',
                'tmp_name' => $f['tmp_name'][$i] ?? '',
                'error'    => $f['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                'size'     => $f['size'][$i]     ?? 0,
            ];
            if ($uno['error'] === UPLOAD_ERR_NO_FILE) continue;

            $r = guardar_archivo_expediente($uno, (int) $o['paciente_id'], (int) $u['id'], $desc);
            if ($r['estado'] === 'ok') {
                db()->prepare('UPDATE archivos SET img_estudio_id = ? WHERE id = ? AND consultorio_id = ?')
                    ->execute([$id, (int) $r['id'], tenant_id()]);
                $ok++;
            } else {
                $err++;
                $ultimoError = $r['mensaje'];
            }
        }

        if ($ok)  {
            auditar('img_estudio_imagenes', 'img_estudio', $id, $o['folio'] . ' (' . $ok . ')');
            flash($ok . ' ' . t('imagen(es) agregada(s). El paciente ya puede verlas en su portal.'));
        }
        if ($err) { flash($ultimoError ?: t('Algunas imágenes no se pudieron subir.'), $ok ? 'warning' : 'danger'); }
        if (!$ok && !$err) { flash(t('No se seleccionó ninguna imagen.'), 'warning'); }
        redirect('/imagenologia/ver?id=' . $id);
    }
}

$hl = db()->prepare('SELECT * FROM img_hallazgos WHERE estudio_id = ? ORDER BY orden, id');
$hl->execute([$id]);
$hallazgos = $hl->fetchAll();

$ar = db()->prepare('SELECT * FROM archivos WHERE img_estudio_id = ? AND consultorio_id = ? ORDER BY creado_en, id');
$ar->execute([$id, tenant_id()]);
$imagenes = $ar->fetchAll();

$capturados = 0;
foreach ($hallazgos as $h) { if (trim((string) $h['valor']) !== '') $capturados++; }

$paciente = $o['pac_nombre'] . ' ' . $o['pac_ape'];
$wa = (modulo_activo('whatsapp') && $o['pac_tel'] && in_array($o['estado'], ['informado', 'entregado'], true))
    ? wa_link($o['pac_tel'], t('Hola') . ' ' . $o['pac_nombre'] . ', '
        . t('el resultado de tu ultrasonido ya está listo') . ' (' . $o['folio'] . ').')
    : '';

$titulo = $o['folio'];
$activo = 'imagenologia';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/imagenologia/index"><?= et('Ultrasonidos') ?></a></li>
    <li class="breadcrumb-item active"><?= e($o['folio']) ?></li>
</ol></nav>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-3">
        <?= avatar_paciente((int) $o['paciente_id'], $o['pac_nombre'], $o['pac_ape'], $o['pac_foto'] ?? null, 56) ?>
        <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-soundwave text-brand"></i> <?= e($o['folio']) ?>
            <span class="badge bg-<?= usg_estado_badge($o['estado']) ?> align-middle"><?= e(usg_estado_label($o['estado'])) ?></span>
        </h1>
        <div class="text-muted small">
            <a href="<?= BASE_URL ?>/pacientes/ver?id=<?= (int) $o['paciente_id'] ?>" class="text-decoration-none">
                <i class="bi bi-person"></i> <?= e($paciente) ?>
            </a>
            · <?= e(edad($o['fecha_nacimiento'])) ?>
            · <?= fmt_fecha($o['fecha']) ?>
            · <?= e($o['nombre']) ?>
            <?php if ($o['med_nombre']): ?> · <?= et('Informa') ?>: <?= e($o['med_nombre']) ?><?php endif; ?>
        </div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($wa): ?>
            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="btn btn-outline-success">
                <i class="bi bi-whatsapp"></i> <?= et('Avisar al paciente') ?>
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/imagenologia/imprimir?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-printer"></i> <?= et('Imprimir informe') ?>
        </a>
        <a href="<?= BASE_URL ?>/imagenologia/estudio?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil"></i> <?= et('Editar datos') ?>
        </a>
    </div>
</div>

<?php /* Avance de estado: solo se ofrece el siguiente paso lógico. */ ?>
<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted small me-2"><?= et('Avanzar el estudio:') ?></span>
        <?php
        $siguiente = [
            'programado' => [['realizado', 'Marcar realizado', 'info'], ['cancelado', 'Cancelar', 'outline-secondary']],
            'realizado'  => [['informado', 'Marcar informado', 'primary'], ['cancelado', 'Cancelar', 'outline-secondary']],
            'informado'  => [['entregado', 'Entregar al paciente', 'success']],
            'entregado'  => [],
            'cancelado'  => [['programado', 'Reabrir', 'outline-secondary']],
        ][$o['estado']] ?? [];
        ?>
        <?php foreach ($siguiente as [$clave, $lbl, $color]): ?>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="accion" value="estado">
            <input type="hidden" name="estado" value="<?= $clave ?>">
            <button class="btn btn-sm btn-<?= $color ?>"><?= et($lbl) ?></button>
        </form>
        <?php endforeach; ?>
        <?php if (!$siguiente): ?>
            <span class="text-muted small"><i class="bi bi-check-circle text-success"></i> <?= et('Estudio cerrado.') ?></span>
        <?php endif; ?>
        <?php if ($o['entregado_en']): ?>
            <span class="ms-auto small text-muted"><?= et('Entregado el') ?> <?= fmt_fecha($o['entregado_en']) ?></span>
        <?php elseif ($o['informado_en']): ?>
            <span class="ms-auto small text-muted"><?= et('Informado el') ?> <?= fmt_fecha($o['informado_en']) ?></span>
        <?php endif; ?>
    </div>
</div>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="accion" value="informe">
<div class="row g-3">
    <div class="col-lg-8">
        <?php if ($hallazgos): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-rulers text-brand"></i> <?= et('Mediciones y hallazgos') ?></span>
                <span class="badge bg-<?= $capturados === count($hallazgos) ? 'success' : 'warning text-dark' ?>">
                    <?= $capturados ?>/<?= count($hallazgos) ?> <?= et('capturados') ?>
                </span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr>
                        <th style="width:34%"><?= et('Renglón') ?></th>
                        <th><?= et('Valor') ?></th>
                        <th style="width:120px"><?= et('Referencia') ?></th>
                        <th style="width:90px" class="text-center"><?= et('Anormal') ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($hallazgos as $h): $hid = (int) $h['id']; ?>
                        <tr class="<?= $h['anormal'] ? 'table-danger' : '' ?>">
                            <td class="fw-semibold"><?= e($h['etiqueta']) ?></td>
                            <td>
                                <?php if ($h['tipo'] === 'opcion'):
                                    $opciones = array_filter(array_map('trim', explode('|', (string) $h['opciones']))); ?>
                                    <select name="valor[<?= $hid ?>]" class="form-select form-select-sm">
                                        <option value="">—</option>
                                        <?php foreach ($opciones as $op): ?>
                                            <option value="<?= e($op) ?>" <?= $h['valor'] === $op ? 'selected' : '' ?>><?= e($op) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($h['tipo'] === 'area'): ?>
                                    <textarea name="valor[<?= $hid ?>]" class="form-control form-control-sm" rows="2"
                                              maxlength="255"><?= e($h['valor'] ?? '') ?></textarea>
                                <?php else: ?>
                                    <div class="input-group input-group-sm">
                                        <input name="valor[<?= $hid ?>]" class="form-control form-control-sm" maxlength="255"
                                               <?= $h['tipo'] === 'numero' ? 'inputmode="decimal"' : '' ?>
                                               value="<?= e($h['valor'] ?? '') ?>">
                                        <?php if ($h['unidad']): ?>
                                            <span class="input-group-text"><?= e($h['unidad']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($h['referencia'] ?: '—') ?></td>
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input" name="anormal[<?= $hid ?>]"
                                       value="1" <?= $h['anormal'] ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-light border">
            <i class="bi bi-info-circle"></i>
            <?= et('Este estudio no usa protocolo: redacta los hallazgos abajo.') ?>
            <a href="<?= BASE_URL ?>/imagenologia/estudio?id=<?= $id ?>"><?= et('Asignar un protocolo') ?></a>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="bi bi-file-earmark-text text-brand"></i> <?= et('Informe') ?></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label"><?= et('Técnica') ?></label>
                    <textarea name="tecnica" class="form-control" rows="2" maxlength="2000"><?= e($o['tecnica'] ?? '') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= et('Hallazgos (narrativa)') ?></label>
                    <textarea name="hallazgos" class="form-control" rows="6" maxlength="5000"
                              placeholder="<?= e(t('Lo que no cabe en los renglones medidos.')) ?>"><?= e($o['hallazgos'] ?? '') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= et('Impresión diagnóstica') ?></label>
                    <textarea name="impresion" class="form-control" rows="4" maxlength="2000"><?= e($o['impresion'] ?? '') ?></textarea>
                    <div class="form-text"><?= et('Al guardar una impresión diagnóstica, el estudio pasa solo a "Informado".') ?></div>
                </div>
                <div>
                    <label class="form-label"><?= et('Recomendaciones') ?></label>
                    <textarea name="recomendaciones" class="form-control" rows="2" maxlength="2000"><?= e($o['recomendaciones'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="card-body border-top text-end">
                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= et('Guardar informe') ?></button>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <?php if ($o['indicacion']): ?>
                    <div class="mb-2"><span class="text-muted small"><?= et('Indicación') ?>:</span> <?= e($o['indicacion']) ?></div>
                <?php endif; ?>
                <?php if ($o['referente']): ?>
                    <div class="mb-2"><span class="text-muted small"><?= et('Solicita') ?>:</span> <?= e($o['referente']) ?></div>
                <?php endif; ?>
                <?php if ($o['equipo'] || $o['transductor']): ?>
                    <div class="mb-2 small text-muted">
                        <i class="bi bi-cpu"></i> <?= e(trim($o['equipo'] . ' · ' . $o['transductor'], ' ·')) ?>
                    </div>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="text-muted"><?= et('Precio') ?></span>
                    <span class="h5 mb-0 fw-bold"><?= fmt_money($o['precio']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-images text-brand"></i> <?= et('Capturas del ecógrafo') ?></span>
                <span class="badge bg-secondary"><?= count($imagenes) ?></span>
            </div>
            <?php if ($imagenes): ?>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($imagenes as $a): $esImagen = strpos((string) $a['mime'], 'image/') === 0; ?>
                    <div class="col-6 col-md-4 col-xl-3">
                        <a href="<?= BASE_URL ?>/pacientes/archivo?id=<?= (int) $a['id'] ?>&ver=1" target="_blank"
                           class="d-block text-decoration-none border rounded overflow-hidden"
                           title="<?= e($a['descripcion'] ?: $a['nombre_original']) ?>">
                            <?php if ($esImagen): ?>
                                <img src="<?= BASE_URL ?>/pacientes/archivo?id=<?= (int) $a['id'] ?>&ver=1" alt=""
                                     style="width:100%;aspect-ratio:4/3;object-fit:cover;background:#0b1220">
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-center"
                                     style="width:100%;aspect-ratio:4/3">
                                    <i class="bi <?= archivo_icono($a['nombre_original']) ?> text-brand fs-1"></i>
                                </div>
                            <?php endif; ?>
                            <div class="small text-truncate px-2 py-1 text-body-secondary"><?= fmt_fecha($a['creado_en']) ?></div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body text-center text-muted py-4">
                <i class="bi bi-images d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                <?= et('Sin capturas todavía.') ?>
            </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="card-body border-top">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="imagenes">
                <div class="row g-2">
                    <div class="col-md-5">
                        <input type="file" name="imagenes[]" class="form-control form-control-sm" multiple required
                               accept="image/*,application/pdf">
                    </div>
                    <div class="col-md-4">
                        <input name="descripcion" class="form-control form-control-sm" maxlength="255"
                               placeholder="<?= e(t('Descripción (opcional)')) ?>">
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-sm btn-primary w-100"><i class="bi bi-upload"></i> <?= et('Subir') ?></button>
                    </div>
                </div>
                <div class="form-text">
                    <?= et('Puedes seleccionar varias a la vez. Máximo') ?> <?= fmt_bytes(archivo_max_bytes()) ?> <?= et('por archivo') ?>.
                    <?= et('Se guardan en el expediente y el paciente las ve en su portal.') ?>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($o['preparacion']): ?>
        <div class="card mb-3"><div class="card-body small">
            <div class="fw-semibold mb-1"><i class="bi bi-info-circle text-brand"></i> <?= et('Preparación del protocolo') ?></div>
            <div class="text-muted"><?= e($o['preparacion']) ?></div>
        </div></div>
        <?php endif; ?>

        <?php if (has_role('admin')): ?>
        <form method="post" action="<?= BASE_URL ?>/imagenologia/delete"
              onsubmit="return confirm('<?= e(t('¿Eliminar este estudio? No se puede deshacer.')) ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-trash"></i> <?= et('Eliminar estudio') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
