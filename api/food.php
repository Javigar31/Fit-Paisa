<?php
/**
 * FitPaisa — Endpoint de Catálogo de Alimentos
 *
 * Búsqueda de información nutricional de alimentos genéricos.
 *
 * @package  FitPaisa\Api
 * @author   Javier Andrés García Vargas
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_jwt.php';

fp_cors();

$payload = jwt_require(); /* 401 si no auténticado */
$action  = fp_sanitize($_GET['action'] ?? 'search', 32);

match ($action) {
    'search'        => handle_search_food(),
    'save_external' => handle_save_external(),
    default         => fp_error(400, "Acción '{$action}' no reconocida."),
};

/**
 * Busca alimentos en el catálogo por nombre (ILIKE).
 */
function handle_search_food(): never
{
    $q = fp_sanitize($_GET['q'] ?? '', 100);
    
    if (mb_strlen($q) < 2) {
        fp_success(['results' => []]);
    }

    // 1. Búsqueda Local
    $localRows = fp_query(
        "SELECT * FROM food_catalog 
         WHERE name ILIKE :q 
         ORDER BY is_verified DESC, (name ILIKE :q_exact) DESC, name ASC 
         LIMIT 15",
        [
            ':q' => '%' . $q . '%',
            ':q_exact' => $q . '%'
        ]
    )->fetchAll();

    $results = [];
    foreach ($localRows as $row) {
        $row['source'] = 'local';
        $results[] = $row;
    }

    // 2. Fallback automático a Open Food Facts si hay pocos resultados locales
    if (count($results) < 5) {
        $externalResults = search_open_food_facts($q);
        
        // Evitar duplicados si el producto ya está en local (por barcode/external_id)
        $localExternalIds = array_filter(array_column($results, 'external_id'));
        
        foreach ($externalResults as $ext) {
            if (!in_array($ext['external_id'], $localExternalIds)) {
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
    $url = "https://world.openfoodfacts.org/cgi/search.pl?search_terms=" . urlencode($query) . "&search_simple=1&action=process&json=1&page_size=15&fields=product_name,nutriments,code,image_small_url";
    
    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => 'User-Agent: FitPaisa - WebApp - Version 1.0',
            'timeout' => 2.5 // Timeout agresivo para no bloquear la UX
        ]
    ];

    $context = stream_context_create($opts);
    $response = @file_get_contents($url, false, $context);

    if (!$response) return [];

    $data = json_decode($response, true);
    if (!isset($data['products'])) return [];

    $mapped = [];
    foreach ($data['products'] as $p) {
        // Solo incluir si tiene al menos calorías
        if (!isset($p['nutriments']['energy-kcal_100g'])) continue;

        $mapped[] = [
            'external_id'   => $p['code'] ?? null,
            'name'           => $p['product_name'] ?? 'Producto Desconocido',
            'calories_100g' => (float)($p['nutriments']['energy-kcal_100g'] ?? 0),
            'protein_100g'  => (float)($p['nutriments']['proteins_100g'] ?? 0),
            'carbs_100g'    => (float)($p['nutriments']['carbohydrates_100g'] ?? 0),
            'fat_100g'      => (float)($p['nutriments']['fat_100g'] ?? 0),
            'image_url'     => $p['image_small_url'] ?? null,
            'is_verified'   => false,
            'unit_name'     => '100g',
            'weight_std'    => 100
        ];
    }

    return $mapped;
}

/**
 * Guarda un alimento externo en la base de datos local para acceso rápido futuro.
 */
function handle_save_external(): never
{
    $data = fp_input(); // Captura el body JSON
    
    if (empty($data['external_id']) || empty($data['name'])) {
        fp_error(400, 'Datos de alimento insuficientes para guardar.');
    }

    try {
        fp_query(
            "INSERT INTO food_catalog 
             (name, calories_100g, protein_100g, carbs_100g, fat_100g, external_id, is_verified, image_url, unit_name, weight_std)
             VALUES (:name, :cal, :pro, :carb, :fat, :ext, false, :img, '100g', 100)
             ON CONFLICT (external_id) DO UPDATE SET name = EXCLUDED.name",
            [
                ':name' => $data['name'],
                ':cal'  => $data['calories_100g'],
                ':pro'  => $data['protein_100g'],
                ':carb' => $data['carbs_100g'],
                ':fat'  => $data['fat_100g'],
                ':ext'  => $data['external_id'],
                ':img'  => $data['image_url'] ?? null
            ]
        );
        
        fp_success(['message' => 'Alimento guardado localmente', 'external_id' => $data['external_id']]);
    } catch (Exception $e) {
        fp_error(500, 'No se pudo persistir el alimento externo: ' . $e->getMessage());
    }
}
