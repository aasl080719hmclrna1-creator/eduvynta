<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

$body    = getBody();
$usuario = trim($body['usuario'] ?? '');
$pass    = trim($body['password'] ?? '');

if (!$usuario || !$pass) {
    jsonError('Usuario y contraseña son requeridos');
}

$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id, nombre, email, usuario, password_hash, rol, activo FROM usuarios WHERE usuario = ? LIMIT 1');
$stmt->execute([$usuario]);
$user = $stmt->fetch();

if (!$user || !$user['activo']) {
    jsonError('Credenciales incorrectas', 401);
}

if (!password_verify($pass, $user['password_hash'])) {
    jsonError('Credenciales incorrectas', 401);
}

// Generar token JWT con id, rol y nombre del usuario
$token = jwtEncode([
    'id'     => $user['id'],
    'rol'    => $user['rol'],
    'nombre' => $user['nombre'],
]);

jsonResponse([
    'token'   => $token,
    'usuario' => [
        'id'     => $user['id'],
        'nombre' => $user['nombre'],
        'email'  => $user['email'],
        'rol'    => $user['rol'],
    ],
]);
