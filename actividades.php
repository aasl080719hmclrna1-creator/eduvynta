<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id > 0) {
        $stmt = $pdo->prepare('
            SELECT a.*, m.nombre AS materia_nombre
            FROM actividades a
            LEFT JOIN materias m ON m.id = a.materia_id
            WHERE a.id = ? LIMIT 1
        ');
        $stmt->execute([$id]);
        $actividad = $stmt->fetch();
        if (!$actividad) jsonError('Actividad no encontrada', 404);
        jsonResponse($actividad);
    }

    if ($payload['rol'] === 'maestro') {
        $stmt = $pdo->prepare('
            SELECT a.*, m.nombre AS materia_nombre
            FROM actividades a
            LEFT JOIN materias m ON m.id = a.materia_id
            WHERE a.maestro_id = ?
            ORDER BY a.fecha_limite DESC
        ');
        $stmt->execute([$payload['id']]);
    } else {
        $stmt = $pdo->prepare('
            SELECT a.*, m.nombre AS materia_nombre
            FROM actividades a
            JOIN alumnos_grupos ag ON ag.grupo_id = a.grupo_id
            LEFT JOIN materias m ON m.id = a.materia_id
            WHERE ag.alumno_id = ?
            ORDER BY a.fecha_limite ASC
        ');
        $stmt->execute([$payload['id']]);
    }

    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden crear actividades', 403);

    $body        = getBody();
    $descripcion = trim($body['descripcion']  ?? '');
    $fecha_limite = trim($body['fecha_limite'] ?? '');
    $grupo_id    = (int)($body['grupo_id']    ?? 0);
    $materia_id  = (int)($body['materia_id']  ?? 0);

    if (!$descripcion || !$fecha_limite) jsonError('descripcion y fecha_limite son requeridos');

    $ins = $pdo->prepare('
        INSERT INTO actividades (maestro_id, grupo_id, materia_id, descripcion, fecha_limite)
        VALUES (?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $payload['id'],
        $grupo_id  ?: null,
        $materia_id ?: null,
        $descripcion,
        $fecha_limite,
    ]);

    jsonResponse(['message' => 'Actividad creada', 'id' => (int)$pdo->lastInsertId()], 201);
}

if ($method === 'PUT') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden editar actividades', 403);

    $body         = getBody();
    $id           = (int)($body['id']          ?? 0);
    $descripcion  = trim($body['descripcion']  ?? '');
    $fecha_limite = trim($body['fecha_limite'] ?? '');

    if (!$id) jsonError('id es requerido');

    $chk = $pdo->prepare('SELECT id FROM actividades WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Actividad no encontrada o no autorizado', 403);

    $updates = [];
    $params  = [];

    if ($descripcion)  { $updates[] = 'descripcion = ?';  $params[] = $descripcion; }
    if ($fecha_limite) { $updates[] = 'fecha_limite = ?'; $params[] = $fecha_limite; }

    if (empty($updates)) jsonError('Nada que actualizar');

    $params[] = $id;
    $pdo->prepare('UPDATE actividades SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    jsonResponse(['message' => 'Actividad actualizada']);
}

if ($method === 'DELETE') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden eliminar actividades', 403);

    $body = getBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonError('id es requerido');

    $chk = $pdo->prepare('SELECT id FROM actividades WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Actividad no encontrada o no autorizado', 403);

    $pdo->prepare('DELETE FROM actividades WHERE id = ?')->execute([$id]);

    jsonResponse(['message' => 'Actividad eliminada']);
}

jsonError('Método no permitido', 405);
