<?php
// Script para verificar sesiones que deberían estar cerradas

$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'exael';
$dbName = 'pbstudio';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== SESIONES QUE DEBERÍAN ESTAR CERRADAS ===\n\n";
    
    $now = date('Y-m-d H:i:s');
    echo "Hora actual: {$now}\n\n";
    
    // Sesiones abiertas que ya pasaron
    $stmt = $pdo->query("
        SELECT 
            id,
            date_start,
            time_start,
            status,
            CASE status
                WHEN 0 THEN 'CERRADA'
                WHEN 1 THEN 'ABIERTA'
                WHEN 2 THEN 'LLENA'
                WHEN -1 THEN 'CANCELADA'
                ELSE 'OTRO'
            END as status_label,
            CONCAT(date_start, ' ', time_start) as session_datetime
        FROM session
        WHERE status IN (1, 2)
        AND CONCAT(date_start, ' ', time_start) < NOW()
        ORDER BY date_start DESC, time_start DESC
        LIMIT 20
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($results) > 0) {
        echo "📌 Sesiones que YA pasaron pero AÚN están abiertas/llenas:\n";
        echo "   Total encontradas: " . count($results) . "\n\n";
        foreach ($results as $row) {
            echo "   - Sesión #{$row['id']}: {$row['session_datetime']} - Status: {$row['status_label']}\n";
        }
        echo "\n";
        echo "⚠️  Estas sesiones deberían cerrarse con:\n";
        echo "   php bin/console app:session:autoclosing\n";
    } else {
        echo "✅ No hay sesiones abiertas que deberían estar cerradas\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
