<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'conexion.php';

// Obtener todos los choferes (activos = 1)
$sql = "SELECT id, nombre_chofer, placas, numero_economico FROM choferes WHERE activo = 1 ORDER BY nombre_chofer ASC";
$result = $conn->query($sql);

$choferes = [];
while ($row = $result->fetch_assoc()) {
    $choferes[] = $row;
}

echo json_encode($choferes);
$conn->close();
?>
