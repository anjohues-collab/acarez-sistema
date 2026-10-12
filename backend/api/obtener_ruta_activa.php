<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../conexion.php';

$chofer = trim($_GET['chofer'] ?? '');

if (empty($chofer)) {
    echo json_encode(['success' => false, 'mensaje' => '❌ Chofer no especificado']);
    exit;
}

$sql = "SELECT * FROM rutas WHERE chofer = ? AND estatus = 'activa' ORDER BY fecha_inicio DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $chofer);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $ruta = $result->fetch_assoc();
    echo json_encode(['success' => true, 'ruta' => $ruta]);
} else {
    echo json_encode(['success' => false, 'mensaje' => 'No hay ruta activa']);
}

$stmt->close();
$conn->close();
?>
