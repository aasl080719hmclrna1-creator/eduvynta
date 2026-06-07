<?php
/**
 * GET  /api/grupos/index.php              → lista grupos del maestro autenticado
 * POST /api/grupos/index.php              → crear grupo (maestro)
 * GET  /api/grupos/alumnos.php?grupo_id=X → alumnos de un grupo
 * POST /api/grupos/alumnos.php            → inscribir alumno a grupo { alumno_id, grupo_id }
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../middleware/response.php';

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
        // Alumno: sus grupos inscritos
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
