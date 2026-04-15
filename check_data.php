<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=pbstudio', 'root', 'exael');
$tables = ['post', 'staff', 'user', 'package', 'session', 'branch_office'];
foreach ($tables as $table) {
    $r = $pdo->query("SELECT COUNT(*) as total FROM $table");
    $count = $r->fetch(PDO::FETCH_ASSOC)['total'];
    echo "$table: $count registros\n";
}
?>
