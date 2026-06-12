<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

$body    = getBody();
$nombre  = trim($body['nombre']   ?? '');
$email   = trim($body['email']    ?? '');
$usuario = trim($body['usuario']  ?? '');
$pass    = trim($body['password'] ?? '');

// El registro público SIEMPRE crea alumnos.
// Los maestros de apoyo los registra el maestro desde su panel.
$rol = 'alumno';

if (!$nombre || !$email || !$usuario || !$pass) {
    jsonError('Todos los campos son requeridos');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Email inválido');
}

if (strlen($pass) < 6) {
    jsonError('La contraseña debe tener al menos 6 caracteres');
}

$pdo = getDB();

$chk = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? OR usuario = ? LIMIT 1');
$chk->execute([$email, $usuario]);
if ($chk->fetch()) {
    jsonError('El email o usuario ya está registrado', 409);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

$ins = $pdo->prepare('INSERT INTO usuarios (nombre, email, usuario, password_hash, rol) VALUES (?, ?, ?, ?, ?)');
$ins->execute([$nombre, $email, $usuario, $hash, $rol]);

jsonResponse(['message' => 'Cuenta de alumno creada exitosamente', 'id' => (int)$pdo->lastInsertId()], 201);
