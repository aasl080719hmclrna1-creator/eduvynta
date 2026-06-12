<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET: devuelve datos completos del usuario logueado ────────────────────
if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, nombre, email, usuario, rol, creado_en FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute([$payload['id']]);
    $user = $stmt->fetch();
    if (!$user) jsonError('Usuario no encontrado', 404);
    jsonResponse($user);
}

// ── PUT: actualizar perfil ────────────────────────────────────────────────
if ($method === 'PUT') {
    $body    = getBody();
    $nombre  = trim($body['nombre']   ?? '');
    $email   = trim($body['email']    ?? '');
    $newPass = trim($body['password'] ?? '');

    $updates = [];
    $params  = [];

    if ($nombre)  { $updates[] = 'nombre = ?';        $params[] = $nombre; }
    if ($email)   { $updates[] = 'email = ?';         $params[] = $email;  }
    if ($newPass) { $updates[] = 'password_hash = ?'; $params[] = password_hash($newPass, PASSWORD_BCRYPT); }

    if (empty($updates)) jsonError('Nada que actualizar');

    $params[] = $payload['id'];
    $sql = 'UPDATE usuarios SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $pdo->prepare($sql)->execute($params);

    jsonResponse(['message' => 'Perfil actualizado']);
}

jsonError('Método no permitido', 405);
