<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../conexion.php';

$sql = "SELECT id, razon_social, sucursal, direccion FROM destinos WHERE activo = 1 ORDER BY razon_social ASC";
$result = $conn->query($sql);

$destinos = [];
while ($row = $result->fetch_assoc()) {
    $destinos[] = $row;
}

echo json_encode(['success' => true, 'destinos' => $destinos]);
$conn->close();
?>
