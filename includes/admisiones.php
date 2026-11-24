<?php
/**
 * Admisiones: solicitudes de atención que llegan ANTES de que exista el
 * paciente. Típico en salud mental: quien escribe es un familiar ("mi hijo
 * necesita ayuda"), no el paciente.
 *
 * Embudo: nuevo → contactado → valoración → ingresó | no procede.
 * Llegan por el formulario de la página pública (/admision/solicitar) o las
 * captura la Jefatura de Admisión a mano (llamadas). Desde la solicitud se
 * crea el paciente, se agenda su valoración o se interna.
 */

function asegurar_admisiones(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    db()->exec("CREATE TABLE IF NOT EXISTS admisiones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        paciente_nombre VARCHAR(160) NOT NULL,
        paciente_edad VARCHAR(20) NULL,
        solicitante VARCHAR(160) NULL,
        parentesco VARCHAR(60) NULL,
        telefono VARCHAR(40) NULL,
        email VARCHAR(150) NULL,
        situacion TEXT NULL,
        servicio VARCHAR(40) NULL,
        urgencia VARCHAR(20) NULL,
        preferencia VARCHAR(20) NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'nuevo',
        notas TEXT NULL,
        paciente_id INT NULL,
        asignado_a INT NULL,
        origen VARCHAR(20) NOT NULL DEFAULT 'web',
        ip VARCHAR(45) NULL,
        creado_en DATETIME NOT NULL,
        actualizado_en DATETIME NULL,
        INDEX idx_adm (consultorio_id, estado, creado_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function admision_estados(): array
{
    return [
        'nuevo'      => ['Nuevas', 'bi-inbox', '#2563eb'],
        'contactado' => ['Contactadas', 'bi-telephone', '#f59e0b'],
        'valoracion' => ['Valoración agendada', 'bi-calendar-check', '#7c3aed'],
        'ingreso'    => ['Ingresaron', 'bi-check-circle', '#16a34a'],
        'no_procede' => ['No procede', 'bi-x-circle', '#64748b'],
    ];
}

function admision_servicios(): array
{
    return [
        'psicologia'  => 'Terapia psicológica',
        'psiquiatria' => 'Atención psiquiátrica',
        'residencial' => 'Programa residencial / internamiento',
        'no_se'       => 'No sé, necesito orientación',
    ];
}

function admision_urgencias(): array
{
    return ['hoy' => 'Lo antes posible', 'semana' => 'Esta semana', 'info' => 'Solo quiero información'];
}

/** ¿La clínica muestra "¿Tienes un ser querido…?" en su página? */
function admision_web_activa(): bool
{
    if (!modulo_activo('crm')) return false;
    $def = in_array(giro_actual(), ['psiquiatria', 'psicologia', 'clinica'], true) ? '1' : '0';
    return (string) cfg('admision_web', $def) === '1';
}

/** Cuántas solicitudes nuevas sin atender (para el menú). */
function admisiones_nuevas(): int
{
    try {
        asegurar_admisiones();
        $st = db()->prepare("SELECT COUNT(*) FROM admisiones WHERE consultorio_id = ? AND estado = 'nuevo'");
        $st->execute([tenant_id()]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Mueve una solicitud de etapa (y la liga al paciente si ya existe). */
function admision_marcar(int $id, string $estado, ?int $pacienteId = null): void
{
    if (!array_key_exists($estado, admision_estados())) return;
    asegurar_admisiones();
    db()->prepare('UPDATE admisiones SET estado = ?, paciente_id = COALESCE(?, paciente_id), actualizado_en = NOW() WHERE id = ? AND consultorio_id = ?')
        ->execute([$estado, $pacienteId, $id, tenant_id()]);
}

/** Mensaje de WhatsApp para el primer contacto con quien solicitó. */
function admision_wa(array $a): string
{
    if (empty($a['telefono'])) return '';
    $hola = t('Hola') . ($a['solicitante'] ? ' ' . preg_split('/\s+/', trim($a['solicitante']))[0] : '') . ', '
          . sprintf(t('le escribimos de %s por la solicitud que nos dejó'), marca_nombre())
          . ($a['paciente_nombre'] && $a['paciente_nombre'] !== $a['solicitante'] ? ' ' . t('para') . ' ' . $a['paciente_nombre'] : '')
          . '. ' . t('¿Es buen momento para platicar?');
    return wa_link($a['telefono'], $hola);
}
