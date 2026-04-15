<?php
$gz_file = 'Especificaciones/pbstudio_15ene2026_back.sql.gz';
$sql_file = 'Especificaciones/pbstudio_15ene2026_back.sql';

if (!file_exists($gz_file)) {
    die("Archivo no encontrado: $gz_file\n");
}

echo "Extrayendo: $gz_file\n";
echo "Destino: $sql_file\n";

$fp_in = gzopen($gz_file, 'rb');
if (!$fp_in) {
    die("Error: No se puede leer el archivo comprimido\n");
}

$fp_out = fopen($sql_file, 'wb');
if (!$fp_out) {
    die("Error: No se puede escribir el archivo SQL\n");
}

$size = 0;
while (!gzeof($fp_in)) {
    $data = gzread($fp_in, 1024 * 1024); // 1 MB a la vez
    fwrite($fp_out, $data);
    $size += strlen($data);
    echo ".";
}

gzclose($fp_in);
fclose($fp_out);

$file_size = filesize($sql_file) / (1024 * 1024);
echo "\n✅ Extracción completada\n";
echo "Archivo SQL: " . round($file_size, 2) . " MB\n";
