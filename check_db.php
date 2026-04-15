<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbstudio', 'root', 'exael');
$result = $pdo->query('SHOW TABLES');
$rows = $result->fetchAll(PDO::FETCH_COLUMN);
echo "Tables in pbstudio:\n";
var_dump($rows);
?>
