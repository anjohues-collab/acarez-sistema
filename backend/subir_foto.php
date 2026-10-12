<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Manejar preflight CORS (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$carpetas = [
    'inicio' => 'km_inicio/',
    'fin' => 'km_fin/',
    'hotel_ida' => 'hotel/',
    'hotel_regreso' => 'hotel/',
    'caseta_ida' => 'caseta/',
    'caseta_regreso' => 'caseta/',
    'comida_ida' => 'comida/',
    'comida_regreso' => 'comida/',
    'estac_ida' => 'estacionamiento/',
    'estac_regreso' => 'estacionamiento/',
    'gasolina_ida' => 'gasolina/',
    'gasolina_regreso' => 'gasolina/',
];

$tipo = $_POST['tipo'] ?? 'general';
$carpeta_base = "uploads/";
$subcarpeta = $carpetas[$tipo] ?? 'otros/';

$carpeta_destino = $carpeta_base . $subcarpeta;
if (!file_exists($carpeta_destino)) {
    mkdir($carpeta_destino, 0777, true);
}

if ($_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $extension = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
    $nombre_archivo = uniqid($tipo . '_') . '.' . $extension;
    $ruta_completa = $carpeta_destino . $nombre_archivo;
    
    if (move_uploaded_file($_FILES['foto']['tmp_name'], $ruta_completa)) {
        // ========== CORRECCIÓN: GUARDAR RUTA RELATIVA ==========
        $ruta_relativa = 'uploads/' . $subcarpeta . $nombre_archivo;
        echo json_encode(['success' => true, 'ruta' => $ruta_relativa]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se pudo mover']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Error al subir: ' . $_FILES['foto']['error']]);
}
?>
