<?php
/** "¿Te fue útil?" del centro de ayuda. Público; máx. 30 votos por sesión. */
require_once __DIR__ . '/../includes/centro_ayuda.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{}'); }
verify_csrf();
$_SESSION['ca_votos'] = ($_SESSION['ca_votos'] ?? 0) + 1;
$slug = preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['slug'] ?? ''));
if ($_SESSION['ca_votos'] > 30 || !isset(ca_articulos()[$slug])) exit('{"ok":false}');
asegurar_ayuda_util();
db()->prepare('INSERT INTO ayuda_util (slug, util, comentario, ip) VALUES (?,?,?,?)')
    ->execute([$slug, ($_POST['util'] ?? '') === '1' ? 1 : 0, mb_substr(trim((string) ($_POST['comentario'] ?? '')), 0, 500) ?: null, client_ip()]);
echo '{"ok":true}';
