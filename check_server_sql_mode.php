<?php

$dsn = 'mysql:host=172.20.3.52;port=3306;dbname=dbpbstud';
$user = 'pbsuser';
$pass = 'Pb5tud10';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $result = $pdo->query('SELECT @@sql_mode as sql_mode')->fetch(PDO::FETCH_ASSOC);
    
    echo "=== SERVIDOR MySQL SQL Mode Status ===\n";
    echo "Server: 172.20.3.52\n";
    echo "Database: dbpbstud\n\n";
    echo "Current sql_mode: " . $result['sql_mode'] . "\n\n";
    
    if (strpos($result['sql_mode'], 'ONLY_FULL_GROUP_BY') !== false) {
        echo "❌ ONLY_FULL_GROUP_BY is ENABLED (Strict Mode)\n";
        echo "⚠️  ERROR: The ReservationRepository GROUP BY queries WILL FAIL in production!\n";
        echo "\nProblematic methods:\n";
        echo "  - getGroupedInstructorStudio()\n";
        echo "  - getGroupedByPackage()\n";
        echo "  - getGroupedByExerciseRoom()\n";
        echo "  - getGroupedByDay()\n";
        echo "  - getGroupedBySchedule()\n";
        echo "  - getStudiosGroupedByCustomer()\n";
        echo "  - getGroupedByCustomer()\n";
    } else {
        echo "✅ ONLY_FULL_GROUP_BY is DISABLED (Permissive Mode)\n";
        echo "Server configuration matches specification requirements.\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error connecting to server: " . $e->getMessage() . "\n";
}
