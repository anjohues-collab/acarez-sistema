<?php
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'acarez_logistica');
if ($conn->connect_error) {
    echo json_encode(['mensaje' => 'Error de conexión: ' . $conn->connect_error]);
    exit;
}

$id = intval($_POST['id'] ?? 0);
$nombre_chofer = trim($_POST['nombre_chofer'] ?? '');
$placas = trim($_POST['placas'] ?? '');
$numero_economico = trim($_POST['numero_economico'] ?? '');

if ($id <= 0 || empty($nombre_chofer) || empty($placas) || empty($numero_economico)) {
    echo json_encode(['mensaje' => '❌ Datos inválidos']);
    exit;
}

$stmt = $conn->prepare("UPDATE choferes SET nombre_chofer = ?, placas = ?, numero_economico = ? WHERE id = ?");
$stmt->bind_param("sssi", $nombre_chofer, $placas, $numero_economico, $id);

if ($stmt->execute()) {
    echo json_encode(['mensaje' => '✅ Chofer actualizado correctamente']);
} else {
    echo json_encode(['mensaje' => '❌ Error al actualizar: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
