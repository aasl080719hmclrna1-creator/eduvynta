<?php
/**
 * POST /api/auth/register.php
 * Body: { "nombre", "email", "usuario", "password", "rol" }
 * Response: { "message": "...", "id": int }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/response.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido', 405);
}

$body    = getBody();
$nombre  = trim($body['nombre']   ?? '');
$email   = trim($body['email']    ?? '');
$usuario = trim($body['usuario']  ?? '');
$pass    = trim($body['password'] ?? '');
$rol     = trim($body['rol']      ?? 'alumno');

if (!$nombre || !$email || !$usuario || !$pass) {
    jsonError('Todos los campos son requeridos');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Email inválido');
}

if (!in_array($rol, ['maestro', 'alumno'])) {
    $rol = 'alumno';
}

$pdo = getDB();

// Verificar duplicados
$chk = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? OR usuario = ? LIMIT 1');
$chk->execute([$email, $usuario]);
if ($chk->fetch()) {
    jsonError('El email o usuario ya está registrado', 409);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

$ins = $pdo->prepare('INSERT INTO usuarios (nombre, email, usuario, password_hash, rol) VALUES (?, ?, ?, ?, ?)');
$ins->execute([$nombre, $email, $usuario, $hash, $rol]);

jsonResponse(['message' => 'Usuario registrado exitosamente', 'id' => (int)$pdo->lastInsertId()], 201);
