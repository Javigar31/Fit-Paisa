<?php
require_once __DIR__ . '/_db.php';

try {
    $db = fp_connect();

    echo "--- INICIANDO MIGRACIÓN ---\n";

    // 1. ALTER TABLE profiles
    echo "1. Altering profiles table...\n";
    try {
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_weight DECIMAL(5,2)");
        echo "target_weight column added (or already existed).\n";
    } catch (PDOException $e) {
        echo "Info: target_weight column might already exist. Error: " . $e->getMessage() . "\n";
    }

    try {
        $db->exec("ALTER TABLE profiles ADD COLUMN IF NOT EXISTS target_time_weeks SMALLINT");
        echo "target_time_weeks column added (or already existed).\n";
    } catch (PDOException $e) {
        echo "Info: target_time_weeks column might already exist. Error: " . $e->getMessage() . "\n";
    }

    // 2. CREATE TABLE food_catalog
    echo "2. Creating food_catalog table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS food_catalog (
            food_id SERIAL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            calories_100g DECIMAL(6,2) NOT NULL,
            protein_100g DECIMAL(6,2) NOT NULL,
            carbs_100g DECIMAL(6,2) NOT NULL,
            fat_100g DECIMAL(6,2) NOT NULL
        )
    ");
    echo "food_catalog table created.\n";

    // 3. SEED food_catalog
    // Si la tabla ya tiene datos, no los insertamos para no duplicar.
    $stmt = $db->query("SELECT COUNT(*) FROM food_catalog");
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        echo "3. Seeding food_catalog...\n";
        
        $foods = [
            // Carnes y Aves
            ['Pechuga de pollo (cruda)', 120, 22.5, 0, 2.6],
            ['Pechuga de pavo (cruda)', 104, 21.9, 0, 1.2],
            ['Carne de res magra (cruda)', 133, 21.4, 0, 5.3],
            ['Carne de res molida 90/10 (cruda)', 176, 20, 0, 10],
            ['Carne de cerdo, lomo (cruda)', 143, 21.1, 0, 5.5],
            ['Pollo entero con piel (crudo)', 215, 18, 0, 15],
            ['Cordero, chuleta (cruda)', 294, 16.5, 0, 24.8],
            
            // Pescados y Mariscos
            ['Salmón rosado (crudo)', 127, 20.5, 0, 4.4],
            ['Atún en agua (enlatado)', 86, 19.4, 0, 0.9],
            ['Atún en aceite (enlatado)', 198, 29.1, 0, 8.2],
            ['Merluza (cruda)', 72, 16.5, 0, 0.5],
            ['Bacalao (crudo)', 82, 17.8, 0, 0.7],
            ['Tilapia (cruda)', 96, 20, 0, 1.7],
            ['Camarón/Gambas (crudos)', 85, 20.1, 0.2, 0.5],
            
            // Huevos y Lácteos
            ['Huevo entero (crudo)', 143, 12.6, 0.7, 9.5],
            ['Clara de huevo (cruda)', 52, 10.9, 0.7, 0.2],
            ['Leche entera', 61, 3.2, 4.8, 3.3],
            ['Leche desnatada', 34, 3.4, 5, 0.1],
            ['Queso fresco', 299, 13.5, 1.5, 26.5],
            ['Queso mozzarella', 280, 28, 3.1, 17],
            ['Queso cottage', 98, 11.1, 3.4, 4.3],
            ['Yogur natural entero', 61, 3.5, 4.7, 3.3],
            ['Yogur griego natural (Zero Grasa)', 59, 10.3, 3.6, 0.4],
            ['Proteína Whey (suero de leche)', 379, 78, 7.8, 3.5], // Aprox genérico
            ['Mantequilla', 717, 0.9, 0.1, 81.1],

            // Cereales y Tubérculos
            ['Arroz blanco (crudo)', 360, 6.6, 79.3, 0.6],
            ['Arroz integral (crudo)', 370, 7.9, 77.2, 2.9],
            ['Avena en hojuelas', 389, 16.9, 66.3, 6.9],
            ['Pasta de trigo (cruda)', 371, 13, 74.7, 1.5],
            ['Patata/Papa (cruda)', 77, 2, 17.5, 0.1],
            ['Boniato/Camote (crudo)', 86, 1.6, 20.1, 0.1],
            ['Quinoa (cruda)', 368, 14.1, 64.2, 6.1],
            ['Pan blanco', 265, 8.8, 49, 3.2],
            ['Pan integral', 252, 12.4, 42.7, 3.5],
            ['Tortilla de maíz', 218, 5.7, 44.6, 2.8],
            ['Tortilla de trigo', 297, 7.9, 49, 7.2],
            
            // Legumbres
            ['Lentejas (crudas)', 353, 25.8, 60.1, 1.1],
            ['Garbanzos (crudos)', 364, 19.3, 61, 6],
            ['Frijoles/Alubias negras (crudos)', 341, 21.6, 62.4, 1.4],
            ['Soja (cruda)', 446, 36.5, 30.2, 19.9],
            ['Tofu (firme)', 144, 15.8, 2.8, 8.7],
            
            // Verduras y Hortalizas
            ['Brócoli (crudo)', 34, 2.8, 6.6, 0.4],
            ['Espinaca (cruda)', 23, 2.9, 3.6, 0.4],
            ['Zanahoria (cruda)', 41, 0.9, 9.6, 0.2],
            ['Tomate', 18, 0.9, 3.9, 0.2],
            ['Cebolla (cruda)', 40, 1.1, 9.3, 0.1],
            ['Ajo (crudo)', 149, 6.4, 33.1, 0.5],
            ['Aguacate/Palta', 160, 2, 8.5, 14.7],
            ['Champiñones (crudos)', 22, 3.1, 3.3, 0.3],
            ['Calabacín / Zucchini (crudo)', 17, 1.2, 3.1, 0.3],
            ['Pimiento / Pimentón (crudo)', 20, 0.9, 4.6, 0.2],
            
            // Frutas
            ['Plátano/Banana', 89, 1.1, 22.8, 0.3],
            ['Manzana (con piel)', 52, 0.3, 13.8, 0.2],
            ['Naranja', 47, 0.9, 11.8, 0.1],
            ['Fresa/Frutilla', 32, 0.7, 7.7, 0.3],
            ['Arándanos', 57, 0.7, 14.5, 0.3],
            ['Sandía', 30, 0.6, 7.6, 0.2],
            ['Piña', 50, 0.5, 13.1, 0.1],
            ['Uva', 69, 0.7, 18.1, 0.2],
            ['Mango', 60, 0.8, 15, 0.4],
            ['Papaya', 43, 0.5, 10.8, 0.3],
            
            // Grasas, Frutos Secos y Semillas
            ['Aceite de oliva', 884, 0, 0, 100],
            ['Almendras (crudas)', 579, 21.2, 21.6, 49.9],
            ['Nueces (crudas)', 654, 15.2, 13.7, 65.2],
            ['Cacahuetes/Maníes (crudos)', 567, 25.8, 16.1, 49.2],
            ['Mantequilla de maní (sin azúcar añadido)', 588, 25.1, 20, 50.4],
            ['Semillas de chía', 486, 16.5, 42.1, 30.7],
            ['Semillas de lino/linaza', 534, 18.3, 28.9, 42.2],
            ['Aceite de coco', 862, 0, 0, 100]
        ];

        $stmt = $db->prepare("INSERT INTO food_catalog (name, calories_100g, protein_100g, carbs_100g, fat_100g) VALUES (?, ?, ?, ?, ?)");
        foreach ($foods as $food) {
            $stmt->execute($food);
        }
        echo "Inserted " . count($foods) . " foods.\n";
    } else {
        echo "3. food_catalog already has $count items. Skipping seed.\n";
    }

    echo "--- MIGRACIÓN COMPLETADA ---\n";

} catch (Exception $e) {
    echo "ERROR FATAL: " . $e->getMessage() . "\n";
}
