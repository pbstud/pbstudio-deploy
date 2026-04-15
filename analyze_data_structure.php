<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

// Cargar variables de entorno
$dotenv = new Dotenv();
$dotenv->loadEnv('.env');

// Acceso directo mediante PDO para consulta rápida
$dbUrl = $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');

if (!$dbUrl) {
    die("DATABASE_URL no configurada\n");
}

// Parser simple del dsn
preg_match('/^(\w+):\/\/([^:]+):([^@]+)@([^:\/]+)(?::(\d+))?\/(.+)$/', $dbUrl, $m);
if (!$m) {
    die("No se pudo parsear DATABASE_URL\n");
}

$driver = $m[1];
$user = $m[2];
$pass = $m[3];
$host = $m[4];
$port = $m[5] ?? '3306';
$db = $m[6];

try {
    $pdo = new PDO(
        "$driver:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Exception $e) {
    die("Error conectando a BD: " . $e->getMessage() . "\n");
}

echo "=== ANÁLISIS DE ESTRUCTURA DE DATOS DE USUARIOS ===\n";
echo date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 80) . "\n\n";

// Obtener todos los usuarios
$stmt = $pdo->query("SELECT id, name, lastname FROM user ORDER BY id DESC");
$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_users = count($all_users);

echo "Total de usuarios en BD: " . number_format($total_users) . "\n\n";

// Categorías de análisis
$stats = [
    'con_lastname_normal' => 0,           // Tiene lastname no vacío
    'sin_lastname_name_simple' => 0,      // Sin lastname, name con 1 palabra
    'sin_lastname_name_completo' => 0,    // Sin lastname, name con 2+ palabras (CRÍTICO)
    'lastname_vacio_count' => 0,          // Total sin lastname
];

$examples_name_completo = [];
$word_distribution = [];
$last_words_found = [];

foreach ($all_users as $user) {
    $name = trim($user['name'] ?? '');
    $lastname = trim($user['lastname'] ?? '');
    
    $has_lastname = !empty($lastname);
    $name_words = array_filter(explode(' ', $name), 'strlen');
    $word_count = count($name_words);
    
    if ($has_lastname) {
        $stats['con_lastname_normal']++;
    } else {
        $stats['lastname_vacio_count']++;
        
        if ($word_count === 1) {
            $stats['sin_lastname_name_simple']++;
        } elseif ($word_count >= 2) {
            $stats['sin_lastname_name_completo']++;
            
            // Recolectar ejemplos
            if (count($examples_name_completo) < 20) {
                $examples_name_completo[] = [
                    'id' => $user['id'],
                    'name' => $name,
                    'words' => $word_count,
                    'last_word' => end($name_words),
                ];
            }
            
            // Distribución de palabras
            $word_distribution[$word_count] = ($word_distribution[$word_count] ?? 0) + 1;
            
            // Palabras finales (posibles apellidos)
            $last_word = end($name_words);
            $last_words_found[$last_word] = ($last_words_found[$last_word] ?? 0) + 1;
        }
    }
}

// Mostrar resultados
echo str_repeat('-', 80) . "\n";
echo "DISTRIBUCIÓN GENERAL:\n";
echo str_repeat('-', 80) . "\n";
echo sprintf(
    "✅ Con lastname (datos correctos):           %6d (%5.2f%%)\n",
    $stats['con_lastname_normal'],
    ($stats['con_lastname_normal'] / max($total_users, 1)) * 100
);
echo sprintf(
    "⚠️  Sin lastname, name simple (1 palabra):  %6d (%5.2f%%)\n",
    $stats['sin_lastname_name_simple'],
    ($stats['sin_lastname_name_simple'] / max($total_users, 1)) * 100
);
echo sprintf(
    "❌ Sin lastname, name completo (2+ palabras): %6d (%5.2f%%) [PROBLEMÁTICO]\n",
    $stats['sin_lastname_name_completo'],
    ($stats['sin_lastname_name_completo'] / max($total_users, 1)) * 100
);
echo sprintf(
    "Total sin lastname:                        %6d (%5.2f%%)\n",
    $stats['lastname_vacio_count'],
    ($stats['lastname_vacio_count'] / max($total_users, 1)) * 100
);

echo "\n" . str_repeat('-', 80) . "\n";
echo "DISTRIBUCIÓN DE PALABRAS EN USUARIOS SIN LASTNAME:\n";
echo str_repeat('-', 80) . "\n";

ksort($word_distribution);
foreach ($word_distribution as $word_count => $count) {
    echo sprintf(
        "%d palabras: %6d casos (%5.2f%%)\n",
        $word_count,
        $count,
        ($count / max($stats['lastname_vacio_count'], 1)) * 100
    );
}

echo "\n" . str_repeat('-', 80) . "\n";
echo "TOP 15 PALABRAS FINALES (POSIBLES APELLIDOS) EN NOMBRES COMPLETOS:\n";
echo str_repeat('-', 80) . "\n";

arsort($last_words_found);
$top_15 = array_slice($last_words_found, 0, 15, true);
foreach ($top_15 as $word => $count) {
    echo sprintf(
        "'%-30s': %6d casos (%5.2f%%)\n",
        $word,
        $count,
        ($count / max($stats['sin_lastname_name_completo'], 1)) * 100
    );
}

echo "\n" . str_repeat('-', 80) . "\n";
echo "EJEMPLOS DE USUARIOS CON NOMBRE COMPLETO EN 'NAME' (sin lastname):\n";
echo str_repeat('-', 80) . "\n";

foreach ($examples_name_completo as $idx => $example) {
    echo sprintf(
        "%2d. ID: %6d | %s (%d palabras, última: '%s')\n",
        $idx + 1,
        $example['id'],
        $example['name'],
        $example['words'],
        $example['last_word']
    );
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "CONCLUSIÓN:\n";
echo str_repeat('=', 80) . "\n";

$percentage_problematic = ($stats['sin_lastname_name_completo'] / max($total_users, 1)) * 100;

if ($percentage_problematic > 0) {
    echo "⚠️  DATO CRÍTICO ENCONTRADO:\n";
    echo sprintf(
        "   %d usuarios (%.2f%%) tienen el nombre COMPLETO en el campo 'name',\n",
        $stats['sin_lastname_name_completo'],
        $percentage_problematic
    );
    echo "   sin un campo 'lastname' separado.\n";
    echo "   \n";
    echo "   IMPACTO EN BÚSQUEDAS:\n";
    echo "   - Las búsquedas por 'lastname' fallarán para estos usuarios\n";
    echo "   - Es necesario buscar la última palabra del 'name' como apellido\n";
    echo "   - O mejorar el algoritmo de búsqueda para soportar nombres completos\n";
} else {
    echo "✅ DATO POSITIVO:\n";
    echo "   Todos los usuarios tienen una separación correcta entre nombre y apellido.\n";
}

echo "\n";

