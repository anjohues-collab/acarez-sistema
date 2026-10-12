<?php
// ============================================
// ACAREZ - PANEL ADMINISTRATIVO COMPLETO
// ============================================

// OBSERVACIÓN: Se inicia la sesión para manejar variables globales (como mensajes de éxito/error) 
// y se incluye el archivo de conexión a la base de datos.
session_start();
require_once 'conexion.php';

// 🕒 Ajustar zona horaria de México en PHP y MySQL (UTC-6)
// OBSERVACIÓN: Garantiza que todas las fechas registradas en PHP y en las consultas MySQL coincidan con la hora local de México.
date_default_timezone_set('America/Mexico_City');
if (isset($conn) && $conn) {
    $conn->query("SET time_zone = '-06:00'");
}

// OBSERVACIÓN: Recolección de variables pasadas por la URL (método GET) con valores por defecto si no existen.
$seccion = $_GET['seccion'] ?? 'reportes';
$semana_seleccionada = $_GET['semana_facturar'] ?? '';
$accion = $_GET['accion'] ?? '';

// ==============================================
// 1. OBTENER DATOS DE LA SEMANA (para el modal de detalle)
// ==============================================
// OBSERVACIÓN: Este bloque funciona como una API (retorna JSON). Si la URL trae accion=get_semana_data, 
// calcula las fechas de inicio y fin de la semana y consulta todas las rutas en ese rango.
if ($accion == 'get_semana_data' && !empty($_GET['semana'])) {
    header('Content-Type: application/json');
    $semana = $_GET['semana'];
    
    // OBSERVACIÓN: Extrae el año y la semana de la cadena (ej. "2026-W34") para determinar los días exactos.
    $year = substr($semana, 0, 4);
    $week = substr($semana, 6);
    $fecha_inicio = date('Y-m-d', strtotime($year . 'W' . $week . '1'));
    $fecha_fin = date('Y-m-d', strtotime($year . 'W' . $week . '7'));
    
    // OBSERVACIÓN: Subconsulta en el SELECT para sumar automáticamente los gastos asociados a cada ruta.
    $sql = "SELECT r.id, r.numero_ruta, r.fecha_inicio AS fecha, r.chofer, r.placas, r.origen, r.km_inicial, r.km_final, r.km_total,
                   (SELECT COALESCE(SUM(g.monto), 0) FROM gastos g WHERE g.ruta_id = r.id) AS total_general 
            FROM rutas r 
            WHERE DATE(r.fecha_inicio) BETWEEN '$fecha_inicio' AND '$fecha_fin' 
            ORDER BY r.fecha_inicio";
    $result = $conn->query($sql);
    
    $viajes = [];
    $total_gastos_semana = 0;
    while ($row = $result->fetch_assoc()) {
        $gasto = floatval($row['total_general']);
        $total_gastos_semana += $gasto;
        $viajes[] = [
            'id' => $row['id'],
            'numero_ruta' => $row['numero_ruta'] ?? '',
            'fecha' => date('d/m/Y H:i', strtotime($row['fecha'])),
            'chofer' => $row['chofer'],
            'placas' => $row['placas'],
            'destino' => $row['origen'],
            'gasto' => number_format($gasto, 2),
            'km_inicial' => isset($row['km_inicial']) ? $row['km_inicial'] : 0,
            'km_final'   => isset($row['km_final']) ? $row['km_final'] : 0,
            'km_total'   => isset($row['km_total']) ? $row['km_total'] : 0
        ];
    }
    
    // OBSERVACIÓN: Termina la ejecución del script (exit) para no renderizar el HTML que está más abajo.
    echo json_encode([
        'semana' => $semana,
        'fecha_inicio' => $fecha_inicio,
        'fecha_fin' => $fecha_fin,
        'viajes' => $viajes,
        'total_gastos_semana' => number_format($total_gastos_semana, 2)
    ]);
    exit;
}

// ==============================================
// 2. OBTENER DATOS DE RUTA CON PARADAS, GASTOS Y CUCAS
// ==============================================
// OBSERVACIÓN: API Endpoint para llenar el modal de detalle de una ruta específica cuando se hace clic en "Ver Detalles".
if ($accion == 'get_ruta_data' && !empty($_GET['ruta_id'])) {
    header('Content-Type: application/json');
    $ruta_id = intval($_GET['ruta_id']);
    
    // OBSERVACIÓN: Se obtienen los datos generales de la ruta, incluyendo las fotos de los odómetros.
    $ruta_sql = "SELECT *, foto_inicio, foto_fin FROM rutas WHERE id = $ruta_id";
    $ruta_result = $conn->query($ruta_sql);
    $ruta = $ruta_result->fetch_assoc();
    
    // OBSERVACIÓN: Sumatoria total de los gastos de esta ruta. COALESCE asegura que devuelva 0 si es null.
    $sql_gastos_suma = "SELECT COALESCE(SUM(monto), 0) AS total_gastos FROM gastos WHERE ruta_id = $ruta_id";
    $res_gastos_suma = $conn->query($sql_gastos_suma);
    $row_suma = $res_gastos_suma->fetch_assoc();
    $total_gastos = floatval($row_suma['total_gastos']);
    
    if ($ruta) {
        $ruta['total_gastos'] = $total_gastos;
    }
    
    // OBSERVACIÓN: Extrae todos los gastos y facturas (cucas) asociados a esta ruta incluyendo observaciones.
    $sql_lista_gastos = "SELECT id, parada_id, concepto, monto, foto, observaciones, fecha FROM gastos WHERE ruta_id = $ruta_id ORDER BY id DESC";
    $res_lista_gastos = $conn->query($sql_lista_gastos);
    $gastos = [];
    while ($g = $res_lista_gastos->fetch_assoc()) {
        $gastos[] = $g;
    }

    $sql_lista_cucas = "SELECT id, parada_id, numero_cuca, observaciones, foto_cuca, fecha FROM cucas WHERE ruta_id = $ruta_id ORDER BY id ASC";
    $res_lista_cucas = $conn->query($sql_lista_cucas);
    $cucas = [];
    while ($c = $res_lista_cucas->fetch_assoc()) {
        $cucas[] = $c;
    }
    
    // OBSERVACIÓN: Extrae las paradas ordenadas (1, 2, 3...) cruzando la tabla 'paradas' con 'destinos' para tener la dirección real.
    $paradas_sql = "SELECT p.*, d.razon_social, d.sucursal, d.direccion 
                    FROM paradas p 
                    LEFT JOIN destinos d ON p.destino_id = d.id 
                    WHERE p.ruta_id = $ruta_id 
                    ORDER BY p.orden ASC";
    $paradas_result = $conn->query($paradas_sql);
    $paradas = [];
    while ($row = $paradas_result->fetch_assoc()) {
        $paradas[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'ruta' => $ruta,
        'paradas' => $paradas,
        'gastos' => $gastos,
        'cucas' => $cucas,
        'total_gastos' => $total_gastos
    ]);
    exit;
}

// ==============================================
// 3. FUNCIONES DE APOYO
// ==============================================
function obtenerReportes($conn, $filtros = []) {
    $where = "1=1";
    
    if (!empty($filtros['semana'])) {
        $year = substr($filtros['semana'], 0, 4);
        $week = substr($filtros['semana'], 6);
        $fecha_inicio = date('Y-m-d', strtotime($year . 'W' . $week . '1'));
        $fecha_fin = date('Y-m-d', strtotime($year . 'W' . $week . '7'));
        $where .= " AND DATE(r.fecha_inicio) BETWEEN '$fecha_inicio' AND '$fecha_fin'";
    } elseif (!empty($filtros['fecha_inicio']) && !empty($filtros['fecha_fin'])) {
        $where .= " AND DATE(r.fecha_inicio) BETWEEN '{$filtros['fecha_inicio']}' AND '{$filtros['fecha_fin']}'";
    }

    if (!empty($filtros['chofer'])) {
        $choferEsc = $conn->real_escape_string($filtros['chofer']);
        $where .= " AND r.chofer = '$choferEsc'";
    }

    $sql = "SELECT r.*, 
                   (SELECT COALESCE(SUM(g.monto), 0) FROM gastos g WHERE g.ruta_id = r.id) AS total_general,
                   (SELECT COUNT(p.id) FROM paradas p WHERE p.ruta_id = r.id) AS total_paradas
            FROM rutas r 
            WHERE $where 
            ORDER BY r.fecha_inicio DESC";
            
    return $conn->query($sql);
}

function obtenerSemanasDisponibles($conn) {
    return $conn->query("SELECT DISTINCT CONCAT(YEAR(fecha_inicio), '-W', LPAD(WEEK(fecha_inicio, 1), 2, '0')) as semana, MIN(DATE(fecha_inicio)) as inicio, MAX(DATE(fecha_inicio)) as fin FROM rutas GROUP BY semana ORDER BY semana DESC");
}

function obtenerDestinos($conn) {
    return $conn->query("SELECT * FROM destinos WHERE activo = 1 ORDER BY razon_social ASC");
}

function obtenerRutas($conn, $filtros = []) {
    $where = "1=1";
    if (!empty($filtros['chofer'])) {
        $where .= " AND r.chofer LIKE '%" . $conn->real_escape_string($filtros['chofer']) . "%'";
    }
    if (!empty($filtros['estatus'])) {
        $where .= " AND r.estatus = '" . $conn->real_escape_string($filtros['estatus']) . "'";
    }
    
    $sql = "SELECT r.*, 
                   (SELECT COALESCE(SUM(g.monto), 0) FROM gastos g WHERE g.ruta_id = r.id) AS total_gastos 
            FROM rutas r 
            WHERE $where 
            ORDER BY r.id DESC";
            
    return $conn->query($sql);
}

// ==============================================
// 4. FUNCIÓN PARA NORMALIZAR NÚMERO ECONÓMICO
// ==============================================
function normalizarNoEconomico($no) {
    $no = trim($no);
    if (preg_match('/^([A-Z])(\d+)$/', $no, $matches)) {
        return $matches[1] . '-' . $matches[2];
    }
    if (preg_match('/^[A-Z]-\d+$/', $no)) {
        return $no;
    }
    return null;
}

function manejarCatalogos($conn) {
    return [
        'placas' => $conn->query("SELECT * FROM catalogo_placas ORDER BY id ASC"),
        'no_economicos' => $conn->query("SELECT * FROM catalogo_no_economico ORDER BY id ASC"),
        'choferes' => $conn->query("SELECT id, nombre_chofer, placas, numero_economico FROM choferes WHERE activo = 1 ORDER BY nombre_chofer ASC"),
        'auxiliares' => $conn->query("SELECT id, nombre FROM auxiliares WHERE activo = 1 ORDER BY nombre ASC")
    ];
}

