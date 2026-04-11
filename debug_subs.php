<?php
require_once __DIR__ . '/api/_db.php';
try {
    $db = fp_db();
    echo "DB Connected.\n";
    $q = "SELECT
            COUNT(*) FILTER (WHERE status = 'ACTIVE')  AS active,
            COUNT(*) FILTER (WHERE status = 'PENDING') AS pending,
            COUNT(*) FILTER (WHERE status = 'CANCELLED' 
                AND updated_at >= date_trunc('month', NOW())) AS cancelled_month,
            COALESCE(SUM(CASE 
                WHEN plan_type = 'PREMIUM_MONTHLY' AND status = 'ACTIVE' THEN 9.99
                WHEN plan_type = 'PREMIUM_ANNUAL'  AND status = 'ACTIVE' THEN 99.99 / 12
                ELSE 0 
            END), 0) AS mrr_estimate
        FROM subscriptions";
    $res = $db->query($q);
    var_dump($res->fetch(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
