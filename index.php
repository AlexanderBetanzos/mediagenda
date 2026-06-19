<?php
require_once __DIR__ . '/includes/functions.php';
$logged = is_logged_in();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?> · Gestión integral de consultorios</title>
    <meta name="description" content="Software para consultorios médicos y dentales: agenda de citas, expediente clínico electrónico y recordatorios en un solo lugar.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg landing-nav sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold text-brand" href="#"><i class="bi bi-heart-pulse-fill"></i> <?= e(marca_nombre()) ?></a>
        <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                <li class="nav-item"><a class="nav-link" href="#funciones">Funciones</a></li>
                <li class="nav-item"><a class="nav-link" href="#beneficios">Beneficios</a></li>
                <li class="nav-item"><a class="nav-link" href="#planes">Planes</a></li>
                <li class="nav-item">
                    <?php if ($logged): ?>
                        <a class="btn btn-primary px-3" href="<?= BASE_URL ?>/dashboard.php"><i class="bi bi-speedometer2"></i> Ir al panel</a>
                    <?php else: ?>
                        <a class="btn btn-primary px-3" href="<?= BASE_URL ?>/auth/login.php"><i class="bi bi-box-arrow-in-right"></i> Iniciar sesión</a>
                    <?php endif; ?>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Hero -->
<header class="hero py-5">
    <div class="container py-4">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="badge bg-light text-brand mb-3">Médico · Dental</span>
                <h1 class="display-5 mb-3">Expediente clínico, agenda y recordatorios en un solo lugar</h1>
                <p class="lead mb-4">Digitaliza tu consultorio: administra pacientes, agenda citas y lleva el historial clínico de forma simple, segura y desde cualquier dispositivo.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-light btn-lg px-4 text-brand fw-semibold">Comenzar ahora</a>
                    <a href="#funciones" class="btn btn-outline-light btn-lg px-4">Ver funciones</a>
                </div>
                <p class="small mt-3 mb-0"><i class="bi bi-shield-check"></i> Datos protegidos · Acceso por roles</p>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-semibold text-brand"><i class="bi bi-calendar-day"></i> Agenda de hoy</span>
                            <span class="badge bg-success">3 citas</span>
                        </div>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between"><span><span class="badge bg-light text-dark border me-2">09:00</span> María García</span><span class="badge bg-info">Confirmada</span></li>
                            <li class="list-group-item d-flex justify-content-between"><span><span class="badge bg-light text-dark border me-2">10:30</span> Juan Pérez</span><span class="badge bg-secondary">Programada</span></li>
                            <li class="list-group-item d-flex justify-content-between"><span><span class="badge bg-light text-dark border me-2">12:00</span> Ana Torres</span><span class="badge bg-success">Atendida</span></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Funciones -->
<section id="funciones" class="py-5">
    <div class="container py-3">
        <div class="text-center mb-5">
            <h2 class="section-title">Todo lo que tu consultorio necesita</h2>
            <p class="text-muted">Una plataforma simple para médicos, dentistas y recepción.</p>
        </div>
        <div class="row g-4">
            <?php
            $funcs = [
                ['bi-calendar-check','Agenda de citas','Programa, confirma y da seguimiento a las citas. Filtra por médico, fecha y estado.'],
                ['bi-folder2-open','Expediente clínico','Historial de consultas, diagnósticos, tratamientos, recetas y signos vitales por paciente.'],
                ['bi-bell','Recordatorios','Panel de próximas citas y agenda del día para no perder ninguna atención.'],
                ['bi-people','Gestión de pacientes','Registro completo: contacto, alergias, antecedentes y notas, médico o dental.'],
                ['bi-person-badge','Acceso por roles','Permisos para administrador, médicos/dentistas y recepción.'],
                ['bi-phone','Multidispositivo','Diseño responsivo: úsalo desde computadora, tableta o celular.'],
            ];
            foreach ($funcs as [$icon,$t,$d]): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-4">
                        <div class="feature-icon mb-3"><i class="bi <?= $icon ?>"></i></div>
                        <h5 class="fw-semibold"><?= e($t) ?></h5>
                        <p class="text-muted mb-0"><?= e($d) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Beneficios (sin/con) -->
