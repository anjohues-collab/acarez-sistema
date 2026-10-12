<?php
// Cargar variables de entorno del servidor
$host     = getenv('MYSQLHOST') ?: 'localhost';
$port     = getenv('MYSQLPORT') ?: '3306';
$dbname   = getenv('MYSQLDATABASE') ?: 'acarez_logistica';
$user     = getenv('MYSQLUSER') ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: '';

// Establecer zona horaria en PHP (-06:00 para México)[cite: 5]
date_default_timezone_set('America/Mexico_City');

// 1. CREAR CONEXIÓN MYSQLI (Para tus scripts existentes)[cite: 5]
$conn = new mysqli($host, $user, $password, $dbname, (int)$port);

// Validar fallos de conexión MySQLi[cite: 5]
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'mensaje' => 'Error de conexión a la base de datos (MySQLi): ' . $conn->connect_error
    ]));
}

// Establecer caracteres UTF-8 y zona horaria en MySQLi[cite: 5]
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '-06:00'");


// 2. CREAR CONEXIÓN PDO (Para los nuevos scripts de login y gestión de usuarios)
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    
    $pdo = new PDO($dsn, $user, $password, $options);
    $pdo->exec("SET time_zone = '-06:00'");

} catch (\PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'mensaje' => 'Error de conexión a la base de datos (PDO): ' . $e->getMessage()
    ]));
}
?>
