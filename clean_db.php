<?php
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'exael';
$dbName = 'pbstudio';

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName";
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Obtener todas las tablas
    $result = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = "' . $dbName . '"');
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Total de tablas encontradas: " . count($tables) . PHP_EOL;
    echo "Borrando datos de todas las tablas..." . PHP_EOL;
    
    // Deshabilitar verificación de FK temporalmente
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    
    foreach ($tables as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
        echo "✓ Limpiado: $table" . PHP_EOL;
    }
    
    // Reabilitar verificación de FK
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    
    echo PHP_EOL . "✅ BD completamente limpia" . PHP_EOL;
    $pdo = null;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
