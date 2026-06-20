<?php
require_once __DIR__ . '/../includes/functions.php';
if (is_logged_in()) { auditar('logout'); }
$_SESSION = [];
session_destroy();
redirect('/auth/login');
