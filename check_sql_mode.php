<?php

$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=pbstudio';
$user = 'root';
$pass = 'exael';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $result = $pdo->query('SELECT @@sql_mode as sql_mode')->fetch(PDO::FETCH_ASSOC);
    
    echo "=== MySQL SQL Mode Status ===\n";
    echo "Current sql_mode: " . $result['sql_mode'] . "\n\n";
    
    if (strpos($result['sql_mode'], 'ONLY_FULL_GROUP_BY') !== false) {
        echo "❌ ONLY_FULL_GROUP_BY is ENABLED (Strict Mode)\n";
        echo "This mode will cause the ReservationRepository GROUP BY queries to FAIL.\n";
    } else {
        echo "✅ ONLY_FULL_GROUP_BY is DISABLED (Permissive Mode)\n";
        echo "This is why the Reportes/Estadísticas errors are hidden in local environment.\n";
    }
    
} catch (Exception $e) {
    echo "Error connecting to database: " . $e->getMessage() . "\n";
}
