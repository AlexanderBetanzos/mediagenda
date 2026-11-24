<?php
/**
 * Manifiesto de la app instalable (PWA). Con el nombre, color y página de
 * inicio del consultorio de quien la instala: en el celular del médico se ve
 * "Clínica Santa María", no "MediOS". ?app=portal para el portal del paciente.
 */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

$portal = ($_GET['app'] ?? '') === 'portal';
$nombre = marca_nombre();
$corto  = mb_strlen($nombre) > 12 ? mb_substr($nombre, 0, 12) : $nombre;
$ic = fn($s, $p = 'any') => ['src' => BASE_URL . '/assets/img/app-' . $s . '.png', 'sizes' => $s . 'x' . $s, 'type' => 'image/png', 'purpose' => $p];
echo json_encode([
    'id'               => BASE_URL . ($portal ? '/portal/' : '/dashboard'),
    'name'             => $portal ? $nombre . ' · ' . t('Mi portal') : $nombre,
    'short_name'       => $corto,
    'description'      => $portal ? t('Tus citas, recetas y estudios.') : t('Agenda, expediente y cobros de tu consultorio.'),
    'start_url'        => BASE_URL . ($portal ? '/portal/index' : '/dashboard'),
    'scope'            => BASE_URL . '/',
    'display'          => 'standalone',
    'background_color' => '#ffffff',
    'theme_color'      => color_acento(),
    'lang'             => 'es-MX',
    'icons'            => [$ic(192), $ic(512), $ic(512, 'maskable')],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
