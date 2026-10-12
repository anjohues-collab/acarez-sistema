<?php
// api/login_chofer.php
header('Content-Type: application/json');
require_once '../conexion.php';

$nombreChofer = $_POST['usuario'] ?? '';
$pin = $_POST['pin'] ?? '';

if (empty($nombreChofer) || empty($pin)) {
    echo json_encode(["success" => false, "mensaje" => "Selecciona tu nombre e ingresa tu PIN"]);
    exit;
}

try {
    // Buscamos al chofer en la tabla choferes por su nombre
    $stmt = $pdo->prepare("SELECT * FROM choferes WHERE nombre_chofer = ? AND activo = 1");
    $stmt->execute([$nombreChofer]);
    $chofer = $stmt->fetch();

    if ($chofer && $pin === $chofer['pin_password']) {
        echo json_encode([
            "success" => true,
            "mensaje" => "Bienvenido",
            "chofer" => [
                "id" => $chofer['id'],
                "usuario" => $chofer['nombre_chofer'],
                "nombre_completo" => $chofer['nombre_chofer'],
                "placas" => $chofer['placas'],
                "numero_economico" => $chofer['numero_economico']
            ]
        ]);
    } else {
        echo json_encode(["success" => false, "mensaje" => "PIN incorrecto o chofer no encontrado"]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "mensaje" => "Error en el servidor: " . $e->getMessage()]);
}
?>
