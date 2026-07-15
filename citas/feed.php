<?php
/**
 * Feed JSON de citas para FullCalendar. Devuelve los eventos del consultorio
 * activo en el rango que pide el calendario (?start=&end=), opcionalmente
 * filtrados por médico.
 */
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_modulo_json('citas');

header('Content-Type: application/json; charset=utf-8');

$ini = substr($_GET['start'] ?? '', 0, 10) ?: date('Y-m-01');
$fin = substr($_GET['end']   ?? '', 0, 10) ?: date('Y-m-t');
$medico = $_GET['medico'] ?? '';

$where  = ['c.consultorio_id = ?', 'c.fecha BETWEEN ? AND ?'];
$params = [tenant_id(), $ini, $fin];
if ($medico !== '' && ctype_digit($medico)) { $where[] = 'c.medico_id = ?'; $params[] = (int) $medico; }

$sql = "SELECT c.id, c.fecha, c.hora, c.duracion, c.estado, c.tipo, c.motivo, c.paciente_id,
               p.nombre AS pac_nombre, p.apellidos AS pac_ape, u.nombre AS med_nombre
        FROM citas c
        JOIN pacientes p ON p.id = c.paciente_id
        JOIN usuarios  u ON u.id = c.medico_id
        WHERE " . implode(' AND ', $where);
$st = db()->prepare($sql);
$st->execute($params);

// Colores por estado (coherentes con los badges de la app).
$colores = [
    'programada' => '#6c757d', 'confirmada' => '#0dcaf0', 'esperando' => '#f59e0b',
    'en_consulta' => '#1f6b73', 'atendida' => '#198754', 'cancelada' => '#dc3545',
    'no_asistio' => '#343a40',
];

$eventos = [];
foreach ($st as $c) {
    $inicio = $c['fecha'] . 'T' . $c['hora'];
    $dur    = (int) ($c['duracion'] ?: 30);
    $fin_ev = date('Y-m-d\TH:i:s', strtotime($inicio) + $dur * 60);
    $eventos[] = [
        'id'    => (int) $c['id'],
        'title' => $c['pac_ape'] . ', ' . $c['pac_nombre'],
        'start' => $inicio,
        'end'   => $fin_ev,
        'color' => $colores[$c['estado']] ?? '#6c757d',
        'url'   => BASE_URL . '/citas/edit?id=' . (int) $c['id'],
        'extendedProps' => [
            'estado' => estado_label($c['estado']),
            'medico' => $c['med_nombre'],
            'motivo' => $c['motivo'],
            'tipo'   => $c['tipo'],
        ],
    ];
}

// Bloqueos como eventos de fondo (franjas en rojo donde no se agenda).
$wb = ['b.consultorio_id = ?', 'b.fin >= ?', 'b.inicio <= ?'];
$pb = [tenant_id(), $ini . ' 00:00:00', $fin . ' 23:59:59'];
if ($medico !== '' && ctype_digit($medico)) {
    $wb[] = '(b.medico_id = ? OR b.medico_id IS NULL)';
    $pb[] = (int) $medico;
}
$bq = db()->prepare('SELECT inicio, fin, motivo FROM bloqueos b WHERE ' . implode(' AND ', $wb));
$bq->execute($pb);
foreach ($bq as $b) {
    $eventos[] = [
        'start'   => str_replace(' ', 'T', $b['inicio']),
        'end'     => str_replace(' ', 'T', $b['fin']),
        'display' => 'background',
        'color'   => '#dc3545',
        'title'   => $b['motivo'] ?: 'Bloqueo',
    ];
}

echo json_encode($eventos, JSON_UNESCAPED_UNICODE);