// ==============================================
// 5. PROCESAR POST (App de Flutter y Formularios Web)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // ========== GESTIÓN DE ACCESOS APP Y CHOFERES ==========
    if (isset($_POST['accion_usuario_app'])) {
        $sub_accion = $_POST['accion_usuario_app'];

        if ($sub_accion === 'guardar') {
            $id = $_POST['id'] ?? '';
            $nombre = trim($_POST['nombre_chofer'] ?? '');
            $pin = trim($_POST['pin_password'] ?? '');
            $placas = trim($_POST['placas'] ?? 'PENDIENTE');
            $no_eco = trim($_POST['numero_economico'] ?? 'PENDIENTE');

            if (!empty($nombre) && !empty($pin)) {
                try {
                    if (!empty($id)) {
                        $stmt = $pdo->prepare("UPDATE choferes SET nombre_chofer = ?, pin_password = ?, placas = ?, numero_economico = ? WHERE id = ?");
                        $stmt->execute([$nombre, $pin, $placas, $no_eco, $id]);
                        $_SESSION['mensaje_exito'] = "✅ Chofer actualizado correctamente.";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO choferes (nombre_chofer, placas, numero_economico, pin_password, activo) VALUES (?, ?, ?, ?, 1)");
                        $stmt->execute([$nombre, $placas, $no_eco, $pin]);
                        $_SESSION['mensaje_exito'] = "✅ Chofer registrado correctamente.";
                    }
                } catch (Exception $e) {
                    $_SESSION['mensaje_error'] = "❌ Error al guardar en la base de datos.";
                }
            } else {
                $_SESSION['mensaje_error'] = "❌ El nombre y el PIN son obligatorios.";
            }
            header("Location: ?seccion=usuarios_app");
            exit;
        } elseif ($sub_accion === 'eliminar') {
            $id = $_POST['id'] ?? '';
            if (!empty($id)) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM choferes WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['mensaje_exito'] = "🗑️ Chofer eliminado del sistema.";
                } catch (Exception $e) {
                    $_SESSION['mensaje_error'] = "❌ Error al eliminar el chofer.";
                }
            }
            header("Location: ?seccion=usuarios_app");
            exit;
        }
    }

    // ========== REGISTRAR GASTOS DESDE FLUTTER ==========
    if (isset($_POST['accion']) && $_POST['accion'] == 'registrar_parada') {
        header('Content-Type: application/json');
        
        $ruta_id = intval($_POST['ruta_id']);
        $parada_id = intval($_POST['parada_id']);
        $concepto = isset($_POST['tipo_gasto']) ? $conn->real_escape_string($_POST['tipo_gasto']) : 'Gasto';
        $monto = isset($_POST['monto']) ? floatval($_POST['monto']) : 0.0;
        
        if ($monto > 0 || $concepto != 'Sin Gastos') {
            $stmt = $conn->prepare("INSERT INTO gastos (ruta_id, parada_id, concepto, monto, fecha) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("iisd", $ruta_id, $parada_id, $concepto, $monto);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->query("UPDATE paradas SET estatus = 'completada', completada = 1 WHERE id = $parada_id");
        
        echo json_encode(['success' => true, 'mensaje' => 'Gasto y parada registrados correctamente']);
        exit;
    }

    // ========== CREAR RUTA ==========
    if (isset($_POST['crear_ruta'])) {
        $numero_ruta = trim($_POST['numero_ruta'] ?? '');
        $chofer_id = intval($_POST['chofer_id']);
        $auxiliar_nombre = trim($_POST['auxiliar_nombre'] ?? '');
        $fecha_ruta = $_POST['fecha_ruta'] ?? date('Y-m-d');
        $destinos_seleccionados = $_POST['destinos'] ?? [];
        
        // 🔥 Recibir placas y no_economico desde los dropdowns
        $placas_ruta = trim($_POST['placas_ruta'] ?? '');
        $no_economico_ruta = trim($_POST['no_economico_ruta'] ?? '');
        
        if ($chofer_id > 0 && !empty($destinos_seleccionados)) {
            $chofer_sql = "SELECT nombre_chofer, placas, numero_economico FROM choferes WHERE id = $chofer_id AND activo = 1";
            $chofer_result = $conn->query($chofer_sql);
            $chofer = $chofer_result->fetch_assoc();
            
            if ($chofer) {
                $fecha_inicio = $fecha_ruta . ' 00:00:00';
                
                // 🔥 Si el usuario no seleccionó placas o no económico, usar los del chofer como respaldo
                if (empty($placas_ruta)) {
                    $placas_ruta = $chofer['placas'];
                }
                if (empty($no_economico_ruta)) {
                    $no_economico_ruta = $chofer['numero_economico'];
                }
                
                $stmt = $conn->prepare("INSERT INTO rutas (numero_ruta, chofer, auxiliar, placas, no_economico, origen, fecha_inicio, km_inicial, estatus) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'programada')");
                $origen = 'Pendiente';
                $stmt->bind_param("sssssss", $numero_ruta, $chofer['nombre_chofer'], $auxiliar_nombre, $placas_ruta, $no_economico_ruta, $origen, $fecha_inicio);
                $stmt->execute();
                $ruta_id = $conn->insert_id;
                $stmt->close();
                
                $orden = 0;
                foreach ($destinos_seleccionados as $destino_id) {
                    $orden++;
                    $stmt = $conn->prepare("INSERT INTO paradas (ruta_id, orden, destino_id, km_actual) VALUES (?, ?, ?, NULL)");
                    $stmt->bind_param("iii", $ruta_id, $orden, $destino_id);
                    $stmt->execute();
                    $stmt->close();
                }
                
                $conn->query("UPDATE rutas SET numero_paradas = $orden WHERE id = $ruta_id");
                
                $_SESSION['mensaje_exito'] = "✅ Ruta creada correctamente con " . $orden . " destinos.";
            }
        } else {
            $_SESSION['mensaje_error'] = "❌ Debes seleccionar un chofer y al menos un destino.";
        }
        header("Location: ?seccion=rutas");
        exit;
    }

    // ========== PLACAS ==========
    if (isset($_POST['agregar_placa'])) {
        $placa = trim($_POST['placa']);
        if (!empty($placa)) $conn->query("INSERT INTO catalogo_placas (placa) VALUES ('$placa')");
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['eliminar_placa'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM catalogo_placas WHERE id = $id");
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['editar_placa'])) {
        $id = intval($_POST['id']); $placa = trim($_POST['placa']);
        $conn->query("UPDATE catalogo_placas SET placa = '$placa' WHERE id = $id");
        header("Location: ?seccion=catalogos");
        exit;
    }
    
    // ========== NÚMEROS ECONÓMICOS ==========
    if (isset($_POST['agregar_no_economico'])) {
        $no = trim($_POST['no_economico']);
        $no_normalizado = normalizarNoEconomico($no);
        if ($no_normalizado !== null && !empty($no_normalizado)) {
            $conn->query("INSERT INTO catalogo_no_economico (no_economico) VALUES ('$no_normalizado')");
        }
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['eliminar_no_economico'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM catalogo_no_economico WHERE id = $id");
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['editar_no_economico'])) {
        $id = intval($_POST['id']);
        $no = trim($_POST['no_economico']);
        $no_normalizado = normalizarNoEconomico($no);
        if ($no_normalizado !== null && !empty($no_normalizado)) {
            $conn->query("UPDATE catalogo_no_economico SET no_economico = '$no_normalizado' WHERE id = $id");
        }
        header("Location: ?seccion=catalogos");
        exit;
    }
    
    // ========== CHOFERES ==========
    if (isset($_POST['agregar_chofer'])) {
        $nombre = trim($_POST['nombre_chofer']);
        $placas = trim($_POST['placas_chofer']);
        $no_economico = trim($_POST['numero_economico_chofer']);
        $no_normalizado = normalizarNoEconomico($no_economico);
        if (!empty($nombre) && !empty($placas) && $no_normalizado !== null && !empty($no_normalizado)) {
            $stmt = $conn->prepare("INSERT INTO choferes (nombre_chofer, placas, numero_economico) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $nombre, $placas, $no_normalizado);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['editar_chofer'])) {
        $id = intval($_POST['id']);
        $nombre = trim($_POST['nombre_chofer']);
        $placas = trim($_POST['placas_chofer']);
        $no_economico = trim($_POST['numero_economico_chofer']);
        $no_normalizado = normalizarNoEconomico($no_economico);
        if (!empty($nombre) && !empty($placas) && $no_normalizado !== null && !empty($no_normalizado)) {
            $stmt = $conn->prepare("UPDATE choferes SET nombre_chofer = ?, placas = ?, numero_economico = ? WHERE id = ?");
            $stmt->bind_param("sssi", $nombre, $placas, $no_normalizado, $id);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['eliminar_chofer'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM choferes WHERE id = $id");
        header("Location: ?seccion=catalogos");
        exit;
    }

    // ========== AUXILIARES DE CONDUCTOR ==========
    if (isset($_POST['agregar_auxiliar'])) {
        $nombre = trim($_POST['nombre_auxiliar']);
        if (!empty($nombre)) {
            $stmt = $conn->prepare("INSERT INTO auxiliares (nombre, activo) VALUES (?, 1)");
            $stmt->bind_param("s", $nombre);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['editar_auxiliar'])) {
        $id = intval($_POST['id']);
        $nombre = trim($_POST['nombre_auxiliar']);
        if (!empty($nombre)) {
            $stmt = $conn->prepare("UPDATE auxiliares SET nombre = ? WHERE id = ?");
            $stmt->bind_param("si", $nombre, $id);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: ?seccion=catalogos");
        exit;
    }
    if (isset($_POST['eliminar_auxiliar'])) {
        $id = intval($_POST['id']);
        $conn->query("UPDATE auxiliares SET activo = 0 WHERE id = $id");
        header("Location: ?seccion=catalogos");
        exit;
    }
    
    // ========== DESTINOS ==========
    if (isset($_POST['agregar_destino'])) {
        $razon_social = trim($_POST['razon_social']);
        $sucursal = trim($_POST['sucursal']);
        $direccion = trim($_POST['direccion']);
        if (!empty($razon_social) && !empty($sucursal) && !empty($direccion)) {
            $stmt = $conn->prepare("INSERT INTO destinos (razon_social, sucursal, direccion) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $razon_social, $sucursal, $direccion);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: ?seccion=destinos");
        exit;
    }
    if (isset($_POST['editar_destino'])) {
        $id = intval($_POST['id']);
        $razon_social = trim($_POST['razon_social']);
        $sucursal = trim($_POST['sucursal']);
        $direccion = trim($_POST['direccion']);
        if (!empty($razon_social) && !empty($sucursal) && !empty($direccion)) {
            $stmt = $conn->prepare("UPDATE destinos SET razon_social = ?, sucursal = ?, direccion = ? WHERE id = ?");
            $stmt->bind_param("sssi", $razon_social, $sucursal, $direccion, $id);
            $stmt->execute();
            $stmt->close();
        }
        header("Location: ?seccion=destinos");
        exit;
    }
    if (isset($_POST['eliminar_destino'])) {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM destinos WHERE id = $id");
        header("Location: ?seccion=destinos");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>ACAREZ - Panel Administrativo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; }
        .header {
            background: #ffffff;
            height: 70px;
            display: flex;
            align-items: center;
            padding: 0 20px;
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .menu-btn {
            background: #4A148C;
            color: white;
            border: none;
            width: 40px; height: 40px;
            border-radius: 8px;
            margin-right: 15px;
            cursor: pointer;
            font-size: 20px;
        }
        .logo-header {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo-header img { height: 50px; width: auto; }
        .sidebar {
            position: fixed;
            top: 0; left: -280px;
            width: 280px; height: 100%;
            background: #3C096C;
            color: white;
            transition: left 0.3s ease;
            z-index: 200;
            padding-top: 80px;
        }
        .sidebar.open { left: 0; }
        .sidebar a {
            display: block; padding: 15px 25px;
            color: white; text-decoration: none;
            border-left: 4px solid transparent;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #6A1B9A;
            border-left-color: #ffc107;
        }
        .overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5); z-index: 150; display: none;
        }
        .overlay.show { display: block; }
        .main-content { margin-top: 80px; padding: 20px; }
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4A148C; color: white; }
        .tabla-catalogo th, .tabla-catalogo td { padding: 6px 12px; line-height: 1.4; vertical-align: middle; }
        .btn {
            padding: 8px 16px; border: none; border-radius: 6px;
            cursor: pointer; font-weight: bold; transition: 0.3s;
        }
        .btn-primary { background: #4A148C; color: white; }
        .btn-success { background: #6A1B9A; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #222; }
        .form-inline { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; align-items: center; }
        .form-inline input, .form-inline select { padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        #logoFijo {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 80px; height: 80px;
            z-index: 1000;
            cursor: pointer;
        }
        #logoFijo img { width: 100%; height: 100%; object-fit: contain; }
        .girar-logo {
            animation: girarInfinito 5s linear;
        }
        @keyframes girarInfinito {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .detalle-modal {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.85); z-index: 2000;
            display: none; align-items: center; justify-content: center;
        }
        .detalle-content {
            background: white; padding: 20px; border-radius: 15px;
            width: 95%; max-width: 1400px;
            max-height: 92vh;
            overflow-y: auto; position: relative;
        }
        .cerrar-modal {
            position: absolute; top: 15px; right: 20px;
            font-size: 28px; cursor: pointer; color: #999;
        }
        .cerrar-modal:hover { color: #333; }
        .grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 15px 0; }
        .seccion-viaje {
            background: #f8f9fa; padding: 12px; border-radius: 10px;
            border-top: 4px solid #4A148C;
        }
        .seccion-viaje-regreso { border-top-color: #6A1B9A; }
        .grupo-fotos {
            display: flex; flex-wrap: wrap; gap: 10px;
            margin: 10px 0; justify-content: center;
        }
        .grupo-fotos img {
            width: 80px; height: 80px;
            object-fit: cover; border-radius: 8px;
            cursor: pointer; border: 1px solid #ddd;
        }
        .resumen-dia { background-color: #f0f0f0; font-weight: bold; }
        .tabla-semana { min-width: 1000px; white-space: nowrap; }
        .tabla-semana th, .tabla-semana td { white-space: nowrap; padding: 8px 12px; }
        .btn-pequeno { padding: 4px 10px; font-size: 12px; }
        .error-msg { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; margin-bottom: 10px; }
        .success-msg { color: #28a745; background: #d4edda; padding: 10px; border-radius: 5px; margin-bottom: 10px; }
        
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-programada { background: #fff3cd; color: #856404; }
        .badge-activa { background: #d4edda; color: #155724; }
        .badge-completada { background: #cce5ff; color: #004085; }
        .badge-cancelada { background: #f8d7da; color: #721c24; }
        
        .seleccion-destinos {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 8px;
            background: #fafafa;
        }
        .seleccion-destinos label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 4px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            cursor: pointer;
        }
        .seleccion-destinos label:hover {
            background: #f0f0f0;
        }
        .seleccion-destinos input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #4A148C;
        }
        .campo-vehiculo {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .campo-vehiculo label {
            font-size: 12px;
            font-weight: bold;
            color: #4A148C;
        }
        .campo-vehiculo select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #f9f9f9;
            cursor: pointer;
            width: 100%;
        }
        .campo-vehiculo select:focus {
            background: #fff;
            border-color: #4A148C;
            outline: none;
        }
        .nota-vehiculo {
            font-size: 11px;
            color: #666;
            font-style: italic;
            margin-top: 4px;
        }
        @media (max-width: 768px) {
            .grid-2col { grid-template-columns: 1fr; }
            #logoFijo { width: 60px; height: 60px; }
            .logo-header img { height: 35px; }
        }
    </style>
</head>
<body>

<div class="header">
    <button class="menu-btn" onclick="toggleMenu()">☰</button>
    <div class="logo-header">
        <img src="imagenes/acarez_3.png" alt="Logo">
    </div>
</div>

<div class="sidebar" id="sidebar">
    <a href="?seccion=reportes" class="<?= $seccion == 'reportes' ? 'active' : '' ?>">📋 Viajes y Gastos</a>
    <a href="?seccion=rutas" class="<?= $seccion == 'rutas' ? 'active' : '' ?>">🔄 Rutas</a>
    <a href="?seccion=destinos" class="<?= $seccion == 'destinos' ? 'active' : '' ?>">📍 Destinos</a>
    <a href="?seccion=usuarios_app" class="<?= $seccion == 'usuarios_app' ? 'active' : '' ?>">📱 Accesos App Choferes</a>
    <a href="?seccion=catalogos" class="<?= $seccion == 'catalogos' ? 'active' : '' ?>">⚙️ Configuración</a>
</div>

<div class="overlay" id="overlay" onclick="toggleMenu()"></div>

<div id="logoFijo">
    <img src="imagenes/acarez_2.png" alt="Logo Acarez">
</div>

<div id="detalleModal" class="detalle-modal">
    <div class="detalle-content" id="modalBody">
        <span class="cerrar-modal" onclick="cerrarModal()">&times;</span>
        <p style="text-align:center;">Cargando detalles...</p>
    </div>
</div>

<div id="semanaModal" class="detalle-modal" style="display:none;">
    <div class="detalle-content" style="max-width: 1400px;">
        <span class="cerrar-modal" onclick="cerrarSemanaModal()">&times;</span>
        <h2 style="color:#4A148C;">📊 Detalle de Viajes por Semana</h2>
        <div id="semanaDetalleContent"></div>
        <div style="margin-top:20px; text-align:right; display:flex; justify-content:flex-end; gap:10px;">
            <button class="btn btn-primary" onclick="cerrarSemanaModal()">Cerrar</button>
        </div>
    </div>
</div>

<!-- VENTANA EMERGENTE (MODAL) PARA EDITAR CHOFER -->
<div id="editarChoferModal" class="detalle-modal" style="display:none;">
    <div class="detalle-content" style="max-width: 500px;">
        <span class="cerrar-modal" onclick="cerrarEditarChoferModal()">&times;</span>
        <h3 style="color:#4A148C; margin-bottom:15px;">✏️ Editar Chofer</h3>
        <form method="POST">
            <input type="hidden" name="id" id="modal_edit_chofer_id">
            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:bold;">Nombre del Chofer</label>
                <input type="text" name="nombre_chofer" id="modal_edit_nombre_chofer" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:bold;">Placas</label>
                <input type="text" name="placas_chofer" id="modal_edit_placas_chofer" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:bold;">Número Económico</label>
                <input type="text" name="numero_economico_chofer" id="modal_edit_noe_chofer" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="text-align:right; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-danger" onclick="cerrarEditarChoferModal()">Cancelar</button>
                <button type="submit" name="editar_chofer" class="btn btn-success">💾 Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- VENTANA EMERGENTE (MODAL) PARA EDITAR AUXILIAR -->
<div id="editarAuxiliarModal" class="detalle-modal" style="display:none;">
    <div class="detalle-content" style="max-width: 450px;">
        <span class="cerrar-modal" onclick="cerrarEditarAuxiliarModal()">&times;</span>
        <h3 style="color:#4A148C; margin-bottom:15px;">✏️ Editar Auxiliar de Conductor</h3>
        <form method="POST">
            <input type="hidden" name="id" id="modal_edit_auxiliar_id">
            <div style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:bold;">Nombre del Auxiliar</label>
                <input type="text" name="nombre_auxiliar" id="modal_edit_nombre_auxiliar" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="text-align:right; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-danger" onclick="cerrarEditarAuxiliarModal()">Cancelar</button>
                <button type="submit" name="editar_auxiliar" class="btn btn-success">💾 Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- VENTANA EMERGENTE (MODAL) PARA EDITAR DESTINO -->
<div id="editarDestinoModal" class="detalle-modal" style="display:none;">
    <div class="detalle-content" style="max-width: 550px;">
        <span class="cerrar-modal" onclick="cerrarEditarDestinoModal()">&times;</span>
        <h3 style="color:#4A148C; margin-bottom:15px;">✏️ Editar Destino / Cliente</h3>
        <form method="POST">
            <input type="hidden" name="id" id="modal_edit_destino_id">
            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:bold;">Razón Social</label>
                <input type="text" name="razon_social" id="modal_edit_razon_social" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px; font-weight:bold;">Sucursal</label>
                <input type="text" name="sucursal" id="modal_edit_sucursal" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:bold;">Dirección</label>
                <input type="text" name="direccion" id="modal_edit_direccion" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:5px;">
            </div>
            <div style="text-align:right; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-danger" onclick="cerrarEditarDestinoModal()">Cancelar</button>
                <button type="submit" name="editar_destino" class="btn btn-success">💾 Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<div id="imageModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:3000; align-items:center; justify-content:center;" onclick="cerrarImageModal()">
    <span style="position:absolute; top:20px; right:35px; color:white; font-size:40px; cursor:pointer;" onclick="cerrarImageModal()">&times;</span>
    <img id="modalImage" style="max-width:90%; max-height:90%; border-radius:10px;">
</div>

<div class="main-content">
    
    <?php if ($seccion == 'reportes'): ?>
        <?php
        $semana = $_GET['semana'] ?? '';
        $fecha_inicio = $_GET['fecha_inicio'] ?? '';
        $fecha_fin = $_GET['fecha_fin'] ?? '';
        $choferFiltro = $_GET['chofer'] ?? '';
        
        $filtros = [
            'semana' => $semana, 
            'fecha_inicio' => $fecha_inicio, 
            'fecha_fin' => $fecha_fin,
            'chofer' => $choferFiltro
        ];
        
        $reportes = obtenerReportes($conn, $filtros);
        $semanas = obtenerSemanasDisponibles($conn);
        $listaChoferesFiltro = $conn->query("SELECT DISTINCT nombre_chofer FROM choferes WHERE activo = 1 ORDER BY nombre_chofer ASC");
        ?>
        
        <div class="card">
            <h2>📊 Control y Auditoría de Viajes</h2>
            <form method="GET" class="form-inline" id="filtroForm" style="align-items: flex-end;">
                <input type="hidden" name="seccion" value="reportes">
                
                <div style="display:flex; flex-direction:column; gap:4px;">
                    <label style="font-size:12px; font-weight:bold;">Semana</label>
                    <select name="semana" id="semanaSelect">
                        <option value="">-- Filtrar por semana --</option>
                        <?php while($row = $semanas->fetch_assoc()): ?>
                            <option value="<?= $row['semana'] ?>" <?= ($semana == $row['semana']) ? 'selected' : '' ?>>
                                Semana <?= substr($row['semana'], -2) ?> (<?= $row['inicio'] ?> al <?= $row['fin'] ?>)
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div style="display:flex; flex-direction:column; gap:4px;">
                    <label style="font-size:12px; font-weight:bold;">Fecha Inicio</label>
                    <input type="date" name="fecha_inicio" value="<?= htmlspecialchars($fecha_inicio) ?>">
                </div>

                <div style="display:flex; flex-direction:column; gap:4px;">
                    <label style="font-size:12px; font-weight:bold;">Fecha Fin</label>
                    <input type="date" name="fecha_fin" value="<?= htmlspecialchars($fecha_fin) ?>">
                </div>

                <div style="display:flex; flex-direction:column; gap:4px;">
                    <label style="font-size:12px; font-weight:bold;">Filtrar por Chofer</label>
                    <select name="chofer" style="padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        <option value="">-- Todos los choferes --</option>
                        <?php while($ch = $listaChoferesFiltro->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($ch['nombre_chofer']) ?>" <?= ($choferFiltro == $ch['nombre_chofer']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ch['nombre_chofer']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:5px;">
                    <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
                    <a href="?seccion=reportes" class="btn btn-warning" style="text-decoration:none; display:inline-flex; align-items:center;">Limpiar</a>
                    <button type="button" class="btn btn-success" onclick="exportarExcel()">Exportar Excel</button>
                    <button type="button" class="btn btn-info" onclick="exportarCSV()">📱 Exportar CSV</button>
                    <button type="button" class="btn btn-danger" onclick="exportarPDF()">📄 Exportar PDF</button>
                    <button type="button" class="btn btn-success" id="btnDetalleSemana" style="background:#17a2b8; color:white;">📋 Detalle Semana</button>
                </div>
            </form>
        </div>
        
        <?php 
        $totalViajesCount = 0;
        $sumaKmTotales = 0;
        $sumaGastosGenerales = 0;
        $arrayReportes = [];
        while($r = $reportes->fetch_assoc()) {
            $totalViajesCount++;
            $sumaKmTotales += floatval($r['km_total'] ?? 0);
            $sumaGastosGenerales += floatval($r['total_general'] ?? 0);
            $arrayReportes[] = $r;
        }
        ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
            <div class="card" style="background: linear-gradient(135deg, #4A148C, #6A1B9A); color: white; margin-bottom:0;">
                <h5 style="font-size: 14px; opacity: 0.9;">Viajes / Rutas Filtradas</h5>
                <h3 style="font-size: 28px; margin-top: 5px;"><?= $totalViajesCount ?></h3>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #2e7d32, #4caf50); color: white; margin-bottom:0;">
                <h5 style="font-size: 14px; opacity: 0.9;">Gasto Total Comprobado</h5>
                <h3 style="font-size: 28px; margin-top: 5px;">$<?= number_format($sumaGastosGenerales, 2) ?></h3>
            </div>
            <div class="card" style="background: linear-gradient(135deg, #0277bd, #03a9f4); color: white; margin-bottom:0;">
                <h5 style="font-size: 14px; opacity: 0.9;">Kilómetros Recorridos</h5>
                <h3 style="font-size: 28px; margin-top: 5px;"><?= number_format($sumaKmTotales, 1) ?> km</h3>
            </div>
        </div>

        <?php if(empty($arrayReportes)): ?>
            <div class="card">No hay reportes para los filtros seleccionados.</div>
        <?php else: ?>
            <div class="card">
                <div style="overflow-x: auto;">
                    <table id="tablaReportes">
                        <thead>
                            <tr>
                                <th>ID Ruta</th>
                                <th>No. Ruta</th>
                                <th>Fecha Inicio</th>
                                <th>Chofer</th>
                                <th>Placas</th>
                                <th>No. Eco</th>
                                <th>Km Inicial</th>
                                <th>Km Final</th>
                                <th>Km Recorrido</th>
                                <th>Total Gastos</th>
                                <th>Estatus</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $fecha_actual = '';
                        $suma_km_dia = 0;
                        foreach($arrayReportes as $row): 
                            $km_inicial = isset($row['km_inicial']) ? floatval($row['km_inicial']) : 0;
                            $km_final   = isset($row['km_final']) ? floatval($row['km_final']) : 0;
                            $km_total   = isset($row['km_total']) ? floatval($row['km_total']) : 0;
                            $fecha_row = date('Y-m-d', strtotime($row['fecha_inicio']));
                            
                            if ($fecha_actual != '' && $fecha_actual != $fecha_row) {
                                echo '<tr class="resumen-dia">
                                        <td colspan="8" style="text-align:right;">Total Km del día ' . date('d/m/Y', strtotime($fecha_actual)) . ':</td>
                                        <td colspan="1">' . number_format($suma_km_dia, 0) . ' km</td>
                                        <td colspan="3"></td>
                                      </tr>';
                                $suma_km_dia = 0;
                            }
                            $fecha_actual = $fecha_row;
                            $suma_km_dia += $km_total;
                        ?>
                            <tr>
                                <td><strong>#<?= $row['id'] ?></strong></td>
                                <td><span style="font-weight:bold; color:#4A148C;"><?= htmlspecialchars($row['numero_ruta'] ?? '') ?></span></td>
                                <td><?= date('d/m/Y H:i', strtotime($row['fecha_inicio'])) ?></td>
                                <td><?= htmlspecialchars($row['chofer']) ?></td>
                                <td><?= htmlspecialchars($row['placas']) ?></td>
                                <td><?= htmlspecialchars($row['no_economico']) ?></td>
                                <td><?= number_format($km_inicial, 0) ?></td>
                                <td><?= number_format($km_final, 0) ?></td>
                                <td><?= number_format($km_total, 0) ?> km</td>
                                <td style="font-weight: bold; color: #4A148C;">$<?= number_format($row['total_general'], 2) ?></td>
                                <td>
                                    <?php 
                                        $est = strtolower(trim($row['estatus'] ?? 'programada'));
                                        $badgeClase = 'badge-programada';
                                        if ($est == 'completada') $badgeClase = 'badge-completada';
                                        elseif ($est == 'activa' || $est == 'en_proceso' || $est == 'en proceso' || $est == 'iniciada') $badgeClase = 'badge-activa';
                                        elseif ($est == 'cancelada') $badgeClase = 'badge-cancelada';
                                    ?>
                                    <span class="badge <?= $badgeClase ?>"><?= ucfirst($row['estatus'] ?? 'Programada') ?></span>
                                </td>
                                <td>
                                    <button class="btn btn-info btn-pequeno" onclick="verDetalleRuta(<?= $row['id'] ?>)">Ver Detalles</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($fecha_actual != ''): ?>
                            <tr class="resumen-dia">
                                <td colspan="8" style="text-align:right;">Total Km del día <?= date('d/m/Y', strtotime($fecha_actual)) ?>:</td>
                                <td colspan="1"><?= number_format($suma_km_dia, 0) ?> km</td>
                                <td colspan="3"></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        
    <?php elseif ($seccion == 'rutas'): ?>
        <?php
        if (isset($_SESSION['mensaje_exito'])) {
            echo '<div class="success-msg">' . $_SESSION['mensaje_exito'] . '</div>';
            unset($_SESSION['mensaje_exito']);
        }
        if (isset($_SESSION['mensaje_error'])) {
            echo '<div class="error-msg">' . $_SESSION['mensaje_error'] . '</div>';
            unset($_SESSION['mensaje_error']);
        }

        if (isset($_GET['eliminar_ruta']) && is_numeric($_GET['eliminar_ruta'])) {
            $ruta_id = intval($_GET['eliminar_ruta']);
            $conn->query("DELETE FROM paradas WHERE ruta_id = $ruta_id");
            $conn->query("DELETE FROM rutas WHERE id = $ruta_id");
            echo '<div class="success-msg">✅ Ruta eliminada correctamente.</div>';
        }
        
        // 🔥 Obtener choferes con sus placas y números económicos para el dropdown dinámico
        $choferes = $conn->query("SELECT id, nombre_chofer, placas, numero_economico FROM choferes WHERE activo = 1 ORDER BY nombre_chofer ASC");
        $choferes_data = [];
        while ($ch = $choferes->fetch_assoc()) {
            $choferes_data[] = $ch;
        }
        
        $auxiliares_lista = $conn->query("SELECT id, nombre FROM auxiliares WHERE activo = 1 ORDER BY nombre ASC");
        
        // 🔥 NUEVO: Cargar catálogos de placas y números económicos
        $catalogo_placas_ruta = $conn->query("SELECT placa FROM catalogo_placas ORDER BY placa ASC");
        $catalogo_no_economico_ruta = $conn->query("SELECT no_economico FROM catalogo_no_economico ORDER BY no_economico ASC");
        
        $destinos = $conn->query("SELECT id, razon_social, sucursal, direccion FROM destinos WHERE activo = 1 ORDER BY razon_social ASC");
        
        $filtro_chofer = $_GET['filtro_chofer'] ?? '';
        $filtro_estatus = $_GET['filtro_estatus'] ?? '';
        $filtros = [];
        if (!empty($filtro_chofer)) $filtros['chofer'] = $filtro_chofer;
        if (!empty($filtro_estatus)) $filtros['estatus'] = $filtro_estatus;
        $rutas = obtenerRutas($conn, $filtros);
        ?>
        
        <div class="card">
            <h2>➕ Crear Nueva Ruta</h2>
            <form method="POST" style="display: flex; flex-direction: column; gap: 15px; margin-top: 15px;">
                <input type="hidden" name="crear_ruta" value="1">
                
                <!-- 🔥 NUEVO: Layout en Grid para alinear todo uniformemente -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: flex-end;">
                    
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:12px; font-weight:bold; color:#4A148C;">📌 Número de Ruta:</label>
                        <input type="text" name="numero_ruta" placeholder="Ej. R-01" required style="padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                    </div>

                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:12px; font-weight:bold; color:#4A148C;">👤 Chofer:</label>
                        <select name="chofer_id" id="choferSelect" required style="padding: 8px; border: 1px solid #ddd; border-radius: 5px;" onchange="autocompletarVehiculo()">
                            <option value="">-- Seleccionar Chofer --</option>
                            <?php foreach($choferes_data as $ch): ?>
                                <option value="<?= $ch['id'] ?>" 
                                        data-placas="<?= htmlspecialchars($ch['placas']) ?>" 
                                        data-no-economico="<?= htmlspecialchars($ch['numero_economico']) ?>">
                                    <?= htmlspecialchars($ch['nombre_chofer']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo-vehiculo">
                        <label for="placas_ruta">🚛 Placas (catálogo)</label>
                        <select name="placas_ruta" id="placas_ruta">
                            <option value="">-- Seleccionar placa --</option>
                            <?php while($cp = $catalogo_placas_ruta->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($cp['placa']) ?>"><?= htmlspecialchars($cp['placa']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="campo-vehiculo">
                        <label for="no_economico_ruta">🔢 No. Económico</label>
                        <select name="no_economico_ruta" id="no_economico_ruta">
                            <option value="">-- Seleccionar No. Eco --</option>
                            <?php while($cne = $catalogo_no_economico_ruta->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($cne['no_economico']) ?>"><?= htmlspecialchars($cne['no_economico']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:12px; font-weight:bold; color:#4A148C;">🤝 Auxiliar (Opcional):</label>
                        <select name="auxiliar_nombre" style="padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                            <option value="">-- Sin Auxiliar --</option>
                            <?php while($aux = $auxiliares_lista->fetch_assoc()): ?>
                                <option value="<?= htmlspecialchars($aux['nombre']) ?>"><?= htmlspecialchars($aux['nombre']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label style="font-size:12px; font-weight:bold; color:#4A148C;">📅 Fecha de Ruta:</label>
                        <input type="date" name="fecha_ruta" value="<?= date('Y-m-d') ?>" required style="padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                    </div>

                    <div style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn btn-success" style="width: 100%; height: 35px; margin: 0;">➕ Crear Ruta</button>
                    </div>

                </div>
                
                <div style="width: 100%; margin-top: 5px;">
                    <p class="nota-vehiculo">💡 Al seleccionar un chofer, se cargarán automáticamente sus placas y número económico desde el catálogo. Puedes cambiarlos si ese día usa otro vehículo. <strong>Esto NO afecta la tabla de choferes.</strong></p>
                </div>
                
                <div style="width: 100%; margin-top: 10px;">
                    <p><strong>Selecciona los destinos para esta ruta:</strong> 
                    <span style="color:#666; font-size:12px;">(marca los clientes en el orden que debe visitarlos el chofer)</span></p>
                    
                    <input type="text" id="filtroSeleccionDestinos" placeholder="🔍 Escribe para filtrar clientes o sucursales..." onkeyup="filtrarSeleccionDestinos()" style="width: 100%; padding: 8px; margin: 8px 0; border: 1px solid #ddd; border-radius: 6px;">

                    <div class="seleccion-destinos">
                        <?php if($destinos->num_rows == 0): ?>
                            <p style="color: #999; text-align:center; padding:20px;">No hay destinos registrados. Ve a la sección <strong>Destinos</strong> para agregar.</p>
                        <?php else: ?>
                            <?php while($row = $destinos->fetch_assoc()): ?>
                                <label>
                                    <input type="checkbox" name="destinos[]" value="<?= $row['id'] ?>">
                                    <span><?= htmlspecialchars($row['razon_social']) ?> - <?= htmlspecialchars($row['sucursal']) ?></span>
                                    <span style="color:#999; font-size:11px; margin-left:auto;"><?= htmlspecialchars($row['direccion']) ?></span>
                                </label>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top:10px; font-size:12px; color:#666;">
                        <span id="contadorDestinos">0</span> destinos seleccionados en orden de visita
                    </div>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2>🔄 Rutas Programadas</h2>
            <form method="GET" class="form-inline">
                <input type="hidden" name="seccion" value="rutas">
                <input type="text" name="filtro_chofer" placeholder="Filtrar por chofer" value="<?= htmlspecialchars($filtro_chofer) ?>">
                <select name="filtro_estatus">
                    <option value="">-- Todos --</option>
                    <option value="programada" <?= $filtro_estatus == 'programada' ? 'selected' : '' ?>>Programada</option>
                    <option value="activa" <?= $filtro_estatus == 'activa' ? 'selected' : '' ?>>Activa</option>
                    <option value="completada" <?= $filtro_estatus == 'completada' ? 'selected' : '' ?>>Completada</option>
                    <option value="cancelada" <?= $filtro_estatus == 'cancelada' ? 'selected' : '' ?>>Cancelada</option>
                </select>
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="?seccion=rutas" class="btn btn-primary">Limpiar</a>
            </form>
            
            <?php if($rutas->num_rows == 0): ?>
                <p style="margin-top:15px;">No hay rutas registradas.</p>
            <?php else: ?>
                <div style="overflow-x: auto; margin-top:15px;">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>No. Ruta</th>
                                <th>Chofer</th>
                                <th>Auxiliar</th>
                                <th>Placas</th>
                                <th>Fecha</th>
                                <th>Destinos</th>
                                <th>Km Total</th>
                                <th>Gastos</th>
                                <th>Estatus</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $rutas->fetch_assoc()): 
                                $destinos_ruta = $conn->query("
                                    SELECT p.*, d.razon_social, d.sucursal 
                                    FROM paradas p 
                                    LEFT JOIN destinos d ON p.destino_id = d.id 
                                    WHERE p.ruta_id = {$row['id']} 
                                    ORDER BY p.orden ASC
                                ");
                                $destinos_list = [];
                                while($d = $destinos_ruta->fetch_assoc()) {
                                    $nombre = $d['razon_social'] ?? $d['destino_manual'] ?? 'Destino';
                                    $destinos_list[] = $nombre;
                                }
                                $destinos_text = implode(', ', array_slice($destinos_list, 0, 3));
                                if (count($destinos_list) > 3) $destinos_text .= ' +' . (count($destinos_list) - 3) . ' más';
                            ?>
                            <tr>
                                <td><?= $row['id'] ?></td>
                                <td><strong><?= htmlspecialchars($row['numero_ruta'] ?? '') ?></strong></td>
                                <td><?= htmlspecialchars($row['chofer']) ?></td>
                                <td><?= !empty($row['auxiliar']) ? htmlspecialchars($row['auxiliar']) : '<span style="color:#aaa;">Sin auxiliar</span>' ?></td>
                                <td><?= htmlspecialchars($row['placas']) ?></td>
                                <td><?= date('d/m/Y', strtotime($row['fecha_inicio'])) ?></td>
                                <td><?= $destinos_text ?></td>
                                <td><?= number_format($row['km_total'] ?? 0, 0) ?> km</td>
                                <td>$<?= number_format($row['total_gastos'] ?? 0, 2) ?></td>
                                <td>
                                    <span class="badge badge-<?= $row['estatus'] ?>">
                                        <?= ucfirst($row['estatus']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-info btn-pequeno" onclick="verDetalleRuta(<?= $row['id'] ?>)">Ver Detalle</button>
                                    <a href="?seccion=rutas&eliminar_ruta=<?= $row['id'] ?>" 
                                       class="btn btn-danger btn-pequeno" 
                                       onclick="return confirm('¿Eliminar esta ruta y todas sus paradas?')">🗑️</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
    <?php elseif ($seccion == 'destinos'): ?>
        <?php $destinos = obtenerDestinos($conn); ?>
        
        <div class="card">
            <h2>📍 Destinos (Clientes)</h2>
            
            <form method="POST" class="form-inline">
                <input type="text" name="razon_social" placeholder="Razón Social" required>
                <input type="text" name="sucursal" placeholder="Sucursal" required>
                <input type="text" name="direccion" placeholder="Dirección" required>
                <button type="submit" name="agregar_destino" class="btn btn-success">➕ Agregar</button>
            </form>

            <div style="margin: 15px 0;">
                <input type="text" id="filtroTablaDestinos" placeholder="🔍 Buscar por Razón Social o Sucursal para validar duplicados..." onkeyup="filtrarTablaDestinos()" style="width: 100%; max-width: 400px; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
            </div>

            <div style="overflow-x: auto;">
                <table id="tablaDestinos">
                    <thead>
                        <tr><th>ID</th><th>Razón Social</th><th>Sucursal</th><th>Dirección</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php while($row = $destinos->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td id="ds_razon_<?= $row['id'] ?>"><?= htmlspecialchars($row['razon_social']) ?></td>
                            <td id="ds_sucursal_<?= $row['id'] ?>"><?= htmlspecialchars($row['sucursal']) ?></td>
                            <td id="ds_direccion_<?= $row['id'] ?>"><?= htmlspecialchars($row['direccion']) ?></td>
                            <td>
                                <button class="btn btn-warning btn-pequeno" onclick="editarDestino(<?= $row['id'] ?>)">✏️</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="eliminar_destino" class="btn btn-danger btn-pequeno" onclick="return confirm('¿Eliminar este destino?')">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($seccion == 'usuarios_app'): ?>
        <?php
        if (isset($_SESSION['mensaje_exito'])) {
            echo '<div class="success-msg">' . $_SESSION['mensaje_exito'] . '</div>';
            unset($_SESSION['mensaje_exito']);
        }
        if (isset($_SESSION['mensaje_error'])) {
            echo '<div class="error-msg">' . $_SESSION['mensaje_error'] . '</div>';
            unset($_SESSION['mensaje_error']);
        }

        try {
            $stmt_u = $pdo->query("SELECT * FROM choferes ORDER BY id DESC");
            $choferes_app = $stmt_u->fetchAll();
        } catch (Exception $e) {
            $choferes_app = [];
        }
        ?>
        <div class="card">
            <h2>📱 Gestión de Accesos App y Choferes</h2>
            <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Administra los choferes, sus PINs de acceso a la aplicación móvil, placas y número económico.</p>

            <div class="card shadow-sm mb-4" style="background: #fafafa; border: 1px solid #eee;">
                <div class="card-body">
                    <h5 class="mb-3" style="color: #4A148C; font-size: 16px;">Registrar o Modificar Chofer</h5>
                    <form method="POST" class="row g-3" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <input type="hidden" name="accion_usuario_app" value="guardar">
                        <input type="hidden" name="id" id="form-id">

                        <div style="flex: 2; min-width: 200px;">
                            <label style="font-size: 12px; font-weight: bold;">Nombre Completo</label>
                            <input type="text" class="form-control" name="nombre_chofer" id="form-nombre" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="flex: 1; min-width: 130px;">
                            <label style="font-size: 12px; font-weight: bold;">PIN (Contraseña)</label>
                            <input type="text" class="form-control" name="pin_password" id="form-pin" maxlength="6" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="flex: 1; min-width: 130px;">
                            <label style="font-size: 12px; font-weight: bold;">Placas</label>
                            <input type="text" class="form-control" name="placas" id="form-placas" value="PENDIENTE" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div style="flex: 1; min-width: 130px;">
                            <label style="font-size: 12px; font-weight: bold;">No. Económico</label>
                            <input type="text" class="form-control" name="numero_economico" id="form-no-eco" value="PENDIENTE" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div>
                            <button type="submit" class="btn btn-success" style="background-color: #4A148C; border: none; height: 38px;">💾 Guardar</button>
                            <button type="button" class="btn btn-warning" onclick="limpiarFormChoferApp()" style="height: 38px; display:none;" id="btnCancelarEdicion">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr style="background: #4A148C; color: white;">
                            <th>ID</th>
                            <th>Nombre Completo</th>
                            <th>PIN</th>
                            <th>Placas</th>
                            <th>No. Económico</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($choferes_app)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:20px; color:#888;">No hay choferes registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($choferes_app as $c): ?>
                                <tr>
                                    <td><?= $c['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($c['nombre_chofer']) ?></strong></td>
                                    <td><code><?= htmlspecialchars($c['pin_password'] ?? '1234') ?></code></td>
                                    <td><?= htmlspecialchars($c['placas']) ?></td>
                                    <td><?= htmlspecialchars($c['numero_economico']) ?></td>
                                    <td style="text-align: right;">
                                        <button class="btn btn-warning btn-pequeno" onclick="editarChoferApp(<?= $c['id'] ?>, '<?= htmlspecialchars($c['nombre_chofer'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['pin_password'] ?? '1234', ENT_QUOTES) ?>', '<?= htmlspecialchars($c['placas'], ENT_QUOTES) ?>', '<?= htmlspecialchars($c['numero_economico'], ENT_QUOTES) ?>')">✏️ Editar</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de eliminar este chofer?');">
                                            <input type="hidden" name="accion_usuario_app" value="eliminar">
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-pequeno">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php elseif ($seccion == 'catalogos'): ?>
        <?php 
        $catalogos = manejarCatalogos($conn);
        if (isset($_SESSION['error_catalogo'])) {
            echo '<div class="error-msg">' . $_SESSION['error_catalogo'] . '</div>';
            unset($_SESSION['error_catalogo']);
        }
        if (isset($_SESSION['error_chofer'])) {
            echo '<div class="error-msg">' . $_SESSION['error_chofer'] . '</div>';
            unset($_SESSION['error_chofer']);
        }
        ?>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="card">
                <h2>📋 Placas</h2>
                <form method="POST" class="form-inline">
                    <input type="text" name="placa" placeholder="Nueva placa" required>
                    <button type="submit" name="agregar_placa" class="btn btn-success">➕ Agregar</button>
                </form>
                <table class="tabla-catalogo">
                    <thead><tr><th>ID</th><th>Placa</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php while($row = $catalogos['placas']->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td id="placa_<?= $row['id'] ?>"><?= htmlspecialchars($row['placa']) ?></td>
                            <td><button class="btn btn-warning btn-pequeno" onclick="editarPlaca(<?= $row['id'] ?>, '<?= $row['placa'] ?>')">✏️</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="eliminar_placa" class="btn btn-danger btn-pequeno" onclick="return confirm('¿Eliminar?')">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h2>🔢 Números Económicos</h2>
                <form method="POST" class="form-inline">
                    <input type="text" name="no_economico" placeholder="Ej: A-01" required>
                    <button type="submit" name="agregar_no_economico" class="btn btn-success">➕ Agregar</button>
                </form>
                <table class="tabla-catalogo">
                    <thead><tr><th>ID</th><th>Número Económico</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php while($row = $catalogos['no_economicos']->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td id="noe_<?= $row['id'] ?>"><?= htmlspecialchars($row['no_economico']) ?></td>
                            <td><button class="btn btn-warning btn-pequeno" onclick="editarNoEconomico(<?= $row['id'] ?>, '<?= $row['no_economico'] ?>')">✏️</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="eliminar_no_economico" class="btn btn-danger btn-pequeno" onclick="return confirm('¿Eliminar?')">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top:20px;">
            <div class="card">
                <h2>👤 Choferes</h2>
                <form method="POST" class="form-inline">
                    <input type="text" name="nombre_chofer" placeholder="Nombre del chofer" required style="flex:2;">
                    <input type="text" name="placas_chofer" placeholder="Placas" required style="flex:1;">
                    <input type="text" name="numero_economico_chofer" placeholder="Ej: A-01" required style="flex:1;">
                    <button type="submit" name="agregar_chofer" class="btn btn-success">➕ Agregar</button>
                </form>
                <table class="tabla-catalogo">
                    <thead><tr><th>ID</th><th>Nombre</th><th>Placas</th><th>No. Económico</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php while($row = $catalogos['choferes']->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td id="chofer_nombre_<?= $row['id'] ?>"><?= htmlspecialchars($row['nombre_chofer']) ?></td>
                            <td id="chofer_placas_<?= $row['id'] ?>"><?= htmlspecialchars($row['placas']) ?></td>
                            <td id="chofer_noe_<?= $row['id'] ?>"><?= htmlspecialchars($row['numero_economico']) ?></td>
                            <td>
                                <button class="btn btn-warning btn-pequeno" onclick="editarChofer(<?= $row['id'] ?>)">✏️</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="eliminar_chofer" class="btn btn-danger btn-pequeno" onclick="return confirm('¿Eliminar este chofer?')">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="card">
                <h2>🤝 Auxiliares de Conductor</h2>
                <form method="POST" class="form-inline">
                    <input type="text" name="nombre_auxiliar" placeholder="Nombre del auxiliar" required style="flex:2;">
                    <button type="submit" name="agregar_auxiliar" class="btn btn-success">➕ Agregar</button>
                </form>
                <table class="tabla-catalogo">
                    <thead><tr><th>ID</th><th>Nombre Auxiliar</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php while($row = $catalogos['auxiliares']->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['id'] ?></td>
                            <td id="aux_nombre_<?= $row['id'] ?>"><?= htmlspecialchars($row['nombre']) ?></td>
                            <td>
                                <button class="btn btn-warning btn-pequeno" onclick="editarAuxiliar(<?= $row['id'] ?>)">✏️</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="eliminar_auxiliar" class="btn btn-danger btn-pequeno" onclick="return confirm('¿Eliminar este auxiliar?')">🗑️</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('show');
    girarLogoInferior();
}

function girarLogoInferior() {
    const logo = document.getElementById('logoFijo');
    logo.classList.remove('girar-logo');
    void logo.offsetWidth; 
    logo.classList.add('girar-logo');
    setTimeout(() => logo.classList.remove('girar-logo'), 5000);
}

// 🔥 Autocompletar placas y número económico al seleccionar chofer (basado en catálogos)
function autocompletarVehiculo() {
    const select = document.getElementById('choferSelect');
    const opcionSeleccionada = select.options[select.selectedIndex];
    
    const placas = opcionSeleccionada.getAttribute('data-placas') || '';
    const noEconomico = opcionSeleccionada.getAttribute('data-no-economico') || '';
    
    const selectPlacas = document.getElementById('placas_ruta');
    const selectNoEco = document.getElementById('no_economico_ruta');
    
    // Buscar la opción que coincida con la placa del chofer
    let placaEncontrada = false;
    for (let i = 0; i < selectPlacas.options.length; i++) {
        if (selectPlacas.options[i].value === placas) {
            selectPlacas.selectedIndex = i;
            placaEncontrada = true;
            break;
        }
    }
    // Si la placa del chofer no está en el catálogo, agregarla temporalmente
    if (!placaEncontrada && placas !== '') {
        // Eliminar opciones temporales anteriores
        for (let i = selectPlacas.options.length - 1; i >= 0; i--) {
            if (selectPlacas.options[i].textContent.includes('(del chofer)')) {
                selectPlacas.remove(i);
            }
        }
        const nuevaOpcion = document.createElement('option');
        nuevaOpcion.value = placas;
        nuevaOpcion.textContent = placas + ' (del chofer)';
        nuevaOpcion.selected = true;
        selectPlacas.appendChild(nuevaOpcion);
    }
    
    // Buscar la opción que coincida con el número económico del chofer
    let noEcoEncontrado = false;
    for (let i = 0; i < selectNoEco.options.length; i++) {
        if (selectNoEco.options[i].value === noEconomico) {
            selectNoEco.selectedIndex = i;
            noEcoEncontrado = true;
            break;
        }
    }
    // Si el número económico del chofer no está en el catálogo, agregarlo temporalmente
    if (!noEcoEncontrado && noEconomico !== '') {
        // Eliminar opciones temporales anteriores
        for (let i = selectNoEco.options.length - 1; i >= 0; i--) {
            if (selectNoEco.options[i].textContent.includes('(del chofer)')) {
                selectNoEco.remove(i);
            }
        }
        const nuevaOpcion = document.createElement('option');
        nuevaOpcion.value = noEconomico;
        nuevaOpcion.textContent = noEconomico + ' (del chofer)';
        nuevaOpcion.selected = true;
        selectNoEco.appendChild(nuevaOpcion);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    girarLogoInferior();

    const contenedor = document.querySelector('.seleccion-destinos');
    if (contenedor) {
        let ordenClicks = [];
        
        contenedor.querySelectorAll('input[type="checkbox"]').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                const contador = document.querySelectorAll('input[name="destinos[]"]:checked').length;
                document.getElementById('contadorDestinos').textContent = contador;

                if (this.checked) {
                    ordenClicks.push(this.value);
                    this.closest('label').style.background = '#e8f5e9';
                    contenedor.appendChild(this.closest('label'));
                } else {
                    ordenClicks = ordenClicks.filter(val => val !== this.value);
                    this.closest('label').style.background = 'transparent';
                }
            });
        });
    }
});

function filtrarTablaDestinos() {
    let input = document.getElementById('filtroTablaDestinos').value.toLowerCase();
    let table = document.getElementById('tablaDestinos');
    let tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        let tdRazon = tr[i].getElementsByTagName('td')[1];
        let tdSucursal = tr[i].getElementsByTagName('td')[2];
        if (tdRazon || tdSucursal) {
            let textoRazon = tdRazon.textContent || tdRazon.innerText;
            let textoSucursal = tdSucursal.textContent || tdSucursal.innerText;
            if (textoRazon.toLowerCase().indexOf(input) > -1 || textoSucursal.toLowerCase().indexOf(input) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }       
    }
}

function filtrarSeleccionDestinos() {
    let input = document.getElementById('filtroSeleccionDestinos').value.toLowerCase();
    let contenedor = document.querySelector('.seleccion-destinos');
    if (!contenedor) return;
    let labels = contenedor.getElementsByTagName('label');

    for (let i = 0; i < labels.length; i++) {
        let text = labels[i].textContent || labels[i].innerText;
        if (text.toLowerCase().indexOf(input) > -1) {
            labels[i].style.display = "flex";
        } else {
            labels[i].style.display = "none";
        }
    }
}

function exportarExcel() {
    let params = new URLSearchParams(window.location.search);
    window.location.href = 'exportar_excel.php?' + params.toString();
}

function exportarCSV() {
    let params = new URLSearchParams(window.location.search);
    window.location.href = 'exportar_csv.php?' + params.toString();
}

function exportarPDF() {
    let params = new URLSearchParams(window.location.search);
    window.location.href = 'exportar_pdf_tcpdf.php?' + params.toString();
}

function cerrarModal() {
    document.getElementById('detalleModal').style.display = 'none';
}

function cerrarSemanaModal() {
    document.getElementById('semanaModal').style.display = 'none';
}

function cerrarImageModal() {
    const modal = document.getElementById('imageModal');
    modal.style.display = 'none';
}

function verImagenGrandeConMarca(src, titulo, datos) {
    const modal = document.getElementById('imageModal');
    modal.style.display = 'flex';
    
    const datosJSON = encodeURIComponent(JSON.stringify(datos));
    
    let htmlDatos = `<div style="font-size:14px; font-weight:bold; color:#ffc107; margin-bottom:4px;">${titulo}</div>`;
    Object.keys(datos).forEach(key => {
        htmlDatos += `<div><strong>${key}:</strong> ${datos[key]}</div>`;
    });

    modal.innerHTML = `
        <span style="position:absolute; top:20px; right:35px; color:white; font-size:40px; cursor:pointer; z-index:3001;" onclick="cerrarImageModal()">&times;</span>
        
        <div style="position:relative; max-width:90%; max-height:85vh; display:inline-block; text-align:center;" onclick="event.stopPropagation()">
            <img id="modalImage" src="${src}" crossorigin="anonymous" style="max-width:100%; max-height:75vh; border-radius:10px; display:block; margin:0 auto;">
            
            <div style="position:absolute; bottom:15px; left:15px; right:15px; display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; pointer-events:none;">
                
                <div style="background:rgba(0,0,0,0.85); color:#ffffff; padding:10px 14px; border-radius:8px; font-size:13px; line-height:1.3; font-family:sans-serif; border-left:4px solid #4A148C; box-shadow:0 3px 10px rgba(0,0,0,0.5); text-align:left; pointer-events:auto; max-width:70%;">
                    ${htmlDatos}
                </div>

                <button onclick="descargarFotoConMarca('${src}', '${titulo}', '${datosJSON}')" class="btn btn-success" style="background:#2e7d32; padding:10px 16px; font-size:13px; cursor:pointer; border:none; border-radius:6px; color:white; font-weight:bold; white-space:nowrap; pointer-events:auto; box-shadow:0 3px 10px rgba(0,0,0,0.5);">
                    📥 Descargar Fotografía
                </button>
            </div>
        </div>
    `;
}

function descargarFotoConMarca(src, titulo, datosEncoded) {
    const datos = JSON.parse(decodeURIComponent(datosEncoded));
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.src = src;
    
    img.onload = function() {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        canvas.width = img.naturalWidth;
        canvas.height = img.naturalHeight;
        
        ctx.drawImage(img, 0, 0);
        
        const escala = Math.max(canvas.width / 1000, 1);
        const fontSize = Math.round(18 * escala);
        const padding = Math.round(15 * escala);
        const lineHeight = Math.round(24 * escala);
        
        const lineas = [titulo];
        Object.keys(datos).forEach(key => {
            lineas.push(`${key}: ${datos[key]}`);
        });
        
        ctx.font = `bold ${fontSize}px sans-serif`;
        let maxTextoWidth = 0;
        lineas.forEach(l => {
            const w = ctx.measureText(l).width;
            if (w > maxTextoWidth) maxTextoWidth = w;
        });
        
        const boxWidth = maxTextoWidth + (padding * 2);
        const boxHeight = (lineas.length * lineHeight) + (padding * 2);
        const posX = Math.round(20 * escala);
        const posY = canvas.height - boxHeight - Math.round(20 * escala);
        
        ctx.fillStyle = 'rgba(0, 0, 0, 0.85)';
        ctx.fillRect(posX, posY, boxWidth, boxHeight);
        
        ctx.fillStyle = '#4A148C';
        ctx.fillRect(posX, posY, Math.round(6 * escala), boxHeight);
        
        lineas.forEach((linea, index) => {
            if (index === 0) {
                ctx.fillStyle = '#FFC107';
                ctx.font = `bold ${fontSize}px sans-serif`;
            } else {
                ctx.fillStyle = '#FFFFFF';
                ctx.font = `${fontSize}px sans-serif`;
            }
            const textY = posY + padding + ((index + 1) * lineHeight) - Math.round(5 * escala);
            ctx.fillText(linea, posX + padding + Math.round(6 * escala), textY);
        });
        
        const enlace = document.createElement('a');
        enlace.download = `comprobante_acarez_${Date.now()}.jpg`;
        enlace.href = canvas.toDataURL('image/jpeg', 0.95);
        enlace.click();
    };
}

function editarChofer(id) {
    document.getElementById('modal_edit_chofer_id').value = id;
    document.getElementById('modal_edit_nombre_chofer').value = document.getElementById('chofer_nombre_' + id).innerText;
    document.getElementById('modal_edit_placas_chofer').value = document.getElementById('chofer_placas_' + id).innerText;
    document.getElementById('modal_edit_noe_chofer').value = document.getElementById('chofer_noe_' + id).innerText;
    document.getElementById('editarChoferModal').style.display = 'flex';
}

function cerrarEditarChoferModal() {
    document.getElementById('editarChoferModal').style.display = 'none';
}

function editarAuxiliar(id) {
    document.getElementById('modal_edit_auxiliar_id').value = id;
    document.getElementById('modal_edit_nombre_auxiliar').value = document.getElementById('aux_nombre_' + id).innerText;
    document.getElementById('editarAuxiliarModal').style.display = 'flex';
}

function cerrarEditarAuxiliarModal() {
    document.getElementById('editarAuxiliarModal').style.display = 'none';
}

function editarDestino(id) {
    document.getElementById('modal_edit_destino_id').value = id;
    document.getElementById('modal_edit_razon_social').value = document.getElementById('ds_razon_' + id).innerText;
    document.getElementById('modal_edit_sucursal').value = document.getElementById('ds_sucursal_' + id).innerText;
    document.getElementById('modal_edit_direccion').value = document.getElementById('ds_direccion_' + id).innerText;
    document.getElementById('editarDestinoModal').style.display = 'flex';
}

function cerrarEditarDestinoModal() {
    document.getElementById('editarDestinoModal').style.display = 'none';
}

function editarChoferApp(id, nombre, pin, placas, noEco) {
    document.getElementById('form-id').value = id;
    document.getElementById('form-nombre').value = nombre;
    document.getElementById('form-pin').value = pin;
    document.getElementById('form-placas').value = placas;
    document.getElementById('form-no-eco').value = noEco;
    document.getElementById('btnCancelarEdicion').style.display = 'inline-block';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function limpiarFormChoferApp() {
    document.getElementById('form-id').value = '';
    document.getElementById('form-nombre').value = '';
    document.getElementById('form-pin').value = '';
    document.getElementById('form-placas').value = 'PENDIENTE';
    document.getElementById('form-no-eco').value = 'PENDIENTE';
    document.getElementById('btnCancelarEdicion').style.display = 'none';
}

function verDetalleRuta(id) {
    const modal = document.getElementById('detalleModal');
    const body = document.getElementById('modalBody');
    body.innerHTML = '<span class="cerrar-modal" onclick="cerrarModal()">&times;</span><div style="text-align:center; padding:40px;">Cargando detalles...</div>';
    modal.style.display = 'flex';
    
    fetch('?accion=get_ruta_data&ruta_id=' + id)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<span class="cerrar-modal" onclick="cerrarModal()">&times;</span><div style="padding:20px; color:red;">Error al cargar los datos</div>';
                return;
            }
            const r = data.ruta;
            const paradas = data.paradas || [];
            const gastos = data.gastos || [];
            const cucas = data.cucas || [];
            const numRuta = r.numero_ruta ? r.numero_ruta : '#' + r.id;
            
            const prepararSrc = (img) => {
                if (!img || img.trim() === '') return '';
                if (!img.startsWith('/') && !img.startsWith('http')) return '/' + img;
                return img;
            };

            const fotoInicioSrc = prepararSrc(r.foto_inicio);
            const fotoFinSrc = prepararSrc(r.foto_fin);

            const datosOdoInicio = JSON.stringify({ "Ruta": numRuta, "Chofer": r.chofer, "Km Inicial": (r.km_inicial || 0) + ' km', "Fecha": r.fecha_inicio || '' }).replace(/"/g, '&quot;');
            const datosOdoFin = JSON.stringify({ "Ruta": numRuta, "Chofer": r.chofer, "Km Final": (r.km_final || 0) + ' km', "Total Recorrido": (r.km_total || 0) + ' km', "Fecha": r.fecha_fin || r.fecha_inicio || '' }).replace(/"/g, '&quot;');

            let paradasHtml = paradas.length === 0 ? '<p>No hay paradas registradas</p>' : '';
            
            paradas.forEach((p) => {
                const estatusLwr = p.estatus ? p.estatus.toLowerCase().trim() : '';
                const esCompletado = estatusLwr === 'completado' || estatusLwr === 'completada' || p.completada == 1;
                
                const destinoNombre = p.razon_social ? `${p.razon_social} - ${p.sucursal}` : (p.destino_manual || 'Destino');
                const gastosParada = gastos.filter(g => g.parada_id == p.id);
                const cucasParada = cucas.filter(c => c.parada_id == p.id);
                
                // 💰 GASTOS: Foto a la izquierda, detalles u observaciones a la derecha
                let gastosDetalleHtml = '';
                if (gastosParada.length > 0) {
                    gastosDetalleHtml = '<div style="margin-top:8px; padding-left:12px; border-left:3px solid #4A148C; font-size:13px;">';
                    gastosDetalleHtml += '<p style="font-weight:bold; color:#4A148C; margin-bottom:6px;">💰 Gastos:</p>';
                    gastosParada.forEach(gp => {
                        const obsGasto = (gp.observaciones && gp.observaciones.trim() !== '') ? gp.observaciones : 'Sin observaciones';
                        
                        gastosDetalleHtml += `<div style="margin-bottom: 8px; display: flex; align-items: flex-start; gap: 10px; background: #ffffff; padding: 8px; border-radius: 6px; border: 1px solid #e0e0e0;">`;
                        
                        if (gp.foto && gp.foto.trim() !== '') {
                            let fotoSrc = prepararSrc(gp.foto);
                            const datosGasto = JSON.stringify({ "Ruta": numRuta, "Chofer": r.chofer, "Concepto": gp.concepto, "Monto": "$" + parseFloat(gp.monto).toFixed(2), "Observaciones": obsGasto, "Fecha": gp.fecha || '' }).replace(/"/g, '&quot;');
                            
                            gastosDetalleHtml += `<div style="flex-shrink: 0;"><img src="${fotoSrc}" style="width: 65px; height: 65px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid #ddd;" onclick="verImagenGrandeConMarca('${fotoSrc}', '💰 Comprobante de Gasto', ${datosGasto})" title="Ver comprobante"></div>`;
                        }
                        
                        gastosDetalleHtml += `<div style="flex-grow: 1;">
                            <div>• <strong>${gp.concepto}:</strong> <span style="color:#2e7d32; font-weight:bold;">$${parseFloat(gp.monto).toFixed(2)}</span></div>
                            <div style="font-size:11px; color:#555; margin-top:3px; background:#fafafa; padding:4px 6px; border-radius:4px; border:1px solid #eee;">💬 <strong>Obs:</strong> ${obsGasto}</div>
                        </div>`;
                        
                        gastosDetalleHtml += `</div>`;
                    });
                    gastosDetalleHtml += '</div>';
                }

                // 📄 QUKAS: Fotos a la izquierda, número, fecha y observaciones a un costado
                let cucasDetalleHtml = '';
                if (cucasParada.length > 0) {
                    const cucasAgrupadas = {};
                    cucasParada.forEach(cp => {
                        const num = cp.numero_cuca || 'Sin número';
                        if (!cucasAgrupadas[num]) {
                            cucasAgrupadas[num] = {
                                numero_cuca: num,
                                observaciones: cp.observaciones || '',
                                fecha: cp.fecha || '',
                                fotos: []
                            };
                        }
                        if (cp.observaciones && cp.observaciones.trim() !== '') {
                            cucasAgrupadas[num].observaciones = cp.observaciones;
                        }
                        if (cp.foto_cuca && cp.foto_cuca.trim() !== '') {
                            cucasAgrupadas[num].fotos.push(cp.foto_cuca);
                        }
                    });

                    cucasDetalleHtml = '<div style="margin-top:6px; padding:8px 12px; border-left:3px solid #2e7d32; background:#f4fbf4; border-radius:6px; font-size:12px; line-height:1.3;">';
                    cucasDetalleHtml += '<p style="font-weight:bold; color:#2e7d32; margin-bottom:6px; font-size:12px;">📄 Comprobantes QK (Facturas):</p>';

                    Object.values(cucasAgrupadas).forEach(cucaGroup => {
                        const obsCuca = (cucaGroup.observaciones && cucaGroup.observaciones.trim() !== '') ? cucaGroup.observaciones : 'Sin observaciones';
                        const datosCuca = JSON.stringify({ "Ruta": numRuta, "Chofer": r.chofer, "No. QK": cucaGroup.numero_cuca, "Observaciones": obsCuca, "Páginas": cucaGroup.fotos.length, "Fecha": cucaGroup.fecha }).replace(/"/g, '&quot;');

                        cucasDetalleHtml += `<div style="margin-bottom: 8px; padding-bottom:6px; border-bottom:1px dashed #c8e6c9;">
                            <div style="display: flex; align-items: flex-start; gap: 10px; flex-wrap: wrap;">`;
                        
                        if (cucaGroup.fotos.length > 0) {
                            cucasDetalleHtml += `<div style="display:flex; flex-wrap:wrap; gap:6px; flex-shrink: 0;">`;
                            cucaGroup.fotos.forEach((fotoPath, idx) => {
                                let fotoCucaSrc = prepararSrc(fotoPath);
                                cucasDetalleHtml += `
                                    <div style="text-align:center;">
                                        <img src="${fotoCucaSrc}" style="width: 65px; height: 65px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 2px solid #2e7d32;" onclick="verImagenGrandeConMarca('${fotoCucaSrc}', '📄 QK ${cucaGroup.numero_cuca} (Pág ${idx + 1})', ${datosCuca})" title="Ver Pág ${idx + 1}">
                                        <div style="font-size:10px; color:#555; margin-top:2px;">Pág ${idx + 1}</div>
                                    </div>`;
                            });
                            cucasDetalleHtml += `</div>`;
                        }

                        cucasDetalleHtml += `<div style="flex-grow: 1; min-width: 180px;">
                            <div>• <strong>No. QK:</strong> ${cucaGroup.numero_cuca} (${cucaGroup.fotos.length} pág(s))</div>
                            <div>• <strong>Fecha:</strong> ${cucaGroup.fecha}</div>
                            <div style="margin-top: 3px; background: #ffffff; padding: 4px 6px; border-radius: 4px; border: 1px solid #d0e8d0; font-size:11px;">
                                💬 <strong>Obs:</strong> ${obsCuca}
                            </div>
                        </div>`;

                        cucasDetalleHtml += `</div></div>`;
                    });
                    cucasDetalleHtml += '</div>';
                } else {
                    cucasDetalleHtml = '<div style="margin-top:6px; font-size:12px; color:#888;">Sin QK (facturas) registradas en esta parada</div>';
                }

                const bgColor = esCompletado ? '#e8f5e9' : '#ffffff';
                const borderColor = esCompletado ? '#4caf50' : '#ddd';
                const badgeEstado = esCompletado 
                    ? '<span style="color:#2e7d32; font-weight:bold; font-size:12px;">✅ Completado</span>' 
                    : '<span style="color:#ed6c02; font-weight:bold; font-size:12px;">⏳ Pendiente</span>';

                paradasHtml += `
                    <div style="background:${bgColor}; border:1px solid ${borderColor}; border-radius:8px; padding:12px; margin-bottom:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <strong>#${p.orden} - ${destinoNombre}</strong>
                            ${badgeEstado}
                        </div>
                        <div style="font-size:13px; color:#555; margin-top:4px;">📍 ${p.direccion || ''}</div>
                        ${cucasDetalleHtml}
                        ${gastosDetalleHtml}
                    </div>
                `;
            });
            
            body.innerHTML = `
                <span class="cerrar-modal" onclick="cerrarModal()">&times;</span>
                <h2 style="color:#4A148C;">📋 Detalle de Ruta #${r.numero_ruta || r.id}</h2>
                <div style="background:#f5f5f5; padding:15px; border-radius:10px; margin:10px 0;">
                    <p><strong>Número de Ruta:</strong> ${r.numero_ruta || 'No asignado'}</p>
                    <p><strong>Chofer:</strong> ${r.chofer}</p>
                    <p><strong>Auxiliar:</strong> ${r.auxiliar ? r.auxiliar : 'Sin auxiliar'}</p>
                    <p><strong>Vehículo:</strong> ${r.placas} | ${r.no_economico}</p>
                    <p><strong>Origen:</strong> ${r.origen}</p>
                    <p><strong>Destino Final:</strong> ${r.destino_final || 'Pendiente'}</p>
                    
                    <div style="display:flex; gap:15px; align-items:center; margin-top:8px;">
                        <p><strong>Km Inicial:</strong> ${r.km_inicial || 0} km</p>
                        ${fotoInicioSrc ? `<button class="btn btn-info btn-pequeno" onclick="verImagenGrandeConMarca('${fotoInicioSrc}', '📷 Odómetro Inicial', ${datosOdoInicio})">📷 Foto Odómetro Inicial</button>` : '<span style="color:#aaa; font-size:12px;">(Sin foto inicial)</span>'}
                    </div>

                    <div style="display:flex; gap:15px; align-items:center; margin-top:6px;">
                        <p><strong>Km Final:</strong> ${r.km_final || 0} km (Total: ${r.km_total || 0} km)</p>
                        ${fotoFinSrc ? `<button class="btn btn-info btn-pequeno" onclick="verImagenGrandeConMarca('${fotoFinSrc}', '📷 Odómetro Final', ${datosOdoFin})">📷 Foto Odómetro Final</button>` : '<span style="color:#aaa; font-size:12px;">(Sin foto final)</span>'}
                    </div>

                    <p style="margin-top:10px;"><strong>Total de Gastos de la Ruta:</strong> <span style="color:green; font-weight:bold;">$${parseFloat(r.total_gastos || 0).toFixed(2)}</span></p>
                    <p><strong>Estatus General:</strong> <span class="badge badge-${r.estatus}">${r.estatus}</span></p>
                </div>
                <h3 style="margin-top:15px; margin-bottom:10px;">📍 Destinos, QK y Gastos por Parada</h3>
                ${paradasHtml}
            `;
        })
        .catch(err => {
            body.innerHTML = '<span class="cerrar-modal" onclick="cerrarModal()">&times;</span><div style="padding:20px; color:red;">Error al cargar los datos</div>';
        });
}

function editarPlaca(id, actual) {
    let nueva = prompt("Editar placa:", actual);
    if (nueva && nueva !== actual) {
        let f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="id" value="${id}"><input type="hidden" name="placa" value="${nueva}"><input type="hidden" name="editar_placa" value="1">`;
        document.body.appendChild(f);
        f.submit();
    }
}

function editarNoEconomico(id, actual) {
    let nueva = prompt("Editar número económico:", actual);
    if (nueva && nueva !== actual) {
        let f = document.createElement('form');
        f.method = 'POST';
        f.innerHTML = `<input type="hidden" name="id" value="${id}"><input type="hidden" name="no_economico" value="${nueva}"><input type="hidden" name="editar_no_economico" value="1">`;
        document.body.appendChild(f);
        f.submit();
    }
}

document.getElementById('btnDetalleSemana')?.addEventListener('click', function() {
    const semanaSelect = document.getElementById('semanaSelect');
    const semanaValor = semanaSelect.value;
    if (!semanaValor) {
        alert('Por favor, selecciona una semana para ver el detalle');
        return;
    }
    
    const modal = document.getElementById('semanaModal');
    const content = document.getElementById('semanaDetalleContent');
    modal.style.display = 'flex';
    content.innerHTML = '<div style="text-align:center; padding:40px;">Cargando detalles de la semana...</div>';
    
    fetch('?accion=get_semana_data&semana=' + semanaValor)
        .then(response => response.json())
        .then(data => {
            let html = `
                <div style="margin-bottom:20px; background:#f5f5f5; padding:15px; border-radius:10px;">
                    <p><strong>Semana:</strong> ${data.semana}</p>
                    <p><strong>Período:</strong> ${data.fecha_inicio} al ${data.fecha_fin}</p>
                    <p><strong>Total de viajes:</strong> ${data.viajes.length}</p>
                </div>
            `;
            
            if (data.viajes.length > 0) {
                html += '<div style="overflow-x: auto;">';
                html += '<table class="tabla-semana" style="width:100%; border-collapse:collapse;">';
                html += '<thead><tr style="background:#4A148C; color:white;">';
                html += '<th>ID Ruta</th><th>No. Ruta</th><th>Fecha Inicio</th><th>Chofer</th><th>Placas</th><th>Origen</th>';
                html += '<th>Km Inicial</th><th>Km Final</th><th>Km Total</th><th>Total Gastos</th>';
                html += '</tr></thead><tbody>';
                
                data.viajes.forEach(v => {
                    html += `<tr>
                        <td style="white-space:nowrap;">#${v.id}</td>
                        <td style="white-space:nowrap;">${v.numero_ruta}</td>
                        <td style="white-space:nowrap;">${v.fecha}</td>
                        <td style="white-space:nowrap;">${v.chofer}</td>
                        <td style="white-space:nowrap;">${v.placas}</td>
                        <td style="white-space:nowrap;">${v.destino}</td>
                        <td style="white-space:nowrap; text-align:center;">${v.km_inicial}</td>
                        <td style="white-space:nowrap; text-align:center;">${v.km_final}</td>
                        <td style="white-space:nowrap; text-align:center;">${v.km_total}</td>
                        <td style="white-space:nowrap; text-align:right;">$${v.gasto}</td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p>No hay viajes en esta semana</p>';
            }
            
            html += `
                <div style="margin-top:20px; background:#E1BEE7; padding:15px; border-radius:10px; text-align:right;">
                    <p style="font-size:18px; color:#4A148C;"><strong>TOTAL DE GASTOS: $${data.total_gastos_semana}</strong></p>
                </div>
            `;
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = '<div style="text-align:center; padding:40px; color:red;">Error al cargar los detalles de la semana</div>';
        });
});
</script>
</body>
</html>
