<?php
/**
 * GET  index.php              → lista grupos del maestro autenticado / grupos del alumno
 * POST index.php              → crear grupo (maestro) { nombre, semestre }
 *
 * CORREGIDO: require_once usa __DIR__ hacia el mismo directorio (estructura plana)
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if ($payload['rol'] === 'maestro') {
        $stmt = $pdo->prepare('
            SELECT g.*, COUNT(ag.alumno_id) AS total_alumnos
            FROM grupos g
            LEFT JOIN alumnos_grupos ag ON ag.grupo_id = g.id
            WHERE g.maestro_id = ? AND g.activo = 1
            GROUP BY g.id
            ORDER BY g.nombre ASC
        ');
        $stmt->execute([$payload['id']]);
    } else {
        $stmt = $pdo->prepare('
            SELECT g.*, u.nombre AS maestro_nombre
            FROM alumnos_grupos ag
            JOIN grupos   g ON g.id = ag.grupo_id
            JOIN usuarios u ON u.id = g.maestro_id
            WHERE ag.alumno_id = ? AND g.activo = 1
        ');
        $stmt->execute([$payload['id']]);
    }
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden crear grupos', 403);

    $body     = getBody();
    $nombre   = trim($body['nombre']   ?? '');
    $semestre = (int)($body['semestre'] ?? 1);

    if (!$nombre) jsonError('nombre es requerido');

    $ins = $pdo->prepare('INSERT INTO grupos (nombre, semestre, maestro_id) VALUES (?, ?, ?)');
    $ins->execute([$nombre, $semestre, $payload['id']]);

    jsonResponse(['message' => 'Grupo creado', 'id' => (int)$pdo->lastInsertId()], 201);
}

jsonError('Método no permitido', 405);