<section id="beneficios" class="py-5 bg-white">
    <div class="container py-3">
        <div class="text-center mb-5"><h2 class="section-title">Antes y después</h2></div>
        <div class="row g-4 justify-content-center">
            <div class="col-md-5">
                <div class="card h-100 border-danger-subtle">
                    <div class="card-body p-4">
                        <h5 class="text-danger"><i class="bi bi-x-circle"></i> Sin el sistema</h5>
                        <ul class="text-muted mt-3 mb-0">
                            <li class="mb-2">Citas en libreta de papel y llamadas dispersas.</li>
                            <li class="mb-2">Expedientes físicos difíciles de consultar.</li>
                            <li class="mb-2">Pacientes que olvidan sus citas.</li>
                            <li>Información sin respaldo ni control de acceso.</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card h-100 border-success-subtle">
                    <div class="card-body p-4">
                        <h5 class="text-success"><i class="bi bi-check-circle"></i> Con el sistema</h5>
                        <ul class="text-muted mt-3 mb-0">
                            <li class="mb-2">Agenda centralizada con estados de cita.</li>
                            <li class="mb-2">Expediente electrónico accesible al instante.</li>
                            <li class="mb-2">Panel de recordatorios de próximas citas.</li>
                            <li>Acceso seguro por roles para tu equipo.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Planes -->
<section id="planes" class="py-5">
    <div class="container py-3">
        <div class="text-center mb-5">
            <h2 class="section-title">Planes para cada consultorio</h2>
            <p class="text-muted">Empieza simple y crece cuando lo necesites.</p>
        </div>
        <div class="row g-4 justify-content-center">
            <?php
            $planes = [
                ['Básico','$0','Para empezar', ['1 médico','Pacientes y citas','Expediente clínico'], false],
                ['Estándar','$299','Consultorio en crecimiento', ['Hasta 5 médicos','Recordatorios','Reportes básicos','Soporte por correo'], true],
                ['Premium','$599','Clínicas y equipos', ['Médicos ilimitados','Roles avanzados','Respaldo diario','Soporte prioritario'], false],
            ];
            foreach ($planes as [$nombre,$precio,$desc,$items,$feat]): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card price-card h-100 shadow-sm <?= $feat ? 'featured' : '' ?>">
                    <div class="card-body p-4 text-center">
                        <?php if ($feat): ?><span class="badge bg-primary mb-2">Más popular</span><?php endif; ?>
                        <h5 class="fw-bold"><?= e($nombre) ?></h5>
                        <div class="display-6 fw-bold text-brand"><?= e($precio) ?><span class="fs-6 text-muted fw-normal">/mes</span></div>
                        <p class="text-muted small"><?= e($desc) ?></p>
                        <ul class="list-unstyled text-start my-4">
                            <?php foreach ($items as $it): ?>
                                <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i><?= e($it) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <a href="<?= BASE_URL ?>/auth/login.php" class="btn <?= $feat ? 'btn-primary' : 'btn-outline-primary' ?> w-100">Elegir plan</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="hero py-5">
    <div class="container text-center py-3">
        <h2 class="mb-3">¿Listo para digitalizar tu consultorio?</h2>
        <p class="lead mb-4">Accede al sistema y comienza a gestionar tus pacientes hoy mismo.</p>
        <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-light btn-lg px-5 text-brand fw-semibold">Iniciar sesión</a>
    </div>
</section>

<!-- Footer -->
<footer class="landing-footer py-4">
    <div class="container d-flex flex-wrap justify-content-between align-items-center">
        <span><i class="bi bi-heart-pulse-fill"></i> <?= e(APP_NAME) ?></span>
        <span class="small">&copy; <?= date('Y') ?> · Todos los derechos reservados</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
