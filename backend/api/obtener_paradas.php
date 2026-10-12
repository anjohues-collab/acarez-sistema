<?php
// Configuración de cabeceras CORS y tipo de respuesta para Flutter
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../conexion.php';

// Activar excepciones estrictas para que el bloque try-catch realmente funcione con mysqli
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ruta_id = intval($_GET['ruta_id'] ?? 0);

if ($ruta_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'mensaje' => '❌ Ruta no válida o parámetro ausente'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // 1. OBTENER LAS PARADAS DE LA RUTA
    $sql_paradas = "SELECT 
                p.id,
                p.ruta_id,
                p.destino_id,
                p.orden,
                p.km_actual,
                COALESCE(p.estatus, 'programada') AS estatus,
                d.razon_social, 
                d.sucursal,
                d.direccion
            FROM paradas p 
            LEFT JOIN destinos d ON p.destino_id = d.id 
            WHERE p.ruta_id = ? 
            ORDER BY p.orden ASC";

    $stmt_paradas = $conn->prepare($sql_paradas);
    $stmt_paradas->bind_param("i", $ruta_id);
    $stmt_paradas->execute();
    $result_paradas = $stmt_paradas->get_result();

    $paradas = [];
    while ($row = $result_paradas->fetch_assoc()) {
        $estatusLwr = strtolower(trim($row['estatus']));
        $esCompletado = ($estatusLwr === 'completado' || $estatusLwr === 'completada' || $estatusLwr === '1' || $row['km_actual'] !== null);
        
        $paradas[] = [
            'id'           => intval($row['id']),
            'ruta_id'      => intval($row['ruta_id']),
            'destino_id'   => $row['destino_id'] !== null ? intval($row['destino_id']) : null,
            'orden'        => intval($row['orden']),
            'km_actual'    => $row['km_actual'] !== null ? floatval($row['km_actual']) : null,
            'estatus'      => $esCompletado ? 'completado' : 'programada',
            'completado'   => $esCompletado,
            'razon_social' => $row['razon_social'] ?? '',
            'sucursal'     => $row['sucursal'] ?? '',
            'direccion'    => $row['direccion'] ?? ''
        ];
    }
    $stmt_paradas->close();

    // 2. OBTENER LOS GASTOS ASOCIADOS A LA RUTA
    $sql_gastos = "SELECT 
                id,
                COALESCE(concepto, tipo_gasto, 'Gasto') AS concepto,
                monto,
                COALESCE(foto, foto_comprobante, '') AS foto,
                fecha
            FROM gastos 
            WHERE ruta_id = ? 
            ORDER BY id DESC";

    $stmt_gastos = $conn->prepare($sql_gastos);
    $stmt_gastos->bind_param("i", $ruta_id);
    $stmt_gastos->execute();
    $result_gastos = $stmt_gastos->get_result();

    $gastos = [];
    $total_gastos = 0.0;

    while ($row_gasto = $result_gastos->fetch_assoc()) {
        $monto = floatval($row_gasto['monto']);
        $total_gastos += $monto;

        $gastos[] = [
            'id'       => intval($row_gasto['id']),
            'concepto' => $row_gasto['concepto'],
            'monto'    => $monto,
            'foto'     => $row_gasto['foto'] ?? '',
            'fecha'    => $row_gasto['fecha'] ?? ''
        ];
    }
    $stmt_gastos->close();

    // 3. RESPUESTA UNIFICADA EN JSON
    http_response_code(200);
    echo json_encode([
        'success'      => true,
        'paradas'      => $paradas,
        'gastos'       => $gastos,
        'total_gastos' => round($total_gastos, 2)
    ], JSON_UNESCAPED_UNICODE);

} catch (mysqli_sql_exception $e) { // Captura específica de errores de MySQL
    http_response_code(500);
    // Registrar el error interno en Render sin exponer la consulta SQL exacta a la app
    error_log("Error de BD en obtener_paradas.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'mensaje' => 'Error de conexión o de consulta en la base de datos.',
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
