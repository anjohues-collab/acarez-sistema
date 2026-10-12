<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../conexion.php';

$chofer = trim($_GET['chofer'] ?? '');
$tipo = trim($_GET['tipo'] ?? 'activas'); // 'activas' o 'historial'

if (empty($chofer)) {
    echo json_encode(['success' => false, 'mensaje' => '❌ Chofer no especificado']);
    exit;
}

// Si se solicita 'historial' consulta las completadas; si son 'activas' incluye programadas/activas de cualquier fecha anterior
if ($tipo === 'historial') {
    $sql = "SELECT r.*, 
            (SELECT COUNT(*) FROM paradas WHERE ruta_id = r.id) as total_paradas 
            FROM rutas r 
            WHERE r.chofer = ? AND r.estatus = 'completada' 
            ORDER BY r.fecha_inicio DESC";
} else {
    $sql = "SELECT r.*, 
            (SELECT COUNT(*) FROM paradas WHERE ruta_id = r.id) as total_paradas 
            FROM rutas r 
            WHERE r.chofer = ? AND r.estatus IN ('programada', 'activa', 'en_proceso') 
            ORDER BY r.id DESC";
}

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'mensaje' => '❌ Error en la consulta SQL: ' . $conn->error]);
    exit;
}

$stmt->bind_param("s", $chofer);
$stmt->execute();
$result = $stmt->get_result();

$rutas = [];
while ($row = $result->fetch_assoc()) {
    $destinos_sql = "SELECT p.*, d.razon_social, d.sucursal, d.direccion 
                     FROM paradas p 
                     LEFT JOIN destinos d ON p.destino_id = d.id 
                     WHERE p.ruta_id = " . intval($row['id']) . " 
                     ORDER BY p.orden ASC";
    $destinos_result = $conn->query($destinos_sql);
    $destinos = [];
    
    if ($destinos_result) {
        while ($d = $destinos_result->fetch_assoc()) {
            $destinos[] = [
                'id' => $d['id'],
                'destino_id' => $d['destino_id'],
                'razon_social' => $d['razon_social'],
                'sucursal' => $d['sucursal'],
                'direccion' => $d['direccion'],
                'orden' => $d['orden']
            ];
        }
    }
    $row['destinos'] = $destinos;
    $rutas[] = $row;
}

echo json_encode(['success' => true, 'rutas' => $rutas]);
$stmt->close();
$conn->close();
?>
