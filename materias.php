<?php
/**
 * GET  /api/grupos/materias.php?grupo_id=X  → materias de un grupo
 * POST /api/grupos/materias.php             → crear materia { nombre, grupo_id }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../middleware/response.php';

setCorsHeaders();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $grupo_id = (int)($_GET['grupo_id'] ?? 0);
    if (!$grupo_id) jsonError('grupo_id requerido');

    $stmt = $pdo->prepare('SELECT * FROM materias WHERE grupo_id = ? ORDER BY nombre ASC');
    $stmt->execute([$grupo_id]);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Acceso denegado', 403);

    $body     = getBody();
    $nombre   = trim($body['nombre']   ?? '');
    $grupo_id = (int)($body['grupo_id'] ?? 0);

    if (!$nombre || !$grupo_id) jsonError('nombre y grupo_id requeridos');

    $ins = $pdo->prepare('INSERT INTO materias (nombre, grupo_id) VALUES (?, ?)');
    $ins->execute([$nombre, $grupo_id]);

    jsonResponse(['message' => 'Materia creada', 'id' => (int)$pdo->lastInsertId()], 201);
}

jsonError('Método no permitido', 405);
