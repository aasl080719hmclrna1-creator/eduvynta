<?php
// config/database.php – Conexión PDO a MySQL
// Lee las variables de entorno de Railway automáticamente.

define('DB_HOST',    $_ENV['MYSQLHOST']     ?? getenv('MYSQLHOST')     ?? 'localhost');
define('DB_NAME',    $_ENV['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE') ?? 'railway');
define('DB_USER',    $_ENV['MYSQLUSER']     ?? getenv('MYSQLUSER')     ?? 'root');
define('DB_PASS',    $_ENV['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD') ?? '');
define('DB_PORT',    $_ENV['MYSQLPORT']     ?? getenv('MYSQLPORT')     ?? '3306');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST
             . ";port=" . DB_PORT
             . ";dbname=" . DB_NAME
             . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error de conexión a la base de datos']);
            exit;
        }
    }
    return $pdo;
}
