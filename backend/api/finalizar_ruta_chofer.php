<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../conexion.php';

$ruta_id = intval($_POST['ruta_id'] ?? 0);
$km_final = floatval($_POST['km_final'] ?? 0);
$destino_final = trim($_POST['destino_final'] ?? '');

if ($ruta_id <= 0 || $km_final <= 0) {
    echo json_encode(['success' => false, 'mensaje' => '❌ Faltan datos obligatorios']);
    exit;
}

// Verificar que la ruta existe y está activa o en proceso
$check = $conn->query("SELECT id, km_inicial FROM rutas WHERE id = $ruta_id AND estatus IN ('activa', 'en_proceso', 'en proceso')");
if ($check->num_rows == 0) {
    echo json_encode(['success' => false, 'mensaje' => '❌ Ruta no encontrada o ya finalizada']);
    exit;
}
$ruta = $check->fetch_assoc();
$km_inicial = floatval($ruta['km_inicial']);

// Calcular km total
$km_total = $km_final - $km_inicial;
if ($km_total < 0) {
    echo json_encode(['success' => false, 'mensaje' => '❌ El km final debe ser mayor al km inicial']);
    exit;
}

// 🟢 PROCESAR LA SUBIDA DE FOTO FÍSICA A LA CARPETA uploads/gastos/
$upload_dir = '../uploads/gastos/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$ruta_foto_bd = null;

if (isset($_FILES['foto_fin']) && $_FILES['foto_fin']['error'] === UPLOAD_ERR_OK) {
    $tmp_name = $_FILES['foto_fin']['tmp_name'];
    $extension = strtolower(pathinfo($_FILES['foto_fin']['name'], PATHINFO_EXTENSION));
    $nuevo_nombre = "foto_fin_{$ruta_id}_" . time() . "." . ($extension ?: 'jpg');
    $destino_final_archivo = $upload_dir . $nuevo_nombre;

    if (move_uploaded_file($tmp_name, $destino_final_archivo)) {
        $ruta_foto_bd = "uploads/gastos/" . $nuevo_nombre;
    }
}

$fecha_fin = date('Y-m-d H:i:s');

if ($ruta_foto_bd) {
    $stmt = $conn->prepare("UPDATE rutas SET 
        km_final = ?, 
        km_total = ?, 
        foto_fin = ?, 
        destino_final = ?, 
        fecha_fin = ?, 
        estatus = 'completada' 
    WHERE id = ?");
    $stmt->bind_param("ddsssi", $km_final, $km_total, $ruta_foto_bd, $destino_final, $fecha_fin, $ruta_id);
} else {
    $stmt = $conn->prepare("UPDATE rutas SET 
        km_final = ?, 
        km_total = ?, 
        destino_final = ?, 
        fecha_fin = ?, 
        estatus = 'completada' 
    WHERE id = ?");
    $stmt->bind_param("ddssi", $km_final, $km_total, $destino_final, $fecha_fin, $ruta_id);
}

if ($stmt->execute()) {
    // Obtener resumen de la ruta
    $resumen = $conn->query("SELECT total_gastos, numero_paradas FROM rutas WHERE id = $ruta_id")->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'mensaje' => '✅ Ruta finalizada correctamente',
        'km_total' => $km_total,
        'total_gastos' => $resumen['total_gastos'],
        'numero_paradas' => $resumen['numero_paradas']
    ]);
} else {
    echo json_encode(['success' => false, 'mensaje' => '❌ Error: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
