<?php
ob_start();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="reporte_rutas_acarez_' . date('Ymd_His') . '.csv"');
header('Cache-Control: max-age=0');

// BOM para que Excel en celular reconozca acentos
echo "\xEF\xBB\xBF"; 

require_once 'conexion.php';

$semana = $_GET['semana'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';
$choferFiltro = $_GET['chofer'] ?? '';

$where = "1=1";
if (!empty($semana)) {
    $year = substr($semana, 0, 4);
    $week = substr($semana, 6);
    $f_inicio = date('Y-m-d', strtotime($year . 'W' . $week . '1'));
    $f_fin = date('Y-m-d', strtotime($year . 'W' . $week . '7'));
    $where .= " AND DATE(r.fecha_inicio) BETWEEN '$f_inicio' AND '$f_fin'";
} elseif (!empty($fecha_inicio) && !empty($fecha_fin)) {
    $where .= " AND DATE(r.fecha_inicio) BETWEEN '$fecha_inicio' AND '$fecha_fin'";
}
if (!empty($choferFiltro)) {
    $choferEsc = $conn->real_escape_string($choferFiltro);
    $where .= " AND r.chofer = '$choferEsc'";
}

$sql = "SELECT r.*, 
               (SELECT COALESCE(SUM(g.monto), 0) FROM gastos g WHERE g.ruta_id = r.id) AS total_general
        FROM rutas r 
        WHERE $where 
        ORDER BY r.fecha_inicio DESC";

$result = $conn->query($sql);

if (!$result) {
    echo "Error en la consulta: " . $conn->error;
    exit;
}

$output = fopen('php://output', 'w');

// Cabeceras del CSV con columnas separadas por concepto
fputcsv($output, [
    'RUTA', 'FECHA', 'HORA', 'CHOFER', 'AUXILIAR', 'VEHICULO',
    'ORIGEN', 'KM INICIAL', 'KM FINAL', 'KM RECORRIDO',
    'COMIDA', 'HOTEL', 'CASETAS', 'ESTACIONAMIENTO', 'GASOLINA', 'OTROS',
    'TOTAL GASTOS', 'ESTATUS'
]);

while ($row = $result->fetch_assoc()) {
    $f_inicio_formato = !empty($row['fecha_inicio']) ? date('d/m/Y', strtotime($row['fecha_inicio'])) : 'N/A';
    $h_ini_cruda = !empty($row['fecha_inicio']) ? date('H:i', strtotime($row['fecha_inicio'])) : '00:00';
    $h_inicio_formato = ($h_ini_cruda == '00:00') ? 'No registrada' : $h_ini_cruda;

    $id_ruta = $row['id'];

    // Obtener gastos agrupados por concepto
    $sql_gastos = "SELECT concepto, SUM(monto) as total_concepto 
                   FROM gastos 
                   WHERE ruta_id = $id_ruta 
                   GROUP BY concepto";
    $res_gastos = $conn->query($sql_gastos);

    // Inicializar acumuladores
    $comida = 0.0;
    $hotel = 0.0;
    $casetas = 0.0;
    $estacionamiento = 0.0;
    $gasolina = 0.0;
    $otros = 0.0;

    if ($res_gastos) {
        while ($g = $res_gastos->fetch_assoc()) {
            $concepto = mb_strtolower(trim($g['concepto']), 'UTF-8');
            $monto = floatval($g['total_concepto']);

            if (strpos($concepto, 'comida') !== false || strpos($concepto, 'alimento') !== false) {
                $comida += $monto;
            } elseif (strpos($concepto, 'hotel') !== false || strpos($concepto, 'hospedaje') !== false) {
                $hotel += $monto;
            } elseif (strpos($concepto, 'caseta') !== false || strpos($concepto, 'peaje') !== false) {
                $casetas += $monto;
            } elseif (strpos($concepto, 'estacionamiento') !== false || strpos($concepto, 'estacion') !== false || strpos($concepto, 'pension') !== false || strpos($concepto, 'pensión') !== false) {
                $estacionamiento += $monto;
            } elseif (strpos($concepto, 'gasolina') !== false || strpos($concepto, 'diesel') !== false || strpos($concepto, 'diésel') !== false || strpos($concepto, 'combustible') !== false) {
                $gasolina += $monto;
            } else {
                $otros += $monto;
            }
        }
    }

    $ruta_num = $row['numero_ruta'] ?? 'Sin número';
    $chofer = str_replace(["\t", "\n", "\r", ","], " ", $row['chofer'] ?? '');
    $auxiliar = str_replace(["\t", "\n", "\r", ","], " ", $row['auxiliar'] ?? 'Sin auxiliar');
    $vehiculo = str_replace(["\t", "\n", "\r", ","], " ", ($row['placas'] ?? '') . ' ' . ($row['no_economico'] ?? ''));
    $origen = str_replace(["\t", "\n", "\r", ","], " ", $row['origen'] ?? '');

    fputcsv($output, [
        $ruta_num,
        $f_inicio_formato,
        $h_inicio_formato,
        $chofer,
        $auxiliar,
        $vehiculo,
        $origen,
        $row['km_inicial'] ?? 0,
        $row['km_final'] ?? 0,
        $row['km_total'] ?? 0,
        number_format($comida, 2, '.', ''),
        number_format($hotel, 2, '.', ''),
        number_format($casetas, 2, '.', ''),
        number_format($estacionamiento, 2, '.', ''),
        number_format($gasolina, 2, '.', ''),
        number_format($otros, 2, '.', ''),
        number_format($row['total_general'] ?? 0, 2, '.', ''),
        ucfirst($row['estatus'] ?? '')
    ]);
}

$conn->close();
fclose($output);
exit;
?>
