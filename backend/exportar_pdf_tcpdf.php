<?php
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/tcpdf/tcpdf.php';
require_once __DIR__ . '/conexion.php';

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
    die("Error en la consulta: " . $conn->error);
}

$total_viajes = 0;
$total_km = 0;
$total_gastos = 0;

$html = '<h1 style="text-align:center; color:#4A148C; font-size:20px; margin-top:0; margin-bottom:2px;">Reporte de Rutas y Gastos</h1>
<p style="text-align:center; font-size:10px; color:#666; margin-top:-3px;">Generado: ' . date('d/m/Y H:i:s') . '</p>';

if (!empty($semana)) {
    $week = substr($semana, 6);
    $year = substr($semana, 0, 4);
    $html .= '<p style="font-size:9px; text-align:center;"><strong>Filtro:</strong> Semana ' . $week . ' del ' . $year . '</p>';
} elseif (!empty($fecha_inicio) && !empty($fecha_fin)) {
    $html .= '<p style="font-size:9px; text-align:center;"><strong>Filtro:</strong> Del ' . date('d/m/Y', strtotime($fecha_inicio)) . ' al ' . date('d/m/Y', strtotime($fecha_fin)) . '</p>';
} elseif (!empty($choferFiltro)) {
    $html .= '<p style="font-size:9px; text-align:center;"><strong>Filtro:</strong> Chofer: ' . htmlspecialchars($choferFiltro) . '</p>';
} else {
    $html .= '<p style="font-size:9px; text-align:center;"><strong>Filtro:</strong> Todos los registros</p>';
}

// Ancho total exacto = 100%
$html .= '<table border="1" cellpadding="3" style="font-size:7px; border-collapse:collapse; width:100%;">
<thead>
    <tr style="background-color:#4A148C; color:#FFFFFF; font-weight:bold;">
        <th width="5%" align="center">Ruta</th>
        <th width="6%" align="center">Fecha</th>
        <th width="5%" align="center">Hora</th>
        <th width="10%" align="left">Chofer</th>
        <th width="8%" align="left">Auxiliar</th>
        <th width="8%" align="center">Vehículo</th>
        <th width="10%" align="left">Origen</th>
        <th width="5%" align="center">Km Inic</th>
        <th width="5%" align="center">Km Fin</th>
        <th width="5%" align="center">Km Tot</th>
        <th width="16%" align="left">Detalle Gastos</th>
        <th width="9%" align="right">Total</th>
        <th width="8%" align="center">Estatus</th>
    </tr>
</thead>
<tbody>';

while ($row = $result->fetch_assoc()) {
    $total_viajes++;
    $km_inicial = floatval($row['km_inicial'] ?? 0);
    $km_final = floatval($row['km_final'] ?? 0);
    $km_recorrido = floatval($row['km_total'] ?? 0);
    
    $total_km += $km_recorrido;
    $total_gastos += floatval($row['total_general']);

    $f_inicio_formato = !empty($row['fecha_inicio']) ? date('d/m/y', strtotime($row['fecha_inicio'])) : 'N/A';
    $h_ini_cruda = !empty($row['fecha_inicio']) ? date('H:i', strtotime($row['fecha_inicio'])) : '00:00';
    $h_inicio_formato = ($h_ini_cruda == '00:00') ? '<span style="color:#d32f2f;">Falta</span>' : "<strong>$h_ini_cruda</strong>";
    
    $id_ruta = $row['id'];
    $sql_gastos = "SELECT concepto, SUM(monto) as total_concepto FROM gastos WHERE ruta_id = $id_ruta GROUP BY concepto";
    $res_gastos = $conn->query($sql_gastos);
    $detalle_gastos = [];
    while ($g = $res_gastos->fetch_assoc()) {
        $detalle_gastos[] = "<b>" . $g['concepto'] . ":</b> $" . number_format($g['total_concepto'], 2);
    }
    $texto_gastos = empty($detalle_gastos) ? 'Sin gastos' : implode("<br>", $detalle_gastos);

    $ruta_num = htmlspecialchars($row['numero_ruta'] ?? 'Sin número');
    $auxiliar = htmlspecialchars($row['auxiliar'] ?? 'Sin aux.');

    $html .= '<tr>
        <td width="5%" align="center"><strong>' . $ruta_num . '</strong></td>
        <td width="6%" align="center">' . $f_inicio_formato . '</td>
        <td width="5%" align="center">' . $h_inicio_formato . '</td>
        <td width="10%" align="left">' . htmlspecialchars($row['chofer']) . '</td>
        <td width="8%" align="left">' . $auxiliar . '</td>
        <td width="8%" align="center">' . htmlspecialchars($row['placas']) . '</td>
        <td width="10%" align="left">' . htmlspecialchars($row['origen'] ?? '') . '</td>
        <td width="5%" align="center">' . number_format($km_inicial, 0) . '</td>
        <td width="5%" align="center">' . number_format($km_final, 0) . '</td>
        <td width="5%" align="center"><strong>' . number_format($km_recorrido, 0) . '</strong></td>
        <td width="16%" align="left">' . $texto_gastos . '</td>
        <td width="9%" align="right" style="font-weight:bold; color:#4A148C;">$' . number_format($row['total_general'], 2) . '</td>
        <td width="8%" align="center">' . ucfirst($row['estatus']) . '</td>
    </tr>';
}

$html .= '</tbody></table>';

$html .= '<h3 style="text-align:center; color:#4A148C; font-size:11px; margin-top:15px;">Resumen del Periodo</h3>
<table border="0" cellpadding="3" style="margin:0 auto; font-size:10px;">
    <tr><td align="right" style="font-weight:bold;">Total de rutas/viajes:</td><td align="left">' . $total_viajes . '</td></tr>
    <tr><td align="right" style="font-weight:bold;">Total kilómetros recorridos:</td><td align="left">' . number_format($total_km, 0) . ' km</td></tr>
    <tr><td align="right" style="font-weight:bold; color:#4A148C;">Gran total gastos comprobados:</td><td align="left" style="font-weight:bold; color:#4A148C;">$' . number_format($total_gastos, 2) . '</td></tr>
</table>';

$html .= '<p style="text-align:center; font-size:8px; color:#999; margin-top:15px; border-top:1px solid #ddd; padding-top:5px;">
    Reporte generado automáticamente por ACAREZ Logística. © ' . date('Y') . ' - Todos los derechos reservados.
</p>';

$pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 10);
$pdf->AddPage();

$logoPath = __DIR__ . '/imagenes/acarez_3.png';
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 10, 10, 0, 12, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
}

$pdf->SetY(24);
$pdf->SetFont('helvetica', '', 9);
$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Output('reporte_rutas_' . date('Ymd_His') . '.pdf', 'D');
exit;
?>
