<?php
/**
 * Alta / edición de un médico del catálogo.
 *
 * El interruptor "Acceso al sistema" decide todo:
 *   · Sin acceso  -> solo nombre, especialidad, cédula, teléfono. No hay login;
 *                    el médico existe para asignarle citas y consultas.
 *   · Con acceso  -> además correo y contraseña. Entra al dashboard y ve SU
 *                    agenda (rol 'medico' ya lo restringe a lo suyo).
 */
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$id  = (int) ($_GET['id'] ?? 0);
$m   = ['nombre' => '', 'especialidad' => '', 'cedula' => '', 'telefono' => '', 'email' => ''];
$errores = [];

if ($id) {
    $st = db()->prepare("SELECT * FROM usuarios WHERE id = ? AND consultorio_id = ? AND rol = 'medico'");
    $st->execute([$id, tenant_id()]);
    $m = $st->fetch();
    if (!$m) { flash('Médico no encontrado.', 'warning'); redirect('/medicos/index'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $nombre       = trim($_POST['nombre'] ?? '');
    $especialidad = trim($_POST['especialidad'] ?? '') ?: null;
    $cedula       = trim($_POST['cedula'] ?? '') ?: null;
    $telefono     = trim($_POST['telefono'] ?? '') ?: null;
    $acceso       = !empty($_POST['acceso']);
    $email        = trim($_POST['email'] ?? '');
    $pass         = $_POST['password'] ?? '';

    if ($nombre === '') $errores[] = t('El nombre es obligatorio.');

    if ($acceso) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = t('Para dar acceso, el correo debe ser válido.');
        } else {
            // Correo único (entre todos los usuarios, no solo médicos).
            $chk = db()->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
            $chk->execute([$email, $id]);
            if ($chk->fetch()) $errores[] = t('Ya existe un usuario con ese correo.');
        }
        // En alta, o al activar el acceso por primera vez, la contraseña es obligatoria.
        $yaTenia = $id && !empty($m['password_hash']);
        if (!$yaTenia && strlen($pass) < 6) {
            $errores[] = t('La contraseña debe tener al menos 6 caracteres.');
        } elseif ($pass !== '' && strlen($pass) < 6) {
            $errores[] = t('La contraseña debe tener al menos 6 caracteres.');
        }
    }

    if (!$errores) {
        // Resuelve email y password según el acceso.
        $emailFinal = $acceso ? $email : null;   // sin acceso: sin correo -> no puede entrar
        $hashSql = ''; $hashParam = [];
        if ($acceso) {
            if ($pass !== '') { $hashSql = ', password_hash = ?'; $hashParam = [password_hash($pass, PASSWORD_DEFAULT)]; }
        } else {
            $hashSql = ', password_hash = NULL';   // sin acceso: se anula el login
        }

        if ($id) {
            $sql = "UPDATE usuarios SET nombre = ?, especialidad = ?, cedula = ?, telefono = ?, email = ?$hashSql
                    WHERE id = ? AND consultorio_id = ? AND rol = 'medico'";
            $params = array_merge([$nombre, $especialidad, $cedula, $telefono, $emailFinal], $hashParam, [$id, tenant_id()]);
            db()->prepare($sql)->execute($params);
            auditar('medico_editar', 'usuario', $id, $nombre);
            flash('Médico actualizado.');
        } else {
            $hash = ($acceso && $pass !== '') ? password_hash($pass, PASSWORD_DEFAULT) : null;
            db()->prepare(
                "INSERT INTO usuarios (consultorio_id, nombre, email, password_hash, rol, especialidad, cedula, telefono)
                 VALUES (?,?,?,?, 'medico', ?,?,?)"
            )->execute([tenant_id(), $nombre, $emailFinal, $hash, $especialidad, $cedula, $telefono]);
            auditar('medico_crear', 'usuario', (int) db()->lastInsertId(), $nombre);
            flash('Médico agregado. Ya puedes asignarle citas.');
        }
        redirect('/medicos/index');
    }

    // Al re-pintar tras error, conserva lo escrito.
    $m = array_merge((array) $m, [
        'nombre' => $nombre, 'especialidad' => $especialidad, 'cedula' => $cedula,
        'telefono' => $telefono, 'email' => $email,
    ]);
    $accesoOn = $acceso;
}

$accesoOn = $accesoOn ?? (!empty($m['email']) && !empty($m['password_hash']));

$titulo = $id ? t('Editar médico') : t('Nuevo médico');
$activo = 'medicos';
include __DIR__ . '/../includes/header.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/medicos/index"><?= et('Médicos') ?></a></li>
    <li class="breadcrumb-item active"><?= $id ? et('Editar') : et('Nuevo') ?></li>
</ol></nav>

<h1 class="h3 mb-3"><i class="bi bi-person-vcard text-brand"></i> <?= $id ? et('Editar médico') : et('Nuevo médico') ?></h1>

<?php if ($errores): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card" style="max-width:720px">
    <div class="card-body row g-3">
        <?= csrf_field() ?>

        <div class="col-md-7">
            <label class="form-label"><?= et('Nombre completo') ?> *</label>
            <input type="text" name="nombre" class="form-control" required maxlength="120" value="<?= e($m['nombre']) ?>"
                   placeholder="<?= e(t('Dra. Ana López')) ?>">
        </div>
        <div class="col-md-5">
            <label class="form-label"><?= et('Especialidad') ?></label>
            <input type="text" name="especialidad" class="form-control" maxlength="120" value="<?= e($m['especialidad'] ?? '') ?>"
                   placeholder="<?= e(t('Pediatría, Odontología…')) ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label"><?= et('Cédula profesional') ?></label>
            <input type="text" name="cedula" class="form-control" maxlength="40" value="<?= e($m['cedula'] ?? '') ?>">
        </div>
        <div class="col-md-6">
            <label class="form-label"><?= et('Teléfono') ?></label>
            <input type="text" name="telefono" class="form-control" maxlength="40" value="<?= e($m['telefono'] ?? '') ?>">
        </div>

        <div class="col-12"><hr class="my-1"></div>

        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="acceso" name="acceso" value="1"
                       <?= $accesoOn ? 'checked' : '' ?> onchange="document.getElementById('bloqueAcceso').classList.toggle('d-none', !this.checked)">
                <label class="form-check-label" for="acceso">
                    <strong><?= et('Este médico entra al sistema') ?></strong>
                </label>
            </div>
            <div class="form-text">
                <?= et('Actívalo solo si el médico va a usar el dashboard (ver su agenda, registrar consultas). Si solo pasa consulta y tú le agendas, déjalo apagado: no necesita usuario ni contraseña.') ?>
            </div>
        </div>

        <div id="bloqueAcceso" class="col-12 <?= $accesoOn ? '' : 'd-none' ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><?= et('Correo') ?></label>
                    <input type="email" name="email" class="form-control" value="<?= e($m['email'] ?? '') ?>" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label">
                        <?= et('Contraseña') ?>
                        <?php if ($id && !empty($m['password_hash'])): ?>
                            <span class="text-muted small">(<?= et('en blanco = no cambiar') ?>)</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password">
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white text-end">
        <a href="<?= BASE_URL ?>/medicos/index" class="btn btn-light"><?= et('Cancelar') ?></a>
        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= $id ? et('Guardar') : et('Agregar médico') ?></button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
