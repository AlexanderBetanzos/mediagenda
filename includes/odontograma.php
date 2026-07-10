<?php
/**
 * Odontograma: vocabulario clínico y acceso a datos.
 *
 * Una marca es la tupla (paciente, diente, cara, condición) => estado.
 *   · existente = hallazgo actual del diente/cara
 *   · requerido = tratamiento planeado
 *   · realizado = tratamiento ya ejecutado
 * Los hallazgos y los tratamientos son vocabularios distintos: "caries" es un
 * hallazgo, "obturación" es el tratamiento que lo resuelve.
 */
require_once __DIR__ . '/functions.php';

/** Hallazgos (condición "existente"). completo = afecta al diente entero. */
function odo_hallazgos(): array
{
    return [
        'caries'     => ['label' => 'Caries',      'color' => '#dc3545', 'completo' => false],
        'obturado'   => ['label' => 'Obturado',    'color' => '#0d6efd', 'completo' => false],
        'sellante'   => ['label' => 'Sellante',    'color' => '#20c997', 'completo' => false],
        'fractura'   => ['label' => 'Fractura',    'color' => '#fd7e14', 'completo' => false],
        'corona'     => ['label' => 'Corona',      'color' => '#ffc107', 'completo' => true],
        'endodoncia' => ['label' => 'Endodoncia',  'color' => '#6f42c1', 'completo' => true],
        'implante'   => ['label' => 'Implante',    'color' => '#0dcaf0', 'completo' => true],
        'protesis'   => ['label' => 'Prótesis',    'color' => '#6c757d', 'completo' => true],
        'ausente'    => ['label' => 'Ausente',     'color' => '#adb5bd', 'completo' => true],
    ];
}

/**
 * Tratamientos (condiciones "requerido" y "realizado"). `resultado` es el
 * hallazgo en que queda la cara una vez ejecutado el tratamiento.
 */
function odo_tratamientos(): array
{
    return [
        'obturacion' => ['label' => 'Obturación', 'color' => '#0d6efd', 'completo' => false, 'resultado' => 'obturado'],
        'sellante'   => ['label' => 'Sellante',   'color' => '#20c997', 'completo' => false, 'resultado' => 'sellante'],
        'endodoncia' => ['label' => 'Endodoncia', 'color' => '#6f42c1', 'completo' => true,  'resultado' => 'endodoncia'],
        'corona'     => ['label' => 'Corona',     'color' => '#ffc107', 'completo' => true,  'resultado' => 'corona'],
        'protesis'   => ['label' => 'Prótesis',   'color' => '#6c757d', 'completo' => true,  'resultado' => 'protesis'],
        'implante'   => ['label' => 'Implante',   'color' => '#0dcaf0', 'completo' => true,  'resultado' => 'implante'],
        'extraccion' => ['label' => 'Extracción', 'color' => '#212529', 'completo' => true,  'resultado' => 'ausente'],
    ];
}

/** Caras de un diente. 'C' (completo) no se lista: es implícito. */
function odo_caras(): array
{
    return ['O' => 'Oclusal', 'M' => 'Mesial', 'D' => 'Distal', 'V' => 'Vestibular', 'L' => 'Lingual/Palatina'];
}

/** Vocabulario que corresponde a una condición. */
function odo_vocabulario(string $condicion): array
{
    return $condicion === 'existente' ? odo_hallazgos() : odo_tratamientos();
}

/** ¿La pareja condición/estado existe en el vocabulario correcto? */
function odo_estado_valido(string $condicion, string $estado): bool
{
    return isset(odo_vocabulario($condicion)[$estado]);
}

/** Cara donde se guarda un estado: 'C' si el estado afecta al diente entero. */
function odo_cara_de(string $condicion, string $estado, string $cara): string
{
    $def = odo_vocabulario($condicion)[$estado] ?? null;
    return ($def && $def['completo']) ? 'C' : $cara;
}

/**
 * Lado del paciente al que apunta la cara mesial de un diente. Los cuadrantes 1
 * y 4 (dientes 11-18 y 41-48) están a la derecha del paciente y se dibujan a la
 * izquierda de la pantalla, así que su mesial apunta a la derecha del recuadro.
 */
function odo_mesial_a_la_derecha(int $diente): bool
{
    $cuadrante = (int) floor($diente / 10);
    return in_array($cuadrante, [1, 4], true);
}

/**
 * Estado completo del odontograma de un paciente.
 * @return array{marcas: array<string, array<string, array<string,string>>>, notas: array<string,string>}
 *         marcas[diente][cara][condicion] = estado
 */
function odo_cargar(int $paciente_id): array
{
    odo_importar_legacy($paciente_id);
    return odo_leer($paciente_id);
}

/**
 * Lectura pura del odontograma, sin migrar nada. La usan el snapshot y todo lo
 * que corre dentro de una transacción, donde importar datos viejos reviviría
 * marcas que se acaban de reemplazar.
 */
