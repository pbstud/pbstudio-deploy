<?php
// Script para verificar reservaciones con attended=true

$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'exael';
$dbName = 'pbstudio';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== VERIFICACIÓN DE ATTENDED ===\n\n";
    
    // 1. Total de reservaciones por attended
    echo "1) Total de reservaciones por attended:\n";
    $stmt = $pdo->query("
        SELECT attended, COUNT(*) as total 
        FROM reservation 
        GROUP BY attended
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attended_label = $row['attended'] ? 'SÍ (attended=1)' : 'NO (attended=0)';
        echo "   - {$attended_label}: {$row['total']} reservaciones\n";
    }
    echo "\n";
    
    // 2. Reservaciones activas con attended=true para sesiones cerradas
    echo "2) Reservaciones con attended=true en sesiones cerradas:\n";
    $stmt = $pdo->query("
        SELECT 
            r.id,
            r.user_id,
            u.name,
            u.lastname,
            r.session_id,
            s.date_start,
            s.time_start,
            r.attended,
            r.is_available
        FROM reservation r
        INNER JOIN session s ON r.session_id = s.id
        INNER JOIN user u ON r.user_id = u.id
        WHERE r.attended = 1
        AND r.is_available = 1
        AND s.status = 0
        ORDER BY s.date_start DESC, s.time_start DESC
        LIMIT 10
    ");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($results) > 0) {
        foreach ($results as $row) {
            echo "   - Reserva #{$row['id']}: User {$row['user_id']} ({$row['name']} {$row['lastname']}), Sesión {$row['session_id']}, Fecha: {$row['date_start']} {$row['time_start']}\n";
        }
    } else {
        echo "   ❌ NO hay reservaciones con attended=1 en sesiones cerradas\n";
    }
    echo "\n";
    
    // 3. Sesiones cerradas para verificar
    echo "3) Total de sesiones cerradas:\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM session WHERE status = 0");
    $total_closed = $stmt->fetchColumn();
    echo "   - Total sesiones cerradas (status=0): {$total_closed}\n\n";
    
    // 4. Reservaciones del usuario 14567 (del ejemplo)
    echo "4) Reservaciones del usuario 14567:\n";
    $stmt = $pdo->query("
        SELECT 
            r.id,
            r.session_id,
            s.date_start,
            s.time_start,
            s.status,
            r.attended,
            r.is_available,
            CASE s.status
                WHEN 0 THEN 'CERRADA'
                WHEN 1 THEN 'ABIERTA'
                WHEN 2 THEN 'LLENA'
                WHEN -1 THEN 'CANCELADA'
                ELSE 'OTRO'
            END as status_label
        FROM reservation r
        INNER JOIN session s ON r.session_id = s.id
        WHERE r.user_id = 14567
        AND r.is_available = 1
        ORDER BY s.date_start DESC, s.time_start DESC
        LIMIT 10
    ");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($results) > 0) {
        echo "   Total: " . count($results) . " reservaciones\n";
        foreach ($results as $row) {
            $attended_label = $row['attended'] ? '✅ Asistió' : '❌ NO asistió';
            echo "   - Reserva #{$row['id']}: Sesión {$row['session_id']} ({$row['status_label']}), {$row['date_start']} {$row['time_start']}, {$attended_label}\n";
        }
    } else {
        echo "   No hay reservaciones para el usuario 14567\n";
    }
    echo "\n";
    
    echo "✅ Verificación completada\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
