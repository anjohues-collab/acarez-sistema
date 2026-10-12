<?php
session_start();
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="reporte_rutas_acarez_' . date('Ymd_His') . '.xls"');
header('Cache-Control: max-age=0');

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
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte ACAREZ</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; }
        h1 { color: #4A148C; font-size: 18px; }
        .fecha { font-size: 12px; color: #666; margin-bottom: 15px; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4A148C; color: #FFFFFF; font-weight: bold; padding: 8px 6px; border: 1px solid #3C096C; text-align: center; }
        td { padding: 6px 4px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
        .fila-alternativa { background-color: #f9f9f9; }
        .alerta { color: #dc3545; font-style: italic; }
    </style>
</head>
<body>
    <h1>📋 Reporte de Rutas y Gastos - ACAREZ LOGÍSTICA</h1>
    <div class="fecha">Generado: <?= date('d/m/Y H:i:s') ?></div>
    <table>
        <thead>
            <tr>
                <th>RUTA</th>
                <th>FECHA</th>
                <th style="background-color:#6A1B9A;">HORA</th>
                <th>CHOFER</th>
                <th>AUXILIAR</th>
                <th>VEHÍCULO</th>
                <th>ORIGEN</th>
                <th>KM INICIAL</th>
                <th>KM FINAL</th>
                <th>KM TOTAL</th>
                <th style="background-color:#6A1B9A;">COMIDA</th>
                <th style="background-color:#6A1B9A;">HOTEL</th>
                <th style="background-color:#6A1B9A;">CASETAS</th>
                <th style="background-color:#6A1B9A;">ESTACIONAMIENTO</th>
                <th style="background-color:#6A1B9A;">GASOLINA</th>
                <th style="background-color:#6A1B9A;">OTROS</th>
                <th>TOTAL GASTOS</th>
                <th>ESTATUS</th>
            </tr>
        </thead>
        <tbody>
<?php
$cont = 0;
while ($row = $result->fetch_assoc()) {
    $cont++;
    $clase = ($cont % 2 == 0) ? 'fila-alternativa' : '';

    $f_inicio_formato = !empty($row['fecha_inicio']) ? date('d/m/Y', strtotime($row['fecha_inicio'])) : 'N/A';
    $h_ini_cruda = !empty($row['fecha_inicio']) ? date('H:i', strtotime($row['fecha_inicio'])) : '00:00';
    $h_inicio_formato = ($h_ini_cruda == '00:00') ? '<span class="alerta">Sin registrar</span>' : "<strong>$h_ini_cruda</strong>";

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

    $ruta_num = htmlspecialchars($row['numero_ruta'] ?? 'Sin número');
    $chofer   = htmlspecialchars($row['chofer'] ?? '');
    $auxiliar = htmlspecialchars($row['auxiliar'] ?? 'Sin auxiliar');
    $vehiculo = htmlspecialchars($row['placas'] ?? '') . ' (' . htmlspecialchars($row['no_economico'] ?? '') . ')';
    $origen   = htmlspecialchars($row['origen'] ?? '');

    $km_inicial = number_format($row['km_inicial'] ?? 0, 0, '.', '');
    $km_final   = number_format($row['km_final'] ?? 0, 0, '.', '');
    $km_total   = number_format($row['km_total'] ?? 0, 0, '.', '');
    $total_gen  = number_format($row['total_general'] ?? 0, 2);
?>
            <tr class="<?= $clase ?>">
                <td style="text-align:center;"><strong><?= $ruta_num ?></strong></td>
                <td style="text-align:center;"><?= $f_inicio_formato ?></td>
                <td style="text-align:center;"><?= $h_inicio_formato ?></td>
                <td><?= $chofer ?></td>
                <td><?= $auxiliar ?></td>
                <td style="text-align:center;"><?= $vehiculo ?></td>
                <td><?= $origen ?></td>
                <td style="text-align:center;"><?= $km_inicial ?></td>
                <td style="text-align:center;"><?= $km_final ?></td>
                <td style="text-align:center; font-weight:bold;"><?= $km_total ?> km</td>
                <td style="text-align:right;">$<?= number_format($comida, 2) ?></td>
                <td style="text-align:right;">$<?= number_format($hotel, 2) ?></td>
                <td style="text-align:right;">$<?= number_format($casetas, 2) ?></td>
                <td style="text-align:right;">$<?= number_format($estacionamiento, 2) ?></td>
                <td style="text-align:right;">$<?= number_format($gasolina, 2) ?></td>
                <td style="text-align:right;">$<?= number_format($otros, 2) ?></td>
                <td style="text-align:right; font-weight:bold; color:#4A148C;">$<?= $total_gen ?></td>
                <td style="text-align:center;"><?= ucfirst($row['estatus']) ?></td>
            </tr>
<?php } ?>
        </tbody>
    </table>
</body>
</html>
<?php
$conn->close();
exit;
?>
