<?php
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'acarez_logistica');
if ($conn->connect_error) {
    echo json_encode(['mensaje' => 'Error de conexión: ' . $conn->connect_error]);
    exit;
}

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['mensaje' => '❌ ID inválido']);
    exit;
}

$stmt = $conn->prepare("UPDATE choferes SET activo = 0 WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    echo json_encode(['mensaje' => '✅ Chofer desactivado correctamente']);
} else {
    echo json_encode(['mensaje' => '❌ Error al desactivar: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
