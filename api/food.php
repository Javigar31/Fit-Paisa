<?php
/**
 * FitPaisa — Endpoint de Catálogo de Alimentos
 *
 * Búsqueda de información nutricional de alimentos genéricos.
 * Defensivo: funciona con o sin la extensión pg_trgm y con o sin food_search_cache.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$payload = jwt_require(); /* 401 si no autenticado */
$action  = fp_sanitize($_GET['action'] ?? 'search', 32);

match ($action) {
    'search'        => handle_search_food(),
    'save_external' => handle_save_external(),
    default         => fp_error(400, "Acción '{$action}' no reconocida."),
};

/**
 * Busca alimentos en el catálogo por nombre.
 *
 * Niveles de búsqueda:
 *   1. pg_trgm fuzzy search (similarity + %)   ← requiere extensión pg_trgm
 *   2. ILIKE simple (fallback si pg_trgm falla) ← siempre disponible
 *   3. Caché de búsqueda (food_search_cache)   ← defensivo, puede no existir
 *   4. Open Food Facts API                     ← solo si < 5 resultados locales
 */
function handle_search_food(): never
{
    $q = fp_sanitize($_GET['q'] ?? '', 100);

    if (mb_strlen($q) < 2) {
        fp_success(['results' => []]);
    }

    $db = fp_db();

    // ── 1. Búsqueda Local — Defensiva en 2 capas ──────────────────────────
    // Capa A: trigramas (fuzzy + ranking). Falla → Capa B: ILIKE puro.
    $localRows = [];
    try {
        $stmt = $db->prepare(
            "SELECT *, similarity(name, :q_orig) AS score FROM food_catalog
             WHERE name ILIKE :q
             OR name % :q_orig
             ORDER BY (name ILIKE :q_exact) DESC, score DESC, is_verified DESC
             LIMIT 15"
        );
        $stmt->execute([
            ':q'      => '%' . $q . '%',
            ':q_exact' => $q . '%',
            ':q_orig'  => $q,
        ]);
        $localRows = $stmt->fetchAll();
    } catch (\PDOException $e) {
        // pg_trgm no instalada — fallback a ILIKE simple
        error_log('[FitPaisa][FOOD] pg_trgm no disponible, usando ILIKE: ' . $e->getMessage());
        try {
            $stmt = $db->prepare(
                "SELECT * FROM food_catalog
                 WHERE name ILIKE :q
                 ORDER BY is_verified DESC, name ASC
                 LIMIT 15"
            );
            $stmt->execute([':q' => '%' . $q . '%']);
            $localRows = $stmt->fetchAll();
        } catch (\PDOException $e2) {
            error_log('[FitPaisa][FOOD] Búsqueda local fallida: ' . $e2->getMessage());
            $localRows = [];
        }
    }

    $results = [];
    foreach ($localRows as $row) {
        $row['source'] = 'local';
        $results[] = $row;
    }

    // ── 2. Fallback externo si hay pocos resultados locales ───────────────
    $qNorm = mb_strtolower(trim($q));
    $externalResults = [];

    if (count($results) < 5) {

        // 2a. Leer caché de búsqueda (defensivo — tabla puede no existir)
        $cached = false;
        try {
            $stmt = $db->prepare(
                "SELECT results_json FROM food_search_cache
                 WHERE query_text = :q
                   AND created_at > NOW() - INTERVAL '7 days'"
            );
            $stmt->execute([':q' => $qNorm]);
            $cached = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('[FitPaisa][FOOD] Cache read failed: ' . $e->getMessage());
        }

        if ($cached) {
            $externalResults = json_decode($cached, true) ?: [];
        } else {
            // 2b. Consultar Open Food Facts
            $externalResults = search_open_food_facts($q);

            if (!empty($externalResults)) {
                // Guardar en caché (defensivo)
                try {
                    $stmt = $db->prepare(
                        "INSERT INTO food_search_cache (query_text, results_json)
                         VALUES (:q, :json)
                         ON CONFLICT (query_text) DO UPDATE
                         SET results_json = EXCLUDED.results_json, created_at = NOW()"
                    );
                    $stmt->execute([':q' => $qNorm, ':json' => json_encode($externalResults)]);
                } catch (\PDOException $e) {
                    error_log('[FitPaisa][FOOD] Cache write failed: ' . $e->getMessage());
                }

                // AUTO-INGESTA: persistir cada producto en food_catalog (defensivo)
                foreach ($externalResults as $ext) {
                    try {
                        $stmt = $db->prepare(
                            "INSERT INTO food_catalog
                             (name, calories_100g, protein_100g, carbs_100g, fat_100g,
                              external_id, is_verified, image_url, unit_name, weight_std)
                             VALUES (:name, :cal, :pro, :carb, :fat, :ext, false, :img, '100g', 100)
                             ON CONFLICT (external_id) DO NOTHING"
                        );
                        $stmt->execute([
                            ':name' => $ext['name'],
                            ':cal'  => $ext['calories_100g'],
                            ':pro'  => $ext['protein_100g'],
                            ':carb' => $ext['carbs_100g'],
                            ':fat'  => $ext['fat_100g'],
                            ':ext'  => $ext['external_id'],
                            ':img'  => $ext['image_url'] ?? null,
                        ]);
                    } catch (\PDOException $e) { /* Silencioso */ }
                }
            }
        }

        // Combinar sin duplicar (por external_id)
        $localExternalIds = array_filter(array_column($results, 'external_id'));
        foreach ($externalResults as $ext) {
            if (!in_array($ext['external_id'], $localExternalIds, true)) {
                $ext['source'] = 'external';
                $results[] = $ext;
            }
        }
    }

    fp_success(['results' => array_slice($results, 0, 20)]);
}

