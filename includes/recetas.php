<?php
/**
 * Recetas: catálogo de medicamentos, recetas favoritas, vía de administración
 * y token público para verificar la receta con QR (/rx?t=...).
 *
 * El catálogo APRENDE: cada medicamento que se receta se guarda (o suma un uso)
 * en el catálogo del consultorio, con la dosis, vía, frecuencia y duración de la
 * primera vez como valores sugeridos. Así, sin capturar nada aparte, a la
 * segunda receta ya se autocompleta. Encima hay una lista de genéricos comunes
 * (med_sugeridos) SIN dosis: la dosis siempre la decide el médico.
 */

function asegurar_recetas(): void
{
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $pdo = db();
    if (!$pdo->query("SHOW COLUMNS FROM receta_items LIKE 'via'")->fetch()) {
        $pdo->exec("ALTER TABLE receta_items ADD via VARCHAR(60) NULL AFTER dosis");
    }
    if (!$pdo->query("SHOW COLUMNS FROM recetas LIKE 'token'")->fetch()) {
        $pdo->exec("ALTER TABLE recetas ADD token CHAR(32) NULL, ADD UNIQUE KEY uq_receta_token (token)");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS medicamentos_cat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        nombre VARCHAR(200) NOT NULL,
        dosis VARCHAR(120) NULL,
        via VARCHAR(60) NULL,
        frecuencia VARCHAR(120) NULL,
        duracion VARCHAR(120) NULL,
        favorito TINYINT(1) NOT NULL DEFAULT 0,
        usos INT NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_medcat (consultorio_id, favorito, usos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receta_favoritas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        consultorio_id INT NOT NULL,
        medico_id INT NULL,
        nombre VARCHAR(120) NOT NULL,
        diagnostico VARCHAR(255) NULL,
        indicaciones TEXT NULL,
        items TEXT NOT NULL,
        usos INT NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_rxfav (consultorio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Vías de administración para el selector. */
function receta_vias(): array
{
    return ['Oral', 'Sublingual', 'Tópica', 'Oftálmica', 'Ótica', 'Nasal', 'Inhalada', 'Rectal', 'Vaginal',
            'Intramuscular', 'Intravenosa', 'Subcutánea', 'Transdérmica'];
}

/**
 * Genéricos de uso común para arrancar el catálogo: nombre con concentración y
 * presentación, y su vía. Sin dosis a propósito.
 */
function med_sugeridos(): array
{
    $o = 'Oral';
    return [
        ['Paracetamol 500 mg tabletas', $o], ['Paracetamol 160 mg/5 ml suspensión', $o],
        ['Ibuprofeno 400 mg tabletas', $o], ['Ibuprofeno 100 mg/5 ml suspensión', $o],
        ['Naproxeno 250 mg tabletas', $o], ['Diclofenaco 50 mg tabletas', $o],
        ['Ketorolaco 10 mg tabletas', $o], ['Metamizol 500 mg tabletas', $o],
        ['Amoxicilina 500 mg cápsulas', $o], ['Amoxicilina 250 mg/5 ml suspensión', $o],
        ['Amoxicilina/ácido clavulánico 875/125 mg tabletas', $o], ['Azitromicina 500 mg tabletas', $o],
        ['Claritromicina 500 mg tabletas', $o], ['Cefalexina 500 mg cápsulas', $o],
        ['Ciprofloxacino 500 mg tabletas', $o], ['Trimetoprima/sulfametoxazol 160/800 mg tabletas', $o],
        ['Nitrofurantoína 100 mg cápsulas', $o], ['Metronidazol 500 mg tabletas', $o],
        ['Fluconazol 150 mg cápsulas', $o], ['Loratadina 10 mg tabletas', $o],
        ['Cetirizina 10 mg tabletas', $o], ['Clorfenamina 4 mg tabletas', $o],
        ['Omeprazol 20 mg cápsulas', $o], ['Pantoprazol 40 mg tabletas', $o],
        ['Butilhioscina 10 mg grageas', $o], ['Metoclopramida 10 mg tabletas', $o],
        ['Ondansetrón 8 mg tabletas', $o], ['Loperamida 2 mg tabletas', $o],
        ['Sales de rehidratación oral, sobre', $o], ['Ambroxol 30 mg/5 ml jarabe', $o],
        ['Salbutamol 100 mcg aerosol para inhalación', 'Inhalada'],
        ['Metformina 850 mg tabletas', $o], ['Glibenclamida 5 mg tabletas', $o],
        ['Losartán 50 mg tabletas', $o], ['Enalapril 10 mg tabletas', $o],
        ['Amlodipino 5 mg tabletas', $o], ['Atorvastatina 20 mg tabletas', $o],
        ['Ácido acetilsalicílico 100 mg tabletas', $o], ['Levotiroxina 100 mcg tabletas', $o],
        ['Prednisona 5 mg tabletas', $o], ['Ácido fólico 5 mg tabletas', $o],
        ['Sulfato ferroso 200 mg tabletas', $o],
        ['Dexametasona 8 mg/2 ml solución inyectable', 'Intramuscular'],
        ['Clotrimazol 1% crema', 'Tópica'], ['Mupirocina 2% ungüento', 'Tópica'],
        ['Hidrocortisona 1% crema', 'Tópica'], ['Tobramicina 0.3% solución oftálmica', 'Oftálmica'],
    ];
}

function med_clave(string $s): string
{
    $s = mb_strtolower(trim(preg_replace('/\s+/', ' ', $s)));
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/** Catálogo del consultorio (favoritos y más usados primero). */
function med_catalogo(): array
{
    asegurar_recetas();
    $st = db()->prepare('SELECT * FROM medicamentos_cat WHERE consultorio_id = ? ORDER BY favorito DESC, usos DESC, nombre');
    $st->execute([tenant_id()]);
    return $st->fetchAll();
}

/**
 * Lista para el autocompletado de la receta: lo propio primero, después los
 * sugeridos que el consultorio todavía no tiene. [nombre, dosis, via, frecuencia, duracion, fav]
 */
function med_autocompletar(): array
{
    $out = [];
    $vistos = [];
    foreach (med_catalogo() as $m) {
        $vistos[med_clave($m['nombre'])] = true;
        $out[] = [$m['nombre'], (string) $m['dosis'], (string) $m['via'], (string) $m['frecuencia'], (string) $m['duracion'], (int) $m['favorito']];
    }
    foreach (med_sugeridos() as [$n, $v]) {
        if (!isset($vistos[med_clave($n)])) $out[] = [$n, '', $v, '', '', 0];
    }
    return $out;
}

/** Suma cada medicamento recetado al catálogo del consultorio. */
function med_aprender(array $items): void
{
    asegurar_recetas();
    $pdo = db();
    $mios = [];
    foreach (med_catalogo() as $m) $mios[med_clave($m['nombre'])] = (int) $m['id'];
    $up  = $pdo->prepare('UPDATE medicamentos_cat SET usos = usos + 1 WHERE id = ? AND consultorio_id = ?');
    $ins = $pdo->prepare('INSERT INTO medicamentos_cat (consultorio_id, nombre, dosis, via, frecuencia, duracion, usos) VALUES (?,?,?,?,?,?,1)');
    foreach ($items as $it) {
        $nom = trim((string) ($it['medicamento'] ?? ''));
        if ($nom === '') continue;
        $k = med_clave($nom);
        if (isset($mios[$k])) { $up->execute([$mios[$k], tenant_id()]); continue; }
        $ins->execute([tenant_id(), mb_substr($nom, 0, 200),
            trim((string) ($it['dosis'] ?? '')) ?: null, trim((string) ($it['via'] ?? '')) ?: null,
            trim((string) ($it['frecuencia'] ?? '')) ?: null, trim((string) ($it['duracion'] ?? '')) ?: null]);
        $mios[$k] = (int) $pdo->lastInsertId();
    }
}

/** Recetas favoritas que ve este usuario: las suyas y las de todo el consultorio (medico_id NULL). */
function receta_favoritas(): array
{
    asegurar_recetas();
    $st = db()->prepare('SELECT * FROM receta_favoritas WHERE consultorio_id = ? AND (medico_id IS NULL OR medico_id = ?)
                         ORDER BY usos DESC, nombre');
    $st->execute([tenant_id(), (int) (current_user()['id'] ?? 0)]);
    $out = [];
    foreach ($st->fetchAll() as $f) {
        $f['items'] = json_decode((string) $f['items'], true) ?: [];
        $out[] = $f;
    }
    return $out;
}

/** Token público de la receta (lo crea la primera vez). '' si la receta no existe. */
function receta_token(int $id, ?int $tenant = null): string
{
    asegurar_recetas();
    $tenant = $tenant ?? tenant_id();
    $st = db()->prepare('SELECT token FROM recetas WHERE id = ? AND consultorio_id = ?');
    $st->execute([$id, $tenant]);
    $tok = $st->fetchColumn();
    if ($tok === false) return '';
    if ($tok) return (string) $tok;
    $tok = bin2hex(random_bytes(16));
    db()->prepare('UPDATE recetas SET token = ? WHERE id = ? AND consultorio_id = ? AND token IS NULL')->execute([$tok, $id, $tenant]);
    $st->execute([$id, $tenant]);
    return (string) $st->fetchColumn();
}

function receta_url_publica(string $token): string
{
    return url_absoluta('/rx?t=' . $token);
}

function receta_folio(int $id): string
{
    return 'R-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}
