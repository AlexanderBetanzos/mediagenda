<?php
/**
 * Piezas visuales compartidas del panel.
 *
 * ui_stats(): franja de indicadores al inicio de cada sección.
 *   Cada indicador: [etiqueta, valor, icono, color, url|null, alerta(bool)]
 * Las etiquetas pasan por t() (vocabulario del giro) en quien las arma.
 */
function ui_stats(array $items): string
{
    $h = '<div class="ui-stats">';
    foreach ($items as $it) {
        [$lbl, $val, $ic, $color] = $it;
        $url = $it[4] ?? null; $alerta = !empty($it[5]);
        $tag = $url ? 'a href="' . e($url) . '"' : 'div';
        $h .= '<' . $tag . ' class="ui-stat' . ($alerta ? ' alerta' : '') . '" style="--c:' . e($color) . '">'
            . '<span class="ui-stat-ic"><i class="bi ' . e($ic) . '"></i></span>'
            . '<span><span class="ui-stat-n d-block">' . e((string) $val) . '</span><span class="ui-stat-l d-block">' . e($lbl) . '</span></span>'
            . '</' . ($url ? 'a' : 'div') . '>';
    }
    return $h . '</div>';
}

/** Conteo seguro para indicadores: 0 si la consulta falla (tabla o columna aún no creada). */
function ui_cuenta(string $sql, array $p = []): int
{
    try { $st = db()->prepare($sql); $st->execute($p); return (int) $st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
