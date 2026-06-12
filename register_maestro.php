<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
/**
 * register_maestro.php
 * Endpoint para que un maestro autenticado cree cuentas de maestro de apoyo.
 * Solo accesible con token de rol "maestro".
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

// Solo maestros pueden acceder a este endpoint
$payload = requireRol('maestro');

$body    = getBody();
$nombre  = trim($body['nombre']   ?? '');
$email   = trim($body['email']    ?? '');
$usuario = trim($body['usuario']  ?? '');
$pass    = trim($body['password'] ?? '');

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
$ins->execute([$nombre, $email, $usuario, $hash, 'maestro']);

jsonResponse([
    'message' => 'Maestro de apoyo creado exitosamente',
    'id'      => (int)$pdo->lastInsertId()
], 201);
