<?php
require_once __DIR__ . '/../includes/functions.php';
if (isset($_SESSION['paciente'])) { auditar('portal_logout', 'paciente', (int) $_SESSION['paciente']['id']); }
unset($_SESSION['paciente']);
redirect('/portal/login');
