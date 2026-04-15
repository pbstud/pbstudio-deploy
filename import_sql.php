<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbstudio', 'root', 'exael');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

$sql_file = 'Especificaciones/pbstudio_back.sql';

if (!file_exists($sql_file)) {
    die("SQL file not found: $sql_file\n");
}

echo "Opening SQL file...\n";
$handle = fopen($sql_file, 'r');
if (!$handle) {
    die("Cannot open file\n");
}

$query = '';
$count = 0;
$lines = 0;

while (($line = fgets($handle)) !== false) {
    $lines++;
    
    // Skip comments and empty lines
    $trimmed = trim($line);
    if (empty($trimmed) || $trimmed[0] === '-' || substr($trimmed, 0, 2) === '/*') {
        continue;
    }
    
    $query .= $line;
    
    // Execute when we hit a semicolon
    if (substr(rtrim($line), -1) === ';') {
        $query = trim($query);
        if (!empty($query)) {
            if ($pdo->exec($query) !== false) {
                $count++;
            }
        }
        $query = '';
        
        if ($count % 100 === 0) {
            echo "  Executed $count queries... (line $lines)\n";
        }
    }
}

fclose($handle);

echo "\n✅ Import completed!\n";
echo "Executed: $count SQL statements\n";
echo "Processed: $lines lines\n";

// Verify data
echo "\nVerifying tables:\n";
$tables = ['staff', 'branch_office', 'package', 'session', 'reservation', 'user'];
foreach ($tables as $table) {
    $result = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
    if ($result) {
        $row = $result->fetch(PDO::FETCH_ASSOC);
        echo "  $table: " . (int)$row['cnt'] . " rows\n";
    }
}
?>
