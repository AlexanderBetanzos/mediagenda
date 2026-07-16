<?php
/**
 * Catálogo de médicos del consultorio.
 *
 * Un médico es un usuario con rol 'medico'. Puede tener acceso al sistema (correo
 * y contraseña, entra al dashboard y ve SU agenda) o no tenerlo: un médico que
 * solo pasa consulta existe aquí para asignarle citas, sin obligarlo a un login.
 * En ambos casos aparece en la agenda y puede dar citas y consultas por separado.
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$sql = "SELECT u.*,
               (SELECT COUNT(*) FROM citas c
                WHERE c.medico_id = u.id AND c.consultorio_id = u.consultorio_id
                  AND c.fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS citas_mes
        FROM usuarios u
        WHERE u.consultorio_id = ? AND u.rol = 'medico'
        ORDER BY u.activo DESC, u.nombre";
$st = db()->prepare($sql);
$st->execute([tenant_id()]);
$medicos = $st->fetchAll();

$titulo = t('Médicos');
$activo = 'medicos';
include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-person-vcard text-brand"></i> <?= et('Médicos') ?></h1>
    <a href="<?= BASE_URL ?>/medicos/edit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> <?= et('Nuevo médico') ?></a>
</div>

<p class="text-muted">
    <?= et('Cada médico tiene su propia agenda y consultas. Puedes darle acceso al sistema o dejarlo solo en la agenda (sin usuario ni contraseña).') ?>
</p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><?= et('Médico') ?></th>
                    <th><?= et('Especialidad') ?></th>
                    <th><?= et('Cédula') ?></th>
                    <th><?= et('Acceso') ?></th>
                    <th class="text-center"><?= et('Citas del mes') ?></th>
                    <th><?= et('Estado') ?></th>
                    <th class="text-end"><?= et('Acciones') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($medicos as $m): $tieneAcceso = !empty($m['email']) && !empty($m['password_hash']); ?>
                <tr class="<?= $m['activo'] ? '' : 'opacity-50' ?>">
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold flex-shrink-0"
                                  style="width:38px;height:38px;background:color-mix(in srgb,var(--brand) 15%,transparent);color:var(--brand)">
                                <?= e(strtoupper(mb_substr($m['nombre'], 0, 1))) ?>
                            </span>
                            <div>
                                <div class="fw-semibold"><?= e($m['nombre']) ?></div>
                                <?php if ($m['email']): ?><div class="small text-muted"><?= e($m['email']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td><?= e($m['especialidad'] ?: '—') ?></td>
                    <td class="small text-muted"><?= e($m['cedula'] ?? '' ?: '—') ?></td>
                    <td>
                        <?php if ($tieneAcceso): ?>
                            <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-25">
                                <i class="bi bi-box-arrow-in-right"></i> <?= et('Entra al sistema') ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary bg-opacity-25 text-body border">
                                <i class="bi bi-calendar-week"></i> <?= et('Solo en agenda') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge bg-<?= $m['citas_mes'] > 0 ? 'success' : 'light text-dark border' ?>"><?= (int) $m['citas_mes'] ?></span></td>
                    <td>
                        <?php if ($m['activo']): ?>
                            <span class="badge bg-success"><?= et('Activo') ?></span>
                        <?php else: ?>
                            <span class="badge bg-danger"><?= et('Inactivo') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= BASE_URL ?>/citas/horarios?medico=<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= e(t('Horario')) ?>"><i class="bi bi-clock-history"></i></a>
                        <a href="<?= BASE_URL ?>/medicos/edit?id=<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= e(t('Editar')) ?>"><i class="bi bi-pencil"></i></a>
                        <?php if ((int) $m['id'] !== (int) current_user()['id']): ?>
                        <form action="<?= BASE_URL ?>/usuarios/toggle" method="post" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <input type="hidden" name="volver" value="medicos">
                            <button class="btn btn-sm btn-outline-<?= $m['activo'] ? 'warning' : 'success' ?>"
                                    title="<?= $m['activo'] ? et('Desactivar') : et('Activar') ?>">
                                <i class="bi bi-<?= $m['activo'] ? 'pause' : 'play' ?>"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$medicos): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-person-vcard d-block mb-2" style="font-size:2rem;opacity:.4"></i>
                    <?= et('Todavía no hay médicos. Agrega el primero para empezar a dar citas.') ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
