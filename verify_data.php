<?php
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'exael';
$dbName = 'pbstudio';

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✓ Conectado a BD: $dbName\n\n";
    echo "VERIFICACIÓN DE DATOS CARGADOS:\n";
    echo str_repeat("-", 60) . "\n";
    
    // Obtener todas las tablas con su cantidad de registros
    $result = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = "' . $dbName . '" ORDER BY TABLE_NAME');
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    $total_rows = 0;
    
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        $total_rows += $count;
        
        $status = ($count > 0) ? '✓' : '○';
        printf("%-35s %s %10d registros\n", $table, $status, $count);
    }
    
    echo str_repeat("-", 60) . "\n";
    printf("%-35s   %10d TOTAL\n", "TOTAL REGISTROS", $total_rows);
    echo "\n✅ Verificación completada\n";
    
    $pdo = null;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
