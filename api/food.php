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
    'search' => handle_search_food(),
    default  => fp_error(400, "Acción '{$action}' no reconocida."),
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

    $rows = fp_query(
        "SELECT * FROM food_catalog 
         WHERE name ILIKE :q 
         ORDER BY (name ILIKE :q_exact) DESC, name ASC 
         LIMIT 15",
        [
            ':q' => '%' . $q . '%',
            ':q_exact' => $q . '%'
        ]
    )->fetchAll();

    fp_success(['results' => $rows]);
}
