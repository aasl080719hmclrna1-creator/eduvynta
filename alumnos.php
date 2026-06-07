<?php
/**
 * GET  alumnos.php?grupo_id=X → lista alumnos del grupo
 * POST alumnos.php             → inscribir alumno { alumno_id, grupo_id }
 * DELETE alumnos.php           → dar de baja { alumno_id, grupo_id }
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
    $grupo_id = (int)($_GET['grupo_id'] ?? 0);
    if (!$grupo_id) jsonError('grupo_id requerido');

    $stmt = $pdo->prepare('
        SELECT u.id, u.nombre, u.email, u.usuario
        FROM alumnos_grupos ag
        JOIN usuarios u ON u.id = ag.alumno_id
        WHERE ag.grupo_id = ?
        ORDER BY u.nombre ASC
    ');
    $stmt->execute([$grupo_id]);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Acceso denegado', 403);

    $body      = getBody();
    $alumno_id = (int)($body['alumno_id'] ?? 0);
    $grupo_id  = (int)($body['grupo_id']  ?? 0);

    if (!$alumno_id || !$grupo_id) jsonError('alumno_id y grupo_id requeridos');

    // Verificar que el grupo pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    $ins = $pdo->prepare('INSERT IGNORE INTO alumnos_grupos (alumno_id, grupo_id) VALUES (?, ?)');
    $ins->execute([$alumno_id, $grupo_id]);

    jsonResponse(['message' => 'Alumno inscrito al grupo'], 201);
}

if ($method === 'DELETE') {
    if ($payload['rol'] !== 'maestro') jsonError('Acceso denegado', 403);

    $body      = getBody();
    $alumno_id = (int)($body['alumno_id'] ?? 0);
    $grupo_id  = (int)($body['grupo_id']  ?? 0);

    $del = $pdo->prepare('DELETE FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ?');
    $del->execute([$alumno_id, $grupo_id]);

    jsonResponse(['message' => 'Alumno eliminado del grupo']);
}

jsonError('Método no permitido', 405);
