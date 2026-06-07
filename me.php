<?php
/**
 * GET /api/usuarios/me.php      → datos del usuario autenticado
 * PUT /api/usuarios/me.php      → actualizar nombre / email / password
 * GET /api/usuarios/search.php?q=texto → buscar alumnos (maestro, para inscribir)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../middleware/response.php';

setCorsHeaders();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, nombre, email, usuario, rol, creado_en FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$payload['id']]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Usuario no encontrado', 404);
    jsonResponse($user);
}

if ($method === 'PUT') {
    $body    = getBody();
    $nombre  = trim($body['nombre']  ?? '');
    $email   = trim($body['email']   ?? '');
    $newPass = trim($body['password'] ?? '');

    $updates = [];
    $params  = [];

    if ($nombre) { $updates[] = 'nombre = ?';  $params[] = $nombre; }
    if ($email)  { $updates[] = 'email  = ?';  $params[] = $email;  }
    if ($newPass){ $updates[] = 'password_hash = ?'; $params[] = password_hash($newPass, PASSWORD_BCRYPT); }

    if (empty($updates)) jsonError('Nada que actualizar');

    $params[] = $payload['id'];
    $sql = 'UPDATE usuarios SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);

    jsonResponse(['message' => 'Perfil actualizado']);
}

jsonError('Método no permitido', 405);
