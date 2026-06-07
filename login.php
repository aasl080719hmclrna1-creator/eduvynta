<?php
/**
 * POST /api/auth/login.php
 * Body: { "usuario": "string", "password": "string" }
 * Response: { "token": "jwt", "usuario": { id, nombre, email, rol } }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../middleware/response.php';

setCorsHeaders();

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
