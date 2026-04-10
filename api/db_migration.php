<?php
require_once __DIR__ . '/_db.php';

try {
    $db = fp_db();

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
            fat_100g DECIMAL(6,2) NOT NULL,
            unit_name VARCHAR(50),
            weight_std DECIMAL(5,2),
            weight_small DECIMAL(5,2),
            weight_medium DECIMAL(5,2),
            weight_large DECIMAL(5,2),
            is_liquid BOOLEAN DEFAULT FALSE
        )
    ");
    echo "food_catalog table created (or exists).\n";

    // 2.1 Migrate existing food_catalog (Add columns if they don't exist)
    echo "2.1 Migrating food_catalog columns...\n";
    $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS unit_name VARCHAR(50)");
    $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_std DECIMAL(5,2)");
    $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_small DECIMAL(5,2)");
    $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_medium DECIMAL(5,2)");
    $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS weight_large DECIMAL(5,2)");
    $db->exec("ALTER TABLE food_catalog ADD COLUMN IF NOT EXISTS is_liquid BOOLEAN DEFAULT FALSE");

    // 2.2 Migrate food_entries (Add metadata columns)
    echo "2.2 Migrating food_entries columns...\n";
    $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_amount DECIMAL(6,2)");
    $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS portion_unit VARCHAR(50)");
    $db->exec("ALTER TABLE food_entries ADD COLUMN IF NOT EXISTS unit_size VARCHAR(20)");

    // 3. SEED food_catalog
    // Si la tabla ya tiene datos, no los insertamos para no duplicar.
    $stmt = $db->query("SELECT COUNT(*) FROM food_catalog");
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        echo "3. Seeding food_catalog...\n";
                $foods = [
            // Carnes y Aves
            ['Pechuga de pollo (cruda)', 120, 22.5, 0, 2.6, null, null, null, null, null, false],
            ['Pechuga de pavo (cruda)', 104, 21.9, 0, 1.2, null, null, null, null, null, false],
            ['Alitas de pollo (con piel)', 215, 18, 0.5, 15, 'Ala', 35, null, null, null, false],
            
            // Pescados y Mariscos
            ['Atún en agua (enlatado)', 86, 19.4, 0, 0.9, 'Lata', 52, null, null, null, false],
            
            // Huevos y Lácteos
            ['Huevo entero (crudo)', 143, 12.6, 0.7, 9.5, 'Huevo', 50, 40, 50, 60, false],
            ['Yema de huevo (cruda)', 322, 15.9, 3.6, 26.5, 'Yema', 17, null, null, null, false],
            ['Clara de huevo (cruda)', 52, 10.9, 0.7, 0.2, null, null, null, null, null, false],
            ['Leche entera', 61, 3.2, 4.8, 3.3, 'Vaso', 250, null, null, null, true],
            ['Leche desnatada', 34, 3.4, 5, 0.1, 'Vaso', 250, null, null, null, true],
            
            // Cereales y Tubérculos
            ['Pan blanco', 265, 8.8, 49, 3.2, 'Rebanada', 30, null, null, null, false],
            ['Pan integral', 252, 12.4, 42.7, 3.5, 'Rebanada', 35, null, null, null, false],
            ['Patata/Papa (cruda)', 77, 2, 17.5, 0.1, 'Unidad', 150, 100, 150, 200, false],
            
            // Grasas y Líquidos
            ['Aceite de oliva', 884, 0, 0, 100, 'Cucharada', 15, null, null, null, true],
            ['Aceite de coco', 862, 0, 0, 100, 'Cucharada', 15, null, null, null, true]
        ];

        $stmt = $db->prepare("INSERT INTO food_catalog (name, calories_100g, protein_100g, carbs_100g, fat_100g, unit_name, weight_std, weight_small, weight_medium, weight_large, is_liquid) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
ES (?, ?, ?, ?, ?)");
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
