<?php
$conn = new mysqli('localhost', 'root', '', 'acarez_logistica');

if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['agregar_placa'])) {
        $placa = trim($_POST['placa']);
        if (!empty($placa)) {
            $conn->query("INSERT INTO catalogo_placas (placa) VALUES ('$placa')");
        }
    }
    
    if (isset($_POST['agregar_no_economico'])) {
        $no_economico = trim($_POST['no_economico']);
        if (!empty($no_economico)) {
            $conn->query("INSERT INTO catalogo_no_economico (no_economico) VALUES ('$no_economico')");
        }
    }
    
    if (isset($_POST['eliminar_placa'])) {
        $id = $_POST['id'];
        $conn->query("DELETE FROM catalogo_placas WHERE id = $id");
    }
    
    if (isset($_POST['eliminar_no_economico'])) {
        $id = $_POST['id'];
        $conn->query("DELETE FROM catalogo_no_economico WHERE id = $id");
    }
    
    if (isset($_POST['editar_placa'])) {
        $id = $_POST['id'];
        $placa = trim($_POST['placa']);
        $conn->query("UPDATE catalogo_placas SET placa = '$placa' WHERE id = $id");
    }
    
    if (isset($_POST['editar_no_economico'])) {
        $id = $_POST['id'];
        $no_economico = trim($_POST['no_economico']);
        $conn->query("UPDATE catalogo_no_economico SET no_economico = '$no_economico' WHERE id = $id");
    }
}

// Obtener datos
$placas = $conn->query("SELECT * FROM catalogo_placas ORDER BY placa");
$no_economicos = $conn->query("SELECT * FROM catalogo_no_economico ORDER BY no_economico");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Administrar Catálogos - ACAREZ</title>
    <style>
        body { font-family: 'Segoe UI', Arial; background: #e9ecef; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #0D47A1; border-left: 5px solid #0D47A1; padding-left: 15px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
        .card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .card h2 { color: #0D47A1; margin-top: 0; border-bottom: 2px solid #0D47A1; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #0D47A1; color: white; }
        .btn { padding: 6px 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px; }
        .btn-agregar { background: #28a745; color: white; }
        .btn-editar { background: #ffc107; color: #333; }
        .btn-eliminar { background: #dc3545; color: white; }
        .formulario { margin-bottom: 20px; display: flex; gap: 10px; }
        .formulario input { flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 5px; }
        .acciones { display: flex; gap: 5px; }
        .editar-form { display: none; margin-top: 5px; }
        .editar-form input { padding: 4px; width: 80px; }
        @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <h1>🚚 ACAREZ - Administrar Catálogos</h1>
    <p>Gestiona las placas y números económicos que aparecen en la app</p>
    
    <div class="grid">
        <div class="card">
            <h2>📋 Placas</h2>
            <form method="POST" class="formulario">
                <input type="text" name="placa" placeholder="Nueva placa (ej: ABC-1234)" required>
                <button type="submit" name="agregar_placa" class="btn btn-agregar">➕ Agregar</button>
            </form>
            <table>
                <thead><tr><th>ID</th><th>Placa</th><th>Acciones</th></tr></thead>
                <tbody>
                    <?php while($row = $placas->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td id="placa_text_<?= $row['id'] ?>"><?= htmlspecialchars($row['placa']) ?></td>
                        <td class="acciones">
                            <button class="btn btn-editar" onclick="mostrarEditarPlaca(<?= $row['id'] ?>, '<?= $row['placa'] ?>')">✏️ Editar</button>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="eliminar_placa" class="btn btn-eliminar" onclick="return confirm('¿Eliminar placa?')">🗑️</button>
                            </form>
                            <div id="editar_placa_<?= $row['id'] ?>" class="editar-form">
                                <form method="POST" style="display:flex; gap:5px;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="text" name="placa" value="<?= $row['placa'] ?>">
                                    <button type="submit" name="editar_placa" class="btn btn-editar">Guardar</button>
                                    <button type="button" class="btn" onclick="cancelarEditarPlaca(<?= $row['id'] ?>)">Cancelar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <h2>🔢 Números Económicos</h2>
            <form method="POST" class="formulario">
                <input type="text" name="no_economico" placeholder="Nuevo número (ej: A-11)" required>
                <button type="submit" name="agregar_no_economico" class="btn btn-agregar">➕ Agregar</button>
            </form>
            <table>
                <thead><tr><th>ID</th><th>Número Económico</th><th>Acciones</th></tr></thead>
                <tbody>
                    <?php while($row = $no_economicos->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td id="noe_text_<?= $row['id'] ?>"><?= htmlspecialchars($row['no_economico']) ?></td>
                        <td class="acciones">
                            <button class="btn btn-editar" onclick="mostrarEditarNoE(<?= $row['id'] ?>, '<?= $row['no_economico'] ?>')">✏️ Editar</button>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="eliminar_no_economico" class="btn btn-eliminar" onclick="return confirm('¿Eliminar número económico?')">🗑️</button>
                            </form>
                            <div id="editar_noe_<?= $row['id'] ?>" class="editar-form">
                                <form method="POST" style="display:flex; gap:5px;">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="text" name="no_economico" value="<?= $row['no_economico'] ?>">
                                    <button type="submit" name="editar_no_economico" class="btn btn-editar">Guardar</button>
                                    <button type="button" class="btn" onclick="cancelarEditarNoE(<?= $row['id'] ?>)">Cancelar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
function mostrarEditarPlaca(id) {
    document.getElementById('placa_text_' + id).style.display = 'none';
    document.getElementById('editar_placa_' + id).style.display = 'block';
}
function cancelarEditarPlaca(id) {
    document.getElementById('placa_text_' + id).style.display = 'table-cell';
    document.getElementById('editar_placa_' + id).style.display = 'none';
}
function mostrarEditarNoE(id) {
    document.getElementById('noe_text_' + id).style.display = 'none';
    document.getElementById('editar_noe_' + id).style.display = 'block';
}
function cancelarEditarNoE(id) {
    document.getElementById('noe_text_' + id).style.display = 'table-cell';
    document.getElementById('editar_noe_' + id).style.display = 'none';
}
</script>
</body>
</html>