function odo_leer(int $paciente_id): array
{
    $st = db()->prepare(
        'SELECT diente, cara, condicion, estado FROM odontograma_marcas
         WHERE paciente_id = ? AND consultorio_id = ?'
    );
    $st->execute([$paciente_id, tenant_id()]);
    $marcas = [];
    foreach ($st as $m) {
        $marcas[$m['diente']][$m['cara']][$m['condicion']] = $m['estado'];
    }

    $nt = db()->prepare('SELECT diente, nota FROM odontograma_notas WHERE paciente_id = ? AND consultorio_id = ?');
    $nt->execute([$paciente_id, tenant_id()]);

    return ['marcas' => $marcas, 'notas' => $nt->fetchAll(PDO::FETCH_KEY_PAIR)];
}

/**
 * Importa el odontograma viejo (JSON plano diente => estado) a marcas por cara.
 * Se ejecuta una sola vez por paciente; después la fila queda con migrado = 1.
 */
function odo_importar_legacy(int $paciente_id): void
{
    try {
        $st = db()->prepare('SELECT datos FROM odontogramas
                             WHERE paciente_id = ? AND consultorio_id = ? AND migrado = 0');
        $st->execute([$paciente_id, tenant_id()]);
        $raw = $st->fetchColumn();
        if ($raw === false) return;
    } catch (Throwable $e) {
        return; // la tabla vieja o la columna `migrado` no existen: nada que importar
    }

    $datos  = json_decode((string) $raw, true) ?: [];
    $estAnt = $datos['estados'] ?? (isset($datos['notas']) ? [] : $datos);
    $notAnt = $datos['notas'] ?? [];

    // El odontograma viejo solo marcaba el diente completo. "extracción" era una
    // indicación, no un hallazgo: se importa como tratamiento requerido.
    $mapa = [
        'caries'     => ['existente', 'caries'],
        'obturado'   => ['existente', 'obturado'],
        'corona'     => ['existente', 'corona'],
        'endodoncia' => ['existente', 'endodoncia'],
        'ausente'    => ['existente', 'ausente'],
        'extraccion' => ['requerido', 'extraccion'],
    ];

    $pdo = db();
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO odontograma_marcas
         (consultorio_id, paciente_id, diente, cara, condicion, estado) VALUES (?,?,?,\'C\',?,?)'
    );
    foreach ($estAnt as $diente => $estado) {
        if (!isset($mapa[$estado]) || !in_array((int) $diente, dientes_fdi(), true)) continue;
        [$condicion, $nuevo] = $mapa[$estado];
        $ins->execute([tenant_id(), $paciente_id, (string) (int) $diente, $condicion, $nuevo]);
    }

    $insNota = $pdo->prepare(
        'INSERT IGNORE INTO odontograma_notas (consultorio_id, paciente_id, diente, nota) VALUES (?,?,?,?)'
    );
    foreach ($notAnt as $diente => $nota) {
        $nota = trim((string) $nota);
        if ($nota === '' || !in_array((int) $diente, dientes_fdi(), true)) continue;
        $insNota->execute([tenant_id(), $paciente_id, (string) (int) $diente, mb_substr($nota, 0, 200)]);
    }

    $pdo->prepare('UPDATE odontogramas SET migrado = 1 WHERE paciente_id = ? AND consultorio_id = ?')
        ->execute([$paciente_id, tenant_id()]);
}

/** Guarda una foto del odontograma tal como queda tras el cambio. */
function odo_snapshot(int $paciente_id, ?string $motivo = null): void
{
    $estado = odo_leer($paciente_id);
    db()->prepare(
        'INSERT INTO odontograma_historial (consultorio_id, paciente_id, snapshot, motivo, usuario_id)
         VALUES (?,?,?,?,?)'
    )->execute([
        tenant_id(), $paciente_id,
        json_encode($estado, JSON_UNESCAPED_UNICODE),
        $motivo ? mb_substr($motivo, 0, 120) : null,
        current_user()['id'] ?? null,
    ]);
}

/**
 * Reemplaza el odontograma del paciente con lo que llega del editor y guarda en
 * el historial una foto del resultado.
 *
 * @param array $marcas [diente][cara][condicion] = estado (ya validado o no)
 * @param array $notas  [diente] = nota
 */
function odo_guardar(int $paciente_id, array $marcas, array $notas, ?string $motivo = null): void
{
    $pdo = db();
    $dientes = dientes_fdi();
    $caras   = array_merge(['C'], array_keys(odo_caras()));

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM odontograma_marcas WHERE paciente_id = ? AND consultorio_id = ?')
            ->execute([$paciente_id, tenant_id()]);
        $pdo->prepare('DELETE FROM odontograma_notas WHERE paciente_id = ? AND consultorio_id = ?')
            ->execute([$paciente_id, tenant_id()]);

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO odontograma_marcas
             (consultorio_id, paciente_id, diente, cara, condicion, estado, actualizado_por)
             VALUES (?,?,?,?,?,?,?)'
        );
        $uid = current_user()['id'] ?? null;
        foreach ($marcas as $diente => $porCara) {
            if (!in_array((int) $diente, $dientes, true) || !is_array($porCara)) continue;
            foreach ($porCara as $cara => $porCondicion) {
                if (!in_array((string) $cara, $caras, true) || !is_array($porCondicion)) continue;
                foreach ($porCondicion as $condicion => $estado) {
                    $condicion = (string) $condicion;
                    $estado    = (string) $estado;
                    if (!in_array($condicion, ['existente', 'requerido', 'realizado'], true)) continue;
                    if (!odo_estado_valido($condicion, $estado)) continue;
                    // Un estado "de diente completo" siempre se guarda en la cara C.
                    $ins->execute([tenant_id(), $paciente_id, (string) (int) $diente,
                                   odo_cara_de($condicion, $estado, (string) $cara), $condicion, $estado, $uid]);
                }
            }
        }

        $insNota = $pdo->prepare(
            'INSERT IGNORE INTO odontograma_notas (consultorio_id, paciente_id, diente, nota) VALUES (?,?,?,?)'
        );
        foreach ($notas as $diente => $nota) {
            $nota = trim((string) $nota);
            if ($nota === '' || !in_array((int) $diente, $dientes, true)) continue;
            $insNota->execute([tenant_id(), $paciente_id, (string) (int) $diente, mb_substr($nota, 0, 200)]);
        }

        odo_snapshot($paciente_id, $motivo);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Tratamientos requeridos del paciente, agrupados por diente + estado. */
function odo_requeridos(int $paciente_id): array
{
    $st = db()->prepare(
        "SELECT diente, estado, GROUP_CONCAT(cara ORDER BY cara) AS caras
         FROM odontograma_marcas
         WHERE paciente_id = ? AND consultorio_id = ? AND condicion = 'requerido'
         GROUP BY diente, estado
         ORDER BY CAST(diente AS UNSIGNED), estado"
    );
    $st->execute([$paciente_id, tenant_id()]);
    return $st->fetchAll();
}

/**
 * Un procedimiento del presupuesto se ejecutó: la marca pasa de requerido a
 * realizado y el hallazgo de esas caras se actualiza al resultado del
 * tratamiento (una obturación deja la cara "obturada").
 * Si el item no vino del odontograma ($tratamiento nulo), no hay nada que hacer.
 */
function odo_aplicar_tratamiento(int $paciente_id, string $diente, ?string $caras, ?string $tratamiento): void
{
    $def = $tratamiento ? (odo_tratamientos()[$tratamiento] ?? null) : null;
    if (!$def || !in_array((int) $diente, dientes_fdi(), true)) return;

    $objetivo = $def['completo'] ? ['C'] : array_filter(explode(',', (string) $caras));
    if (!$objetivo) return;

    $pdo = db();
    // Un tratamiento previo sobre la misma cara ya no es el vigente: se sustituye.
    $limpiaRealizado = $pdo->prepare(
        "DELETE FROM odontograma_marcas
         WHERE paciente_id = ? AND consultorio_id = ? AND diente = ? AND cara = ? AND condicion = 'realizado'"
    );
    $mueve = $pdo->prepare(
        "UPDATE odontograma_marcas SET condicion = 'realizado'
         WHERE paciente_id = ? AND consultorio_id = ? AND diente = ? AND cara = ?
           AND condicion = 'requerido' AND estado = ?"
    );
    $hallazgo = $pdo->prepare(
        'INSERT INTO odontograma_marcas (consultorio_id, paciente_id, diente, cara, condicion, estado)
         VALUES (?,?,?,?,\'existente\',?)
         ON DUPLICATE KEY UPDATE estado = VALUES(estado)'
    );
    $limpiaCaries = $pdo->prepare(
        "DELETE FROM odontograma_marcas
         WHERE paciente_id = ? AND consultorio_id = ? AND diente = ? AND condicion = 'existente'
           AND cara <> 'C'"
    );

    foreach ($objetivo as $cara) {
        $limpiaRealizado->execute([$paciente_id, tenant_id(), $diente, $cara]);
        $mueve->execute([$paciente_id, tenant_id(), $diente, $cara, $tratamiento]);
        // Una extracción deja el diente ausente: los hallazgos por cara dejan de aplicar.
        if ($def['resultado'] === 'ausente') {
            $limpiaCaries->execute([$paciente_id, tenant_id(), $diente]);
        }
        $hallazgo->execute([tenant_id(), $paciente_id, $diente, $cara, $def['resultado']]);
    }
}