/**
 * Consulta la API de Open Food Facts (v2) para buscar alimentos en español.
 */
function search_open_food_facts(string $query): array
{
    // lc=es prioriza resultados en español
    $url = 'https://world.openfoodfacts.org/cgi/search.pl?search_terms='
         . urlencode($query)
         . '&search_simple=1&action=process&json=1&page_size=15&lc=es'
         . '&fields=product_name,nutriments,code,image_small_url';

    $opts = [
        'http' => [
            'method'  => 'GET',
            'header'  => 'User-Agent: FitPaisa - WebApp - Version 1.0',
            'timeout' => 2.5,
        ],
    ];

    $context  = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if (!$response) return [];

    $data = json_decode($response, true);
    if (!isset($data['products'])) return [];

    $mapped = [];
    foreach ($data['products'] as $p) {
        if (!isset($p['nutriments']['energy-kcal_100g'])) continue;

        $mapped[] = [
            'external_id'   => $p['code'] ?? null,
            'name'          => $p['product_name'] ?? 'Producto Desconocido',
            'calories_100g' => (float)($p['nutriments']['energy-kcal_100g'] ?? 0),
            'protein_100g'  => (float)($p['nutriments']['proteins_100g'] ?? 0),
            'carbs_100g'    => (float)($p['nutriments']['carbohydrates_100g'] ?? 0),
            'fat_100g'      => (float)($p['nutriments']['fat_100g'] ?? 0),
            'image_url'     => $p['image_small_url'] ?? null,
            'is_verified'   => false,
            'unit_name'     => '100g',
            'weight_std'    => 100,
        ];
    }

    return $mapped;
}

/**
 * Guarda un alimento externo en la base de datos local para acceso rápido futuro.
 */
function handle_save_external(): never
{
    $data = fp_json_body(); // Captura el body JSON

    if (empty($data['external_id']) || empty($data['name'])) {
        fp_error(400, 'Datos de alimento insuficientes para guardar.');
    }

    try {
        $db = fp_db();
        $stmt = $db->prepare(
            "INSERT INTO food_catalog
             (name, calories_100g, protein_100g, carbs_100g, fat_100g,
              external_id, is_verified, image_url, unit_name, weight_std)
             VALUES (:name, :cal, :pro, :carb, :fat, :ext, false, :img, '100g', 100)
             ON CONFLICT (external_id) DO UPDATE SET name = EXCLUDED.name"
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':cal'  => $data['calories_100g'],
            ':pro'  => $data['protein_100g'],
            ':carb' => $data['carbs_100g'],
            ':fat'  => $data['fat_100g'],
            ':ext'  => $data['external_id'],
            ':img'  => $data['image_url'] ?? null,
        ]);

        fp_success(['message' => 'Alimento guardado localmente', 'external_id' => $data['external_id']]);
    } catch (\PDOException $e) {
        fp_error(500, 'No se pudo persistir el alimento externo: ' . $e->getMessage());
    }
}
