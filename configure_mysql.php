<?php
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'exael';

try {
    // Conectar sin especificar BD (para ejecutar comandos globales)
    $dsn = "mysql:host=$dbHost";
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Aplicando configuraciones globales de MySQL...\n";
    echo str_repeat("-", 60) . "\n\n";
    
    // 1. Verificar sql_mode actual
    echo "1️⃣ Verificando sql_mode actual...\n";
    $current_sql_mode = $pdo->query("SELECT @@sql_mode")->fetchColumn();
    echo "   Antes: $current_sql_mode\n\n";
    
    // 2. Remover ONLY_FULL_GROUP_BY del sql_mode
    echo "2️⃣ Removiendo ONLY_FULL_GROUP_BY del sql_mode...\n";
    $pdo->exec("SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
    
    $new_sql_mode = $pdo->query("SELECT @@GLOBAL.sql_mode")->fetchColumn();
    echo "   ✓ Después: $new_sql_mode\n\n";
    
    // 3. Verificar/Establecer collation por defecto
    echo "3️⃣ Verificando configuración de carácter del servidor...\n";
    $charset = $pdo->query("SELECT @@character_set_server")->fetchColumn();
    $collation = $pdo->query("SELECT @@collation_server")->fetchColumn();
    echo "   character_set_server: $charset\n";
    echo "   collation_server: $collation\n\n";
    
    // 4. Intentar establecer collation por defecto (si es posible sin reiniciar)
    echo "4️⃣ Estableciendo defaults para nuevas conexiones...\n";
    $pdo->exec("SET GLOBAL character_set_server = 'utf8mb3'");
    $pdo->exec("SET GLOBAL collation_server = 'utf8mb3_general_ci'");
    
    $new_charset = $pdo->query("SELECT @@GLOBAL.character_set_server")->fetchColumn();
    $new_collation = $pdo->query("SELECT @@GLOBAL.collation_server")->fetchColumn();
    echo "   ✓ character_set_server: $new_charset\n";
    echo "   ✓ collation_server: $new_collation\n\n";
    
    echo str_repeat("-", 60) . "\n";
    echo "✅ Configuraciones aplicadas exitosamente\n\n";
    
    echo "📝 NOTA: Para hacer estos cambios permanentes, debes editar my.ini:\n";
    echo "   1. Localiza el archivo my.ini (C:\\ProgramData\\MySQL\\MySQL Server 5.7\\)\n";
    echo "   2. Agrega bajo [mysqld]:\n";
    echo "      sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\n";
    echo "      character-set-server=utf8mb3\n";
    echo "      collation-server=utf8mb3_general_ci\n";
    echo "   3. Reinicia el servicio MySQL\n";
    
    $pdo = null;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
