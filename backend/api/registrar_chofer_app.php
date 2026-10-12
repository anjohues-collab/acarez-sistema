<?php
// api/registrar_chofer_app.php
header('Content-Type: application/json');
require_once '../conexion.php';

$nombreChofer = trim($_POST['nombre'] ?? '');
$pin = trim($_POST['pin'] ?? '');

if (empty($nombreChofer) || empty($pin)) {
    echo json_encode(["success" => false, "mensaje" => "Ingresa tu nombre y tu PIN numérico"]);
    exit;
}

try {
    // Buscar si el chofer ya existe en la base de datos
    $stmt = $pdo->prepare("SELECT * FROM choferes WHERE nombre_chofer = ?");
    $stmt->execute([$nombreChofer]);
    $chofer = $stmt->fetch();

    if (!$chofer) {
        // 1. Si el chofer NO existe, lo registramos por primera vez con el PIN que ingresó
        $stmtInsert = $pdo->prepare("INSERT INTO choferes (nombre_chofer, placas, numero_economico, pin_password, activo) VALUES (?, 'PENDIENTE', 'PENDIENTE', ?, 1)");
        $stmtInsert->execute([$nombreChofer, $pin]);
        $nuevoId = $pdo->lastInsertId();

        echo json_encode([
            "success" => true,
            "mensaje" => "Registro exitoso",
            "chofer" => [
                "id" => $nuevoId,
                "usuario" => $nombreChofer,
                "nombre_completo" => $nombreChofer
            ]
        ]);
    } else {
        // 2. Si el chofer SÍ existe, validamos el PIN
        // Si no tiene PIN registrado o tiene el de fábrica ('1234'), se lo asignamos
        if (empty($chofer['pin_password']) || $chofer['pin_password'] === '1234') {
            $stmtUpdate = $pdo->prepare("UPDATE choferes SET pin_password = ?, activo = 1 WHERE id = ?");
            $stmtUpdate->execute([$pin, $chofer['id']]);
            
            echo json_encode([
                "success" => true,
                "mensaje" => "PIN configurado correctamente",
                "chofer" => [
                    "id" => $chofer['id'],
                    "usuario" => $chofer['nombre_chofer'],
                    "nombre_completo" => $chofer['nombre_chofer']
                ]
            ]);
        } else {
            // Si ya tiene un PIN asignado (y el admin pudo haberlo cambiado), lo exigimos estrictamente
            if ($pin === $chofer['pin_password']) {
                echo json_encode([
                    "success" => true,
                    "mensaje" => "Bienvenido",
                    "chofer" => [
                        "id" => $chofer['id'],
                        "usuario" => $chofer['nombre_chofer'],
                        "nombre_completo" => $chofer['nombre_chofer']
                    ]
                ]);
            } else {
                echo json_encode([
                    "success" => false, 
                    "mensaje" => "PIN incorrecto. Verifica tu contraseña."
                ]);
            }
        }
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "mensaje" => "Error en el servidor: " . $e->getMessage()]);
}
?>
