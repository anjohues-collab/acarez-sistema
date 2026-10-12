<?php
header('Content-Type: application/json');

$conn = new mysqli('localhost', 'root', '', 'acarez_logistica');
if ($conn->connect_error) {
    echo json_encode(['mensaje' => 'Error de conexión: ' . $conn->connect_error]);
    exit;
}

$nombre_chofer = trim($_POST['nombre_chofer'] ?? '');
$placas = trim($_POST['placas'] ?? '');
$numero_economico = trim($_POST['numero_economico'] ?? '');

if (empty($nombre_chofer) || empty($placas) || empty($numero_economico)) {
    echo json_encode(['mensaje' => '❌ Todos los campos son obligatorios']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO choferes (nombre_chofer, placas, numero_economico) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $nombre_chofer, $placas, $numero_economico);

if ($stmt->execute()) {
    echo json_encode(['mensaje' => '✅ Chofer agregado correctamente']);
} else {
    echo json_encode(['mensaje' => '❌ Error al agregar: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
