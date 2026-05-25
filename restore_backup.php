<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only');

$sqlFile = $argv[1] ?? null;
if (!$sqlFile || !file_exists($sqlFile)) {
    fwrite(STDERR, "Uso: php restore_backup.php <ruta_backup.sql>\n");
    exit(1);
}

// Cargar .env
$env = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (!$line || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, '"\'');
    }
}

$dsn = $env['DATABASE_URL'] ?? 'mysql://root:exael@127.0.0.1:3306/pbstudio';
if (!preg_match('#mysql://([^:]+):([^@]*)@([^:/]+):(\d+)/([^?]+)#', $dsn, $m)) {
    fwrite(STDERR, "DATABASE_URL no válido\n");
    exit(1);
}
[, $user, $pass, $host, $port, $dbname] = $m;

echo "Conectando a {$host}:{$port}/{$dbname}...\n";

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => 
                "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, 
                     character_set_connection=utf8mb4, 
                     character_set_database=utf8mb4, 
                     character_set_client=utf8mb4, 
                     character_set_results=utf8mb4",
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "PDO Error: " . $e->getMessage() . "\n");
    exit(1);
}

// Verificar charset
$stmt = $pdo->query("SELECT @@character_set_client, @@character_set_connection, @@character_set_database");
$row = $stmt->fetch(PDO::FETCH_NUM);
echo "Charset DB: {$row[0]}, {$row[1]}, {$row[2]}\n";

$sizeMB = round(filesize($sqlFile) / 1024 / 1024, 1);
echo "Leyendo {$sizeMB} MB en binario...\n";

// Leer archivo en BINARIO PURO
$content = file_get_contents($sqlFile);
if ($content === false) {
    fwrite(STDERR, "No se pudo leer archivo\n");
    exit(1);
}

echo "Parseando SQL...\n";

// Parser simple pero robusto
$statements = [];
$current = '';
$inString = false;
$strChar = '';
$len = strlen($content);

for ($i = 0; $i < $len; $i++) {
    $c = $content[$i];
    
    if ($inString) {
        $current .= $c;
        if ($c === '\\' && $i + 1 < $len) {
            $i++;
            $current .= $content[$i];
        } elseif ($c === $strChar) {
            $inString = false;
        }
    } else {
        if ($c === "'" || $c === '"' || $c === '`') {
            $inString = true;
            $strChar = $c;
            $current .= $c;
        } elseif ($c === ';') {
            $stmt = trim($current);
            if ($stmt && !str_starts_with($stmt, '--') && !str_starts_with($stmt, '/*')) {
                $statements[] = $stmt;
            }
            $current = '';
        } else {
            $current .= $c;
        }
    }
}
if (trim($current)) {
    $statements[] = trim($current);
}

$total = count($statements);
echo "Total statements: {$total}\n";

// Deshabilitar checks
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
$pdo->exec("SET UNIQUE_CHECKS=0");
$pdo->exec("SET AUTOCOMMIT=0");

echo "Importando...\n";
$start = microtime(true);
$done = 0;
$errors = 0;

foreach ($statements as $idx => $stmt) {
    try {
        $pdo->exec($stmt);
        $done++;
        
        if ($done % 500 === 0 || ($idx + 1) === $total) {
            $pct = round(($done / $total) * 100);
            echo "  [{$pct}%] {$done}/{$total} statements\n";
        }
    } catch (PDOException $e) {
        // Ignorar "already exists" errors (1050, 1051)
        if (!preg_match('/1050|1051|already exists/', $e->getMessage())) {
            echo "  WARN #{$errors}: " . substr($e->getMessage(), 0, 100) . "\n";
            $errors++;
        }
    }
}

// Re-habilitar checks
$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
$pdo->exec("SET UNIQUE_CHECKS=1");
$pdo->exec("COMMIT");

$elapsed = round(microtime(true) - $start, 1);
echo "\n✅ Completado en {$elapsed}s\n";
echo "   Sentencias: {$done}/{$total}\n";
echo "   Warnings: {$errors}\n";

// Verificar integridad
$tables = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='{$dbname}' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
echo "\nTablas presentes: " . count($tables) . "\n";

$counts = $pdo->query("
    SELECT 
        'users' as tbl, COUNT(*) as cnt FROM user
    UNION ALL
    SELECT 'sessions', COUNT(*) FROM session
    UNION ALL
    SELECT 'reservations', COUNT(*) FROM reservation
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($counts as $row) {
    echo "  {$row['tbl']}: {$row['cnt']}\n";
}
