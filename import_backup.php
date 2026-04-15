<?php
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'exael';
$dbName = 'pbstudio';
$sql_file = 'Especificaciones/pbstudio_15ene2026_back.sql';

if (!file_exists($sql_file)) {
    die("Archivo SQL no encontrado: $sql_file\n");
}

echo "Conectando a BD: $dbName...\n";

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✓ Conectado\n\n";
    echo "Leyendo SQL desde: $sql_file\n";
    echo "Tamaño: " . round(filesize($sql_file) / (1024 * 1024), 2) . " MB\n\n";
    
    // Deshabilitar FK checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    // No usar transacciones para la importación
    $pdo->exec('SET AUTOCOMMIT=1');
    
    $fp = fopen($sql_file, 'r');
    if (!$fp) {
        die("Error abriendo archivo SQL\n");
    }
    
    $query = '';
    $line_count = 0;
    $statement_count = 0;
    $batch_size = 50;
    
    echo "Procesando SQL...\n";
    
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        $line_count++;
        
        if (empty($line) || substr($line, 0, 2) === '--') {
            continue;
        }
        
        // Ignorar comandos que pueden causar problemas con transacciones
        $query_upper = strtoupper($line);
        if (strpos($query_upper, 'UNLOCK TABLES') !== false || 
            strpos($query_upper, 'LOCK TABLES') !== false ||
            strpos($query_upper, 'FLUSH') !== false) {
            continue;
        }
        
        $query .= $line . "\n";
        
        // Si la línea termina en ;, es una declaración completa
        if (substr(rtrim($line), -1) === ';') {
            try {
                $pdo->exec($query);
                $statement_count++;
                
                if ($statement_count % $batch_size === 0) {
                    echo "✓ $statement_count sentencias importadas...\n";
                }
                
                $query = '';
            } catch (Exception $e) {
                echo "\n⚠ Advertencia en sentencia $statement_count: " . $e->getMessage() . "\n";
                // No salir si hay error, continuar con la siguiente
                $query = '';
            }
        }
    }
    
    // Importar última sentencia si queda algo
    if (trim($query)) {
        try {
            $pdo->exec($query);
            $statement_count++;
        } catch (Exception $e) {
            echo "Error en última sentencia: " . $e->getMessage() . "\n";
        }
    }
    
    fclose($fp);
    
    // Reabilitar FK checks
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    
    echo "\n✅ Importación completada\n";
    echo "Total de sentencias importadas: $statement_count\n";
    echo "Total de líneas procesadas: $line_count\n";
    
    $pdo = null;
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
