<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'conexion.php';

$placas = [];
$no_economico = [];

$result = $conn->query("SELECT placa FROM catalogo_placas WHERE activo = 1 ORDER BY placa");
while($row = $result->fetch_assoc()) {
    $placas[] = $row['placa'];
}

$result = $conn->query("SELECT no_economico FROM catalogo_no_economico WHERE activo = 1 ORDER BY no_economico");
while($row = $result->fetch_assoc()) {
    $no_economico[] = $row['no_economico'];
}

echo json_encode([
    'placas' => $placas,
    'no_economico' => $no_economico
]);

$conn->close();
?>
